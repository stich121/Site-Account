<?php
// Integração com o webservice estadual da SEFAZ para NF-e (modelo 55), via
// nfephp-org/sped-nfe. Diferente da NFS-e (SEFIN Nacional/ADN, um único webservice
// nacional), aqui cada UF tem seu proprio ponto de entrada - a biblioteca resolve isso
// sozinha a partir da UF da empresa emissora.
//
// Este arquivo não gera HTML — só funções, para ser usado por notas-fiscais.php e
// processar-fila-nfe.php. Reaproveita certificadoEmpresaDisponivel() de
// nfse-nacional-integracao.php (já genérica sobre a empresa) e
// criptografarSegredo/descriptografarSegredo de config_app_key.php.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config_app_key.php';
require_once __DIR__ . '/nfse-nacional-integracao.php';
require_once __DIR__ . '/nfe-xml-fiscal.php';

function integracaoNfeDisponivel(): array
{
    if (!class_exists(\NFePHP\NFe\Tools::class)) {
        return [false, 'Biblioteca nfephp-org/sped-nfe não encontrada no vendor/ (confira o composer.json).'];
    }
    if (!extension_loaded('soap')) {
        return [false, 'A extensão PHP "soap" não está habilitada neste servidor - necessária para falar com a SEFAZ.'];
    }
    if (!extension_loaded('curl')) {
        return [false, 'A extensão PHP "curl" não está habilitada neste servidor.'];
    }

    return [true, ''];
}

/**
 * @return array{0: bool, 1: string, 2: ?\NFePHP\NFe\Tools}
 */
function montarToolsNfe(array $empresa): array
{
    [$disponivel, $erroDisponivel] = integracaoNfeDisponivel();
    if (!$disponivel) {
        return [false, $erroDisponivel, null];
    }
    [$certOk, $erroCert] = certificadoEmpresaDisponivel($empresa);
    if (!$certOk) {
        return [false, $erroCert, null];
    }

    try {
        $caminhoPfx = __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']);
        $conteudoPfx = file_get_contents($caminhoPfx);
        $senha = descriptografarSegredo((string) $empresa['certificado_senha_cifrada']);
        $certificate = \NFePHP\Common\Certificate::readPfx($conteudoPfx, $senha);

        $config = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 1 : 2,
            'razaosocial' => (string) $empresa['razao_social'],
            'cnpj' => preg_replace('/\D+/', '', (string) $empresa['cnpj']),
            'siglaUF' => strtoupper((string) $empresa['uf']),
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
            'tokenIBPT' => '',
            'CSC' => '',
            'CSCid' => '',
        ]);

        $tools = new \NFePHP\NFe\Tools((string) $config, $certificate);
        $tools->model(55);

        return [true, '', $tools];
    } catch (Throwable $e) {
        return [false, 'Falha ao preparar comunicação com a SEFAZ: ' . $e->getMessage(), null];
    }
}

/**
 * Assina e transmite uma NF-e à SEFAZ, retornando um resultado normalizado -
 * mesmo formato de retorno de enviarNfseNacional(), para o restante do código
 * (fila, ações da listagem) tratar os dois tipos de nota de forma igual.
 *
 * @return array{sucesso:bool,status:string,motivo_rejeicao:?string,chave_acesso:?string,protocolo_autorizacao:?string,xml_gerado:?string}
 */
function nfeAssinarEEnviar(string $xmlNaoAssinado, array $empresa): array
{
    [$ok, $erro, $tools] = montarToolsNfe($empresa);
    if (!$ok) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $erro,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }

    try {
        $xmlAssinado = $tools->signNFe($xmlNaoAssinado);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlAssinado);
        $chave = str_replace('NFe', '', $dom->getElementsByTagName('infNFe')->item(0)->getAttribute('Id'));

        $idLote = (string) time();
        $respostaEnvio = $tools->sefazEnviaLote([$xmlAssinado], $idLote, 1);

        $stdEnvio = new SimpleXMLElement($respostaEnvio);
        $stdEnvio->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $protNFe = $stdEnvio->xpath('//n:protNFe') ?: $stdEnvio->xpath('//protNFe');

        if (!empty($protNFe)) {
            $infProt = $protNFe[0]->infProt ?? $protNFe[0]->children('http://www.portalfiscal.inf.br/nfe')->infProt;
            $cStat = (string) $infProt->cStat;
            $xMotivo = (string) $infProt->xMotivo;
            $nProt = (string) $infProt->nProt;

            if ($cStat === '100') {
                $xmlProtocolado = \NFePHP\NFe\Complements::toAuthorize($xmlAssinado, $respostaEnvio);

                return [
                    'sucesso' => true,
                    'status' => 'autorizada',
                    'motivo_rejeicao' => null,
                    'chave_acesso' => $chave,
                    'protocolo_autorizacao' => $nProt,
                    'xml_gerado' => $xmlProtocolado,
                ];
            }

            return [
                'sucesso' => false,
                'status' => 'rejeitada',
                'motivo_rejeicao' => "[$cStat] $xMotivo",
                'chave_acesso' => $chave,
                'protocolo_autorizacao' => null,
                'xml_gerado' => $xmlAssinado,
            ];
        }

        $cStatLote = (string) ($stdEnvio->cStat ?? '');
        $xMotivoLote = (string) ($stdEnvio->xMotivo ?? '');
        $reciboLote = (string) ($stdEnvio->infRec->nRec ?? '');

        if ($reciboLote !== '') {
            return nfeConsultarRecibo($reciboLote, $empresa, $chave, $xmlAssinado);
        }

        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => "[$cStatLote] $xMotivoLote",
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => 'Falha ao transmitir a NF-e: ' . $e->getMessage(),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }
}

/**
 * Consulta um recibo de lote (envio assíncrono) e normaliza o resultado no mesmo
 * formato de nfeAssinarEEnviar().
 */
function nfeConsultarRecibo(string $recibo, array $empresa, string $chave, string $xmlAssinado): array
{
    [$ok, $erro, $tools] = montarToolsNfe($empresa);
    if (!$ok) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $erro,
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
        ];
    }

    try {
        $resposta = $tools->sefazConsultaRecibo($recibo);
        $std = new SimpleXMLElement($resposta);
        $protNFe = $std->xpath('//*[local-name()="protNFe"]');

        if (empty($protNFe)) {
            return [
                'sucesso' => false,
                'status' => 'pendente_envio',
                'motivo_rejeicao' => 'Lote ainda em processamento na SEFAZ, tente novamente em instantes.',
                'chave_acesso' => $chave,
                'protocolo_autorizacao' => null,
                'xml_gerado' => $xmlAssinado,
            ];
        }

        $infProt = $protNFe[0]->children('http://www.portalfiscal.inf.br/nfe')->infProt ?? $protNFe[0]->infProt;
        $cStat = (string) $infProt->cStat;
        $xMotivo = (string) $infProt->xMotivo;
        $nProt = (string) $infProt->nProt;

        if ($cStat === '100') {
            $xmlProtocolado = \NFePHP\NFe\Complements::toAuthorize($xmlAssinado, $resposta);

            return [
                'sucesso' => true,
                'status' => 'autorizada',
                'motivo_rejeicao' => null,
                'chave_acesso' => $chave,
                'protocolo_autorizacao' => $nProt,
                'xml_gerado' => $xmlProtocolado,
            ];
        }

        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => "[$cStat] $xMotivo",
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => 'Falha ao consultar recibo na SEFAZ: ' . $e->getMessage(),
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
        ];
    }
}
