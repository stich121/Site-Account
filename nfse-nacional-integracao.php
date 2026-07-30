<?php
$autoloadNfse = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadNfse)) {
    require_once $autoloadNfse;
}
require_once __DIR__ . '/nfse-dps-fiscal.php';
// Integração com o Portal Nacional da NFS-e (SEFIN Nacional / ADN), via
// nfse-nacional/nfse-php (https://github.com/nfse-nacional/nfse-php).
//
// Este arquivo não gera HTML — só funções, para ser usado por notas-fiscais.php e
// processar-fila-nfse.php. O certificado A1 é por empresa emissora (cadastrado em
// notas-certificados.php, colunas certificado_arquivo/certificado_senha_cifrada em
// empresas_emissoras) — não existe mais um certificado único global.
//
// Duas checagens diferentes precisam passar antes de enviar de verdade:
// 1) integracaoNfseDisponivel(): coisa de servidor (Composer instalado, lib presente).
// 2) certificadoEmpresaDisponivel($empresa): a empresa específica tem certificado configurado.
// Se qualquer uma falhar, as funções retornam um erro claro e recuperável, sem fatal error.
//
// O mapeamento fiscal da DPS é centralizado em nfse-dps-fiscal.php e validado pelo SDK antes da transmissão.

function integracaoNfseDisponivel(): array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [false, 'Dependências do Composer não instaladas neste servidor (rode "composer install").'];
    }

    require_once $autoload;

    if (!class_exists(\Nfse\Nfse::class)) {
        return [false, 'Biblioteca nfse-nacional/nfse-php não encontrada no vendor/ (confira o composer.json).'];
    }

    $configAppKey = __DIR__ . '/config_app_key.php';
    if (!is_file($configAppKey)) {
        return [false, 'Chave de criptografia da aplicação ainda não configurada (falta config_app_key.php).'];
    }

    require_once $configAppKey;

    return [true, ''];
}

function certificadoEmpresaDisponivel(array $empresa): array
{
    if (empty($empresa['certificado_arquivo']) || empty($empresa['certificado_senha_cifrada'])) {
        return [false, 'Empresa sem certificado digital A1 cadastrado.'];
    }
    $caminho = __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']);
    if (!is_file($caminho)) {
        return [false, 'O certificado A1 cadastrado não foi encontrado no servidor.'];
    }
    try {
        $conteudo = file_get_contents($caminho);
        $certs = [];
        if ($conteudo === false || !openssl_pkcs12_read($conteudo, $certs, descriptografarSegredo((string) $empresa['certificado_senha_cifrada'])) || empty($certs['cert'])) {
            return [false, 'Não foi possível abrir o certificado A1; confira arquivo e senha.'];
        }
        $info = openssl_x509_parse($certs['cert']);
        if ($info === false || empty($info['validTo_time_t'])) return [false, 'Não foi possível verificar a validade do certificado A1.'];
        if ((int) $info['validTo_time_t'] < time()) return [false, 'O certificado A1 expirou em ' . date('d/m/Y', (int) $info['validTo_time_t']) . '.'];
        if (!empty($info['validFrom_time_t']) && (int) $info['validFrom_time_t'] > time()) return [false, 'O certificado A1 ainda não está válido.'];
    } catch (Throwable $e) {
        return [false, 'Falha ao validar certificado A1: ' . $e->getMessage()];
    }
    return [true, ''];
}
function normalizarChaveAcessoNfse(?string $valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') return null;
    return str_starts_with($valor, 'NFS') ? substr($valor, 3) : $valor;
}
function nfseIdDpsValido(string $idDps): bool
{
    return preg_match('/^DPS[0-9]{42}$/D', $idDps) === 1;
}
function dpsAPartirDaNota(array $nota, array $empresa, array $cliente, array $itens, ?array $nfse = null): array
{
    return nfseMontarDps($nota, $empresa, $cliente, $itens, $nfse);
}

