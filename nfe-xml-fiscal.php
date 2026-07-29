<?php
/**
 * Monta o XML da NF-e (modelo 55) a partir dos dados ja persistidos em notas_fiscais /
 * notas_fiscais_nfe / notas_fiscais_itens, usando NFePHP\NFe\Make (nfephp-org/sped-nfe).
 *
 * Os valores de imposto usados aqui sao sempre os ja calculados e gravados por
 * includes/nfe-impostos.php - este arquivo so espelha o que esta no banco para dentro do XML,
 * nunca recalcula nada.
 */

require_once __DIR__ . '/vendor/autoload.php';

function nfeSomenteNumeros(?string $valor): string
{
    return preg_replace('/\D+/', '', (string) $valor) ?? '';
}

function nfeIndicadorDestinoOperacao(string $ufEmpresa, string $ufCliente): int
{
    if (strtoupper($ufEmpresa) === strtoupper($ufCliente)) {
        return 1;
    }

    return 2;
}

function nfeIndicadorIeDestinatario(array $cliente): int
{
    $ie = trim((string) ($cliente['inscricao_estadual'] ?? ''));
    if ($ie === '') {
        return 9;
    }
    if (strcasecmp($ie, 'isento') === 0) {
        return 2;
    }

    return 1;
}

/**
 * @param array $nota linha de notas_fiscais
 * @param array $empresa linha de empresas_emissoras
 * @param array $cliente linha de notas_clientes
 * @param array $nfeExtra linha de notas_fiscais_nfe
 * @param array $itens linhas de notas_fiscais_itens (ja com os campos de imposto calculados)
 * @return array{xml:string,chave:string,erros:array}
 */
