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
// Consulta o CNC (Cadastro Nacional de Contribuintes) do Portal Nacional pra descobrir se um
// CPF/CNPJ tem Inscrição Municipal registrada num município - é essa mesma checagem que o Sefin
// Nacional faz ao validar a IM do prestador/tomador na DPS (erro E0116/E0228 quando deveria ter
// IM e não tem). Usado pra autopreencher a IM tanto do tomador (na emissão) quanto da própria
// empresa emissora (no cadastro dela).
//
// $empresaCertificado só empresta o certificado A1 pra autenticar no gateway do ADN - não precisa
// ser a mesma empresa/CNPJ que está sendo consultada (por isso $documento e $codMunicipio são
// parâmetros à parte): uma empresa emissora recém-cadastrada ainda não tem certificado próprio,
// então usa o de qualquer outra empresa já configurada só pra abrir a conexão (ver
// empresaComCertificadoParaConsultaCnc()).
//
// A biblioteca nfse-nacional/nfse-php tem um método pronto (Nfse::municipio()->consultarContribuinte),
// mas ele monta a URL como path (/cnc/consulta/cad/{documento}) - diferente do endpoint oficial
// documentado (GET /cnc/consulta/cad?inscricaoFederal=...&codMunicipio=...), então a chamada
// direta aqui segue a especificação oficial (references/api-specs/*/API-NFS-e-CNC-Consulta-*.json).
function consultarContribuinteCnc(array $empresaCertificado, string $documento, string $codMunicipio): array
{
    [$disponivel, $motivo] = integracaoNfseDisponivel();
    if (!$disponivel) {
        return ['sucesso' => false, 'mensagem' => $motivo, 'im' => null];
    }
    [$certificadoOk, $motivoCertificado] = certificadoEmpresaDisponivel($empresaCertificado);
    if (!$certificadoOk) {
        return ['sucesso' => false, 'mensagem' => $motivoCertificado, 'im' => null];
    }

    $documento = preg_replace('/\D+/', '', $documento);
    if (!in_array(strlen($documento), [11, 14], true)) {
        return ['sucesso' => false, 'mensagem' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.', 'im' => null];
    }

    $codMunicipio = preg_replace('/\D+/', '', $codMunicipio);
    if ($codMunicipio === '') {
        return ['sucesso' => false, 'mensagem' => 'Município (código IBGE) não informado para a consulta.', 'im' => null];
    }

    try {
        $baseUrl = ($empresaCertificado['ambiente_emissao'] ?? 'homologacao') === 'producao'
            ? 'https://adn.nfse.gov.br'
            : 'https://adn.producaorestrita.nfse.gov.br';

        $caminhoCert = __DIR__ . '/certificados-nfse/' . basename((string) $empresaCertificado['certificado_arquivo']);
        $senha = descriptografarSegredo((string) $empresaCertificado['certificado_senha_cifrada']);

        $client = new \GuzzleHttp\Client([
            'base_uri' => $baseUrl,
            'curl' => [
                CURLOPT_SSLCERTTYPE => 'P12',
                CURLOPT_SSLCERT => $caminhoCert,
                CURLOPT_SSLCERTPASSWD => $senha,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ],
            \GuzzleHttp\RequestOptions::HEADERS => ['Accept' => 'application/json'],
        ]);

        $resposta = $client->get('/cnc/consulta/cad', [
            \GuzzleHttp\RequestOptions::QUERY => [
                'inscricaoFederal' => $documento,
                'codMunicipio' => $codMunicipio,
            ],
        ]);

        $decodificado = json_decode((string) $resposta->getBody(), true);
        $lista = $decodificado['ListaCadastroMunicipal'] ?? [];

        $im = null;
        $razaoSocial = null;
        foreach ($lista as $registro) {
            $infCad = $registro['InfCad'] ?? [];
            if (!empty($infCad['InscricaoMunicipal'])) {
                $im = (string) $infCad['InscricaoMunicipal'];
                $razaoSocial = $infCad['RazaoSocial'] ?? null;
                break;
            }
        }

        return [
            'sucesso' => true,
            'mensagem' => $im !== null
                ? 'Inscrição Municipal encontrada no cadastro nacional (CNC).'
                : 'Nenhuma Inscrição Municipal registrada nesse município para esse documento - não precisa preencher.',
            'im' => $im,
            'razao_social' => $razaoSocial,
        ];
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
            return [
                'sucesso' => true,
                'mensagem' => 'Nenhuma Inscrição Municipal registrada nesse município para esse documento - não precisa preencher.',
                'im' => null,
            ];
        }

        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar o CNC: ' . trim(strip_tags($e->getMessage())), 'im' => null];
    } catch (Throwable $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar o CNC: ' . trim(strip_tags($e->getMessage())), 'im' => null];
    }
}

