<?php
/**
 * Lógica compartilhada de emissão de notas fiscais (NF-e e NFS-e).
 *
 * Antes de incluir este arquivo, a página deve definir:
 * - $tipoNotaFixo (string): 'nfe' ou 'nfse' — trava o tipo de nota emitida por essa página,
 *   ignorando qualquer valor de tipo_nota vindo do POST.
 *
 * Ao final da inclusão, ficam disponíveis para a página: $erro, $sucesso, $notaEmEdicao,
 * $nfseEmEdicao, $nfeEmEdicao, $itensEmEdicao, $dadosRestaurar, $empresasAtivas, $clientes, $catalogo,
 * $csrf, $usuario, $catalogoJson, $edicaoJson, $restaurarJson, $codigosTributacaoNacionalNfse,
 * $correlacaoNbsNfse, $cfopCodigosNfe, $db, $dbNotas, $funcionarioId, $usuarioRaw, $nivelAcesso, $podeAdministrar.
 */

require_once __DIR__ . '/../seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/../config_db.php';
require_once __DIR__ . '/../config_db_notas.php';
require_once __DIR__ . '/../nfse-codigos-tributacao-nacional.php';
require_once __DIR__ . '/nfe-impostos.php';
require_once __DIR__ . '/cache-util.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

$erro = '';
$sucesso = '';
$notaEmEdicao = null;
$nfseEmEdicao = null;
$nfeEmEdicao = null;
$itensEmEdicao = [];
$dadosRestaurar = null;

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function codigoMunicipioIbgeValido(string $codigo): bool
{
    static $codigos = null;
    if ($codigos === null) {
        $municipios = cacheJsonArquivo(__DIR__ . '/../ibge-municipios.json');
        $codigos = [];
        foreach ($municipios as $municipio) {
            $codigos[(string) ($municipio['codigo'] ?? '')] = true;
        }
    }

    return isset($codigos[$codigo]);
}

function catalogoIbsCbsNfse(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $catalogo = cacheJsonArquivo(__DIR__ . '/../nfse-ibs-catalogos.json');
    }

    return $catalogo;
}

function catalogoCfopNfe(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $catalogo = cacheJsonArquivo(__DIR__ . '/../cfop-codigos.json');
    }

    return $catalogo;
}

function catalogoCorrelacaoNbsNfse(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $catalogo = cacheJsonArquivo(__DIR__ . '/../nfse-nbs-correlacao.json');
    }

    return $catalogo;
}

function opcoesNbsPorCodigoTributacao(string $codigoTributacao): array
{
    $partes = explode('.', trim($codigoTributacao));
    $itemLc116 = count($partes) >= 2
        ? str_pad((string) ((int) $partes[0]), 2, '0', STR_PAD_LEFT) . '.' . str_pad((string) ((int) $partes[1]), 2, '0', STR_PAD_LEFT)
        : '';
    $opcoes = catalogoCorrelacaoNbsNfse()['itens'][$itemLc116]['nbs'] ?? [];

    return is_array($opcoes) ? $opcoes : [];
}

function itemCatalogoIbsCbs(string $grupo, string $codigo): ?array
{
    foreach (catalogoIbsCbsNfse()[$grupo] ?? [] as $item) {
        if ((string) ($item['codigo'] ?? '') === $codigo) {
            return $item;
        }
    }

    return null;
}

function documentoNfseValido(string $documento): bool
{
    $numero = preg_replace('/\D+/', '', $documento);
    if (!in_array(strlen($numero), [11, 14], true) || preg_match('/^(\d)\1+$/', $numero)) return false;
    if (strlen($numero) === 11) {
        for ($digitoPos = 9; $digitoPos <= 10; $digitoPos++) {
            $soma = 0; $peso = $digitoPos + 1;
            for ($i = 0; $i < $digitoPos; $i++) $soma += (int) $numero[$i] * ($peso--);
            $digito = 11 - ($soma % 11); if ($digito >= 10) $digito = 0;
            if ((int) $numero[$digitoPos] !== $digito) return false;
        }
        return true;
    }
    foreach ([12, 13] as $posicao) {
        $soma = 0; $peso = $posicao === 12 ? 5 : 6;
        for ($i = 0; $i < $posicao; $i++) { $soma += (int) $numero[$i] * $peso; $peso = $peso === 2 ? 9 : $peso - 1; }
        $digito = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
        if ((int) $numero[$posicao] !== $digito) return false;
    }
    return true;
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function colunaExisteFuncionarios(PDO $db, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'funcionarios\'
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunaPermiteNotasFiscais(PDO $db): void
{
    if (!colunaExisteFuncionarios($db, 'permite_notas_fiscais')) {
        $db->exec('ALTER TABLE funcionarios ADD COLUMN permite_notas_fiscais TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto');
    }
}

function prepararTabelaEmpresasEmissorasNotas(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS empresas_emissoras (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            razao_social VARCHAR(180) NOT NULL,
            nome_fantasia VARCHAR(180) NULL,
            cnpj VARCHAR(20) NULL,
            inscricao_estadual VARCHAR(30) NULL,
            inscricao_municipal VARCHAR(30) NULL,
            logradouro VARCHAR(180) NULL,
            numero VARCHAR(20) NULL,
            complemento VARCHAR(120) NULL,
            bairro VARCHAR(120) NULL,
            cep VARCHAR(12) NULL,
            municipio VARCHAR(120) NULL,
            codigo_ibge_municipio VARCHAR(10) NULL,
            uf CHAR(2) NULL,
            crt TINYINT UNSIGNED NULL,
            ambiente_emissao ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
            nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1,
            nfse_regime_apuracao_sn TINYINT UNSIGNED NULL,
            nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel',
            nfse_regime_especial_tributacao VARCHAR(60) NULL,
            certificado_arquivo VARCHAR(255) NULL,
            certificado_senha_cifrada VARCHAR(512) NULL,
            certificado_atualizado_em TIMESTAMP NULL,
            certificado_atualizado_por INT UNSIGNED NULL,
            certificado_validade DATE NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function semearEmpresasEmissorasNotas(PDO $db): void
{
    // So semeia se a tabela estiver vazia (ver comentario equivalente em notas-empresas-emissoras.php).
    if ((int) $db->query('SELECT COUNT(*) FROM empresas_emissoras')->fetchColumn() > 0) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO empresas_emissoras (razao_social, ambiente_emissao, ativo)
         VALUES (:razao_social, \'homologacao\', 1)
         ON DUPLICATE KEY UPDATE razao_social = razao_social'
    );

    foreach (['Account', 'Art Designer', 'Consplatol', 'MC', 'MC2', 'Smarky', 'Tarsos Pizzaria'] as $nome) {
        $stmt->execute(['razao_social' => $nome]);
    }
}

function prepararTabelaNotasProdutosServicosNotas(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_produtos_servicos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            tipo ENUM('produto','servico') NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            codigo_interno VARCHAR(60) NULL,
            ncm VARCHAR(10) NULL,
            cfop VARCHAR(6) NULL,
            cst_csosn VARCHAR(6) NULL,
            codigo_servico_municipal VARCHAR(20) NULL,
            unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
            valor_unitario_padrao DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            aliquota_icms DECIMAL(5,2) NULL,
            aliquota_pis DECIMAL(5,2) NULL,
            aliquota_cofins DECIMAL(5,2) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_produtos_servicos_empresa (empresa_emissora_id, ativo),
            CONSTRAINT fk_produtos_servicos_empresa_notas
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasClientes(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_clientes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo_pessoa ENUM('PJ','PF') NOT NULL,
            nome_razao_social VARCHAR(180) NOT NULL,
            cnpj_cpf VARCHAR(20) NULL,
            inscricao_estadual VARCHAR(30) NULL,
            inscricao_municipal VARCHAR(20) NULL,
            email VARCHAR(180) NULL,
            logradouro VARCHAR(180) NULL,
            numero VARCHAR(20) NULL,
            complemento VARCHAR(120) NULL,
            bairro VARCHAR(120) NULL,
            cep VARCHAR(12) NULL,
            municipio VARCHAR(120) NULL,
            codigo_ibge_municipio VARCHAR(7) NULL,
            codigo_pais VARCHAR(4) NULL,
            nif VARCHAR(40) NULL,
            motivo_nao_nif VARCHAR(2) NULL,
            uf CHAR(2) NULL,
            indicador_consumidor_final TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT UNSIGNED NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_clientes_nome (nome_razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasFiscais(PDO $db): void
{
    // funcionario_id não tem FOREIGN KEY: a tabela funcionarios vive no banco principal
    // (config_db.php), diferente do banco de notas fiscais (config_db_notas.php).
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            cliente_id INT UNSIGNED NOT NULL,
            funcionario_id INT UNSIGNED NOT NULL,
            tipo_nota ENUM('nfe','nfse') NOT NULL,
            natureza_operacao VARCHAR(120) NOT NULL,
            numero_interno INT UNSIGNED NOT NULL,
            status ENUM('rascunho','pendente_envio','autorizada','rejeitada','cancelada') NOT NULL DEFAULT 'rascunho',
            ambiente ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
            forma_pagamento VARCHAR(80) NULL,
            data_emissao DATE NOT NULL,
            data_saida_entrada DATE NULL,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            informacoes_frete TEXT NULL,
            chave_acesso VARCHAR(60) NULL,
            protocolo_autorizacao VARCHAR(80) NULL,
            xml_gerado LONGTEXT NULL,
            motivo_rejeicao TEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_empresa (empresa_emissora_id, tipo_nota),
            KEY idx_notas_fiscais_funcionario (funcionario_id),
            KEY idx_notas_fiscais_status (status),
            CONSTRAINT fk_notas_fiscais_empresa
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id),
            CONSTRAINT fk_notas_fiscais_cliente
                FOREIGN KEY (cliente_id) REFERENCES notas_clientes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasFiscaisItens(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_itens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nota_id BIGINT UNSIGNED NOT NULL,
            produto_servico_id INT UNSIGNED NULL,
            descricao VARCHAR(255) NOT NULL,
            ncm VARCHAR(10) NULL,
            cfop VARCHAR(6) NULL,
            cst_csosn VARCHAR(6) NULL,
            codigo_servico_municipal VARCHAR(20) NULL,
            unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
            quantidade DECIMAL(12,3) NOT NULL,
            valor_unitario DECIMAL(12,2) NOT NULL,
            valor_total DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_itens_nota (nota_id),
            CONSTRAINT fk_notas_fiscais_itens_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function colunaExisteNotas(PDO $db, string $tabela, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['tabela' => $tabela, 'coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunasFase2Notas(PDO $db): void
{
    if (!colunaExisteNotas($db, 'notas_fiscais', 'chave_acesso')) {
        $db->exec('ALTER TABLE notas_fiscais ADD COLUMN chave_acesso VARCHAR(60) NULL AFTER informacoes_frete');
    }

    if (!colunaExisteNotas($db, 'notas_fiscais_itens', 'codigo_servico_municipal')) {
        $db->exec('ALTER TABLE notas_fiscais_itens ADD COLUMN codigo_servico_municipal VARCHAR(20) NULL AFTER cst_csosn');
    }
}

function prepararColunasCertificadoEmpresa(PDO $db): void
{
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfse_opcao_simples_nacional')) {
        $db->exec("ALTER TABLE empresas_emissoras ADD COLUMN nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER ambiente_emissao");
    }
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfse_regime_apuracao_sn')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfse_regime_apuracao_sn TINYINT UNSIGNED NULL AFTER nfse_opcao_simples_nacional');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfse_tributacao_issqn')) {
        $db->exec("ALTER TABLE empresas_emissoras ADD COLUMN nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel' AFTER nfse_regime_apuracao_sn");
    }
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfse_regime_especial_tributacao')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfse_regime_especial_tributacao VARCHAR(60) NULL AFTER nfse_tributacao_issqn');
    }
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_arquivo')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_senha_cifrada')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_atualizado_em')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_atualizado_por')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_validade')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_validade DATE NULL AFTER certificado_atualizado_por');
    }
}

function prepararColunaSerieNfeEmpresaEmissora(PDO $db): void
{
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfe_serie')) {
        $db->exec("ALTER TABLE empresas_emissoras ADD COLUMN nfe_serie VARCHAR(3) NOT NULL DEFAULT '1' AFTER crt");
    }
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'nfe_numero_base')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfe_numero_base INT UNSIGNED NOT NULL DEFAULT 0 AFTER nfe_serie');
    }
}

