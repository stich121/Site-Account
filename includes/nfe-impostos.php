<?php
/**
 * Calculo de impostos por item de NF-e (ICMS/ICMS-ST/IPI/PIS/COFINS).
 *
 * O valor gravado no banco e usado no XML e sempre o calculado aqui no servidor -
 * aliquotas vindas do cadastro do produto ou do POST sao apenas sugestao inicial no
 * formulario, nunca a fonte de verdade.
 *
 * Fora do escopo (ver plano): DIFAL/partilha para consumidor final nao contribuinte em
 * outra UF (bloqueado explicitamente por bloqueioDifalNfe()) e tabela de MVA por
 * NCM/UF para ICMS-ST (a base/aliquota de ST, quando aplicavel, e informada manualmente
 * no item).
 */

const NFE_UFS_SUL_SUDESTE_SEM_ES = ['SP', 'RJ', 'MG', 'PR', 'SC', 'RS'];

const NFE_CSOSN_SEM_DESTAQUE_ICMS = ['101', '102', '103', '300', '400'];
const NFE_CSOSN_COM_ST = ['201', '202', '203', '500'];

const NFE_CST_SEM_DESTAQUE_ICMS = ['40', '41', '50', '60'];
const NFE_CST_COM_ST = ['10', '30', '70'];

function nfeAliquotaIcmsInterestadual(string $ufOrigem, string $ufDestino, bool $importado): float
{
    if ($importado) {
        return 4.0;
    }
    if (strtoupper($ufOrigem) === strtoupper($ufDestino)) {
        return 0.0;
    }
    $origemSulSudeste = in_array(strtoupper($ufOrigem), NFE_UFS_SUL_SUDESTE_SEM_ES, true);
    $destinoSulSudeste = in_array(strtoupper($ufDestino), NFE_UFS_SUL_SUDESTE_SEM_ES, true);

    return ($origemSulSudeste && !$destinoSulSudeste) ? 7.0 : 12.0;
}

function nfeEmpresaSimplesNacional(array $empresa): bool
{
    // CRT 1 (Simples Nacional) e 2 (Simples Nacional - excesso de sublimite) usam CSOSN.
    // CRT 4 (MEI) e' legado, mas ainda cadastravel neste sistema e tambem opera sob o
    // guarda-chuva do Simples Nacional, entao usa CSOSN. So o CRT 3 (Regime Normal) usa CST.
    return in_array((int) ($empresa['crt'] ?? 0), [1, 2, 4], true);
}

/**
 * Calcula os campos de imposto (ICMS/ICMS-ST/IPI/PIS/COFINS) de um item de NF-e.
 *
 * $itemBruto: descricao, valor_total, cst_csosn, icms_origem, icms_aliquota (sugestao),
 *   icms_st_aliquota, icms_st_base_calculo (informados manualmente quando ha ST),
 *   ipi_cst, ipi_aliquota, pis_aliquota, cofins_aliquota.
 * $empresa: crt, uf.
 * $cfop: CFOP do item (define operacao interna/interestadual).
 * $ufDestino: UF do cliente destinatario.
 */
