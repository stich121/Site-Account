<?php
/**
 * Extração "melhor esforço" dos valores de impostos a partir do XML completo
 * guardado nas tabelas de sincronização (notas_fiscais_nfe_dfe / notas_fiscais_nfse_adn).
 * Essas tabelas não têm colunas próprias de imposto: o detalhamento só existe dentro
 * do XML bruto recebido da SEFAZ/Portal Nacional, então a leitura é feita por nome de
 * tag (ignorando o caminho/namespace exato), tolerando tags ausentes.
 *
 * Nomes de tag conferidos contra XML real: NF-e autorizada (bloco <total><ICMSTot>/
 * <IBSCBSTot>) e NFS-e Nacional (DPS 1.01, <valores>/<trib>). A NFS-e Nacional NÃO tem
 * campo de valor monetário para PIS/COFINS retidos (só o CST em <piscofins>); os únicos
 * valores de retenção federal na DPS são IRRF, CSLL e a contribuição previdenciária (CP).
 */

function impostosXmlLerNumero(DOMNode $base, string $tag): ?float
{
    $lista = $base instanceof DOMDocument ? $base->getElementsByTagName($tag) : ($base instanceof DOMElement ? $base->getElementsByTagName($tag) : null);
    if ($lista === null || $lista->length === 0) {
        return null;
    }
    $valor = trim((string) $lista->item(0)->nodeValue);
    return $valor !== '' ? (float) $valor : null;
}

function impostosXmlLerTexto(DOMNode $base, string $tag): ?string
{
    $lista = $base instanceof DOMDocument ? $base->getElementsByTagName($tag) : ($base instanceof DOMElement ? $base->getElementsByTagName($tag) : null);
    if ($lista === null || $lista->length === 0) {
        return null;
    }
    $valor = trim((string) $lista->item(0)->nodeValue);
    return $valor !== '' ? $valor : null;
}

function impostosXmlCarregarDocumento(?string $xml): ?DOMDocument
{
    $xml = trim((string) $xml);
    if ($xml === '') {
        return null;
    }
    $doc = new DOMDocument();
    $anterior = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    return $ok ? $doc : null;
}

/**
 * Impostos de uma NF-e a partir dos blocos <total><ICMSTot> e <total><IBSCBSTot>
 * (totais da nota, já consolidados pela própria SEFAZ — não somamos por item).
 * O bloco IBSCBSTot só existe em notas emitidas já sob a Reforma Tributária (LC 214/2025).
 *
 * @return array<string, float|null>
 */
function extrairImpostosNfeXml(?string $xml): array
{
    $vazio = [
        'vBC' => null, 'vICMS' => null, 'vICMSST' => null, 'vIPI' => null,
        'vPIS' => null, 'vCOFINS' => null, 'vFrete' => null, 'vDesc' => null,
        'vOutro' => null, 'vNF' => null, 'vTotTrib' => null,
        'vBCIBSCBS' => null, 'vIBS' => null, 'vCBS' => null,
    ];
    $doc = impostosXmlCarregarDocumento($xml);
    if (!$doc) {
        return $vazio;
    }

    $icmsTot = $doc->getElementsByTagName('ICMSTot')->item(0);
    $baseIcms = $icmsTot instanceof DOMElement ? $icmsTot : $doc;

    // vIBS/vCBS existem tanto por item (dentro de <det><imposto><IBSCBS>) quanto no total
    // (<IBSCBSTot>), com o MESMO nome de tag — por isso a leitura precisa ficar restrita ao
    // nó IBSCBSTot, senão pegaria por engano o valor do primeiro item em vez do total da nota.
    $ibsTot = $doc->getElementsByTagName('IBSCBSTot')->item(0);
    $baseIbs = $ibsTot instanceof DOMElement ? $ibsTot : null;

    return [
        'vBC' => impostosXmlLerNumero($baseIcms, 'vBC'),
        'vICMS' => impostosXmlLerNumero($baseIcms, 'vICMS'),
        'vICMSST' => impostosXmlLerNumero($baseIcms, 'vST'),
        'vIPI' => impostosXmlLerNumero($baseIcms, 'vIPI'),
        'vPIS' => impostosXmlLerNumero($baseIcms, 'vPIS'),
        'vCOFINS' => impostosXmlLerNumero($baseIcms, 'vCOFINS'),
        'vFrete' => impostosXmlLerNumero($baseIcms, 'vFrete'),
        'vDesc' => impostosXmlLerNumero($baseIcms, 'vDesc'),
        'vOutro' => impostosXmlLerNumero($baseIcms, 'vOutro'),
        'vNF' => impostosXmlLerNumero($baseIcms, 'vNF'),
        'vTotTrib' => impostosXmlLerNumero($baseIcms, 'vTotTrib'),
        'vBCIBSCBS' => $baseIbs ? impostosXmlLerNumero($baseIbs, 'vBCIBSCBS') : null,
        'vIBS' => $baseIbs ? impostosXmlLerNumero($baseIbs, 'vIBS') : null,
        'vCBS' => $baseIbs ? impostosXmlLerNumero($baseIbs, 'vCBS') : null,
    ];
}