// Acha qualquer empresa emissora ativa com certificado A1 válido, pra emprestar a autenticação
// numa consulta ao CNC sobre uma empresa DIFERENTE (ex.: uma que está sendo cadastrada agora e
// ainda não tem certificado próprio). Preferência pelo ambiente de produção, que é onde o CNC
// tem os dados reais - em homologação o cadastro é só de teste.
function empresaComCertificadoParaConsultaCnc(PDO $dbNotas): ?array
{
    // certificadoEmpresaDisponivel() usa descriptografarSegredo(), que só existe depois que
    // config_app_key.php é carregado dentro de integracaoNfseDisponivel(). Mesmo esquecimento já
    // corrigido antes em empresasComCertificadoValidoAdn() - faltou replicar aqui.
    [$integracaoOk] = integracaoNfseDisponivel();
    if (!$integracaoOk) {
        return null;
    }

    $empresas = $dbNotas->query(
        "SELECT * FROM empresas_emissoras WHERE ativo = 1 ORDER BY (ambiente_emissao = 'producao') DESC, razao_social ASC"
    )->fetchAll();

    foreach ($empresas as $empresa) {
        [$certificadoOk] = certificadoEmpresaDisponivel($empresa);
        if ($certificadoOk) {
            return $empresa;
        }
    }

    return null;
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

// Extrai os campos gravados em notas_fiscais_nfse_adn a partir do XML já parseado.
// Isolado para poder ser reaproveitado tanto na sincronização (dados novos vindos do ADN)
// quanto num reprocessamento local (recalcular a partir do xml_completo já salvo, sem
// chamar o Portal Nacional de novo) sempre que essa extração for corrigida/melhorada.
function extrairCamposNfseAdn(\Nfse\Dto\Nfse\NfseData $nfseData, string $cnpjEmpresa): array
{
    $infNfse = $nfseData->infNfse ?? null;
    $infDps = $infNfse?->dps?->infDps;
    $prestador = $infDps?->prestador;
    $tomador = $infDps?->tomador;
    $servico = $infDps?->servico;

    $cnpjPrestador = preg_replace('/\D+/', '', (string) ($prestador?->cnpj ?? ''));
    $cnpjTomador = preg_replace('/\D+/', '', (string) ($tomador?->cnpj ?? ''));
    $dataEmissao = $infDps?->dataEmissao;
    $dataCompetencia = $infDps?->dataCompetencia;

    return [
        'tipo_documento' => $cnpjPrestador === $cnpjEmpresa ? 'emitida' : 'recebida',
        'numero_nfse' => $infNfse?->numeroNfse,
        'codigo_status' => $infNfse?->codigoStatus?->value,
        'cnpj_prestador' => $cnpjPrestador !== '' ? $cnpjPrestador : null,
        // xNome no bloco "prestador" da DPS é opcional (nem todo emissor de terceiros preenche);
        // o nome oficial e sempre garantido é o do bloco "emitente" da NFS-e, preenchido pelo
        // ADN a partir do cadastro nacional do CNPJ emissor.
        'nome_prestador' => $prestador?->nome ?? $infNfse?->emitente?->nome,
        'cnpj_tomador' => $cnpjTomador !== '' ? $cnpjTomador : null,
        'nome_tomador' => $tomador?->nome,
        'descricao_servico' => $servico?->codigoServico?->descricaoServico ?? $servico?->informacaoComplemento?->informacoesComplementares,
        'data_emissao' => !empty($dataEmissao) ? date('Y-m-d H:i:s', strtotime($dataEmissao)) : null,
        'competencia' => !empty($dataCompetencia) ? date('Y-m-d', strtotime($dataCompetencia)) : null,
        'valor_servico' => $infDps?->valores?->valorServicoPrestado?->valorServico,
        'valor_liquido' => $infNfse?->valores?->valorLiquido,
    ];
}

// Reprocessa os documentos já baixados (xml_completo salvo) sem chamar o Portal Nacional de
// novo. Usado depois de corrigir/melhorar extrairCamposNfseAdn(), para os documentos antigos
// ganharem os campos novos/corrigidos sem precisar re-sincronizar (e sem esbarrar no rate limit).
//
// Um mesmo chave_acesso pode aparecer na Distribuição de DFe mais de uma vez: primeiro como a
// NFS-e completa (infNFSe) e depois como um EVENTO (infEvento) — ex.: cancelamento. Só a NFS-e
// completa tem prestador/tomador/valores; o evento só referencia a chave e o tipo do evento. Por
// isso todo lugar que lê o XML salvo/baixado precisa checar infNfse primeiro.
function reprocessarNfseAdnLocal(PDO $dbNotas): int
{
    $parser = new \Nfse\Xml\NfseXmlParser();
    $total = 0;

    $stmtSelecionar = $dbNotas->query(
        "SELECT a.id, a.xml_completo, e.cnpj AS empresa_cnpj
         FROM notas_fiscais_nfse_adn a
         INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id
         WHERE a.xml_completo IS NOT NULL"
    );
    $stmtAtualizar = $dbNotas->prepare(
        'UPDATE notas_fiscais_nfse_adn SET
            tipo_documento = :tipo_documento, numero_nfse = :numero_nfse, codigo_status = :codigo_status,
            cnpj_prestador = :cnpj_prestador, nome_prestador = :nome_prestador,
            cnpj_tomador = :cnpj_tomador, nome_tomador = :nome_tomador,
            descricao_servico = :descricao_servico, data_emissao = :data_emissao, competencia = :competencia,
            valor_servico = :valor_servico, valor_liquido = :valor_liquido, atualizado_em = NOW()
         WHERE id = :id'
    );

    while ($linha = $stmtSelecionar->fetch()) {
        $cnpjEmpresa = preg_replace('/\D+/', '', (string) ($linha['empresa_cnpj'] ?? ''));
        try {
            $nfseData = $parser->parse((string) $linha['xml_completo']);
        } catch (Throwable $e) {
            continue;
        }

        // XML de evento salvo por engano (versão antiga do código, antes dessa checagem existir):
        // não há prestador/tomador/valores pra extrair, só aplica o cancelamento se for o caso.
        if (($nfseData->infNfse ?? null) === null) {
            aplicarEventoNfseAdn($dbNotas, $nfseData);
            continue;
        }

        $campos = extrairCamposNfseAdn($nfseData, $cnpjEmpresa);
        $campos['id'] = (int) $linha['id'];
        $stmtAtualizar->execute($campos);
        $total++;
    }

    return $total;
}

// Códigos de evento (tabela oficial SEFIN Nacional) que significam "esta NFS-e foi cancelada".
const NFSE_ADN_CODIGOS_EVENTO_CANCELAMENTO = ['101101', '105102', '305101'];

// Aplica um evento (infEvento) recebido do ADN: hoje só nos interessa marcar cancelamento na nota
// já sincronizada (identificada pela chave que o próprio evento referencia). Eventos de outro tipo
// (confirmação/rejeição do tomador etc.) são ignorados — não corrigem/alteram a nota.
// Retorna true se o XML era mesmo um evento (então não deve ser tratado como NFS-e completa).
function aplicarEventoNfseAdn(PDO $dbNotas, \Nfse\Dto\Nfse\NfseData $nfseData): bool
{
    $infPedReg = $nfseData->infEvento?->pedRegEvento?->infPedReg ?? null;
    if ($infPedReg === null) {
        return false;
    }

    $chaveEvento = normalizarChaveAcessoNfse($infPedReg->chaveNfse);
    if ($chaveEvento !== null && in_array($infPedReg->tipoEvento, NFSE_ADN_CODIGOS_EVENTO_CANCELAMENTO, true)) {
        $stmt = $dbNotas->prepare(
            'UPDATE notas_fiscais_nfse_adn
             SET cancelada = 1, data_cancelamento = COALESCE(data_cancelamento, :data_evento), atualizado_em = NOW()
             WHERE chave_acesso = :chave_acesso'
        );
        $stmt->execute([
            'data_evento' => !empty($infPedReg->dataHoraEvento) ? date('Y-m-d H:i:s', strtotime($infPedReg->dataHoraEvento)) : date('Y-m-d H:i:s'),
            'chave_acesso' => $chaveEvento,
        ]);
    }

    return true;
}

// Corrige documentos cuja linha ficou sem prestador/tomador/valores porque, numa sincronização
// antiga (antes da checagem infNfse/infEvento existir), um evento sobrescreveu por engano os dados
// da NFS-e original. Refaz a consulta por chave (endpoint de consulta direta, não a distribuição por
// NSU) e regrava os dados corretos, sem precisar re-sincronizar tudo do zero.
function repararNotasCorrompidasPorEventoAdn(PDO $dbNotas, array $empresa): array
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

    $stmtBuscar = $dbNotas->prepare(
        'SELECT id, chave_acesso FROM notas_fiscais_nfse_adn
         WHERE empresa_emissora_id = :empresa_emissora_id AND numero_nfse IS NULL
         LIMIT 10'
    );
    $stmtBuscar->execute(['empresa_emissora_id' => (int) $empresa['id']]);
    $linhas = $stmtBuscar->fetchAll();

    if (empty($linhas)) {
        return ['sucesso' => true, 'mensagem' => 'Nenhum documento corrompido encontrado para essa empresa.', 'total' => 0];
    }

    try {
        $context = new \Nfse\Http\NfseContext(
            ambiente: ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
            certificatePath: __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']),
            certificatePassword: descriptografarSegredo((string) $empresa['certificado_senha_cifrada'])
        );
        $nfse = new \Nfse\Nfse($context);

        $stmtAtualizar = $dbNotas->prepare(
            'UPDATE notas_fiscais_nfse_adn SET
                tipo_documento = :tipo_documento, numero_nfse = :numero_nfse, codigo_status = :codigo_status,
                cnpj_prestador = :cnpj_prestador, nome_prestador = :nome_prestador,
                cnpj_tomador = :cnpj_tomador, nome_tomador = :nome_tomador,
                descricao_servico = :descricao_servico, data_emissao = :data_emissao, competencia = :competencia,
                valor_servico = :valor_servico, valor_liquido = :valor_liquido, xml_completo = :xml_completo,
                atualizado_em = NOW()
             WHERE id = :id'
        );

        $total = 0;
        foreach ($linhas as $linha) {
            try {
                $nfseData = $nfse->contribuinte()->consultar((string) $linha['chave_acesso']);
            } catch (Throwable $e) {
                continue;
            }
            if ($nfseData === null || ($nfseData->infNfse ?? null) === null) {
                continue;
            }

            $campos = extrairCamposNfseAdn($nfseData, $cnpjEmpresa);
            $campos['id'] = (int) $linha['id'];
            $campos['xml_completo'] = $nfseData->nfseXml;
            $stmtAtualizar->execute($campos);
            $total++;
        }

        return ['sucesso' => true, 'mensagem' => "{$total} documento(s) corrigido(s) a partir do Portal Nacional.", 'total' => $total];
    } catch (Throwable $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar o Portal Nacional: ' . trim(strip_tags($e->getMessage())), 'total' => 0];
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
            if ($lote > 0) {
                sleep(3);
            }
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

                // A Distribuição de DFe traz tanto a NFS-e completa (infNFSe) quanto eventos
                // (infEvento, ex.: cancelamento) referenciando uma NFS-e já sincronizada antes.
                // Evento não tem prestador/tomador/valores - só aplica o cancelamento na nota
                // existente e não mexe no restante dos dados dela.
                if (($nfseData->infNfse ?? null) === null) {
                    aplicarEventoNfseAdn($dbNotas, $nfseData);
                    continue;
                }

                $campos = extrairCamposNfseAdn($nfseData, $cnpjEmpresa);
                $campos['empresa_emissora_id'] = (int) $empresa['id'];
                $campos['chave_acesso'] = normalizarChaveAcessoNfse($documento->chaveAcesso) ?? $documento->chaveAcesso;
                $campos['nsu'] = $documento->nsu;
                $campos['xml_completo'] = $xml;

                $stmtUpsert->execute($campos);
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
        // E2220/"NENHUM_DOCUMENTO_LOCALIZADO" não é uma falha: é o próprio ADN avisando que não
        // há documento novo a partir do NSU informado (fim da fila). Trata como sincronização
        // tranquila, sem novidades, em vez de mostrar o JSON de erro cru pro usuário.
        $semDocumentoNovo = str_contains((string) $e->getRawResponse(), 'NENHUM_DOCUMENTO_LOCALIZADO')
            || (bool) array_filter($e->getErrors(), static fn ($erro): bool => ($erro->codigo ?? '') === 'E2220');
        if ($semDocumentoNovo) {
            return ['sucesso' => true, 'mensagem' => 'Nenhum documento novo encontrado no Portal Nacional. A empresa já está em dia.', 'total' => $totalProcessado];
        }

        if ($e->getCode() === 429) {
            // Grava um prazo de bloqueio pra sincronização automática não insistir na mesma trava
            // a cada poucos minutos - mesmo problema encontrado e corrigido no lado da NF-e.
            $dbNotas->prepare('UPDATE empresas_emissoras SET nfse_adn_bloqueado_ate = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :id')
                ->execute(['id' => (int) $empresa['id']]);

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

// Empresas emissoras ativas que já têm CNPJ + certificado A1 válido cadastrado - ou seja, aptas a
// sincronizar com o ADN sem precisar de nenhuma ação manual do usuário.
//
// certificadoEmpresaDisponivel() usa descriptografarSegredo(), que só existe depois que
// config_app_key.php é carregado dentro de integracaoNfseDisponivel(). Por isso essa checagem
// vem sempre primeiro aqui dentro - não dá pra confiar que quem chama essa função já fez isso
// (foi exatamente esse esquecimento, numa das telas, que fazia toda empresa aparecer sem
// certificado válido mesmo com o certificado certo cadastrado).
function empresasComCertificadoValidoAdn(PDO $dbNotas): array
{
    [$integracaoOk] = integracaoNfseDisponivel();
    if (!$integracaoOk) {
        return [];
    }

    $empresas = $dbNotas->query('SELECT * FROM empresas_emissoras WHERE ativo = 1')->fetchAll();

    return array_values(array_filter($empresas, static function (array $empresa): bool {
        [$certificadoOk] = certificadoEmpresaDisponivel($empresa);

        return $certificadoOk;
    }));
}

// Coloca em dia TODAS as empresas com certificado válido, chamando sincronizarNfseAdn() várias
// vezes por empresa (cada chamada já busca até 5 lotes) até não haver mais documento novo, dar
// erro, ou esgotar $maxTentativasPorEmpresa. Usado tanto pela sincronização automática ao abrir
// o buscador (uma empresa por vez) quanto pelo script de cron processar-nfse-adn-automatico.php
// (todas de uma vez, pra funcionar sem ninguém precisar abrir a página).
//
// $maxTentativasPorEmpresa e $tempoLimiteSegundos existem pra dar conta de um catch-up
// retroativo grande (ex.: 3 meses de histórico numa empresa que nunca sincronizou) num único
// cron, sem precisar de dezenas de execuções - mas sem risco de estourar o tempo de execução do
// PHP. Não aumenta o número de chamadas ao Portal Nacional além do necessário: cada empresa já
// para sozinha assim que não há mais documento novo ou dá erro (ex.: rate limit). Cada tentativa
// é espaçada por uma pausa real - o objetivo é avançar de forma sustentável, não processar o
// máximo possível num único cron (foi rajada demais que causou bloqueio de rate limit na NF-e).
function sincronizarTodasEmpresasNfseAdn(PDO $dbNotas, int $maxTentativasPorEmpresa = 10, int $tempoLimiteSegundos = 90): array
{
    $resultados = [];
    $inicio = microtime(true);

    foreach (empresasComCertificadoValidoAdn($dbNotas) as $empresa) {
        if ((microtime(true) - $inicio) > $tempoLimiteSegundos) {
            break; // orçamento de tempo estourado - as empresas restantes ficam pra próxima execução
        }

        if (!empty($empresa['nfse_adn_bloqueado_ate']) && strtotime($empresa['nfse_adn_bloqueado_ate']) > time()) {
            $resultados[] = ['empresa' => $empresa['razao_social'], 'sucesso' => true, 'total' => 0, 'mensagem' => 'Bloqueada pelo Portal Nacional até ' . date('d/m/Y H:i', strtotime($empresa['nfse_adn_bloqueado_ate'])) . '.'];
            continue;
        }

        $totalEmpresa = 0;
        $ultimaMensagem = '';
        $ultimoSucesso = true;

        for ($tentativa = 0; $tentativa < $maxTentativasPorEmpresa; $tentativa++) {
            if ((microtime(true) - $inicio) > $tempoLimiteSegundos) {
                $ultimaMensagem .= ' (parou por orçamento de tempo; continua na próxima execução)';
                break;
            }

            if ($tentativa > 0) {
                sleep(5);
            }

            $resultado = sincronizarNfseAdn($dbNotas, $empresa);
            $ultimaMensagem = $resultado['mensagem'];
            $ultimoSucesso = $resultado['sucesso'];
            $totalEmpresa += $resultado['total'];

            if (!$resultado['sucesso'] || $resultado['total'] === 0) {
                break;
            }

            // Recarrega a empresa pra pegar o NSU já avançado antes da próxima tentativa.
            $stmtRecarregar = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtRecarregar->execute(['id' => (int) $empresa['id']]);
            $empresa = $stmtRecarregar->fetch();
        }

        $resultados[] = [
            'empresa' => $empresa['razao_social'],
            'sucesso' => $ultimoSucesso,
            'total' => $totalEmpresa,
            'mensagem' => $ultimaMensagem,
        ];
    }

    return $resultados;
}
?>
