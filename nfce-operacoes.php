<?php
// Operações remotas complementares da NFC-e (consulta, cancelamento, DANFCE) — mesmo
// padrão de nfe-operacoes.php, reaproveitando o certificado/ambiente da empresa emissora
// via montarToolsNfce().

require_once __DIR__ . '/nfce-sefaz-integracao.php';

/**
 * Consulta o status atual de uma NFC-e pela chave de acesso.
 * @return array{cStat:string,xMotivo:string,nProt:?string}
 */
function consultarNfceRemota(array $empresa, string $chave): array
{
    $chave = preg_replace('/\D+/', '', $chave) ?? '';
    if (strlen($chave) !== 44) {
        throw new InvalidArgumentException('A chave de acesso da NFC-e é inválida.');
    }

    [$ok, $erro, $tools] = montarToolsNfce($empresa);
    if (!$ok) {
        throw new RuntimeException($erro);
    }

    $resposta = $tools->sefazConsultaChave($chave);
    $std = new SimpleXMLElement($resposta);
    $protNFe = $std->xpath('//*[local-name()="protNFe"]');
    if (empty($protNFe)) {
        return [
            'cStat' => (string) ($std->cStat ?? ''),
            'xMotivo' => (string) ($std->xMotivo ?? 'NFC-e não encontrada na SEFAZ.'),
            'nProt' => null,
        ];
    }

    $infProt = $protNFe[0]->children('http://www.portalfiscal.inf.br/nfe')->infProt ?? $protNFe[0]->infProt;

    return [
        'cStat' => (string) $infProt->cStat,
        'xMotivo' => (string) $infProt->xMotivo,
        'nProt' => (string) $infProt->nProt,
    ];
}

/**
 * Cancela uma NFC-e autorizada (evento 110111), exigindo justificativa de 15 a 255 caracteres.
 * @return array{xml_evento:string}
 */
function cancelarNfceRemota(array $empresa, string $chave, string $protocolo, string $justificativa): array
{
    $chave = preg_replace('/\D+/', '', $chave) ?? '';
    if (strlen($chave) !== 44) {
        throw new InvalidArgumentException('A chave de acesso da NFC-e é inválida.');
    }
    $justificativa = trim($justificativa);
    if (mb_strlen($justificativa) < 15 || mb_strlen($justificativa) > 255) {
        throw new InvalidArgumentException('Informe uma justificativa de cancelamento entre 15 e 255 caracteres.');
    }
    if (trim($protocolo) === '') {
        throw new InvalidArgumentException('Protocolo de autorização não informado; não é possível cancelar.');
    }

    [$ok, $erro, $tools] = montarToolsNfce($empresa);
    if (!$ok) {
        throw new RuntimeException($erro);
    }

    $resposta = $tools->sefazCancela($chave, $justificativa, $protocolo);
    $std = new SimpleXMLElement($resposta);
    $retEvento = $std->xpath('//*[local-name()="retEvento"]');
    $infEvento = !empty($retEvento)
        ? ($retEvento[0]->children('http://www.portalfiscal.inf.br/nfe')->infEvento ?? $retEvento[0]->infEvento)
        : null;
    $cStat = $infEvento !== null ? (string) $infEvento->cStat : '';

    if (!in_array($cStat, ['135', '136', '155'], true)) {
        $motivo = $infEvento !== null ? (string) $infEvento->xMotivo : 'Retorno inesperado da SEFAZ.';
        throw new RuntimeException("Cancelamento não confirmado pela SEFAZ: [$cStat] $motivo");
    }

    return ['xml_evento' => $resposta];
}

/**
 * Gera o DANFCE (PDF) localmente a partir do XML autorizado (ou assinado em contingência),
 * via nfephp-org/sped-da. Sempre local — nunca depende de nenhum serviço externo, mesmo em
 * contingência EPEC (é o que permite imprimir a venda na hora, sem esperar a SEFAZ).
 */
function gerarDanfcePdf(string $xmlNfce): string
{
    if (!class_exists(\NFePHP\DA\NFe\Danfce::class)) {
        throw new RuntimeException('Gerador local de DANFCE não instalado no vendor/.');
    }
    if (trim($xmlNfce) === '') {
        throw new RuntimeException('XML da NFC-e não disponível para gerar o DANFCE.');
    }

    $danfce = new \NFePHP\DA\NFe\Danfce($xmlNfce);
    $pdf = $danfce->render();
    if (!str_starts_with((string) $pdf, '%PDF-')) {
        throw new RuntimeException('Não foi possível gerar o DANFCE a partir do XML.');
    }

    return $pdf;
}
