<?php
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
?>