function nfeCalcularImpostosItem(array $itemBruto, array $empresa, string $cfop, string $ufDestino): array
{
    $valorTotal = round((float) ($itemBruto['valor_total'] ?? 0), 2);
    $ufOrigem = (string) ($empresa['uf'] ?? '');
    $origemImportada = in_array((string) ($itemBruto['icms_origem'] ?? '0'), ['1', '2', '6', '7'], true);
    $interestadual = str_starts_with($cfop, '6') || str_starts_with($cfop, '7');
    $simplesNacional = nfeEmpresaSimplesNacional($empresa);
    $cstCsosn = trim((string) ($itemBruto['cst_csosn'] ?? ''));

    $resultado = [
        'icms_origem' => (int) ($itemBruto['icms_origem'] ?? 0),
        'icms_modalidade_bc' => 3,
        'icms_base_calculo' => null,
        'icms_aliquota' => null,
        'icms_valor' => null,
        'icms_st_modalidade_bc' => null,
        'icms_st_aliquota' => null,
        'icms_st_base_calculo' => null,
        'icms_st_valor' => null,
        'ipi_cst' => null,
        'ipi_base_calculo' => null,
        'ipi_aliquota' => null,
        'ipi_valor' => null,
        'pis_cst' => null,
        'pis_base_calculo' => null,
        'pis_aliquota' => null,
        'pis_valor' => null,
        'cofins_cst' => null,
        'cofins_base_calculo' => null,
        'cofins_aliquota' => null,
        'cofins_valor' => null,
        'ibscbs_cst' => null,
        'ibscbs_cclasstrib' => null,
        'ibscbs_base_calculo' => null,
        'ibs_uf_aliquota' => null,
        'ibs_uf_valor' => null,
        'ibs_mun_aliquota' => null,
        'ibs_mun_valor' => null,
        'cbs_aliquota' => null,
        'cbs_valor' => null,
    ];

    $temIcmsProprio = $simplesNacional
        ? ($cstCsosn === '900')
        : !in_array($cstCsosn, NFE_CST_SEM_DESTAQUE_ICMS, true);
    $temIcmsSt = $simplesNacional
        ? in_array($cstCsosn, NFE_CSOSN_COM_ST, true)
        : in_array($cstCsosn, NFE_CST_COM_ST, true);

    if ($temIcmsProprio) {
        $aliquota = $interestadual
            ? nfeAliquotaIcmsInterestadual($ufOrigem, $ufDestino, $origemImportada)
            : round((float) ($itemBruto['icms_aliquota'] ?? 0), 4);
        $resultado['icms_base_calculo'] = $valorTotal;
        $resultado['icms_aliquota'] = $aliquota;
        $resultado['icms_valor'] = round($valorTotal * $aliquota / 100, 2);
    }

    if ($temIcmsSt) {
        $baseSt = round((float) ($itemBruto['icms_st_base_calculo'] ?? 0), 2);
        $aliquotaSt = round((float) ($itemBruto['icms_st_aliquota'] ?? 0), 4);
        if ($baseSt > 0 && $aliquotaSt > 0) {
            $resultado['icms_st_modalidade_bc'] = 4;
            $resultado['icms_st_base_calculo'] = $baseSt;
            $resultado['icms_st_aliquota'] = $aliquotaSt;
            $resultado['icms_st_valor'] = round($baseSt * $aliquotaSt / 100, 2);
        }
    }

    $ipiCst = trim((string) ($itemBruto['ipi_cst'] ?? ''));
    if ($ipiCst !== '') {
        $resultado['ipi_cst'] = $ipiCst;
        if (in_array($ipiCst, ['00', '49', '50', '99'], true)) {
            $aliquotaIpi = round((float) ($itemBruto['ipi_aliquota'] ?? 0), 4);
            $resultado['ipi_base_calculo'] = $valorTotal;
            $resultado['ipi_aliquota'] = $aliquotaIpi;
            $resultado['ipi_valor'] = round($valorTotal * $aliquotaIpi / 100, 2);
        }
    }

    $aliquotaPis = round((float) ($itemBruto['pis_aliquota'] ?? 0), 4);
    $aliquotaCofins = round((float) ($itemBruto['cofins_aliquota'] ?? 0), 4);
    $resultado['pis_cst'] = trim((string) ($itemBruto['pis_cst'] ?? '')) ?: ($simplesNacional ? '99' : '01');
    $resultado['pis_base_calculo'] = $valorTotal;
    $resultado['pis_aliquota'] = $aliquotaPis;
    $resultado['pis_valor'] = round($valorTotal * $aliquotaPis / 100, 2);
    $resultado['cofins_cst'] = trim((string) ($itemBruto['cofins_cst'] ?? '')) ?: ($simplesNacional ? '99' : '01');
    $resultado['cofins_base_calculo'] = $valorTotal;
    $resultado['cofins_aliquota'] = $aliquotaCofins;
    $resultado['cofins_valor'] = round($valorTotal * $aliquotaCofins / 100, 2);

    // Reforma tributaria (LC 214/2025): IBS/CBS. So preenchido quando o item tem um
    // cClassTrib selecionado - fora isso a nota nao inclui o grupo IBSCBS no XML.
    $ibscbsCclasstrib = trim((string) ($itemBruto['ibscbs_cclasstrib'] ?? ''));
    if ($ibscbsCclasstrib !== '') {
        $baseIbscbs = round((float) ($itemBruto['ibscbs_base_calculo'] ?? $valorTotal), 2);
        $aliquotaIbsUf = round((float) ($itemBruto['ibs_uf_aliquota'] ?? 0), 4);
        $aliquotaIbsMun = round((float) ($itemBruto['ibs_mun_aliquota'] ?? 0), 4);
        $aliquotaCbs = round((float) ($itemBruto['cbs_aliquota'] ?? 0), 4);

        // Ano-teste da Reforma Tributaria (LC 214/2025 art. 346, NT 2025.002 v1.20): para
        // NF-e/NFC-e emitidas em 2026 a SEFAZ exige pIBSUF EXATAMENTE 0,1% (rejeicao 1026),
        // pIBSMun EXATAMENTE 0% (rejeicao 321/1036) e pCBS EXATAMENTE 0,9% (rejeicao 1037) -
        // qualquer outro valor, mesmo por arredondamento, rejeita a nota. Nao confiamos no
        // valor digitado no formulario para esses tres campos neste periodo - a sugestao da
        // tela e so um placeholder. Revisar quando a proxima fase da reforma (2027+) definir
        // novas aliquotas-teste.
        if ((int) date('Y') === 2026) {
            $aliquotaIbsUf = 0.1;
            $aliquotaIbsMun = 0.0;
            $aliquotaCbs = 0.9;
        }
        $resultado['ibscbs_cst'] = trim((string) ($itemBruto['ibscbs_cst'] ?? '')) ?: '000';
        $resultado['ibscbs_cclasstrib'] = $ibscbsCclasstrib;
        $resultado['ibscbs_base_calculo'] = $baseIbscbs;
        $resultado['ibs_uf_aliquota'] = $aliquotaIbsUf;
        $resultado['ibs_uf_valor'] = round($baseIbscbs * $aliquotaIbsUf / 100, 2);
        $resultado['ibs_mun_aliquota'] = $aliquotaIbsMun;
        $resultado['ibs_mun_valor'] = round($baseIbscbs * $aliquotaIbsMun / 100, 2);
        $resultado['cbs_aliquota'] = $aliquotaCbs;
        $resultado['cbs_valor'] = round($baseIbscbs * $aliquotaCbs / 100, 2);
    }

    return $resultado;
}

/**
 * Retorna uma mensagem de erro (bloqueando a emissao) quando a operacao exigiria
 * calculo de DIFAL/partilha, que ainda nao esta implementado nesta tela.
 */
function nfeBloqueioDifal(array $empresa, array $cliente): ?string
{
    $ufEmpresa = strtoupper((string) ($empresa['uf'] ?? ''));
    $ufCliente = strtoupper((string) ($cliente['uf'] ?? ''));
    if ($ufEmpresa === '' || $ufCliente === '' || $ufEmpresa === $ufCliente) {
        return null;
    }

    $clienteConsumidorFinal = (int) ($cliente['indicador_consumidor_final'] ?? 1) === 1;
    $clienteContribuinte = trim((string) ($cliente['inscricao_estadual'] ?? '')) !== ''
        && strcasecmp(trim((string) $cliente['inscricao_estadual']), 'isento') !== 0;

    if ($clienteConsumidorFinal && !$clienteContribuinte) {
        return 'Esta venda e interestadual para consumidor final sem inscricao estadual, o que exige calculo de '
            . 'DIFAL/partilha entre estados (EC 87/2015). Essa emissao ainda nao esta disponivel nesta tela.';
    }

    return null;
}
