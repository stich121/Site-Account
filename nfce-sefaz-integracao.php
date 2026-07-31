<?php
// Integração com o webservice estadual da SEFAZ para NFC-e (modelo 65), via
// nfephp-org/sped-nfe — mesma biblioteca/classe NFePHP\NFe\Tools da NF-e
// (nfe-sefaz-integracao.php, não alterado), diferindo em: CSC/CSCid reais (a lib gera o
// QR Code automaticamente ao assinar quando eles estão configurados — ver
// vendor/nfephp-org/sped-nfe/src/Common/Tools.php::addQRCode()), $tools->model(65), envio
// SEMPRE síncrono (sem fila) e o fallback de contingência EPEC (TraitEPECNfce).
//
// Este arquivo não gera HTML — só funções, para ser usado por includes/notas-nfce-motor.php,
// notas-nfce-vendas.php e processar-fila-nfce-epec.php.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config_app_key.php';
require_once __DIR__ . '/nfse-nacional-integracao.php';
require_once __DIR__ . '/nfce-xml-fiscal.php';

function integracaoNfceDisponivel(): array
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
function montarToolsNfce(array $empresa): array
{
    [$disponivel, $erroDisponivel] = integracaoNfceDisponivel();
    if (!$disponivel) {
        return [false, $erroDisponivel, null];
    }
    [$certOk, $erroCert] = certificadoEmpresaDisponivel($empresa);
    if (!$certOk) {
        return [false, $erroCert, null];
    }
    if (empty($empresa['nfce_csc_id']) || empty($empresa['nfce_csc_cifrado'])) {
        return [false, 'Empresa sem CSC/CSCid de NFC-e cadastrado. Configure em "Configuração NFC-e".', null];
    }

    try {
        $caminhoPfx = __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']);
        $conteudoPfx = file_get_contents($caminhoPfx);
        $senha = descriptografarSegredo((string) $empresa['certificado_senha_cifrada']);
        $certificate = \NFePHP\Common\Certificate::readPfx($conteudoPfx, $senha);
        $csc = descriptografarSegredo((string) $empresa['nfce_csc_cifrado']);

        $config = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 1 : 2,
            'razaosocial' => (string) $empresa['razao_social'],
            'cnpj' => preg_replace('/\D+/', '', (string) $empresa['cnpj']),
            'siglaUF' => strtoupper((string) $empresa['uf']),
            // Precisa ficar em sincronia com o hint passado a \NFePHP\NFe\Make() em
            // nfceMontarXml() (nfce-xml-fiscal.php).
            'schemes' => 'PL_010_V1.30',
            'versao' => '4.00',
            'tokenIBPT' => '',
            'CSC' => $csc,
            'CSCid' => (string) $empresa['nfce_csc_id'],
        ]);

        $tools = new \NFePHP\NFe\Tools((string) $config, $certificate);
        $tools->model(65);

        return [true, '', $tools];
    } catch (Throwable $e) {
        return [false, 'Falha ao preparar comunicação com a SEFAZ: ' . $e->getMessage(), null];
    }
}

/**
 * Extrai a URL do QR Code (elemento <qrCode>) de um XML de NFC-e já assinado — a própria
 * lib embute esse elemento em infNFeSupl ao assinar (Tools::signNFe() -> addQRCode()),
 * desde que CSC/CSCid estejam configurados em montarToolsNfce().
 */
function nfceExtrairQrCode(string $xmlAssinado): ?string
{
    try {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlAssinado);
        $qrCode = $dom->getElementsByTagName('qrCode')->item(0);
        if ($qrCode === null) {
            return null;
        }
        $valor = trim((string) $qrCode->nodeValue);

        return $valor !== '' ? $valor : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Assina e transmite uma NFC-e à SEFAZ de forma SÍNCRONA (indSinc=1, sem fila — diferente
 * da NF-e, que pode cair para consulta de recibo em lote assíncrono). Retorno normalizado
 * no mesmo formato de nfeAssinarEEnviar() (nfe-sefaz-integracao.php), com as chaves extras
 * 'modo_emissao'/'epec_status'/'epec_protocolo'/'qrcode_url' usadas por
 * includes/notas-nfce-motor.php.
 *
 * @return array{sucesso:bool,status:string,motivo_rejeicao:?string,chave_acesso:?string,protocolo_autorizacao:?string,xml_gerado:?string,modo_emissao:string,epec_status:string,epec_protocolo:?string,qrcode_url:?string}
 */
function nfceAssinarEEnviar(string $xmlNaoAssinado, array $empresa): array
{
    [$ok, $erro, $tools] = montarToolsNfce($empresa);
    if (!$ok) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $erro,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
            'modo_emissao' => 'normal',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => null,
        ];
    }

    try {
        $xmlAssinado = $tools->signNFe($xmlNaoAssinado);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlAssinado);
        $chave = str_replace('NFe', '', $dom->getElementsByTagName('infNFe')->item(0)->getAttribute('Id'));
        $qrcodeUrl = nfceExtrairQrCode($xmlAssinado);

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
                    'modo_emissao' => 'normal',
                    'epec_status' => 'nenhum',
                    'epec_protocolo' => null,
                    'qrcode_url' => $qrcodeUrl,
                ];
            }

            return [
                'sucesso' => false,
                'status' => 'rejeitada',
                'motivo_rejeicao' => "[$cStat] $xMotivo",
                'chave_acesso' => $chave,
                'protocolo_autorizacao' => null,
                'xml_gerado' => $xmlAssinado,
                'modo_emissao' => 'normal',
                'epec_status' => 'nenhum',
                'epec_protocolo' => null,
                'qrcode_url' => $qrcodeUrl,
            ];
        }

        $cStatLote = (string) ($stdEnvio->cStat ?? '');
        $xMotivoLote = (string) ($stdEnvio->xMotivo ?? '');

        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => "[$cStatLote] $xMotivoLote",
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
            'modo_emissao' => 'normal',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => $qrcodeUrl,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => 'Falha ao transmitir a NFC-e: ' . $e->getMessage(),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
            'modo_emissao' => 'normal',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => null,
        ];
    }
}

