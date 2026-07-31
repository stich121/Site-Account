<?php
/**
 * Monta o XML da NFC-e (modelo 65) a partir dos dados já persistidos em notas_fiscais /
 * notas_fiscais_nfce / notas_fiscais_itens / notas_fiscais_nfce_pagamentos, usando
 * NFePHP\NFe\Make (nfephp-org/sped-nfe) — mesma biblioteca da NF-e (nfe-xml-fiscal.php),
 * só que com mod=65 e um layout mais enxuto (sem transportador/volumes/nota referenciada,
 * tagdest condicional, tagdetPag repetido por forma de pagamento para suportar split).
 *
 * Os valores de imposto usados aqui são sempre os já calculados e gravados por
 * includes/nfe-impostos.php (reaproveitado tal qual, é agnóstico de tipo_nota) — este
 * arquivo só espelha o que está no banco para dentro do XML, nunca recalcula nada.
 */

require_once __DIR__ . '/vendor/autoload.php';

function nfceSomenteNumeros(?string $valor): string
{
    return preg_replace('/\D+/', '', (string) $valor) ?? '';
}

/**
 * @param array $nota linha de notas_fiscais
 * @param array $empresa linha de empresas_emissoras
 * @param array $consumidor linha de notas_clientes (placeholder "CONSUMIDOR NÃO IDENTIFICADO"
 *   ou o próprio placeholder mesmo quando há CPF avulso — o CPF do balcão vem de $nfceExtra)
 * @param array $nfceExtra linha de notas_fiscais_nfce
 * @param array $itens linhas de notas_fiscais_itens (já com os campos de imposto calculados)
 * @param array $pagamentos linhas de notas_fiscais_nfce_pagamentos (uma ou mais formas)
 * @param bool $contingencia true quando é uma emissão em contingência EPEC (tpEmis=4,
 *   inclui dhCont/xJust em <ide>)
 * @param string|null $justificativaContingencia texto de dhCont/xJust obrigatório quando $contingencia=true
 * @return array{xml:string,chave:string,erros:array}
 */
