<?php
// Operações remotas complementares da NF-e (consulta, cancelamento, DANFE).
// Mantidas fora da montagem/assinatura do XML para que consulta, cancelamento e DANFE
// sempre reutilizem o mesmo certificado e ambiente da empresa emissora.

require_once __DIR__ . '/nfe-sefaz-integracao.php';

/**
 * Consulta o status atual de uma NF-e pela chave de acesso.
 * @return array{cStat:string,xMotivo:string,nProt:?string}
 */
function consultarNfeRemota(array $empresa, string $chave): array
{
    $chave = preg_replace('/\D+/', '', $chave) ?? '';
    if (strlen($chave) !== 44) {
        throw new InvalidArgumentException('A chave de acesso da NF-e é inválida.');
    }

    [$ok, $erro, $tools] = montarToolsNfe($empresa);
    if (!$ok) {
        throw new RuntimeException($erro);
    }

    $resposta = $tools->sefazConsultaChave($chave);
    $std = new SimpleXMLElement($resposta);
    $protNFe = $std->xpath('//*[local-name()="protNFe"]');
    if (empty($protNFe)) {
        return [
            'cStat' => (string) ($std->cStat ?? ''),
            'xMotivo' => (string) ($std->xMotivo ?? 'NF-e não encontrada na SEFAZ.'),
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
 * Cancela uma NF-e autorizada (evento 110111), exigindo justificativa de 15 a 255 caracteres.
 * @return array{xml_evento:string}
 */
function cancelarNfeRemota(array $empresa, string $chave, string $protocolo, string $justificativa): array
{
    $chave = preg_replace('/\D+/', '', $chave) ?? '';
    if (strlen($chave) !== 44) {
        throw new InvalidArgumentException('A chave de acesso da NF-e é inválida.');
    }
    $justificativa = trim($justificativa);
    if (mb_strlen($justificativa) < 15 || mb_strlen($justificativa) > 255) {
        throw new InvalidArgumentException('Informe uma justificativa de cancelamento entre 15 e 255 caracteres.');
    }
    if (trim($protocolo) === '') {
        throw new InvalidArgumentException('Protocolo de autorização não informado; não é possível cancelar.');
    }

    [$ok, $erro, $tools] = montarToolsNfe($empresa);
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
 * Gera o DANFE (PDF) localmente a partir do XML autorizado (nfeProc), via nfephp-org/sped-da.
 * Diferente da DANFSe (que dependia de um endpoint remoto do ADN), o DANFE da NF-e sempre é
 * gerado localmente a partir do XML que já temos armazenado - não depende de nenhum serviço externo.
 */
function gerarDanfePdf(string $xmlAutorizado): string
{
    if (!class_exists(\NFePHP\DA\NFe\Danfe::class)) {
        throw new RuntimeException('Gerador local de DANFE não instalado no vendor/.');
    }
    if (trim($xmlAutorizado) === '') {
        throw new RuntimeException('XML autorizado da NF-e não disponível para gerar o DANFE.');
    }

    $danfe = new \NFePHP\DA\NFe\Danfe($xmlAutorizado);
    $pdf = $danfe->render();
    if (!str_starts_with((string) $pdf, '%PDF-')) {
        throw new RuntimeException('Não foi possível gerar o DANFE a partir do XML autorizado.');
    }

    return $pdf;
}