/**
 * Fallback quando a SEFAZ está indisponível/timeout: gera a NFC-e em contingência EPEC
 * (tpEmis=4) - a venda é registrada localmente e o DANFCE de contingência pode ser impresso
 * na hora, sem bloquear o operador. O evento EPEC (TraitEPECNfce::sefazEpecNfce()) é
 * transmitido em paralelo, tentativamente; se também falhar (indisponibilidade total), a
 * nota fica marcada como 'pendente_transmissao' para processar-fila-nfce-epec.php tentar de
 * novo mais tarde via nfceVincularEpecPendentes().
 *
 * Sempre retorna sucesso=true (a venda vale localmente e o DANFCE já pode ser impresso) —
 * só o campo epec_status muda conforme o evento foi ou não aceito pela SEFAZ agora.
 */
function nfceEmitirEmContingenciaEpec(array $nota, array $empresa, array $consumidor, array $nfceExtra, array $itens, array $pagamentos): array
{
    $dhContingencia = (new DateTime())->format('Y-m-d\TH:i:sP');
    $justificativa = 'Indisponibilidade do serviço da SEFAZ no momento da venda.';

    $montagem = nfceMontarXml($nota, $empresa, $consumidor, $nfceExtra, $itens, $pagamentos, true, $justificativa, $dhContingencia);
    if (!empty($montagem['erros'])) {
        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => 'XML de contingência não gerado: ' . implode(' ', array_map('strval', $montagem['erros'])),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
            'modo_emissao' => 'contingencia_epec',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => null,
        ];
    }

    [$ok, $erro, $tools] = montarToolsNfce($empresa);
    if (!$ok) {
        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => 'Não foi possível assinar a NFC-e em contingência: ' . $erro,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
            'modo_emissao' => 'contingencia_epec',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => null,
        ];
    }

    try {
        $xmlAssinado = $tools->signNFe($montagem['xml'], 0); // validXSD=0: schema local ainda não conhece dhCont/xJust como obrigatórios em contingência.
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlAssinado);
        $chave = str_replace('NFe', '', $dom->getElementsByTagName('infNFe')->item(0)->getAttribute('Id'));
        $qrcodeUrl = nfceExtrairQrCode($xmlAssinado);

        $epecStatus = 'pendente_transmissao';
        $epecProtocolo = null;
        try {
            $xmlParaEpec = $xmlAssinado;
            $respostaEpec = $tools->sefazEpecNfce($xmlParaEpec);
            $stdEpec = new SimpleXMLElement($respostaEpec);
            $cStatEpec = (string) ($stdEpec->cStat ?? '');
            if (in_array($cStatEpec, ['128', '135', '136'], true)) {
                $epecStatus = 'transmitido';
                $epecProtocolo = (string) ($stdEpec->infEvento->nProt ?? $stdEpec->xpath('//nProt')[0] ?? '');
            }
        } catch (Throwable $eEpec) {
            // Sem conectividade nem para o evento EPEC: a venda continua valendo localmente,
            // fica pendente para processar-fila-nfce-epec.php tentar de novo.
            $epecStatus = 'pendente_transmissao';
        }

        return [
            'sucesso' => true,
            'status' => 'autorizada',
            'motivo_rejeicao' => null,
            'chave_acesso' => $chave,
            'protocolo_autorizacao' => null,
            'xml_gerado' => $xmlAssinado,
            'modo_emissao' => 'contingencia_epec',
            'epec_status' => $epecStatus,
            'epec_protocolo' => $epecProtocolo,
            'qrcode_url' => $qrcodeUrl,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => 'Falha ao assinar a NFC-e em contingência: ' . $e->getMessage(),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
            'modo_emissao' => 'contingencia_epec',
            'epec_status' => 'nenhum',
            'epec_protocolo' => null,
            'qrcode_url' => null,
        ];
    }
}