function nfeMontarXml(array $nota, array $empresa, array $cliente, array $nfeExtra, array $itens): array
{
    // 'PL_010_V1.30' habilita os campos da Reforma Tributaria (NT 2025.002, IBS/CBS) na
    // montagem local do XML; a transmissao a SEFAZ continua declarando versao="4.00" no
    // proprio documento (schema aditivo, mesma versao de layout que a SEFAZ ja aceita).
    $make = new \NFePHP\NFe\Make('PL_010_V1.30');

    $ufEmpresa = strtoupper((string) $empresa['uf']);
    $ufCliente = strtoupper((string) ($cliente['uf'] ?? $ufEmpresa));
    $cnpjEmpresa = nfeSomenteNumeros($empresa['cnpj'] ?? '');

    $std = new stdClass();
    $std->Id = null;
    $std->versao = '4.00';
    $make->taginfNFe($std);

    $std = new stdClass();
    $std->cUF = (int) \NFePHP\Common\UFList::getCodeByUF($ufEmpresa);
    $std->natOp = (string) $nota['natureza_operacao'];
    $std->mod = 55;
    $std->serie = (int) $nota['serie'];
    $std->nNF = (int) $nota['numero_interno'];
    $std->dhEmi = (new DateTime($nota['data_emissao'] . ' ' . date('H:i:s')))->format('Y-m-d\TH:i:sP');
    if (!empty($nota['data_saida_entrada'])) {
        $std->dhSaiEnt = (new DateTime($nota['data_saida_entrada'] . ' ' . date('H:i:s')))->format('Y-m-d\TH:i:sP');
    }
    $std->tpNF = 1;
    $std->idDest = nfeIndicadorDestinoOperacao($ufEmpresa, $ufCliente);
    $std->cMunFG = nfeSomenteNumeros($empresa['codigo_ibge_municipio'] ?? '');
    $std->tpImp = 1;
    $std->tpEmis = 1;
    $std->tpAmb = ($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 1 : 2;
    $finalidadeMap = ['normal' => 1, 'complementar' => 2, 'ajuste' => 3, 'devolucao' => 4];
    $std->finNFe = $finalidadeMap[$nfeExtra['finalidade_emissao'] ?? 'normal'] ?? 1;
    $std->indFinal = (int) ($cliente['indicador_consumidor_final'] ?? 0) === 1 ? 1 : 0;
    $std->indPres = (int) ($nfeExtra['indicador_presenca'] ?? 9);
    $std->procEmi = 0;
    $std->verProc = '1.0.0';
    $make->tagide($std);

    if (!empty($nfeExtra['nfe_referenciada'])) {
        $std = new stdClass();
        $std->refNFe = nfeSomenteNumeros($nfeExtra['nfe_referenciada']);
        $make->tagrefNFe($std);
    }

    $std = new stdClass();
    $std->CNPJ = $cnpjEmpresa;
    $std->xNome = (string) $empresa['razao_social'];
    $std->xFant = $empresa['nome_fantasia'] ?? null;
    $std->IE = nfeSomenteNumeros($empresa['inscricao_estadual'] ?? '');
    $std->CRT = (int) ($empresa['crt'] ?? 1);
    $make->tagEmit($std);

    $std = new stdClass();
    $std->xLgr = (string) ($empresa['logradouro'] ?? '');
    $std->nro = (string) ($empresa['numero'] ?? 'SN');
    $std->xCpl = $empresa['complemento'] ?? null;
    $std->xBairro = (string) ($empresa['bairro'] ?? '');
    $std->cMun = nfeSomenteNumeros($empresa['codigo_ibge_municipio'] ?? '');
    $std->xMun = (string) ($empresa['municipio'] ?? '');
    $std->UF = $ufEmpresa;
    $std->CEP = nfeSomenteNumeros($empresa['cep'] ?? '');
    $std->cPais = '1058';
    $std->xPais = 'Brasil';
    $make->tagenderEmit($std);

    $std = new stdClass();
    $documentoCliente = nfeSomenteNumeros($cliente['cnpj_cpf'] ?? '');
    if (strlen($documentoCliente) > 11) {
        $std->CNPJ = $documentoCliente;
    } else {
        $std->CPF = $documentoCliente;
    }
    $std->xNome = (string) $cliente['nome_razao_social'];
    $std->indIEDest = nfeIndicadorIeDestinatario($cliente);
    if ($std->indIEDest === 1) {
        $std->IE = nfeSomenteNumeros($cliente['inscricao_estadual']);
    }
    $std->email = $cliente['email'] ?? null;
    $make->tagdest($std);

    if (!empty($cliente['logradouro'])) {
        $std = new stdClass();
        $std->xLgr = (string) $cliente['logradouro'];
        $std->nro = (string) ($cliente['numero'] ?? 'SN');
        $std->xCpl = $cliente['complemento'] ?? null;
        $std->xBairro = (string) ($cliente['bairro'] ?? '');
        $std->cMun = nfeSomenteNumeros($cliente['codigo_ibge_municipio'] ?? '');
        $std->xMun = (string) ($cliente['municipio'] ?? '');
        $std->UF = $ufCliente;
        $std->CEP = nfeSomenteNumeros($cliente['cep'] ?? '');
        $std->cPais = '1058';
        $std->xPais = 'Brasil';
        $make->tagenderDest($std);
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
        $std->NCM = nfeSomenteNumeros($item['ncm'] ?? '');
        $std->CFOP = nfeSomenteNumeros($item['cfop'] ?? '');
        $std->uCom = (string) $item['unidade'];
        $std->qCom = (float) $item['quantidade'];
        $std->vUnCom = (float) $item['valor_unitario'];
        $std->vProd = $valorTotalItem;
        $std->cEANTrib = $item['cean_tributavel'] ?: ($item['cean'] ?: 'SEM GTIN');
        $std->uTrib = (string) ($item['unidade_tributavel'] ?: $item['unidade']);
        $std->qTrib = (float) ($item['quantidade_tributavel'] ?: $item['quantidade']);
        $std->vUnTrib = (float) ($item['valor_unitario_tributavel'] ?: $item['valor_unitario']);
        $std->indTot = 1;
        if (!empty($item['codigo_beneficio_fiscal'])) {
            $std->cBenef = (string) $item['codigo_beneficio_fiscal'];
        }
        $make->tagprod($std);

        if (!empty($item['cest'])) {
            $std = new stdClass();
            $std->item = $numeroItem;
            $std->CEST = nfeSomenteNumeros($item['cest']);
            if (!empty($item['indicador_escala_relevante'])) {
                $std->indEscala = (string) $item['indicador_escala_relevante'];
            }
            if (!empty($item['cnpj_fabricante'])) {
                $std->CNPJFab = nfeSomenteNumeros($item['cnpj_fabricante']);
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
            if ($item['icms_st_valor'] !== null) {
                $std->modBCST = (int) ($item['icms_st_modalidade_bc'] ?? 4);
                $std->vBCST = (float) $item['icms_st_base_calculo'];
                $std->pICMSST = (float) $item['icms_st_aliquota'];
                $std->vICMSST = (float) $item['icms_st_valor'];
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
            if ($item['icms_st_valor'] !== null) {
                $std->modBCST = (int) ($item['icms_st_modalidade_bc'] ?? 4);
                $std->vBCST = (float) $item['icms_st_base_calculo'];
                $std->pICMSST = (float) $item['icms_st_aliquota'];
                $std->vICMSST = (float) $item['icms_st_valor'];
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
    $vICMSST = array_sum(array_map(fn ($i) => (float) ($i['icms_st_valor'] ?? 0), $itens));
    $vIPI = array_sum(array_map(fn ($i) => (float) ($i['ipi_valor'] ?? 0), $itens));
    $vPIS = array_sum(array_map(fn ($i) => (float) ($i['pis_valor'] ?? 0), $itens));
    $vCOFINS = array_sum(array_map(fn ($i) => (float) ($i['cofins_valor'] ?? 0), $itens));
    $vBC = array_sum(array_map(fn ($i) => (float) ($i['icms_base_calculo'] ?? 0), $itens));
    $vBCST = array_sum(array_map(fn ($i) => (float) ($i['icms_st_base_calculo'] ?? 0), $itens));

    $std = new stdClass();
    $std->vBC = round($vBC, 2);
    $std->vICMS = round($vICMS, 2);
    $std->vICMSDeson = 0;
    $std->vBCST = round($vBCST, 2);
    $std->vST = round($vICMSST, 2);
    $std->vProd = round($vProd, 2);
    $std->vFrete = 0;
    $std->vSeg = 0;
    $std->vDesc = 0;
    $std->vII = 0;
    $std->vIPI = round($vIPI, 2);
    $std->vPIS = round($vPIS, 2);
    $std->vCOFINS = round($vCOFINS, 2);
    $std->vOutro = 0;
    $std->vNF = round($vProd + $vICMSST + $vIPI, 2);
    $std->vTotTrib = 0;
    $make->tagICMSTot($std);

    $std = new stdClass();
    $std->modFrete = (int) ($nfeExtra['modalidade_frete'] ?? 9);
    $make->tagtransp($std);

    if (!empty($nfeExtra['transportador_nome']) || !empty($nfeExtra['transportador_cnpj_cpf'])) {
        $std = new stdClass();
        $documentoTransportador = nfeSomenteNumeros($nfeExtra['transportador_cnpj_cpf'] ?? '');
        if (strlen($documentoTransportador) > 11) {
            $std->CNPJ = $documentoTransportador;
        } elseif ($documentoTransportador !== '') {
            $std->CPF = $documentoTransportador;
        }
        $std->xNome = $nfeExtra['transportador_nome'] ?? null;
        $std->IE = nfeSomenteNumeros($nfeExtra['transportador_ie'] ?? '') ?: null;
        $std->xEnder = $nfeExtra['transportador_endereco'] ?? null;
        $std->xMun = $nfeExtra['transportador_municipio'] ?? null;
        $std->UF = $nfeExtra['transportador_uf'] ?? null;
        $make->tagtransporta($std);
    }

    if (!empty($nfeExtra['veiculo_placa'])) {
        $std = new stdClass();
        $std->placa = $nfeExtra['veiculo_placa'];
        $std->UF = $nfeExtra['veiculo_uf'] ?? null;
        $std->RNTC = $nfeExtra['veiculo_rntc'] ?? null;
        $make->tagveicTransp($std);
    }

    if (!empty($nfeExtra['volumes_quantidade'])) {
        $std = new stdClass();
        $std->qVol = (int) $nfeExtra['volumes_quantidade'];
        $std->esp = $nfeExtra['volumes_especie'] ?? null;
        $std->marca = $nfeExtra['volumes_marca'] ?? null;
        $std->nVol = $nfeExtra['volumes_numeracao'] ?? null;
        $std->pesoL = $nfeExtra['volumes_peso_liquido'] ?? null;
        $std->pesoB = $nfeExtra['volumes_peso_bruto'] ?? null;
        $make->tagvol($std);
    }

    $std = new stdClass();
    $std->vTroco = round((float) ($nfeExtra['valor_troco'] ?? 0), 2);
    $make->tagpag($std);

    $std = new stdClass();
    $std->indPag = (int) ($nfeExtra['indicador_pagamento'] ?? 0);
    $std->tPag = (string) ($nfeExtra['forma_pagamento_codigo'] ?? '90');
    $std->vPag = round((float) ($nfeExtra['valor_pago'] ?? $std->vNF ?? $vProd), 2);
    $make->tagdetPag($std);

    if (!empty($nfeExtra['informacoes_complementares'])) {
        $std = new stdClass();
        $std->infCpl = (string) $nfeExtra['informacoes_complementares'];
        $make->taginfAdic($std);
    }

    $xml = $make->getXML();

    return [
        'xml' => $xml,
        'chave' => $make->getChave(),
        'erros' => $make->getErrors(),
    ];
}