function nfceMontarXml(
    array $nota,
    array $empresa,
    array $consumidor,
    array $nfceExtra,
    array $itens,
    array $pagamentos,
    bool $contingencia = false,
    ?string $justificativaContingencia = null,
    ?string $dhContingencia = null
): array {
    // Mesmo schema local aditivo (Reforma Tributária/IBS-CBS) usado na NF-e — precisa ficar
    // em sincronia com o 'schemes' configurado em nfce-sefaz-integracao.php::montarToolsNfce().
    $make = new \NFePHP\NFe\Make('PL_010_V1.30');

    $ufEmpresa = strtoupper((string) $empresa['uf']);
    $cnpjEmpresa = nfceSomenteNumeros($empresa['cnpj'] ?? '');

    $std = new stdClass();
    $std->Id = null;
    $std->versao = '4.00';
    $make->taginfNFe($std);

    $std = new stdClass();
    $std->cUF = (int) \NFePHP\Common\UFList::getCodeByUF($ufEmpresa);
    $std->natOp = (string) ($nota['natureza_operacao'] ?: 'Venda ao consumidor');
    $std->mod = 65;
    $std->serie = (int) $nota['serie'];
    $std->nNF = (int) $nota['numero_interno'];
    $std->dhEmi = (new DateTime($nota['data_emissao'] . ' ' . date('H:i:s')))->format('Y-m-d\TH:i:sP');
    $std->tpNF = 1;
    $std->idDest = 1; // venda presencial, sempre operação interna.
    $std->cMunFG = nfceSomenteNumeros($empresa['codigo_ibge_municipio'] ?? '');
    $std->tpImp = 4; // DANFCE (impressão simplificada).
    $std->tpEmis = $contingencia ? 4 : 1;
    $std->tpAmb = ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 1 : 2;
    $std->finNFe = 1;
    $std->indFinal = 1; // NFC-e é sempre para consumidor final.
    $std->indPres = (int) ($nfceExtra['indicador_presenca'] ?? 4);
    $std->procEmi = 0;
    $std->verProc = '1.0.0';
    if ($contingencia) {
        $std->dhCont = $dhContingencia ?? (new DateTime())->format('Y-m-d\TH:i:sP');
        $std->xJust = $justificativaContingencia ?? 'Indisponibilidade do serviço da SEFAZ.';
    }
    $make->tagide($std);

    $std = new stdClass();
    $std->CNPJ = $cnpjEmpresa;
    $std->xNome = (string) $empresa['razao_social'];
    $std->xFant = $empresa['nome_fantasia'] ?? null;
    $std->IE = nfceSomenteNumeros($empresa['inscricao_estadual'] ?? '');
    $std->CRT = (int) ($empresa['crt'] ?? 1);
    $make->tagEmit($std);

    $std = new stdClass();
    $std->xLgr = (string) ($empresa['logradouro'] ?? '');
    $std->nro = (string) ($empresa['numero'] ?? 'SN');
    $std->xCpl = $empresa['complemento'] ?? null;
    $std->xBairro = (string) ($empresa['bairro'] ?? '');
    $std->cMun = nfceSomenteNumeros($empresa['codigo_ibge_municipio'] ?? '');
    $std->xMun = (string) ($empresa['municipio'] ?? '');
    $std->UF = $ufEmpresa;
    $std->CEP = nfceSomenteNumeros($empresa['cep'] ?? '');
    $std->cPais = '1058';
    $std->xPais = 'Brasil';
    $make->tagenderEmit($std);

    // tagdest só é montada quando o comprador foi identificado com CPF/CNPJ avulso no
    // balcão — para "consumidor não identificado" a NFC-e é emitida sem destinatário.
    $consumidorIdentificado = (int) ($nfceExtra['consumidor_identificado'] ?? 0) === 1;
    $documentoConsumidor = $consumidorIdentificado ? nfceSomenteNumeros($nfceExtra['consumidor_cpf_cnpj'] ?? '') : '';
    if ($consumidorIdentificado && $documentoConsumidor !== '') {
        $std = new stdClass();
        if (strlen($documentoConsumidor) > 11) {
            $std->CNPJ = $documentoConsumidor;
        } else {
            $std->CPF = $documentoConsumidor;
        }
        $nomeConsumidor = trim((string) ($nfceExtra['consumidor_nome'] ?? ''));
        if ($nomeConsumidor !== '') {
            $std->xNome = $nomeConsumidor;
        }
        $std->indIEDest = 9;
        $make->tagdest($std);
    }

    $simplesNacional = (int) ($empresa['crt'] ?? 1) === 1;
    $numeroItem = 0;
    foreach ($itens as $item) {
        $numeroItem++;
        $valorTotalItem = round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2);

        $std = new stdClass();
        $std->item = $numeroItem;
        $std->cProd = (string) ($item['produto_servico_id'] ?: ('ITEM' . $numeroItem));
        $std->cEAN = $item['cean'] ?: 'SEM GTIN';
        $std->xProd = (string) $item['descricao'];
        $std->NCM = nfceSomenteNumeros($item['ncm'] ?? '');
        $std->CFOP = nfceSomenteNumeros($item['cfop'] ?? '');
        $std->uCom = (string) $item['unidade'];
        $std->qCom = (float) $item['quantidade'];
        $std->vUnCom = (float) $item['valor_unitario'];
        $std->vProd = $valorTotalItem;
        $std->cEANTrib = $item['cean_tributavel'] ?? ($item['cean'] ?: 'SEM GTIN');
        $std->uTrib = (string) ($item['unidade_tributavel'] ?? $item['unidade']);
        $std->qTrib = (float) ($item['quantidade_tributavel'] ?? $item['quantidade']);
        $std->vUnTrib = (float) ($item['valor_unitario_tributavel'] ?? $item['valor_unitario']);
        $std->indTot = 1;
        if (!empty($item['codigo_beneficio_fiscal'])) {
            $std->cBenef = (string) $item['codigo_beneficio_fiscal'];
        }
        $make->tagprod($std);

        if (!empty($item['cest'])) {
            $std = new stdClass();
            $std->item = $numeroItem;
            $std->CEST = nfceSomenteNumeros($item['cest']);
            if (!empty($item['indicador_escala_relevante'])) {
                $std->indEscala = (string) $item['indicador_escala_relevante'];
            }
            if (!empty($item['cnpj_fabricante'])) {
                $std->CNPJFab = nfceSomenteNumeros($item['cnpj_fabricante']);
            }
            $make->tagCEST($std);
        }

        $cstCsosn = trim((string) ($item['cst_csosn'] ?? ''));
        $std = new stdClass();
        $std->item = $numeroItem;
        $std->orig = (int) ($item['icms_origem'] ?? 0);
        if ($simplesNacional) {
            $std->CSOSN = $cstCsosn;
            if ($item['icms_base_calculo'] !== null) {
                $std->modBC = 3;
                $std->vBC = (float) $item['icms_base_calculo'];
                $std->pICMS = (float) $item['icms_aliquota'];
                $std->vICMS = (float) $item['icms_valor'];
            }
            $make->tagICMSSN($std);
        } else {
            $std->CST = $cstCsosn;
            if ($item['icms_base_calculo'] !== null) {
                $std->modBC = 3;
                $std->vBC = (float) $item['icms_base_calculo'];
                $std->pICMS = (float) $item['icms_aliquota'];
                $std->vICMS = (float) $item['icms_valor'];
            }
            $make->tagICMS($std);
        }

        if (!empty($item['ipi_cst'])) {
            $std = new stdClass();
            $std->item = $numeroItem;
            $std->CST = (string) $item['ipi_cst'];
            $std->cEnq = '999';
            if ($item['ipi_valor'] !== null) {
                $std->vBC = (float) $item['ipi_base_calculo'];
                $std->pIPI = (float) $item['ipi_aliquota'];
                $std->vIPI = (float) $item['ipi_valor'];
            }
            $make->tagIPI($std);
        }

        $std = new stdClass();
        $std->item = $numeroItem;
        $std->CST = (string) ($item['pis_cst'] ?? '99');
        if ($item['pis_valor'] !== null) {
            $std->vBC = (float) $item['pis_base_calculo'];
            $std->pPIS = (float) $item['pis_aliquota'];
            $std->vPIS = (float) $item['pis_valor'];
        } else {
            $std->vPIS = 0;
        }
        $make->tagPIS($std);

        $std = new stdClass();
        $std->item = $numeroItem;
        $std->CST = (string) ($item['cofins_cst'] ?? '99');
        if ($item['cofins_valor'] !== null) {
            $std->vBC = (float) $item['cofins_base_calculo'];
            $std->pCOFINS = (float) $item['cofins_aliquota'];
            $std->vCOFINS = (float) $item['cofins_valor'];
        } else {
            $std->vCOFINS = 0;
        }
        $make->tagCOFINS($std);

        if (!empty($item['ibscbs_cclasstrib'])) {
            $std = new stdClass();
            $std->item = $numeroItem;
            $std->CST = (string) ($item['ibscbs_cst'] ?? '000');
            $std->cClassTrib = (string) $item['ibscbs_cclasstrib'];
            $std->vBC = (float) ($item['ibscbs_base_calculo'] ?? $valorTotalItem);
            $std->gIBSUF_pIBSUF = (float) ($item['ibs_uf_aliquota'] ?? 0);
            $std->gIBSUF_vIBSUF = (float) ($item['ibs_uf_valor'] ?? 0);
            $std->gIBSMun_pIBSMun = (float) ($item['ibs_mun_aliquota'] ?? 0);
            $std->gIBSMun_vIBSMun = (float) ($item['ibs_mun_valor'] ?? 0);
            $std->gCBS_pCBS = (float) ($item['cbs_aliquota'] ?? 0);
            $std->gCBS_vCBS = (float) ($item['cbs_valor'] ?? 0);
            $make->tagIBSCBS($std);
        }
    }

    $vProd = array_sum(array_map(fn ($i) => round((float) $i['quantidade'] * (float) $i['valor_unitario'], 2), $itens));
    $vICMS = array_sum(array_map(fn ($i) => (float) ($i['icms_valor'] ?? 0), $itens));
    $vIPI = array_sum(array_map(fn ($i) => (float) ($i['ipi_valor'] ?? 0), $itens));
    $vPIS = array_sum(array_map(fn ($i) => (float) ($i['pis_valor'] ?? 0), $itens));
    $vCOFINS = array_sum(array_map(fn ($i) => (float) ($i['cofins_valor'] ?? 0), $itens));
    $vBC = array_sum(array_map(fn ($i) => (float) ($i['icms_base_calculo'] ?? 0), $itens));

    $std = new stdClass();
    $std->vBC = round($vBC, 2);
    $std->vICMS = round($vICMS, 2);
    $std->vICMSDeson = 0;
    $std->vBCST = 0;
    $std->vST = 0;
    $std->vProd = round($vProd, 2);
    $std->vFrete = 0;
    $std->vSeg = 0;
    $std->vDesc = 0;
    $std->vII = 0;
    $std->vIPI = round($vIPI, 2);
    $std->vPIS = round($vPIS, 2);
    $std->vCOFINS = round($vCOFINS, 2);
    $std->vOutro = 0;
    $std->vNF = round($vProd + $vIPI, 2);
    $std->vTotTrib = 0;
    $make->tagICMSTot($std);

    // NFC-e não tem transportador/volumes — modFrete=9 (sem transporte) é a única
    // informação obrigatória do grupo <transp>.
    $std = new stdClass();
    $std->modFrete = 9;
    $make->tagtransp($std);

    $valorTotalPago = 0.0;
    foreach ($pagamentos as $pagamento) {
        $valorTotalPago += (float) $pagamento['valor'];
    }
    $valorTroco = max(0, round($valorTotalPago - $std->vNF, 2));

    $std = new stdClass();
    $std->vTroco = $valorTroco;
    $make->tagpag($std);

    foreach ($pagamentos as $pagamento) {
        $std = new stdClass();
        $std->indPag = (int) ($pagamento['indicador_pagamento'] ?? 0);
        $std->tPag = (string) $pagamento['forma_pagamento_codigo'];
        $std->vPag = round((float) $pagamento['valor'], 2);
        $make->tagdetPag($std);
    }

    if (!empty($nfceExtra['informacoes_complementares'])) {
        $std = new stdClass();
        $std->infCpl = (string) $nfceExtra['informacoes_complementares'];
        $make->taginfAdic($std);
    }

    $xml = $make->getXML();

    return [
        'xml' => $xml,
        'chave' => $make->getChave(),
        'erros' => $make->getErrors(),
    ];
}