/**
 * Reconciliação da contingência: varre notas_fiscais_nfce.epec_status='pendente_transmissao'
 * e tenta (1) transmitir o evento EPEC ainda pendente e (2) enviar a NFC-e definitiva à
 * SEFAZ para obter o protocolo de autorização normal, vinculando-a ao EPEC. É o único ponto
 * assíncrono do módulo (chamado por processar-fila-nfce-epec.php) — a venda em si nunca
 * passa por fila.
 *
 * @return array<int, array{nota_id:int,numero_interno:int,situacao:string,detalhe:string}>
 */
function nfceVincularEpecPendentes(PDO $dbNotas): array
{
    $resultados = [];

    $stmt = $dbNotas->query(
        "SELECT n.id AS nota_id, n.numero_interno, n.empresa_emissora_id, n.xml_gerado
         FROM notas_fiscais n
         INNER JOIN notas_fiscais_nfce nf ON nf.nota_id = n.id
         WHERE n.tipo_nota = 'nfce' AND nf.epec_status = 'pendente_transmissao'
         ORDER BY n.criado_em ASC"
    );
    $pendentes = $stmt->fetchAll();

    foreach ($pendentes as $pendente) {
        $notaId = (int) $pendente['nota_id'];
        $xmlAssinado = (string) ($pendente['xml_gerado'] ?? '');
        if ($xmlAssinado === '') {
            $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'ignorada', 'detalhe' => 'Nota sem XML assinado localmente.'];
            continue;
        }

        $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
        $stmtEmpresa->execute(['id' => $pendente['empresa_emissora_id']]);
        $empresa = $stmtEmpresa->fetch();
        if (!$empresa) {
            $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'ignorada', 'detalhe' => 'Empresa emissora não encontrada.'];
            continue;
        }

        [$ok, $erro, $tools] = montarToolsNfce($empresa);
        if (!$ok) {
            $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'adiada', 'detalhe' => $erro];
            continue;
        }

        try {
            $epecStatus = 'pendente_transmissao';
            $epecProtocolo = null;
            try {
                $xmlParaEpec = $xmlAssinado;
                $respostaEpec = $tools->sefazEpecNfce($xmlParaEpec);
                $stdEpec = new SimpleXMLElement($respostaEpec);
                $cStatEpec = (string) ($stdEpec->cStat ?? '');
                if (in_array($cStatEpec, ['128', '135', '136'], true)) {
                    $epecStatus = 'transmitido';
                    $epecProtocolo = (string) ($stdEpec->infEvento->nProt ?? '');
                    $dbNotas->prepare(
                        'UPDATE notas_fiscais_nfce SET epec_status = :epec_status, epec_protocolo = :epec_protocolo, epec_enviado_em = NOW() WHERE nota_id = :nota_id'
                    )->execute(['epec_status' => $epecStatus, 'epec_protocolo' => $epecProtocolo, 'nota_id' => $notaId]);
                }
            } catch (Throwable $eEpec) {
                $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'adiada', 'detalhe' => 'EPEC ainda indisponível: ' . $eEpec->getMessage()];
                continue;
            }

            // Com o evento EPEC aceito, tenta agora transmitir a NFC-e definitiva para obter
            // o protocolo de autorização normal e "vincular" a venda.
            $idLote = (string) time();
            $respostaEnvio = $tools->sefazEnviaLote([$xmlAssinado], $idLote, 1);
            $stdEnvio = new SimpleXMLElement($respostaEnvio);
            $stdEnvio->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $protNFe = $stdEnvio->xpath('//n:protNFe') ?: $stdEnvio->xpath('//protNFe');

            if (!empty($protNFe)) {
                $infProt = $protNFe[0]->infProt ?? $protNFe[0]->children('http://www.portalfiscal.inf.br/nfe')->infProt;
                $cStat = (string) $infProt->cStat;
                $nProt = (string) $infProt->nProt;
                if ($cStat === '100') {
                    $xmlProtocolado = \NFePHP\NFe\Complements::toAuthorize($xmlAssinado, $respostaEnvio);
                    $dbNotas->prepare(
                        'UPDATE notas_fiscais SET protocolo_autorizacao = :protocolo, xml_gerado = :xml WHERE id = :id'
                    )->execute(['protocolo' => $nProt, 'xml' => $xmlProtocolado, 'id' => $notaId]);
                    $dbNotas->prepare(
                        "UPDATE notas_fiscais_nfce SET epec_status = 'vinculado', epec_vinculado_em = NOW() WHERE nota_id = :nota_id"
                    )->execute(['nota_id' => $notaId]);
                    $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'vinculada', 'detalhe' => 'Protocolo ' . $nProt];
                    continue;
                }
            }

            $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'epec_transmitido', 'detalhe' => 'Evento EPEC aceito; NFC-e definitiva ainda não autorizada — tentará de novo.'];
        } catch (Throwable $e) {
            $resultados[] = ['nota_id' => $notaId, 'numero_interno' => (int) $pendente['numero_interno'], 'situacao' => 'adiada', 'detalhe' => $e->getMessage()];
        }
    }

    return $resultados;
}