function enviarNfseNacional(array $dpsMontada, string $ambiente, array $empresa): array
{
    [$disponivel, $motivo] = integracaoNfseDisponivel();
    if (!$disponivel) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $motivo,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }

    if (!empty($dpsMontada['erros_validacao'])) {
        return [
            'sucesso' => false, 'status' => 'rejeitada',
            'motivo_rejeicao' => 'DPS não transmitida: ' . implode(' ', $dpsMontada['erros_validacao']),
            'chave_acesso' => null, 'protocolo_autorizacao' => null, 'xml_gerado' => null,
        ];
    }

    [$certificadoOk, $motivoCertificado] = certificadoEmpresaDisponivel($empresa);
    if (!$certificadoOk) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $motivoCertificado,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }

    try {
        $context = new \Nfse\Http\NfseContext(
            ambiente: $ambiente === 'producao' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
            certificatePath: __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']),
            certificatePassword: descriptografarSegredo($empresa['certificado_senha_cifrada'])
        );

        $nfse = new \Nfse\Nfse($context);
        $dps = new \Nfse\Dto\Nfse\DpsData($dpsMontada['dados']);
        if (class_exists(\Nfse\Validator\DpsValidator::class)) {
            $validacao = (new \Nfse\Validator\DpsValidator())->validate($dps);
            if (!$validacao->isValid) {
                return ['sucesso' => false, 'status' => 'rejeitada', 'motivo_rejeicao' => 'DPS inválida: ' . implode(' ', $validacao->errors), 'chave_acesso' => null, 'protocolo_autorizacao' => null, 'xml_gerado' => null];
            }
        }
        $nfseData = $nfse->contribuinte()->emitir($dps);

        return [
            'sucesso' => true,
            'status' => 'autorizada',
            'motivo_rejeicao' => null,
            'chave_acesso' => normalizarChaveAcessoNfse($nfseData->infNfse->id ?? null),
            'protocolo_autorizacao' => $nfseData->infNfse->numeroDfse ?? null,
            'xml_gerado' => property_exists($nfseData, 'nfseXml') && is_string($nfseData->nfseXml) ? $nfseData->nfseXml : null,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => $e->getMessage(),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }
}