/**
 * Impostos de uma NFS-e (DPS Nacional, layout 1.01) — leitura por nome de tag.
 * <valores><vServPrest><vServ> é o valor do serviço; ISSQN (base/alíquota/valor) fica em
 * <trib><tribMun>, mas só é preenchido quando a operação é efetivamente tributada (isenção,
 * imunidade ou Simples Nacional costumam deixar esses campos ausentes). As retenções
 * federais (<trib><tribFed>) só existem como valor monetário para IRRF/CSLL/CP — PIS/COFINS
 * aparecem apenas como código de situação tributária (<piscofins><CST>), sem valor. Os
 * tributos aproximados (Lei da Transparência) tanto podem vir como valor (<vTotTrib>) quanto
 * como percentual detalhado (<pTotTrib>) ou, no caso do Simples Nacional, como um percentual
 * único (<pTotTribSN>).
 *
 * @return array{vServ: float|null, vBCISSQN: float|null, vISSQN: float|null,
 *     cstPisCofins: string|null, vRetCP: float|null, vRetIRRF: float|null,
 *     vRetCSLL: float|null, vTotTribFed: float|null, vTotTribEst: float|null,
 *     vTotTribMun: float|null, pTotTribSN: float|null}
 */
function extrairImpostosNfseXml(?string $xml): array
{
    $vazio = [
        'vServ' => null, 'vBCISSQN' => null, 'vISSQN' => null, 'cstPisCofins' => null,
        'vRetCP' => null, 'vRetIRRF' => null, 'vRetCSLL' => null,
        'vTotTribFed' => null, 'vTotTribEst' => null, 'vTotTribMun' => null, 'pTotTribSN' => null,
    ];
    $doc = impostosXmlCarregarDocumento($xml);
    if (!$doc) {
        return $vazio;
    }

    $tribMun = $doc->getElementsByTagName('tribMun')->item(0);
    $baseIssqn = $tribMun instanceof DOMElement ? $tribMun : $doc;

    $piscofins = $doc->getElementsByTagName('piscofins')->item(0);
    $baseCst = $piscofins instanceof DOMElement ? $piscofins : $doc;

    return [
        'vServ' => impostosXmlLerNumero($doc, 'vServ'),
        'vBCISSQN' => impostosXmlLerNumero($baseIssqn, 'vBC'),
        'vISSQN' => impostosXmlLerNumero($baseIssqn, 'vISSQN'),
        'cstPisCofins' => impostosXmlLerTexto($baseCst, 'CST'),
        'vRetCP' => impostosXmlLerNumero($doc, 'vRetCP'),
        'vRetIRRF' => impostosXmlLerNumero($doc, 'vRetIRRF'),
        'vRetCSLL' => impostosXmlLerNumero($doc, 'vRetCSLL'),
        'vTotTribFed' => impostosXmlLerNumero($doc, 'vTotTribFed'),
        'vTotTribEst' => impostosXmlLerNumero($doc, 'vTotTribEst'),
        'vTotTribMun' => impostosXmlLerNumero($doc, 'vTotTribMun'),
        'pTotTribSN' => impostosXmlLerNumero($doc, 'pTotTribSN'),
    ];
}
