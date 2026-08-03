<?php
/**
 * Extração "melhor esforço" dos valores de impostos a partir do XML completo
 * guardado nas tabelas de sincronização (notas_fiscais_nfe_dfe / notas_fiscais_nfse_adn).
 * Essas tabelas não têm colunas próprias de imposto: o detalhamento só existe dentro
 * do XML bruto recebido da SEFAZ/Portal Nacional, então a leitura é feita por nome de
 * tag (ignorando o caminho/namespace exato), tolerando tags ausentes.
 */

function impostosXmlValorTag(DOMDocument $doc, string $tag): ?string
{
    $lista = $doc->getElementsByTagName($tag);
    if ($lista->length === 0) {
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
 * Impostos de uma NF-e a partir do bloco <total><ICMSTot> (totais da nota, já
 * consolidados pela própria SEFAZ — não é necessário somar por item).
 *
 * @return array<string, float|null>
 */
function extrairImpostosNfeXml(?string $xml): array
{
    $vazio = [
        'vBC' => null, 'vICMS' => null, 'vICMSST' => null, 'vIPI' => null,
        'vPIS' => null, 'vCOFINS' => null, 'vFrete' => null, 'vDesc' => null,
        'vOutro' => null, 'vNF' => null, 'vTotTrib' => null,
    ];
    $doc = impostosXmlCarregarDocumento($xml);
    if (!$doc) {
        return $vazio;
    }

    $icmsTot = $doc->getElementsByTagName('ICMSTot')->item(0);
    $base = $icmsTot instanceof DOMElement ? $icmsTot : $doc;

    $ler = static function (string $tag) use ($base): ?float {
        $lista = $base->getElementsByTagName($tag);
        if ($lista->length === 0) {
            return null;
        }
        $valor = trim((string) $lista->item(0)->nodeValue);
        return $valor !== '' ? (float) $valor : null;
    };

    return [
        'vBC' => $ler('vBC'),
        'vICMS' => $ler('vICMS'),
        'vICMSST' => $ler('vST'),
        'vIPI' => $ler('vIPI'),
        'vPIS' => $ler('vPIS'),
        'vCOFINS' => $ler('vCOFINS'),
        'vFrete' => $ler('vFrete'),
        'vDesc' => $ler('vDesc'),
        'vOutro' => $ler('vOutro'),
        'vNF' => $ler('vNF'),
        'vTotTrib' => $ler('vTotTrib'),
    ];
}

/**
 * Impostos de uma NFS-e (DPS do Portal Nacional) — leitura por nome de tag,
 * já que a estrutura de <valores>/<trib> pode variar de versão para versão do layout.
 *
 * @return array<string, float|null>
 */
function extrairImpostosNfseXml(?string $xml): array
{
    $vazio = [
        'vServ' => null, 'vBCISSQN' => null, 'vISSQN' => null,
        'vPis' => null, 'vCofins' => null, 'vRetCP' => null,
        'vRetIRRF' => null, 'vRetCSLL' => null, 'vTotTribFed' => null,
        'vTotTribEst' => null, 'vTotTribMun' => null, 'vLiq' => null,
    ];
    $doc = impostosXmlCarregarDocumento($xml);
    if (!$doc) {
        return $vazio;
    }

    $ler = static function (string $tag) use ($doc): ?float {
        $valor = impostosXmlValorTag($doc, $tag);
        return $valor !== null ? (float) $valor : null;
    };

    return [
        'vServ' => $ler('vServPrest') ?? $ler('vServ'),
        'vBCISSQN' => $ler('vBC'),
        'vISSQN' => $ler('vISSQN'),
        'vPis' => $ler('vPis'),
        'vCofins' => $ler('vCofins'),
        'vRetCP' => $ler('vRetCP'),
        'vRetIRRF' => $ler('vRetIRRF'),
        'vRetCSLL' => $ler('vRetCSLL'),
        'vTotTribFed' => $ler('vTotTribFed'),
        'vTotTribEst' => $ler('vTotTribEst'),
        'vTotTribMun' => $ler('vTotTribMun'),
        'vLiq' => $ler('vLiq'),
    ];
}