// Buscador de NFS-e: usa a Distribuição de DFe do ADN (/contribuintes/DFe/{nsu}) para baixar,
// por NSU sequencial, TODOS os documentos ligados ao CNPJ da empresa — tanto as notas que ela
// emitiu (prestador = empresa) quanto as que ela recebeu (tomador = empresa). Não existe filtro
// de data nesse endpoint: por isso os documentos baixados são gravados em
// notas_fiscais_nfse_adn e a busca/filtro por período acontece sobre essa cópia local.
function sincronizarNfseAdn(PDO $dbNotas, array $empresa): array
{
    [$disponivel, $motivo] = integracaoNfseDisponivel();
    if (!$disponivel) {
        return ['sucesso' => false, 'mensagem' => $motivo, 'total' => 0];
    }

    [$certificadoOk, $motivoCertificado] = certificadoEmpresaDisponivel($empresa);
    if (!$certificadoOk) {
        return ['sucesso' => false, 'mensagem' => $motivoCertificado, 'total' => 0];
    }

    $cnpjEmpresa = preg_replace('/\D+/', '', (string) ($empresa['cnpj'] ?? ''));
    if ($cnpjEmpresa === '') {
        return ['sucesso' => false, 'mensagem' => 'Empresa sem CNPJ cadastrado; cadastre o CNPJ em Empresas emissoras.', 'total' => 0];
    }

    $totalProcessado = 0;

    try {
        $context = new \Nfse\Http\NfseContext(
            ambiente: ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
            certificatePath: __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']),
            certificatePassword: descriptografarSegredo((string) $empresa['certificado_senha_cifrada'])
        );
        $nfse = new \Nfse\Nfse($context);
        $parser = new \Nfse\Xml\NfseXmlParser();

        $stmtUpsert = $dbNotas->prepare(
            'INSERT INTO notas_fiscais_nfse_adn
                (empresa_emissora_id, chave_acesso, nsu, tipo_documento, numero_nfse, codigo_status,
                 cnpj_prestador, nome_prestador, cnpj_tomador, nome_tomador, descricao_servico,
                 data_emissao, competencia, valor_servico, valor_liquido, xml_completo, atualizado_em)
             VALUES
                (:empresa_emissora_id, :chave_acesso, :nsu, :tipo_documento, :numero_nfse, :codigo_status,
                 :cnpj_prestador, :nome_prestador, :cnpj_tomador, :nome_tomador, :descricao_servico,
                 :data_emissao, :competencia, :valor_servico, :valor_liquido, :xml_completo, NOW())
             ON DUPLICATE KEY UPDATE
                nsu = VALUES(nsu), tipo_documento = VALUES(tipo_documento), numero_nfse = VALUES(numero_nfse),
                codigo_status = VALUES(codigo_status), cnpj_prestador = VALUES(cnpj_prestador),
                nome_prestador = VALUES(nome_prestador), cnpj_tomador = VALUES(cnpj_tomador),
                nome_tomador = VALUES(nome_tomador), descricao_servico = VALUES(descricao_servico),
                data_emissao = VALUES(data_emissao), competencia = VALUES(competencia),
                valor_servico = VALUES(valor_servico), valor_liquido = VALUES(valor_liquido),
                xml_completo = VALUES(xml_completo), atualizado_em = NOW()'
        );

        $ultimoNsu = (int) ($empresa['nfse_adn_ultimo_nsu'] ?? 0);
        // O ADN aplica rate limit por CNPJ nesse endpoint (HTTP 429 se chamado rápido demais).
        // Por isso um clique em "Sincronizar agora" baixa no máximo 5 lotes; para pegar um backlog
        // maior o usuário clica de novo (o NSU já avançado fica salvo, então continua de onde parou).
        $maximoLotes = 5;

        $stmtEmpresa = $dbNotas->prepare('UPDATE empresas_emissoras SET nfse_adn_ultimo_nsu = :nsu, nfse_adn_sincronizado_em = NOW() WHERE id = :id');

        for ($lote = 0; $lote < $maximoLotes; $lote++) {
            $resposta = $nfse->contribuinte()->baixarDfe($ultimoNsu, $cnpjEmpresa, true);

            foreach ($resposta->listaNsu as $documento) {
                if (empty($documento->dfeXmlGZipB64) || empty($documento->chaveAcesso)) {
                    continue;
                }
                $xml = @gzdecode(base64_decode($documento->dfeXmlGZipB64));
                if ($xml === false || $xml === '') {
                    continue;
                }
                try {
                    $nfseData = $parser->parse($xml);
                } catch (Throwable $e) {
                    continue;
                }

                $infNfse = $nfseData->infNfse ?? null;
                $infDps = $infNfse?->dps?->infDps;
                $prestador = $infDps?->prestador;
                $tomador = $infDps?->tomador;
                $servico = $infDps?->servico;

                $cnpjPrestador = preg_replace('/\D+/', '', (string) ($prestador?->cnpj ?? ''));
                $cnpjTomador = preg_replace('/\D+/', '', (string) ($tomador?->cnpj ?? ''));
                $tipoDocumento = $cnpjPrestador === $cnpjEmpresa ? 'emitida' : 'recebida';
                $dataEmissao = $infDps?->dataEmissao;
                $dataCompetencia = $infDps?->dataCompetencia;

                $stmtUpsert->execute([
                    'empresa_emissora_id' => (int) $empresa['id'],
                    'chave_acesso' => normalizarChaveAcessoNfse($documento->chaveAcesso) ?? $documento->chaveAcesso,
                    'nsu' => $documento->nsu,
                    'tipo_documento' => $tipoDocumento,
                    'numero_nfse' => $infNfse?->numeroNfse,
                    'codigo_status' => $infNfse?->codigoStatus?->value,
                    'cnpj_prestador' => $cnpjPrestador !== '' ? $cnpjPrestador : null,
                    'nome_prestador' => $prestador?->nome,
                    'cnpj_tomador' => $cnpjTomador !== '' ? $cnpjTomador : null,
                    'nome_tomador' => $tomador?->nome,
                    'descricao_servico' => $servico?->codigoServico?->descricaoServico ?? $servico?->informacaoComplemento?->informacoesComplementares,
                    'data_emissao' => !empty($dataEmissao) ? date('Y-m-d H:i:s', strtotime($dataEmissao)) : null,
                    'competencia' => !empty($dataCompetencia) ? date('Y-m-d', strtotime($dataCompetencia)) : null,
                    'valor_servico' => $infDps?->valores?->valorServicoPrestado?->valorServico,
                    'valor_liquido' => $infNfse?->valores?->valorLiquido,
                    'xml_completo' => $xml,
                ]);
                $totalProcessado++;
            }

            $novoUltimoNsu = (int) ($resposta->ultimoNsu ?? $ultimoNsu);
            if ($novoUltimoNsu <= $ultimoNsu) {
                $ultimoNsu = $novoUltimoNsu;
                $stmtEmpresa->execute(['nsu' => $ultimoNsu, 'id' => (int) $empresa['id']]);
                break;
            }
            $ultimoNsu = $novoUltimoNsu;
            $stmtEmpresa->execute(['nsu' => $ultimoNsu, 'id' => (int) $empresa['id']]);
            if (empty($resposta->listaNsu)) {
                break;
            }
        }

        $mensagem = "Sincronização concluída: {$totalProcessado} documento(s) novo(s)/atualizado(s).";
        if ($totalProcessado > 0) {
            $mensagem .= ' Se ainda houver documentos mais antigos pendentes, clique em "Sincronizar agora" de novo para continuar.';
        }

        return ['sucesso' => true, 'mensagem' => $mensagem, 'total' => $totalProcessado];
    } catch (\Nfse\Http\Exceptions\NfseApiException $e) {
        if ($e->getCode() === 429) {
            $mensagem = $totalProcessado > 0
                ? "Portal Nacional limitou as requisições (erro 429) depois de {$totalProcessado} documento(s). O progresso já foi salvo; aguarde alguns minutos antes de sincronizar de novo."
                : 'O Portal Nacional limitou as requisições neste CNPJ (erro 429 - excesso de chamadas em pouco tempo). Aguarde alguns minutos e clique em "Sincronizar agora" novamente.';

            return ['sucesso' => false, 'mensagem' => $mensagem, 'total' => $totalProcessado];
        }

        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar o Portal Nacional: ' . trim(strip_tags($e->getMessage())), 'total' => $totalProcessado];
    } catch (Throwable $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar o Portal Nacional: ' . trim(strip_tags($e->getMessage())), 'total' => $totalProcessado];
    }
}
?>