function prepararColunaSerieNotaFiscal(PDO $db): void
{
    if (!colunaExisteNotas($db, 'notas_fiscais', 'serie')) {
        $db->exec("ALTER TABLE notas_fiscais ADD COLUMN serie VARCHAR(3) NOT NULL DEFAULT '1' AFTER numero_interno");
    }
}

function prepararTabelaNotasFiscaisNfe(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfe (
            nota_id BIGINT UNSIGNED NOT NULL,
            finalidade_emissao ENUM('normal','complementar','ajuste','devolucao') NOT NULL DEFAULT 'normal',
            indicador_presenca TINYINT UNSIGNED NOT NULL DEFAULT 9,
            nfe_referenciada VARCHAR(44) NULL,
            modalidade_frete TINYINT UNSIGNED NOT NULL DEFAULT 9,
            transportador_nome VARCHAR(180) NULL,
            transportador_cnpj_cpf VARCHAR(20) NULL,
            transportador_ie VARCHAR(20) NULL,
            transportador_endereco VARCHAR(180) NULL,
            transportador_municipio VARCHAR(120) NULL,
            transportador_uf CHAR(2) NULL,
            veiculo_placa VARCHAR(10) NULL,
            veiculo_uf CHAR(2) NULL,
            veiculo_rntc VARCHAR(20) NULL,
            volumes_quantidade INT UNSIGNED NULL,
            volumes_especie VARCHAR(60) NULL,
            volumes_marca VARCHAR(60) NULL,
            volumes_numeracao VARCHAR(60) NULL,
            volumes_peso_liquido DECIMAL(12,3) NULL,
            volumes_peso_bruto DECIMAL(12,3) NULL,
            forma_pagamento_codigo VARCHAR(2) NOT NULL DEFAULT '90',
            indicador_pagamento TINYINT UNSIGNED NOT NULL DEFAULT 0,
            valor_pago DECIMAL(12,2) NULL,
            valor_troco DECIMAL(12,2) NULL,
            informacoes_complementares TEXT NULL,
            chave_acesso_evento_cancelamento VARCHAR(60) NULL,
            PRIMARY KEY (nota_id),
            CONSTRAINT fk_notas_fiscais_nfe_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararColunasImpostoItensNotas(PDO $db): void
{
    $colunas = [
        'numero_item' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN numero_item SMALLINT UNSIGNED NULL AFTER produto_servico_id',
        'cean' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cean VARCHAR(14) NULL AFTER ncm',
        'cean_tributavel' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cean_tributavel VARCHAR(14) NULL AFTER cean',
        'cest' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cest VARCHAR(7) NULL AFTER cean_tributavel',
        'cnpj_fabricante' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cnpj_fabricante VARCHAR(20) NULL AFTER cest',
        'indicador_escala_relevante' => "ALTER TABLE notas_fiscais_itens ADD COLUMN indicador_escala_relevante ENUM('S','N') NULL AFTER cnpj_fabricante",
        'codigo_beneficio_fiscal' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN codigo_beneficio_fiscal VARCHAR(10) NULL AFTER indicador_escala_relevante',
        'unidade_tributavel' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN unidade_tributavel VARCHAR(10) NULL AFTER unidade',
        'quantidade_tributavel' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN quantidade_tributavel DECIMAL(12,3) NULL AFTER unidade_tributavel',
        'valor_unitario_tributavel' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN valor_unitario_tributavel DECIMAL(12,4) NULL AFTER quantidade_tributavel',
        'icms_origem' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_origem TINYINT UNSIGNED NULL AFTER cst_csosn',
        'icms_modalidade_bc' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_modalidade_bc TINYINT UNSIGNED NULL AFTER icms_origem',
        'icms_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_base_calculo DECIMAL(12,2) NULL AFTER icms_modalidade_bc',
        'icms_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_aliquota DECIMAL(6,4) NULL AFTER icms_base_calculo',
        'icms_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_valor DECIMAL(12,2) NULL AFTER icms_aliquota',
        'icms_st_modalidade_bc' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_st_modalidade_bc TINYINT UNSIGNED NULL AFTER icms_valor',
        'icms_st_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_st_aliquota DECIMAL(6,4) NULL AFTER icms_st_modalidade_bc',
        'icms_st_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_st_base_calculo DECIMAL(12,2) NULL AFTER icms_st_aliquota',
        'icms_st_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN icms_st_valor DECIMAL(12,2) NULL AFTER icms_st_base_calculo',
        'ipi_cst' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ipi_cst VARCHAR(2) NULL AFTER icms_st_valor',
        'ipi_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ipi_base_calculo DECIMAL(12,2) NULL AFTER ipi_cst',
        'ipi_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ipi_aliquota DECIMAL(6,4) NULL AFTER ipi_base_calculo',
        'ipi_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ipi_valor DECIMAL(12,2) NULL AFTER ipi_aliquota',
        'pis_cst' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN pis_cst VARCHAR(2) NULL AFTER ipi_valor',
        'pis_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN pis_base_calculo DECIMAL(12,2) NULL AFTER pis_cst',
        'pis_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN pis_aliquota DECIMAL(6,4) NULL AFTER pis_base_calculo',
        'pis_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN pis_valor DECIMAL(12,2) NULL AFTER pis_aliquota',
        'cofins_cst' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cofins_cst VARCHAR(2) NULL AFTER pis_valor',
        'cofins_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cofins_base_calculo DECIMAL(12,2) NULL AFTER cofins_cst',
        'cofins_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cofins_aliquota DECIMAL(6,4) NULL AFTER cofins_base_calculo',
        'cofins_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cofins_valor DECIMAL(12,2) NULL AFTER cofins_aliquota',
        'ibscbs_cst' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibscbs_cst VARCHAR(3) NULL AFTER cofins_valor',
        'ibscbs_cclasstrib' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibscbs_cclasstrib VARCHAR(6) NULL AFTER ibscbs_cst',
        'ibscbs_base_calculo' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibscbs_base_calculo DECIMAL(12,2) NULL AFTER ibscbs_cclasstrib',
        'ibs_uf_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibs_uf_aliquota DECIMAL(6,4) NULL AFTER ibscbs_base_calculo',
        'ibs_uf_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibs_uf_valor DECIMAL(12,2) NULL AFTER ibs_uf_aliquota',
        'ibs_mun_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibs_mun_aliquota DECIMAL(6,4) NULL AFTER ibs_uf_valor',
        'ibs_mun_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN ibs_mun_valor DECIMAL(12,2) NULL AFTER ibs_mun_aliquota',
        'cbs_aliquota' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cbs_aliquota DECIMAL(6,4) NULL AFTER ibs_mun_valor',
        'cbs_valor' => 'ALTER TABLE notas_fiscais_itens ADD COLUMN cbs_valor DECIMAL(12,2) NULL AFTER cbs_aliquota',
    ];
    foreach ($colunas as $coluna => $sql) {
        if (!colunaExisteNotas($db, 'notas_fiscais_itens', $coluna)) {
            $db->exec($sql);
        }
    }
}

function prepararColunasImpostoProdutosServicosNotas(PDO $db): void
{
    $colunas = [
        'ipi_cst' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN ipi_cst VARCHAR(2) NULL AFTER cst_csosn',
        'aliquota_ipi' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN aliquota_ipi DECIMAL(5,2) NULL AFTER ipi_cst',
        'cean' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN cean VARCHAR(14) NULL AFTER codigo_interno',
        'icms_origem' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN icms_origem TINYINT UNSIGNED NULL AFTER cst_csosn',
        'cest' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN cest VARCHAR(7) NULL AFTER ncm',
        'cnpj_fabricante' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN cnpj_fabricante VARCHAR(20) NULL AFTER cest',
        'indicador_escala_relevante' => "ALTER TABLE notas_produtos_servicos ADD COLUMN indicador_escala_relevante ENUM('S','N') NULL AFTER cnpj_fabricante",
        'codigo_beneficio_fiscal' => 'ALTER TABLE notas_produtos_servicos ADD COLUMN codigo_beneficio_fiscal VARCHAR(10) NULL AFTER indicador_escala_relevante',
    ];
    foreach ($colunas as $coluna => $sql) {
        if (!colunaExisteNotas($db, 'notas_produtos_servicos', $coluna)) {
            $db->exec($sql);
        }
    }
}

function prepararTabelaNotasFiscaisNfse(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfse (
            nota_id BIGINT UNSIGNED NOT NULL,
            data_competencia DATE NOT NULL,
            serie_dps VARCHAR(5) NULL,
            numero_dps VARCHAR(15) NULL,
            tomador_local ENUM('nao_informado','brasil','exterior') NOT NULL DEFAULT 'nao_informado',
            tomador_inscricao_municipal VARCHAR(20) NULL,
            tomador_telefone VARCHAR(20) NULL,
            intermediario_incluido TINYINT(1) NOT NULL DEFAULT 0,
            intermediario_local ENUM('nao_informado','brasil') NOT NULL DEFAULT 'nao_informado',
            intermediario_cpf_cnpj VARCHAR(20) NULL,
            intermediario_nome VARCHAR(180) NULL,
            pais_prestacao VARCHAR(60) NOT NULL DEFAULT 'Brasil',
            municipio_prestacao VARCHAR(120) NULL,
            codigo_tributacao_nacional VARCHAR(20) NULL,
            codigo_tributacao_municipal VARCHAR(20) NULL,
            codigo_interno_contribuinte VARCHAR(60) NULL,
            imune_exportacao_nao_incidencia ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            item_nbs VARCHAR(20) NULL,
            descricao_servico TEXT NULL,
            documento_responsabilidade_tecnica VARCHAR(60) NULL,
            documento_referencia VARCHAR(255) NULL,
            informacoes_complementares TEXT NULL,
            numero_pedido_b2b VARCHAR(120) NULL,
            valor_recebido_intermediario DECIMAL(12,2) NULL,
            desconto_incondicionado DECIMAL(12,2) NULL,
            desconto_condicionado DECIMAL(12,2) NULL,
            tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel',
            regime_especial_tributacao VARCHAR(60) NULL,
            exigibilidade_issqn_suspensa ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            tipo_suspensao_issqn VARCHAR(2) NULL,
            numero_processo_suspensao VARCHAR(60) NULL,
            issqn_retido ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            issqn_retido_por ENUM('tomador','intermediario') NULL,
            beneficio_municipal ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            codigo_beneficio_municipal VARCHAR(30) NULL,
            deducao_reducao_base_calculo DECIMAL(12,2) NULL,
            situacao_tributaria_pis_cofins VARCHAR(10) NULL,
            tipo_retencao_pis_cofins_csll VARCHAR(10) NULL,
            irrf DECIMAL(12,2) NULL,
            contribuicoes_sociais_retidas DECIMAL(12,2) NULL,
            contribuicao_previdenciaria_retida DECIMAL(12,2) NULL,
            tributos_modo ENUM('valores','percentuais') NOT NULL DEFAULT 'percentuais',
            tributos_federal_percentual DECIMAL(6,3) NULL,
            tributos_estadual_percentual DECIMAL(6,3) NULL,
            tributos_municipal_percentual DECIMAL(6,3) NULL,
            tributos_federal_valor DECIMAL(12,2) NULL,
            tributos_estadual_valor DECIMAL(12,2) NULL,
            tributos_municipal_valor DECIMAL(12,2) NULL,
            ibscbs_finalidade VARCHAR(2) NOT NULL DEFAULT '0',
            ibscbs_ind_final TINYINT(1) NULL,
            ibscbs_codigo_indicador_operacao VARCHAR(10) NULL,
            ibscbs_ind_destinatario VARCHAR(2) NULL,
            ibscbs_cst VARCHAR(3) NULL,
            ibscbs_classificacao_tributaria VARCHAR(10) NULL,
            PRIMARY KEY (nota_id),
            CONSTRAINT fk_notas_fiscais_nfse_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararColunasFase3bNotasFiscaisNfse(PDO $db): void
{
    // Alinhamento com o Guia do Emissor Publico Nacional Web: o campo do
    // tomador e "Inscricao Municipal" (nao "indicador municipal"), e o
    // bloco Servico Prestado tem um "Codigo interno do contribuinte" obrigatorio.
    if (colunaExisteNotas($db, 'notas_fiscais_nfse', 'tomador_indicador_municipal')
        && !colunaExisteNotas($db, 'notas_fiscais_nfse', 'tomador_inscricao_municipal')) {
        $db->exec('ALTER TABLE notas_fiscais_nfse CHANGE COLUMN tomador_indicador_municipal tomador_inscricao_municipal VARCHAR(20) NULL');
    }

    if (!colunaExisteNotas($db, 'notas_fiscais_nfse', 'codigo_interno_contribuinte')) {
        $db->exec('ALTER TABLE notas_fiscais_nfse ADD COLUMN codigo_interno_contribuinte VARCHAR(60) NULL AFTER codigo_tributacao_municipal');
    }

    $colunasIbsCbs = [
        'tipo_suspensao_issqn' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN tipo_suspensao_issqn VARCHAR(2) NULL',
        'numero_processo_suspensao' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN numero_processo_suspensao VARCHAR(60) NULL',
        'codigo_beneficio_municipal' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN codigo_beneficio_municipal VARCHAR(30) NULL',
        'ibscbs_finalidade' => "ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_finalidade VARCHAR(2) NOT NULL DEFAULT '0'",
        'ibscbs_ind_final' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_ind_final TINYINT(1) NULL',
        'ibscbs_codigo_indicador_operacao' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_codigo_indicador_operacao VARCHAR(10) NULL',
        'ibscbs_ind_destinatario' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_ind_destinatario VARCHAR(2) NULL',
        'ibscbs_cst' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_cst VARCHAR(3) NULL',
        'ibscbs_classificacao_tributaria' => 'ALTER TABLE notas_fiscais_nfse ADD COLUMN ibscbs_classificacao_tributaria VARCHAR(10) NULL',
    ];
    foreach ($colunasIbsCbs as $coluna => $sql) {
        if (!colunaExisteNotas($db, 'notas_fiscais_nfse', $coluna)) $db->exec($sql);
    }
}

function prepararColunaPercentualRetencaoIssqn(PDO $db): void
{
    // Percentual de retenção do ISSQN (campo oficial pAliq da DPS) - editável quando há retenção
    // pelo tomador/intermediário. Coluna nova adicionada depois que "notas_emitir_motor" já podia
    // estar marcada como preparada em servidores existentes, por isso tem flag própria (ver
    // schemaJaPreparada() logo abaixo de onde essa função é chamada).
    if (!colunaExisteNotas($db, 'notas_fiscais_nfse', 'percentual_retencao_issqn')) {
        $db->exec('ALTER TABLE notas_fiscais_nfse ADD COLUMN percentual_retencao_issqn DECIMAL(5,2) NULL AFTER issqn_retido_por');
    }
}

function prepararColunaInscricaoMunicipalClientes(PDO $db): void
{
    // Guarda a última Inscrição Municipal informada para o cliente, para
    // autopreencher o campo do tomador da próxima vez que ele for selecionado/buscado.
    if (!colunaExisteNotas($db, 'notas_clientes', 'inscricao_municipal')) {
        $db->exec('ALTER TABLE notas_clientes ADD COLUMN inscricao_municipal VARCHAR(20) NULL AFTER inscricao_estadual');
    }
    $camposEnderecoFiscal = [
        'codigo_ibge_municipio' => 'ALTER TABLE notas_clientes ADD COLUMN codigo_ibge_municipio VARCHAR(7) NULL AFTER municipio',
        'codigo_pais' => 'ALTER TABLE notas_clientes ADD COLUMN codigo_pais VARCHAR(4) NULL AFTER codigo_ibge_municipio',
        'nif' => 'ALTER TABLE notas_clientes ADD COLUMN nif VARCHAR(40) NULL AFTER codigo_pais',
        'motivo_nao_nif' => 'ALTER TABLE notas_clientes ADD COLUMN motivo_nao_nif VARCHAR(2) NULL AFTER nif',
    ];
    foreach ($camposEnderecoFiscal as $coluna => $sql) {
        if (!colunaExisteNotas($db, 'notas_clientes', $coluna)) $db->exec($sql);
    }
}

function prepararTabelaNotasFiscaisLog(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nota_id BIGINT UNSIGNED NOT NULL,
            funcionario_id INT UNSIGNED NOT NULL,
            acao VARCHAR(60) NOT NULL,
            detalhe VARCHAR(255) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_log_nota (nota_id, criado_em),
            CONSTRAINT fk_notas_fiscais_log_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function registrarLogNota(PDO $db, int $notaId, int $funcionarioId, string $acao, string $detalhe = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO notas_fiscais_log (nota_id, funcionario_id, acao, detalhe) VALUES (:nota_id, :funcionario_id, :acao, :detalhe)'
    );
    $stmt->execute([
        'nota_id' => $notaId,
        'funcionario_id' => $funcionarioId,
        'acao' => $acao,
        'detalhe' => $detalhe !== '' ? $detalhe : null,
    ]);
}

function obterLockEdicaoNota(PDO $db, int $notaId): bool
{
    $stmt = $db->prepare('SELECT GET_LOCK(:nome, 0)');
    $stmt->execute(['nome' => 'account_nfse_nota_' . $notaId]);
    return (int) $stmt->fetchColumn() === 1;
}

function liberarLockEdicaoNota(PDO $db, int $notaId): void
{
    $stmt = $db->prepare('SELECT RELEASE_LOCK(:nome)');
    $stmt->execute(['nome' => 'account_nfse_nota_' . $notaId]);
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    if (!schemaJaPreparada('notas_emitir_motor')) {
        prepararTabelaEmpresasEmissorasNotas($dbNotas);
        prepararTabelaNotasProdutosServicosNotas($dbNotas);
        prepararTabelaNotasClientes($dbNotas);
        prepararTabelaNotasFiscais($dbNotas);
        prepararTabelaNotasFiscaisItens($dbNotas);
        prepararTabelaNotasFiscaisNfse($dbNotas);
        prepararColunasFase3bNotasFiscaisNfse($dbNotas);
        prepararColunaInscricaoMunicipalClientes($dbNotas);
        prepararTabelaNotasFiscaisLog($dbNotas);
        prepararColunasFase2Notas($dbNotas);
        prepararColunasCertificadoEmpresa($dbNotas);
        prepararColunaSerieNfeEmpresaEmissora($dbNotas);
        prepararColunaSerieNotaFiscal($dbNotas);
        prepararTabelaNotasFiscaisNfe($dbNotas);
        prepararColunasImpostoItensNotas($dbNotas);
        prepararColunasImpostoProdutosServicosNotas($dbNotas);
        prepararColunaPermiteNotasFiscais($db);
        marcarSchemaPreparada('notas_emitir_motor');
    }
    if (!schemaJaPreparada('notas_fiscais_nfse_percentual_retencao')) {
        prepararColunaPercentualRetencaoIssqn($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfse_percentual_retencao');
    }
    semearEmpresasEmissorasNotas($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_emitir'])) {
        $_SESSION['csrf_notas_emitir'] = bin2hex(random_bytes(32));
    }

    $notaEdicaoId = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (int) ($_POST['nota_id_edicao'] ?? 0)
        : (int) ($_GET['editar'] ?? 0);
    if ($notaEdicaoId > 0) {
        $sqlEdicao = 'SELECT * FROM notas_fiscais WHERE id = :id';
        $paramsEdicao = ['id' => $notaEdicaoId];
        if (!$podeAdministrar) {
            $sqlEdicao .= ' AND funcionario_id = :funcionario_id';
            $paramsEdicao['funcionario_id'] = $funcionarioId;
        }
        $sqlEdicao .= ' LIMIT 1';
        $stmtEdicao = $dbNotas->prepare($sqlEdicao);
        $stmtEdicao->execute($paramsEdicao);
        $candidataEdicao = $stmtEdicao->fetch() ?: null;
        if (!$candidataEdicao || !in_array($candidataEdicao['tipo_nota'], ['nfse', 'nfe'], true) || !in_array($candidataEdicao['status'], ['rascunho', 'rejeitada'], true)) {
            $erro = 'Esta nota não pode ser editada. Somente rascunhos e notas rejeitadas podem ser corrigidas.';
        } else {
            $notaEmEdicao = $candidataEdicao;
            if ($candidataEdicao['tipo_nota'] === 'nfse') {
                $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfse WHERE nota_id = :nota_id LIMIT 1');
                $stmtEdicao->execute(['nota_id' => $notaEdicaoId]);
                $nfseEmEdicao = $stmtEdicao->fetch() ?: [];
            } else {
                $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfe WHERE nota_id = :nota_id LIMIT 1');
                $stmtEdicao->execute(['nota_id' => $notaEdicaoId]);
                $nfeEmEdicao = $stmtEdicao->fetch() ?: [];
            }
            $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_fiscais_itens WHERE nota_id = :nota_id ORDER BY id ASC');
            $stmtEdicao->execute(['nota_id' => $notaEdicaoId]);
            $itensEmEdicao = $stmtEdicao->fetchAll();
        }

        // Uma nota só pode ser corrigida na página do seu próprio tipo (NF-e/NFS-e);
        // evita abrir uma NFS-e na tela de emissão de produto (ou vice-versa).
        if ($notaEmEdicao && isset($tipoNotaFixo) && (string) $notaEmEdicao['tipo_nota'] !== $tipoNotaFixo) {
            $erro = 'Esta nota é do tipo ' . ($notaEmEdicao['tipo_nota'] === 'nfse' ? 'NFS-e (serviço)' : 'NF-e (produto)') . '. Abra a tela de emissão correspondente para editá-la.';
            $notaEmEdicao = null;
            $nfseEmEdicao = null;
            $nfeEmEdicao = null;
            $itensEmEdicao = [];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_emitir'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif ($erro === '' && in_array(($_POST['acao'] ?? ''), ['criar_nota', 'salvar_edicao'], true)) {
            $salvandoEdicao = ($_POST['acao'] ?? '') === 'salvar_edicao';
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $clienteId = (int) ($_POST['cliente_id'] ?? 0);
            $tipoNota = isset($tipoNotaFixo) ? $tipoNotaFixo : (($_POST['tipo_nota'] ?? 'nfe') === 'nfse' ? 'nfse' : 'nfe');
            if ($salvandoEdicao && $notaEmEdicao) {
                $empresaId = (int) $notaEmEdicao['empresa_emissora_id'];
                $tipoNota = (string) $notaEmEdicao['tipo_nota'];
            }
            $naturezaOperacao = trim($_POST['natureza_operacao'] ?? '');
            $formaPagamento = trim($_POST['forma_pagamento'] ?? '');
            $dataEmissao = trim($_POST['data_emissao'] ?? '') !== '' ? trim($_POST['data_emissao']) : date('Y-m-d');
            $dataSaidaEntrada = trim($_POST['data_saida_entrada'] ?? '');
            $informacoesFrete = trim($_POST['informacoes_frete'] ?? '');

            $itensValidos = [];
            $valorTotalNota = 0.0;
            $dadosNfse = null;
            $dadosNfe = null;

            if ($tipoNota === 'nfse') {
                $numerico = static function (string $campo): ?float {
                    $valor = trim((string) ($_POST[$campo] ?? ''));
                    if ($valor === '') {
                        return null;
                    }
                    // Aceita tanto "1.234,56" (com separador de milhar) quanto "1234.56"
                    // (valor vindo de preenchimento programático em JS, decimal com ponto).
                    if (str_contains($valor, ',')) {
                        $valor = str_replace('.', '', $valor);
                        $valor = str_replace(',', '.', $valor);
                    }
                    return (float) $valor;
                };

                $dataCompetencia = trim($_POST['nfse_data_competencia'] ?? '') !== '' ? trim($_POST['nfse_data_competencia']) : $dataEmissao;
                $informarDps = isset($_POST['nfse_informar_dps']);
                $serieDps = $informarDps ? trim($_POST['nfse_serie_dps'] ?? '') : '';
                $numeroDps = $informarDps ? trim($_POST['nfse_numero_dps'] ?? '') : '';
                $tomadorLocal = in_array($_POST['nfse_tomador_local'] ?? '', ['brasil', 'exterior'], true) ? $_POST['nfse_tomador_local'] : 'nao_informado';
                $tomadorInscricaoMunicipal = trim($_POST['nfse_tomador_inscricao_municipal'] ?? '');
                $tomadorTelefone = trim($_POST['nfse_tomador_telefone'] ?? '');
                $intermediarioIncluido = isset($_POST['nfse_intermediario_incluido']);
                $intermediarioLocal = $intermediarioIncluido && ($_POST['nfse_intermediario_local'] ?? '') === 'brasil' ? 'brasil' : 'nao_informado';
                $intermediarioCpfCnpj = $intermediarioIncluido ? trim($_POST['nfse_intermediario_cpf_cnpj'] ?? '') : '';
                $intermediarioNome = $intermediarioIncluido ? trim($_POST['nfse_intermediario_nome'] ?? '') : '';
                $paisPrestacao = trim($_POST['nfse_pais_prestacao'] ?? '') !== '' ? trim($_POST['nfse_pais_prestacao']) : 'Brasil';
                $municipioPrestacao = trim($_POST['nfse_municipio_prestacao'] ?? '');
                $codigoTributacaoNacional = trim($_POST['nfse_codigo_tributacao_nacional'] ?? '');
                $codigoTributacaoMunicipal = trim($_POST['nfse_codigo_tributacao_municipal'] ?? '');
                $codigoInternoContribuinte = trim($_POST['nfse_codigo_interno_contribuinte'] ?? '');
                // A indicação será derivada da tributação municipal da empresa emissora.
                $imuneExportacao = 'nao';
                $opcoesNbsServico = opcoesNbsPorCodigoTributacao($codigoTributacaoNacional);
                $itemNbs = preg_replace('/\D+/', '', trim($_POST['nfse_item_nbs'] ?? ''));
                if (count($opcoesNbsServico) === 1) {
                    $itemNbs = (string) ($opcoesNbsServico[0]['codigo'] ?? '');
                }
                $descricaoServico = trim($_POST['nfse_descricao_servico'] ?? '');
                $documentoResponsabilidadeTecnica = trim($_POST['nfse_documento_responsabilidade_tecnica'] ?? '');
                $documentoReferencia = trim($_POST['nfse_documento_referencia'] ?? '');
                $informacoesComplementaresNfse = trim($_POST['nfse_informacoes_complementares'] ?? '');
                $numeroPedidoB2b = trim($_POST['nfse_numero_pedido_b2b'] ?? '');

                $valorServico = $numerico('nfse_valor_servico') ?? 0.0;
                $valorRecebidoIntermediario = $numerico('nfse_valor_recebido_intermediario');
                $descontoIncondicionado = $numerico('nfse_desconto_incondicionado');
                $descontoCondicionado = $numerico('nfse_desconto_condicionado');

                // Valores autoritativos serão carregados do cadastro da empresa emissora.
                $tributacaoIssqn = 'operacao_tributavel';
                $regimeEspecialTributacao = '';
                $exigibilidadeSuspensa = ($_POST['nfse_exigibilidade_suspensa'] ?? '') === 'sim' ? 'sim' : 'nao';
                $tipoSuspensaoIssqn = $exigibilidadeSuspensa === 'sim' ? trim($_POST['nfse_tipo_suspensao_issqn'] ?? '') : '';
                $numeroProcessoSuspensao = $exigibilidadeSuspensa === 'sim' ? trim($_POST['nfse_numero_processo_suspensao'] ?? '') : '';
                $issqnRetido = ($_POST['nfse_issqn_retido'] ?? '') === 'sim' ? 'sim' : 'nao';
                $issqnRetidoPor = $issqnRetido === 'sim' ? (($_POST['nfse_issqn_retido_por'] ?? '') === 'intermediario' ? 'intermediario' : 'tomador') : null;
                $percentualRetencaoIssqn = $issqnRetido === 'sim' ? $numerico('nfse_percentual_retencao_issqn') : null;
                $beneficioMunicipal = ($_POST['nfse_beneficio_municipal'] ?? '') === 'sim' ? 'sim' : 'nao';
                $codigoBeneficioMunicipal = $beneficioMunicipal === 'sim' ? trim($_POST['nfse_codigo_beneficio_municipal'] ?? '') : '';
                $deducaoReducaoBase = $numerico('nfse_deducao_reducao_base');

                $situacaoTributariaPisCofins = trim($_POST['nfse_situacao_pis_cofins'] ?? '');
                $tipoRetencaoPisCofinsCsll = trim($_POST['nfse_tipo_retencao_pis_cofins_csll'] ?? '');
                $irrf = $numerico('nfse_irrf');
                $contribuicoesSociaisRetidas = $numerico('nfse_contribuicoes_sociais_retidas');
                $contribuicaoPrevidenciariaRetida = $numerico('nfse_contribuicao_previdenciaria_retida');

                $tributosModo = ($_POST['nfse_tributos_modo'] ?? '') === 'valores' ? 'valores' : 'percentuais';
                $tributosFederalPercentual = $numerico('nfse_tributos_federal_percentual');
                $tributosEstadualPercentual = $numerico('nfse_tributos_estadual_percentual');
                $tributosMunicipalPercentual = $numerico('nfse_tributos_municipal_percentual');
                $tributosFederalValor = $numerico('nfse_tributos_federal_valor');
                $tributosEstadualValor = $numerico('nfse_tributos_estadual_valor');
                $tributosMunicipalValor = $numerico('nfse_tributos_municipal_valor');
                $ibscbsFinalidade = trim($_POST['nfse_ibscbs_finalidade'] ?? '0');
                $ibscbsIndFinal = trim((string) ($_POST['nfse_ibscbs_ind_final'] ?? ''));
                $ibscbsCodigoIndicadorOperacao = trim($_POST['nfse_ibscbs_codigo_indicador_operacao'] ?? '');
                $ibscbsIndDestinatario = trim($_POST['nfse_ibscbs_ind_destinatario'] ?? '');
                $ibscbsCst = trim($_POST['nfse_ibscbs_cst'] ?? '');
                $ibscbsClassificacaoTributaria = trim($_POST['nfse_ibscbs_classificacao_tributaria'] ?? '');

                if ($descricaoServico !== '' && $valorServico > 0) {
                    $itensValidos[] = [
                        'produto_servico_id' => null,
                        'descricao' => $descricaoServico,
                        'ncm' => null,
                        'cfop' => null,
                        'cst_csosn' => null,
                        'codigo_servico_municipal' => $codigoTributacaoMunicipal !== '' ? $codigoTributacaoMunicipal : ($codigoTributacaoNacional !== '' ? $codigoTributacaoNacional : null),
                        'unidade' => 'UN',
                        'quantidade' => 1,
                        'valor_unitario' => $valorServico,
                        'valor_total' => round($valorServico, 2),
                    ];
                    $valorTotalNota = round($valorServico, 2);
                }

                $dadosNfse = [
                    'data_competencia' => $dataCompetencia,
                    'serie_dps' => $serieDps !== '' ? $serieDps : null,
                    'numero_dps' => $numeroDps !== '' ? $numeroDps : null,
                    'tomador_local' => $tomadorLocal,
                    'tomador_inscricao_municipal' => $tomadorInscricaoMunicipal !== '' ? $tomadorInscricaoMunicipal : null,
                    'tomador_telefone' => $tomadorTelefone !== '' ? $tomadorTelefone : null,
                    'intermediario_incluido' => $intermediarioIncluido ? 1 : 0,
                    'intermediario_local' => $intermediarioLocal,
                    'intermediario_cpf_cnpj' => $intermediarioCpfCnpj !== '' ? $intermediarioCpfCnpj : null,
                    'intermediario_nome' => $intermediarioNome !== '' ? $intermediarioNome : null,
                    'pais_prestacao' => $paisPrestacao,
                    'municipio_prestacao' => $municipioPrestacao !== '' ? $municipioPrestacao : null,
                    'codigo_tributacao_nacional' => $codigoTributacaoNacional !== '' ? $codigoTributacaoNacional : null,
                    'codigo_tributacao_municipal' => $codigoTributacaoMunicipal !== '' ? $codigoTributacaoMunicipal : null,
                    'codigo_interno_contribuinte' => $codigoInternoContribuinte !== '' ? $codigoInternoContribuinte : null,
                    'imune_exportacao_nao_incidencia' => $imuneExportacao,
                    'item_nbs' => $itemNbs !== '' ? $itemNbs : null,
                    'descricao_servico' => $descricaoServico !== '' ? $descricaoServico : null,
                    'documento_responsabilidade_tecnica' => $documentoResponsabilidadeTecnica !== '' ? $documentoResponsabilidadeTecnica : null,
                    'documento_referencia' => $documentoReferencia !== '' ? $documentoReferencia : null,
                    'informacoes_complementares' => $informacoesComplementaresNfse !== '' ? $informacoesComplementaresNfse : null,
                    'numero_pedido_b2b' => $numeroPedidoB2b !== '' ? $numeroPedidoB2b : null,
                    'valor_recebido_intermediario' => $valorRecebidoIntermediario,
                    'desconto_incondicionado' => $descontoIncondicionado,
                    'desconto_condicionado' => $descontoCondicionado,
                    'tributacao_issqn' => $tributacaoIssqn,
                    'regime_especial_tributacao' => $regimeEspecialTributacao !== '' ? $regimeEspecialTributacao : null,
                    'exigibilidade_issqn_suspensa' => $exigibilidadeSuspensa,
                    'tipo_suspensao_issqn' => $tipoSuspensaoIssqn !== '' ? $tipoSuspensaoIssqn : null,
                    'numero_processo_suspensao' => $numeroProcessoSuspensao !== '' ? $numeroProcessoSuspensao : null,
                    'issqn_retido' => $issqnRetido,
                    'issqn_retido_por' => $issqnRetidoPor,
                    'percentual_retencao_issqn' => $percentualRetencaoIssqn,
                    'beneficio_municipal' => $beneficioMunicipal,
                    'codigo_beneficio_municipal' => $codigoBeneficioMunicipal !== '' ? $codigoBeneficioMunicipal : null,
                    'deducao_reducao_base_calculo' => $deducaoReducaoBase,
                    'situacao_tributaria_pis_cofins' => $situacaoTributariaPisCofins !== '' ? $situacaoTributariaPisCofins : null,
                    'tipo_retencao_pis_cofins_csll' => $tipoRetencaoPisCofinsCsll !== '' ? $tipoRetencaoPisCofinsCsll : null,
                    'irrf' => $irrf,
                    'contribuicoes_sociais_retidas' => $contribuicoesSociaisRetidas,
                    'contribuicao_previdenciaria_retida' => $contribuicaoPrevidenciariaRetida,
                    'tributos_modo' => $tributosModo,
                    'tributos_federal_percentual' => $tributosFederalPercentual,
                    'tributos_estadual_percentual' => $tributosEstadualPercentual,
                    'tributos_municipal_percentual' => $tributosMunicipalPercentual,
                    'tributos_federal_valor' => $tributosFederalValor,
                    'tributos_estadual_valor' => $tributosEstadualValor,
                    'tributos_municipal_valor' => $tributosMunicipalValor,
                    'ibscbs_finalidade' => $ibscbsFinalidade,
                    'ibscbs_ind_final' => $ibscbsIndFinal !== '' ? (int) $ibscbsIndFinal : null,
                    'ibscbs_codigo_indicador_operacao' => $ibscbsCodigoIndicadorOperacao !== '' ? $ibscbsCodigoIndicadorOperacao : null,
                    'ibscbs_ind_destinatario' => $ibscbsIndDestinatario !== '' ? $ibscbsIndDestinatario : null,
                    'ibscbs_cst' => $ibscbsCst !== '' ? $ibscbsCst : null,
                    'ibscbs_classificacao_tributaria' => $ibscbsClassificacaoTributaria !== '' ? $ibscbsClassificacaoTributaria : null,
                ];
            } else {
                $numericoNfe = static function (string $valorBruto): float {
                    $valorBruto = trim($valorBruto);
                    if (str_contains($valorBruto, ',')) {
                        $valorBruto = str_replace('.', '', $valorBruto);
                        $valorBruto = str_replace(',', '.', $valorBruto);
                    }
                    return (float) $valorBruto;
                };

                $descricoes = $_POST['item_descricao'] ?? [];
                $ncms = $_POST['item_ncm'] ?? [];
                $cfops = $_POST['item_cfop'] ?? [];
                $csts = $_POST['item_cst'] ?? [];
                $unidades = $_POST['item_unidade'] ?? [];
                $quantidades = $_POST['item_quantidade'] ?? [];
                $valoresUnitarios = $_POST['item_valor_unitario'] ?? [];
                $produtoIds = $_POST['item_produto_id'] ?? [];
                $ceans = $_POST['item_cean'] ?? [];
                $icmsOrigens = $_POST['item_icms_origem'] ?? [];
                $icmsStBases = $_POST['item_icms_st_base_calculo'] ?? [];
                $icmsStAliquotas = $_POST['item_icms_st_aliquota'] ?? [];
                $ipiCsts = $_POST['item_ipi_cst'] ?? [];
                $ipiAliquotas = $_POST['item_ipi_aliquota'] ?? [];
                $pisAliquotas = $_POST['item_pis_aliquota'] ?? [];
                $cofinsAliquotas = $_POST['item_cofins_aliquota'] ?? [];
                $pisCsts = $_POST['item_pis_cst'] ?? [];
                $cofinsCsts = $_POST['item_cofins_cst'] ?? [];
                $cests = $_POST['item_cest'] ?? [];
                $cnpjsFabricante = $_POST['item_cnpj_fabricante'] ?? [];
                $indicadoresEscalaRelevante = $_POST['item_indicador_escala_relevante'] ?? [];
                $codigosBeneficioFiscal = $_POST['item_codigo_beneficio_fiscal'] ?? [];
                $ibscbsCsts = $_POST['item_ibscbs_cst'] ?? [];
                $ibscbsCclasstribs = $_POST['item_ibscbs_cclasstrib'] ?? [];
                $ibscbsBases = $_POST['item_ibscbs_base_calculo'] ?? [];
                $ibsUfAliquotas = $_POST['item_ibs_uf_aliquota'] ?? [];
                $ibsMunAliquotas = $_POST['item_ibs_mun_aliquota'] ?? [];
                $cbsAliquotas = $_POST['item_cbs_aliquota'] ?? [];

                foreach ($descricoes as $indice => $descricaoItem) {
                    $descricaoItem = trim((string) $descricaoItem);
                    $quantidade = $numericoNfe((string) ($quantidades[$indice] ?? '0'));
                    $valorUnitario = $numericoNfe((string) ($valoresUnitarios[$indice] ?? '0'));

                    if ($descricaoItem === '' || $quantidade <= 0) {
                        continue;
                    }

                    $valorTotalItem = round($quantidade * $valorUnitario, 2);
                    $valorTotalNota += $valorTotalItem;

                    $itensValidos[] = [
                        'produto_servico_id' => (int) ($produtoIds[$indice] ?? 0) > 0 ? (int) $produtoIds[$indice] : null,
                        'descricao' => $descricaoItem,
                        'ncm' => preg_replace('/\D+/', '', (string) ($ncms[$indice] ?? '')) ?: null,
                        'cfop' => trim((string) ($cfops[$indice] ?? '')) ?: null,
                        'cst_csosn' => trim((string) ($csts[$indice] ?? '')) ?: null,
                        'codigo_servico_municipal' => null,
                        'unidade' => trim((string) ($unidades[$indice] ?? '')) ?: 'UN',
                        'quantidade' => $quantidade,
                        'valor_unitario' => $valorUnitario,
                        'valor_total' => $valorTotalItem,
                        'cean' => trim((string) ($ceans[$indice] ?? '')) ?: null,
                        'icms_origem' => (string) ($icmsOrigens[$indice] ?? '0'),
                        'icms_st_base_calculo' => $numericoNfe((string) ($icmsStBases[$indice] ?? '0')),
                        'icms_st_aliquota' => $numericoNfe((string) ($icmsStAliquotas[$indice] ?? '0')),
                        'ipi_cst' => trim((string) ($ipiCsts[$indice] ?? '')),
                        'ipi_aliquota' => $numericoNfe((string) ($ipiAliquotas[$indice] ?? '0')),
                        'icms_aliquota' => $numericoNfe((string) ($_POST['item_icms_aliquota'][$indice] ?? '0')),
                        'pis_aliquota' => $numericoNfe((string) ($pisAliquotas[$indice] ?? '0')),
                        'cofins_aliquota' => $numericoNfe((string) ($cofinsAliquotas[$indice] ?? '0')),
                        'pis_cst' => trim((string) ($pisCsts[$indice] ?? '')),
                        'cofins_cst' => trim((string) ($cofinsCsts[$indice] ?? '')),
                        'cest' => trim((string) ($cests[$indice] ?? '')) ?: null,
                        'cnpj_fabricante' => preg_replace('/\D+/', '', (string) ($cnpjsFabricante[$indice] ?? '')) ?: null,
                        'indicador_escala_relevante' => in_array($indicadoresEscalaRelevante[$indice] ?? '', ['S', 'N'], true) ? $indicadoresEscalaRelevante[$indice] : null,
                        'codigo_beneficio_fiscal' => trim((string) ($codigosBeneficioFiscal[$indice] ?? '')) ?: null,
                        'ibscbs_cst' => trim((string) ($ibscbsCsts[$indice] ?? '')),
                        'ibscbs_cclasstrib' => trim((string) ($ibscbsCclasstribs[$indice] ?? '')),
                        'ibscbs_base_calculo' => $numericoNfe((string) ($ibscbsBases[$indice] ?? '0')),
                        'ibs_uf_aliquota' => $numericoNfe((string) ($ibsUfAliquotas[$indice] ?? '0')),
                        'ibs_mun_aliquota' => $numericoNfe((string) ($ibsMunAliquotas[$indice] ?? '0')),
                        'cbs_aliquota' => $numericoNfe((string) ($cbsAliquotas[$indice] ?? '0')),
                    ];
                }

                $dadosNfe = [
                    'finalidade_emissao' => in_array($_POST['nfe_finalidade_emissao'] ?? '', ['normal', 'complementar', 'ajuste', 'devolucao'], true) ? $_POST['nfe_finalidade_emissao'] : 'normal',
                    'indicador_presenca' => (int) ($_POST['nfe_indicador_presenca'] ?? 9),
                    'nfe_referenciada' => trim((string) ($_POST['nfe_referenciada'] ?? '')) !== '' ? preg_replace('/\D+/', '', $_POST['nfe_referenciada']) : null,
                    'modalidade_frete' => (int) ($_POST['nfe_modalidade_frete'] ?? 9),
                    'transportador_nome' => trim((string) ($_POST['nfe_transportador_nome'] ?? '')) ?: null,
                    'transportador_cnpj_cpf' => trim((string) ($_POST['nfe_transportador_cnpj_cpf'] ?? '')) ?: null,
                    'transportador_ie' => trim((string) ($_POST['nfe_transportador_ie'] ?? '')) ?: null,
                    'transportador_endereco' => trim((string) ($_POST['nfe_transportador_endereco'] ?? '')) ?: null,
                    'transportador_municipio' => trim((string) ($_POST['nfe_transportador_municipio'] ?? '')) ?: null,
                    'transportador_uf' => trim((string) ($_POST['nfe_transportador_uf'] ?? '')) ?: null,
                    'veiculo_placa' => trim((string) ($_POST['nfe_veiculo_placa'] ?? '')) ?: null,
                    'veiculo_uf' => trim((string) ($_POST['nfe_veiculo_uf'] ?? '')) ?: null,
                    'veiculo_rntc' => trim((string) ($_POST['nfe_veiculo_rntc'] ?? '')) ?: null,
                    'volumes_quantidade' => trim((string) ($_POST['nfe_volumes_quantidade'] ?? '')) !== '' ? (int) $_POST['nfe_volumes_quantidade'] : null,
                    'volumes_especie' => trim((string) ($_POST['nfe_volumes_especie'] ?? '')) ?: null,
                    'volumes_marca' => trim((string) ($_POST['nfe_volumes_marca'] ?? '')) ?: null,
                    'volumes_numeracao' => trim((string) ($_POST['nfe_volumes_numeracao'] ?? '')) ?: null,
                    'volumes_peso_liquido' => trim((string) ($_POST['nfe_volumes_peso_liquido'] ?? '')) !== '' ? $numericoNfe($_POST['nfe_volumes_peso_liquido']) : null,
                    'volumes_peso_bruto' => trim((string) ($_POST['nfe_volumes_peso_bruto'] ?? '')) !== '' ? $numericoNfe($_POST['nfe_volumes_peso_bruto']) : null,
                    'forma_pagamento_codigo' => trim((string) ($_POST['nfe_forma_pagamento_codigo'] ?? '')) ?: '90',
                    'indicador_pagamento' => (int) ($_POST['nfe_indicador_pagamento'] ?? 0),
                    'valor_pago' => trim((string) ($_POST['nfe_valor_pago'] ?? '')) !== '' ? $numericoNfe($_POST['nfe_valor_pago']) : null,
                    'valor_troco' => trim((string) ($_POST['nfe_valor_troco'] ?? '')) !== '' ? $numericoNfe($_POST['nfe_valor_troco']) : 0,
                    'informacoes_complementares' => trim((string) ($_POST['nfe_informacoes_complementares'] ?? '')) ?: null,
                ];
            }

            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id AND ativo = 1 LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaId]);
            $empresaSelecionada = $stmtEmpresa->fetch() ?: null;
            $stmtCliente = $dbNotas->prepare('SELECT * FROM notas_clientes WHERE id = :id LIMIT 1');
            $stmtCliente->execute(['id' => $clienteId]);
            $clienteSelecionado = $stmtCliente->fetch() ?: null;
            $ambienteNota = ($empresaSelecionada['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';
            $erroDifalNfe = null;
            if ($tipoNota === 'nfe' && $empresaSelecionada !== null && $clienteSelecionado !== null) {
                $erroDifalNfe = nfeBloqueioDifal($empresaSelecionada, $clienteSelecionado);
                if ($erroDifalNfe === null) {
                    foreach ($itensValidos as &$itemNfe) {
                        $impostosItem = nfeCalcularImpostosItem(
                            $itemNfe,
                            $empresaSelecionada,
                            (string) ($itemNfe['cfop'] ?? ''),
                            (string) ($clienteSelecionado['uf'] ?? '')
                        );
                        $itemNfe = array_merge($itemNfe, $impostosItem);
                    }
                    unset($itemNfe);
                }
            }
            if ($tipoNota === 'nfse' && $dadosNfse !== null && $empresaSelecionada !== null) {
                $dadosNfse['tributacao_issqn'] = (string) ($empresaSelecionada['nfse_tributacao_issqn'] ?? '');
                $dadosNfse['regime_especial_tributacao'] = trim((string) ($empresaSelecionada['nfse_regime_especial_tributacao'] ?? '')) ?: null;
                $dadosNfse['imune_exportacao_nao_incidencia'] = in_array($dadosNfse['tributacao_issqn'], ['imune', 'exportacao', 'nao_incidencia'], true) ? 'sim' : 'nao';
            }
            $documentoCliente = preg_replace('/\D+/', '', (string) ($clienteSelecionado['cnpj_cpf'] ?? ''));
            $camposIbsCbs = $dadosNfse ? [$dadosNfse['ibscbs_ind_final'], $dadosNfse['ibscbs_codigo_indicador_operacao'], $dadosNfse['ibscbs_ind_destinatario'], $dadosNfse['ibscbs_cst'], $dadosNfse['ibscbs_classificacao_tributaria']] : [];
            $algumIbsCbsInformado = count(array_filter($camposIbsCbs, static fn($valor) => $valor !== null && $valor !== '')) > 0;
            $ibscbsCompleto = $dadosNfse && in_array($dadosNfse['ibscbs_ind_final'], [0, 1], true) && $dadosNfse['ibscbs_codigo_indicador_operacao'] !== null && $dadosNfse['ibscbs_ind_destinatario'] !== null && $dadosNfse['ibscbs_cst'] !== null && $dadosNfse['ibscbs_classificacao_tributaria'] !== null;
            $ibscbsObrigatorio = $tipoNota === 'nfse' && (int) ($empresaSelecionada['nfse_opcao_simples_nacional'] ?? 0) === 1 && ($dadosNfse['data_competencia'] ?? '') >= '2026-08-03';
            $cindopOficial = $dadosNfse && $dadosNfse['ibscbs_codigo_indicador_operacao'] !== null ? itemCatalogoIbsCbs('cindop', (string) $dadosNfse['ibscbs_codigo_indicador_operacao']) : null;
            $cclassOficial = $dadosNfse && $dadosNfse['ibscbs_classificacao_tributaria'] !== null ? itemCatalogoIbsCbs('cclass', (string) $dadosNfse['ibscbs_classificacao_tributaria']) : null;
            $opcoesNbsOficiais = $dadosNfse ? opcoesNbsPorCodigoTributacao((string) ($dadosNfse['codigo_tributacao_nacional'] ?? '')) : [];
            $codigosNbsPermitidos = array_column($opcoesNbsOficiais, 'codigo');

            if ($empresaId <= 0 || $empresaSelecionada === null) {
                $erro = 'Selecione uma empresa emissora ativa.';
            } elseif ($tipoNota === 'nfse' && (!documentoNfseValido((string) ($empresaSelecionada['cnpj'] ?? '')) || !preg_match('/^\d{7}$/', (string) ($empresaSelecionada['codigo_ibge_municipio'] ?? '')) || empty($empresaSelecionada['inscricao_municipal']) || empty($empresaSelecionada['logradouro']) || empty($empresaSelecionada['numero']) || empty($empresaSelecionada['bairro']) || empty($empresaSelecionada['cep']))) {
                $erro = 'Complete CNPJ, Inscrição Municipal, endereço e código IBGE da empresa emissora antes de emitir NFS-e.';
            } elseif ($tipoNota === 'nfse' && !in_array((int) ($empresaSelecionada['nfse_opcao_simples_nacional'] ?? 0), [1, 2, 3, 4], true)) {
                $erro = 'Configure a opção pelo Simples Nacional da empresa emissora.';
            } elseif ($tipoNota === 'nfse' && (int) $empresaSelecionada['nfse_opcao_simples_nacional'] === 3 && !in_array((int) ($empresaSelecionada['nfse_regime_apuracao_sn'] ?? 0), [1, 2, 3], true)) {
                $erro = 'Configure o regime de apuração do Simples para a empresa ME/EPP.';
            } elseif ($tipoNota === 'nfse' && !in_array((string) ($empresaSelecionada['nfse_tributacao_issqn'] ?? ''), ['operacao_tributavel', 'imune', 'exportacao', 'nao_incidencia'], true)) {
                $erro = 'Configure a tributação municipal padrão da empresa emissora.';
            } elseif ($tipoNota === 'nfse' && !in_array((string) ($empresaSelecionada['nfse_regime_especial_tributacao'] ?? ''), ['', 'cooperativa', 'estimativa', 'microempresa_municipal', 'notario_registrador', 'profissional_autonomo', 'sociedade_profissionais'], true)) {
                $erro = 'Configure o regime especial de tributação da empresa emissora.';
            } elseif ($tipoNota === 'nfse' && in_array((int) ($empresaSelecionada['nfse_opcao_simples_nacional'] ?? 0), [2, 3], true) && !empty($empresaSelecionada['nfse_regime_especial_tributacao'])) {
                $erro = 'Remova o regime especial da empresa: o Simples Nacional prevalece para MEI e ME/EPP.';
            } elseif ($clienteId <= 0 || $clienteSelecionado === null) {
                $erro = 'Selecione um cliente destinatário válido.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['tomador_local'] !== 'exterior' && !documentoNfseValido($documentoCliente)) {
                $erro = 'O tomador brasileiro precisa ter CPF ou CNPJ válido no cadastro.';
            } elseif ($tipoNota === 'nfse' && ($clienteSelecionado['nome_razao_social'] ?? '') === '') {
                $erro = 'O tomador precisa ter nome ou razão social.';
            } elseif ($naturezaOperacao === '') {
                $erro = 'Informe a natureza da operação.';
            } elseif ($tipoNota === 'nfse' && ($dadosNfse === null || $dadosNfse['municipio_prestacao'] === null || $dadosNfse['codigo_tributacao_nacional'] === null || $dadosNfse['codigo_interno_contribuinte'] === null)) {
                $erro = 'Informe o município da prestação, o código de tributação nacional e o código interno do contribuinte do serviço.';

            } elseif ($tipoNota === 'nfse' && !codigoMunicipioIbgeValido((string) $dadosNfse['municipio_prestacao'])) {
                $erro = 'Selecione um município válido na lista oficial do IBGE.';
            } elseif ($tipoNota === 'nfse' && $opcoesNbsOficiais !== [] && $dadosNfse['item_nbs'] === null) {
                $erro = 'Selecione a NBS oficial correspondente ao serviço prestado.';
            } elseif ($tipoNota === 'nfse' && $opcoesNbsOficiais !== [] && !in_array((string) $dadosNfse['item_nbs'], $codigosNbsPermitidos, true)) {
                $erro = 'A NBS informada não corresponde ao serviço na correlação oficial da NFS-e.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['intermediario_incluido'] && (!documentoNfseValido((string) $dadosNfse['intermediario_cpf_cnpj']) || $dadosNfse['intermediario_nome'] === null)) {
                $erro = 'Intermediário informado exige CPF/CNPJ válido e nome/razão social.';
            } elseif ($tipoNota === 'nfse' && ((float) ($dadosNfse['desconto_incondicionado'] ?? 0) + (float) ($dadosNfse['desconto_condicionado'] ?? 0) > $valorTotalNota)) {
                $erro = 'A soma dos descontos não pode superar o valor bruto do serviço.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['tomador_local'] === 'brasil' && !preg_match('/^\d{7}$/', (string) ($clienteSelecionado['codigo_ibge_municipio'] ?? ''))) {
                $erro = 'Informe o código IBGE do município no cadastro do tomador brasileiro.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['tomador_local'] === 'exterior' && (empty($clienteSelecionado['codigo_pais']) || (empty($clienteSelecionado['nif']) && empty($clienteSelecionado['motivo_nao_nif'])))) {
                $erro = 'Tomador exterior exige código do país e NIF ou motivo oficial da ausência de NIF.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['exigibilidade_issqn_suspensa'] === 'sim' && ($dadosNfse['tipo_suspensao_issqn'] === null || $dadosNfse['numero_processo_suspensao'] === null)) {
                $erro = 'Exigibilidade suspensa exige tipo e número do processo de suspensão.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['beneficio_municipal'] === 'sim' && $dadosNfse['codigo_beneficio_municipal'] === null) {
                $erro = 'Benefício municipal exige o código oficial do benefício.';
            } elseif ($tipoNota === 'nfse' && (($dadosNfse['serie_dps'] === null) !== ($dadosNfse['numero_dps'] === null))) {
                $erro = 'Informe juntos a série e o número da DPS, ou deixe ambos em branco.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['serie_dps'] !== null && !preg_match('/^[0-9]{1,5}$/D', (string) $dadosNfse['serie_dps'])) {
                $erro = 'A série da DPS deve conter de 1 a 5 dígitos.';
            } elseif ($tipoNota === 'nfse' && $dadosNfse['numero_dps'] !== null && !preg_match('/^[0-9]{1,15}$/D', (string) $dadosNfse['numero_dps'])) {
                $erro = 'O número da DPS deve conter de 1 a 15 dígitos.';
            } elseif ($tipoNota === 'nfse' && ($ibscbsObrigatorio || $algumIbsCbsInformado) && !preg_match('/^[0-9]{9}$/D', (string) ($dadosNfse['item_nbs'] ?? ''))) {
                $erro = 'Com IBS/CBS, informe o código NBS completo com 9 dígitos.';
            } elseif ($tipoNota === 'nfse' && ($ibscbsObrigatorio || $algumIbsCbsInformado) && !$ibscbsCompleto) {
                $erro = 'Preencha o conjunto completo IBS/CBS selecionando os códigos nas tabelas oficiais.';
            } elseif ($tipoNota === 'nfse' && ($ibscbsObrigatorio || $algumIbsCbsInformado) && $cindopOficial === null) {
                $erro = 'Selecione um código indicador da operação (cIndOp) válido na tabela oficial da NFS-e.';
            } elseif ($tipoNota === 'nfse' && ($ibscbsObrigatorio || $algumIbsCbsInformado) && $cclassOficial === null) {
                $erro = 'Selecione uma classificação tributária (cClassTrib) vigente e permitida para NFS-e.';
            } elseif ($tipoNota === 'nfse' && $cclassOficial !== null && (string) $dadosNfse['ibscbs_cst'] !== (string) ($cclassOficial['cst'] ?? '')) {
                $erro = 'O CST IBS/CBS não corresponde à classificação tributária selecionada.';
            } elseif ($tipoNota === 'nfe' && (empty($empresaSelecionada['cnpj']) || empty($empresaSelecionada['uf']) || !preg_match('/^\d{7}$/', (string) ($empresaSelecionada['codigo_ibge_municipio'] ?? '')) || empty($empresaSelecionada['inscricao_estadual']) || empty($empresaSelecionada['logradouro']) || empty($empresaSelecionada['numero']) || empty($empresaSelecionada['bairro']) || empty($empresaSelecionada['cep']))) {
                $erro = 'Complete CNPJ, Inscrição Estadual, endereço, UF e código IBGE da empresa emissora antes de emitir NF-e.';
            } elseif ($tipoNota === 'nfe' && !in_array((int) ($empresaSelecionada['crt'] ?? 0), [1, 2, 3], true)) {
                $erro = 'Configure o regime tributário (CRT) da empresa emissora antes de emitir NF-e.';
            } elseif ($tipoNota === 'nfe' && empty($clienteSelecionado['uf'])) {
                $erro = 'Informe a UF do cliente destinatário antes de emitir NF-e.';
            } elseif ($tipoNota === 'nfe' && (empty($clienteSelecionado['logradouro']) || empty($clienteSelecionado['numero']) || empty($clienteSelecionado['bairro']) || empty($clienteSelecionado['municipio']) || !preg_match('/^\d{7}$/', (string) ($clienteSelecionado['codigo_ibge_municipio'] ?? '')) || empty($clienteSelecionado['cep']))) {
                $erro = 'Complete o endereço do cliente destinatário (logradouro, número, bairro, município, código IBGE e CEP) antes de emitir NF-e.';
            } elseif ($tipoNota === 'nfe' && $erroDifalNfe !== null) {
                $erro = $erroDifalNfe;
            } elseif (empty($itensValidos)) {
                $erro = $tipoNota === 'nfse'
                    ? 'Informe a descrição do serviço e um valor do serviço maior que zero.'
                    : 'Adicione ao menos um item com descrição e quantidade maior que zero.';
            } elseif ($tipoNota === 'nfe' && count(array_filter($itensValidos, static fn($item) => empty($item['cfop']) || empty($item['cst_csosn']) || empty($item['ncm']) || !preg_match('/^\d{8}$/', (string) $item['ncm']))) > 0) {
                $erro = 'Todo item precisa de NCM (8 dígitos), CFOP e CST/CSOSN preenchidos.';
            } else {
                $lockEdicaoAdquirido = false;
                try {
                    if ($salvandoEdicao) {
                        $lockEdicaoAdquirido = obterLockEdicaoNota($dbNotas, $notaEdicaoId);
                        if (!$lockEdicaoAdquirido) throw new RuntimeException('A nota está sendo processada. Aguarde e tente novamente.');
                    }
                    $dbNotas->beginTransaction();
                    if ($salvandoEdicao) {
                        $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais WHERE id = :id FOR UPDATE');
                        $stmt->execute(['id' => $notaEdicaoId]);
                        $notaBloqueada = $stmt->fetch();
                        if (!$notaBloqueada || !in_array($notaBloqueada['status'], ['rascunho', 'rejeitada'], true)) {
                            throw new RuntimeException('A nota mudou de estado e não pode mais ser editada.');
                        }
                        if (!$podeAdministrar && (int) $notaBloqueada['funcionario_id'] !== $funcionarioId) {
                            throw new RuntimeException('Você não tem permissão para editar esta nota.');
                        }
                        if (!hash_equals((string) $notaBloqueada['atualizado_em'], (string) ($_POST['nota_atualizada_em'] ?? ''))) {
                            throw new RuntimeException('A nota foi alterada em outra tela. Reabra a edição para não sobrescrever dados.');
                        }
                        $notaId = $notaEdicaoId;
                        $numeroInterno = (int) $notaBloqueada['numero_interno'];
                        $stmt = $dbNotas->prepare('UPDATE notas_fiscais SET cliente_id = :cliente_id, natureza_operacao = :natureza_operacao, status = \'rascunho\', forma_pagamento = :forma_pagamento, data_emissao = :data_emissao, data_saida_entrada = :data_saida_entrada, valor_total = :valor_total, informacoes_frete = :informacoes_frete, chave_acesso = NULL, protocolo_autorizacao = NULL, xml_gerado = NULL, motivo_rejeicao = NULL WHERE id = :id');
                        $stmt->execute(['cliente_id' => $clienteId, 'natureza_operacao' => $naturezaOperacao, 'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null, 'data_emissao' => $dataEmissao, 'data_saida_entrada' => $dataSaidaEntrada !== '' ? $dataSaidaEntrada : null, 'valor_total' => round($valorTotalNota, 2), 'informacoes_frete' => $informacoesFrete !== '' ? $informacoesFrete : null, 'id' => $notaId]);
                        $dbNotas->prepare('DELETE FROM notas_fiscais_itens WHERE nota_id = :nota_id')->execute(['nota_id' => $notaId]);
                        $dbNotas->prepare('DELETE FROM notas_fiscais_nfse WHERE nota_id = :nota_id')->execute(['nota_id' => $notaId]);
                        $dbNotas->prepare('DELETE FROM notas_fiscais_nfe WHERE nota_id = :nota_id')->execute(['nota_id' => $notaId]);
                    } else {
                    $serieNota = $tipoNota === 'nfe' ? (string) ($empresaSelecionada['nfe_serie'] ?? '1') : '1';
                    $stmt = $dbNotas->prepare(
                        'SELECT COALESCE(MAX(numero_interno), 0) FROM notas_fiscais
                         WHERE empresa_emissora_id = :empresa_id AND tipo_nota = :tipo_nota AND serie = :serie FOR UPDATE'
                    );
                    $stmt->execute(['empresa_id' => $empresaId, 'tipo_nota' => $tipoNota, 'serie' => $serieNota]);
                    $maxNumeroInternoAtual = (int) $stmt->fetchColumn();
                    // nfe_numero_base permite ao admin "avancar" a numeracao manualmente (ex.: notas ja
                    // emitidas fora do sistema); nunca reduz o proximo numero, so serve de piso.
                    $numeroBaseManual = $tipoNota === 'nfe' ? (int) ($empresaSelecionada['nfe_numero_base'] ?? 0) : 0;
                    $numeroInterno = max($maxNumeroInternoAtual, $numeroBaseManual) + 1;

                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais (
                            empresa_emissora_id, cliente_id, funcionario_id, tipo_nota, natureza_operacao,
                            numero_interno, serie, status, ambiente, forma_pagamento, data_emissao, data_saida_entrada,
                            valor_total, informacoes_frete
                         ) VALUES (
                            :empresa_id, :cliente_id, :funcionario_id, :tipo_nota, :natureza_operacao,
                            :numero_interno, :serie, \'rascunho\', :ambiente, :forma_pagamento, :data_emissao, :data_saida_entrada,
                            :valor_total, :informacoes_frete
                         )'
                    );
                    $stmt->execute([
                        'empresa_id' => $empresaId,
                        'cliente_id' => $clienteId,
                        'funcionario_id' => $funcionarioId,
                        'tipo_nota' => $tipoNota,
                        'natureza_operacao' => $naturezaOperacao,
                        'numero_interno' => $numeroInterno,
                        'serie' => $serieNota,
                        'ambiente' => $ambienteNota,
                        'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
                        'data_emissao' => $dataEmissao,
                        'data_saida_entrada' => $dataSaidaEntrada !== '' ? $dataSaidaEntrada : null,
                        'valor_total' => round($valorTotalNota, 2),
                        'informacoes_frete' => $informacoesFrete !== '' ? $informacoesFrete : null,
                    ]);
                    $notaId = (int) $dbNotas->lastInsertId();
                    }

                    $stmtItem = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais_itens (
                            nota_id, produto_servico_id, descricao, ncm, cfop, cst_csosn, codigo_servico_municipal, unidade,
                            quantidade, valor_unitario, valor_total, cean, cest, cnpj_fabricante, indicador_escala_relevante,
                            codigo_beneficio_fiscal, icms_origem, icms_modalidade_bc, icms_base_calculo,
                            icms_aliquota, icms_valor, icms_st_modalidade_bc, icms_st_aliquota, icms_st_base_calculo, icms_st_valor,
                            ipi_cst, ipi_base_calculo, ipi_aliquota, ipi_valor, pis_cst, pis_base_calculo, pis_aliquota, pis_valor,
                            cofins_cst, cofins_base_calculo, cofins_aliquota, cofins_valor,
                            ibscbs_cst, ibscbs_cclasstrib, ibscbs_base_calculo, ibs_uf_aliquota, ibs_uf_valor,
                            ibs_mun_aliquota, ibs_mun_valor, cbs_aliquota, cbs_valor
                         ) VALUES (
                            :nota_id, :produto_servico_id, :descricao, :ncm, :cfop, :cst_csosn, :codigo_servico_municipal, :unidade,
                            :quantidade, :valor_unitario, :valor_total, :cean, :cest, :cnpj_fabricante, :indicador_escala_relevante,
                            :codigo_beneficio_fiscal, :icms_origem, :icms_modalidade_bc, :icms_base_calculo,
                            :icms_aliquota, :icms_valor, :icms_st_modalidade_bc, :icms_st_aliquota, :icms_st_base_calculo, :icms_st_valor,
                            :ipi_cst, :ipi_base_calculo, :ipi_aliquota, :ipi_valor, :pis_cst, :pis_base_calculo, :pis_aliquota, :pis_valor,
                            :cofins_cst, :cofins_base_calculo, :cofins_aliquota, :cofins_valor,
                            :ibscbs_cst, :ibscbs_cclasstrib, :ibscbs_base_calculo, :ibs_uf_aliquota, :ibs_uf_valor,
                            :ibs_mun_aliquota, :ibs_mun_valor, :cbs_aliquota, :cbs_valor
                         )'
                    );
                    foreach ($itensValidos as $item) {
                        $stmtItem->execute([
                            'nota_id' => $notaId,
                            'produto_servico_id' => $item['produto_servico_id'],
                            'descricao' => $item['descricao'],
                            'ncm' => $item['ncm'],
                            'cfop' => $item['cfop'],
                            'cst_csosn' => $item['cst_csosn'],
                            'cean' => $item['cean'] ?? null,
                            'cest' => $item['cest'] ?? null,
                            'cnpj_fabricante' => $item['cnpj_fabricante'] ?? null,
                            'indicador_escala_relevante' => $item['indicador_escala_relevante'] ?? null,
                            'codigo_beneficio_fiscal' => $item['codigo_beneficio_fiscal'] ?? null,
                            'icms_origem' => $item['icms_origem'] ?? null,
                            'icms_modalidade_bc' => $item['icms_modalidade_bc'] ?? null,
                            'icms_base_calculo' => $item['icms_base_calculo'] ?? null,
                            'icms_aliquota' => $item['icms_aliquota'] ?? null,
                            'icms_valor' => $item['icms_valor'] ?? null,
                            'icms_st_modalidade_bc' => $item['icms_st_modalidade_bc'] ?? null,
                            'icms_st_aliquota' => $item['icms_st_aliquota'] ?? null,
                            'icms_st_base_calculo' => $item['icms_st_base_calculo'] ?? null,
                            'icms_st_valor' => $item['icms_st_valor'] ?? null,
                            'ipi_cst' => $item['ipi_cst'] ?? null,
                            'ipi_base_calculo' => $item['ipi_base_calculo'] ?? null,
                            'ipi_aliquota' => $item['ipi_aliquota'] ?? null,
                            'ipi_valor' => $item['ipi_valor'] ?? null,
                            'pis_cst' => $item['pis_cst'] ?? null,
                            'pis_base_calculo' => $item['pis_base_calculo'] ?? null,
                            'pis_aliquota' => $item['pis_aliquota'] ?? null,
                            'pis_valor' => $item['pis_valor'] ?? null,
                            'cofins_cst' => $item['cofins_cst'] ?? null,
                            'cofins_base_calculo' => $item['cofins_base_calculo'] ?? null,
                            'cofins_aliquota' => $item['cofins_aliquota'] ?? null,
                            'cofins_valor' => $item['cofins_valor'] ?? null,
                            'ibscbs_cst' => $item['ibscbs_cst'] ?? null,
                            'ibscbs_cclasstrib' => $item['ibscbs_cclasstrib'] ?? null,
                            'ibscbs_base_calculo' => $item['ibscbs_base_calculo'] ?? null,
                            'ibs_uf_aliquota' => $item['ibs_uf_aliquota'] ?? null,
                            'ibs_uf_valor' => $item['ibs_uf_valor'] ?? null,
                            'ibs_mun_aliquota' => $item['ibs_mun_aliquota'] ?? null,
                            'ibs_mun_valor' => $item['ibs_mun_valor'] ?? null,
                            'cbs_aliquota' => $item['cbs_aliquota'] ?? null,
                            'cbs_valor' => $item['cbs_valor'] ?? null,
                            'codigo_servico_municipal' => $item['codigo_servico_municipal'],
                            'unidade' => $item['unidade'],
                            'quantidade' => $item['quantidade'],
                            'valor_unitario' => $item['valor_unitario'],
                            'valor_total' => $item['valor_total'],
                        ]);
                    }

                    if ($tipoNota === 'nfse' && $dadosNfse !== null) {
                        $stmtNfse = $dbNotas->prepare(
                            'INSERT INTO notas_fiscais_nfse (
                                nota_id, data_competencia, serie_dps, numero_dps, tomador_local, tomador_inscricao_municipal, tomador_telefone,
                                intermediario_incluido, intermediario_local, intermediario_cpf_cnpj, intermediario_nome,
                                pais_prestacao, municipio_prestacao, codigo_tributacao_nacional, codigo_tributacao_municipal, codigo_interno_contribuinte,
                                imune_exportacao_nao_incidencia, item_nbs, descricao_servico, documento_responsabilidade_tecnica,
                                documento_referencia, informacoes_complementares, numero_pedido_b2b, valor_recebido_intermediario,
                                desconto_incondicionado, desconto_condicionado, tributacao_issqn, regime_especial_tributacao,
                                exigibilidade_issqn_suspensa, tipo_suspensao_issqn, numero_processo_suspensao, issqn_retido, issqn_retido_por, percentual_retencao_issqn, beneficio_municipal, codigo_beneficio_municipal, deducao_reducao_base_calculo,
                                situacao_tributaria_pis_cofins, tipo_retencao_pis_cofins_csll, irrf, contribuicoes_sociais_retidas,
                                contribuicao_previdenciaria_retida, tributos_modo, tributos_federal_percentual, tributos_estadual_percentual,
                                tributos_municipal_percentual, tributos_federal_valor, tributos_estadual_valor, tributos_municipal_valor,
                                ibscbs_finalidade, ibscbs_ind_final, ibscbs_codigo_indicador_operacao, ibscbs_ind_destinatario,
                                ibscbs_cst, ibscbs_classificacao_tributaria
                             ) VALUES (
                                :nota_id, :data_competencia, :serie_dps, :numero_dps, :tomador_local, :tomador_inscricao_municipal, :tomador_telefone,
                                :intermediario_incluido, :intermediario_local, :intermediario_cpf_cnpj, :intermediario_nome,
                                :pais_prestacao, :municipio_prestacao, :codigo_tributacao_nacional, :codigo_tributacao_municipal, :codigo_interno_contribuinte,
                                :imune_exportacao_nao_incidencia, :item_nbs, :descricao_servico, :documento_responsabilidade_tecnica,
                                :documento_referencia, :informacoes_complementares, :numero_pedido_b2b, :valor_recebido_intermediario,
                                :desconto_incondicionado, :desconto_condicionado, :tributacao_issqn, :regime_especial_tributacao,
                                :exigibilidade_issqn_suspensa, :tipo_suspensao_issqn, :numero_processo_suspensao, :issqn_retido, :issqn_retido_por, :percentual_retencao_issqn, :beneficio_municipal, :codigo_beneficio_municipal, :deducao_reducao_base_calculo,
                                :situacao_tributaria_pis_cofins, :tipo_retencao_pis_cofins_csll, :irrf, :contribuicoes_sociais_retidas,
                                :contribuicao_previdenciaria_retida, :tributos_modo, :tributos_federal_percentual, :tributos_estadual_percentual,
                                :tributos_municipal_percentual, :tributos_federal_valor, :tributos_estadual_valor, :tributos_municipal_valor,
                                :ibscbs_finalidade, :ibscbs_ind_final, :ibscbs_codigo_indicador_operacao, :ibscbs_ind_destinatario,
                                :ibscbs_cst, :ibscbs_classificacao_tributaria
                             )'
                        );
                        $stmtNfse->execute(array_merge(['nota_id' => $notaId], $dadosNfse));

                        if ($dadosNfse['tomador_inscricao_municipal'] !== null) {
                            $dbNotas->prepare('UPDATE notas_clientes SET inscricao_municipal = :inscricao_municipal WHERE id = :cliente_id')
                                ->execute([
                                    'inscricao_municipal' => $dadosNfse['tomador_inscricao_municipal'],
                                    'cliente_id' => $clienteId,
                                ]);
                        }
                    }

                    if ($tipoNota === 'nfe' && $dadosNfe !== null) {
                        $stmtNfe = $dbNotas->prepare(
                            'INSERT INTO notas_fiscais_nfe (
                                nota_id, finalidade_emissao, indicador_presenca, nfe_referenciada, modalidade_frete,
                                transportador_nome, transportador_cnpj_cpf, transportador_ie, transportador_endereco,
                                transportador_municipio, transportador_uf, veiculo_placa, veiculo_uf, veiculo_rntc,
                                volumes_quantidade, volumes_especie, volumes_marca, volumes_numeracao, volumes_peso_liquido,
                                volumes_peso_bruto, forma_pagamento_codigo, indicador_pagamento, valor_pago, valor_troco,
                                informacoes_complementares
                             ) VALUES (
                                :nota_id, :finalidade_emissao, :indicador_presenca, :nfe_referenciada, :modalidade_frete,
                                :transportador_nome, :transportador_cnpj_cpf, :transportador_ie, :transportador_endereco,
                                :transportador_municipio, :transportador_uf, :veiculo_placa, :veiculo_uf, :veiculo_rntc,
                                :volumes_quantidade, :volumes_especie, :volumes_marca, :volumes_numeracao, :volumes_peso_liquido,
                                :volumes_peso_bruto, :forma_pagamento_codigo, :indicador_pagamento, :valor_pago, :valor_troco,
                                :informacoes_complementares
                             )'
                        );
                        $stmtNfe->execute(array_merge(['nota_id' => $notaId], $dadosNfe));
                    }

                    registrarLogNota($dbNotas, $notaId, $funcionarioId, $salvandoEdicao ? 'editada' : 'criada', ($salvandoEdicao ? 'Correção salva; nota retornou a rascunho com ' : 'Rascunho criado com ') . count($itensValidos) . ' item(ns).');

                    $dbNotas->commit();
                    if ($salvandoEdicao) {
                        $sucesso = 'Correções salvas na nota nº ' . $numeroInterno . '. Ela voltou para rascunho e pode ser marcada como pronta para envio.';
                        $notaEmEdicao = null;
                        $nfseEmEdicao = null;
                        $nfeEmEdicao = null;
                        $itensEmEdicao = [];
                    } else {
                        $sucesso = 'Nota salva como rascunho (nº interno ' . $numeroInterno . '). Veja em "Notas fiscais" para gerar o PDF ou marcar como pronta para envio.';
                    }
                } catch (Throwable $e) {
                    if ($dbNotas->inTransaction()) $dbNotas->rollBack();
                    $erro = 'Não foi possível salvar a nota: ' . $e->getMessage();
                } finally {
                    if ($lockEdicaoAdquirido) liberarLockEdicaoNota($dbNotas, $notaEdicaoId);
                }
            }

            if ($erro !== '') {
                // Preserva o que o usuário digitou para não perder a nota inteira quando a validação falha.
                $dadosRestaurar = [
                    'nota' => [
                        'empresa_emissora_id' => $empresaId,
                        'cliente_id' => $clienteId,
                        'tipo_nota' => $tipoNota,
                        'natureza_operacao' => $naturezaOperacao,
                        'forma_pagamento' => $formaPagamento,
                        'data_emissao' => $dataEmissao,
                        'data_saida_entrada' => $dataSaidaEntrada,
                        'informacoes_frete' => $informacoesFrete,
                    ],
                    'nfse' => $dadosNfse,
                    'nfe' => $dadosNfe,
                    'itens' => $itensValidos,
                ];
            }
        }
    }

    $stmt = $dbNotas->query('SELECT id, razao_social, codigo_ibge_municipio, ambiente_emissao FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC');
    $empresasAtivas = $stmt->fetchAll();

    $stmt = $dbNotas->query('SELECT id, nome_razao_social, cnpj_cpf, municipio, codigo_ibge_municipio, codigo_pais, nif, motivo_nao_nif, uf, inscricao_municipal FROM notas_clientes ORDER BY nome_razao_social ASC');
    $clientes = $stmt->fetchAll();

    $stmt = $dbNotas->query(
        'SELECT id, empresa_emissora_id, tipo, descricao, ncm, cfop, cst_csosn, codigo_servico_municipal, unidade, valor_unitario_padrao,
                aliquota_icms, aliquota_pis, aliquota_cofins, aliquota_ipi, ipi_cst, cean,
                icms_origem, cest, cnpj_fabricante, indicador_escala_relevante, codigo_beneficio_fiscal
         FROM notas_produtos_servicos WHERE ativo = 1 ORDER BY descricao ASC'
    );
    $catalogo = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar dados para emissão: ' . $e->getMessage();
    $empresasAtivas = [];
    $clientes = [];
    $catalogo = [];
}

$csrf = h($_SESSION['csrf_notas_emitir'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
$catalogoJson = json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$edicaoJson = json_encode(['nota' => $notaEmEdicao, 'nfse' => $nfseEmEdicao, 'nfe' => $nfeEmEdicao, 'itens' => $itensEmEdicao], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{"nota":null,"nfse":null,"nfe":null,"itens":[]}';
$restaurarJson = json_encode($dadosRestaurar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
$codigosTributacaoNacionalNfse = obterCodigosTributacaoNacionalNfse();
$correlacaoNbsNfse = catalogoCorrelacaoNbsNfse();
$cfopCodigosNfe = catalogoCfopNfe();
