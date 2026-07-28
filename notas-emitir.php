<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfse-codigos-tributacao-nacional.php';
require_once __DIR__ . '/nfse-codigos-complementares-bh.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

$erro = '';
$sucesso = '';
$notaEmEdicao = null;
$nfseEmEdicao = null;
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
        $arquivo = __DIR__ . '/ibge-municipios.json';
        $municipios = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : [];
        $codigos = [];
        foreach (is_array($municipios) ? $municipios : [] as $municipio) {
            $codigos[(string) ($municipio['codigo'] ?? '')] = true;
        }
    }

    return isset($codigos[$codigo]);
}

function catalogoIbsCbsNfse(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $arquivo = __DIR__ . '/nfse-ibs-catalogos.json';
        $conteudo = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : [];
        $catalogo = is_array($conteudo) ? $conteudo : [];
    }

    return $catalogo;
}

function catalogoCorrelacaoNbsNfse(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $arquivo = __DIR__ . '/nfse-nbs-correlacao.json';
        $conteudo = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : [];
        $catalogo = is_array($conteudo) ? $conteudo : [];
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
    prepararTabelaEmpresasEmissorasNotas($dbNotas);
    semearEmpresasEmissorasNotas($dbNotas);
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

    prepararColunaPermiteNotasFiscais($db);

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
        $rejeicaoLocal = $candidataEdicao && $candidataEdicao['status'] === 'rejeitada'
            && str_starts_with((string) ($candidataEdicao['motivo_rejeicao'] ?? ''), 'DPS não transmitida:');
        if (!$candidataEdicao || $candidataEdicao['tipo_nota'] !== 'nfse' || !($candidataEdicao['status'] === 'rascunho' || $rejeicaoLocal)) {
            $erro = 'Esta nota não pode ser editada. Somente rascunhos e NFS-e rejeitadas antes da transmissão podem ser corrigidos.';
        } else {
            $notaEmEdicao = $candidataEdicao;
            $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfse WHERE nota_id = :nota_id LIMIT 1');
            $stmtEdicao->execute(['nota_id' => $notaEdicaoId]);
            $nfseEmEdicao = $stmtEdicao->fetch() ?: [];
            $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_fiscais_itens WHERE nota_id = :nota_id ORDER BY id ASC');
            $stmtEdicao->execute(['nota_id' => $notaEdicaoId]);
            $itensEmEdicao = $stmtEdicao->fetchAll();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_emitir'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'cadastrar_cliente') {
            $tipoPessoa = ($_POST['tipo_pessoa'] ?? 'PJ') === 'PF' ? 'PF' : 'PJ';
            $nomeRazaoSocial = trim($_POST['nome_razao_social'] ?? '');
            $cnpjCpf = trim($_POST['cnpj_cpf'] ?? '');
            $inscricaoEstadual = trim($_POST['cliente_inscricao_estadual'] ?? '');
            $email = trim($_POST['cliente_email'] ?? '');
            $logradouro = trim($_POST['cliente_logradouro'] ?? '');
            $numero = trim($_POST['cliente_numero'] ?? '');
            $complemento = trim($_POST['cliente_complemento'] ?? '');
            $bairro = trim($_POST['cliente_bairro'] ?? '');
            $cep = trim($_POST['cliente_cep'] ?? '');
            $municipio = trim($_POST['cliente_municipio'] ?? '');
            $codigoIbgeCliente = trim($_POST['cliente_codigo_ibge_municipio'] ?? '');
            $codigoPaisCliente = trim($_POST['cliente_codigo_pais'] ?? '1058');
            $nifCliente = trim($_POST['cliente_nif'] ?? '');
            $motivoNaoNifCliente = trim($_POST['cliente_motivo_nao_nif'] ?? '');
            $uf = strtoupper(trim($_POST['cliente_uf'] ?? ''));
            $consumidorFinal = isset($_POST['indicador_consumidor_final']) ? 1 : 0;

            $documentoClienteCadastro = preg_replace('/\D+/', '', $cnpjCpf);
            $tamanhoEsperado = $tipoPessoa === 'PF' ? 11 : 14;
            $clienteExterior = $codigoPaisCliente !== '' && $codigoPaisCliente !== '1058';
            if ($nomeRazaoSocial === '') {
                $erro = 'Informe o nome/razão social do cliente.';
            } elseif (!$clienteExterior && (strlen($documentoClienteCadastro) !== $tamanhoEsperado || !documentoNfseValido($documentoClienteCadastro))) {
                $erro = $tipoPessoa === 'PF' ? 'Informe um CPF válido.' : 'Informe um CNPJ válido.';
            } elseif ($clienteExterior && $nifCliente === '' && $motivoNaoNifCliente === '') {
                $erro = 'Cliente exterior exige NIF ou motivo oficial da ausência de NIF.';
            } elseif ($logradouro === '' || $numero === '' || $bairro === '' || $municipio === '') {
                $erro = 'Preencha o endereço do cliente.';
            } elseif (!$clienteExterior && (!preg_match('/^[A-Z]{2}$/', $uf) || !preg_match('/^\d{7}$/', $codigoIbgeCliente))) {
                $erro = 'Cliente nacional exige UF válida e código IBGE do município com 7 dígitos.';
            } elseif ($cep !== '' && strlen(preg_replace('/\D+/', '', $cep)) !== 8) {
                $erro = 'Informe um CEP válido, com 8 dígitos.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido para o cliente ou deixe em branco.';
            } else {
                $stmt = $dbNotas->prepare(
                    'INSERT INTO notas_clientes (
                        tipo_pessoa, nome_razao_social, cnpj_cpf, inscricao_estadual, email,
                        logradouro, numero, complemento, bairro, cep, municipio, codigo_ibge_municipio, codigo_pais, nif, motivo_nao_nif, uf,
                        indicador_consumidor_final, criado_por
                     ) VALUES (
                        :tipo_pessoa, :nome_razao_social, :cnpj_cpf, :inscricao_estadual, :email,
                        :logradouro, :numero, :complemento, :bairro, :cep, :municipio, :codigo_ibge_municipio, :codigo_pais, :nif, :motivo_nao_nif, :uf,
                        :indicador_consumidor_final, :criado_por
                     )'
                );
                $stmt->execute([
                    'tipo_pessoa' => $tipoPessoa,
                    'nome_razao_social' => $nomeRazaoSocial,
                    'cnpj_cpf' => $cnpjCpf !== '' ? $cnpjCpf : null,
                    'inscricao_estadual' => $inscricaoEstadual !== '' ? $inscricaoEstadual : null,
                    'email' => $email !== '' ? $email : null,
                    'logradouro' => $logradouro !== '' ? $logradouro : null,
                    'numero' => $numero !== '' ? $numero : null,
                    'complemento' => $complemento !== '' ? $complemento : null,
                    'bairro' => $bairro !== '' ? $bairro : null,
                    'cep' => $cep !== '' ? $cep : null,
                    'municipio' => $municipio !== '' ? $municipio : null,
                    'codigo_ibge_municipio' => $codigoIbgeCliente !== '' ? $codigoIbgeCliente : null,
                    'codigo_pais' => $codigoPaisCliente !== '' ? $codigoPaisCliente : null,
                    'nif' => $nifCliente !== '' ? $nifCliente : null,
                    'motivo_nao_nif' => $motivoNaoNifCliente !== '' ? $motivoNaoNifCliente : null,
                    'uf' => $uf !== '' ? $uf : null,
                    'indicador_consumidor_final' => $consumidorFinal,
                    'criado_por' => $funcionarioId,
                ]);

                $sucesso = 'Cliente cadastrado. Já pode ser selecionado ao criar uma nota.';
            }
        } elseif ($erro === '' && in_array(($_POST['acao'] ?? ''), ['criar_nota', 'salvar_edicao'], true)) {
            $salvandoEdicao = ($_POST['acao'] ?? '') === 'salvar_edicao';
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $clienteId = (int) ($_POST['cliente_id'] ?? 0);
            $tipoNota = ($_POST['tipo_nota'] ?? 'nfe') === 'nfse' ? 'nfse' : 'nfe';
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
                $descricoes = $_POST['item_descricao'] ?? [];
                $ncms = $_POST['item_ncm'] ?? [];
                $cfops = $_POST['item_cfop'] ?? [];
                $csts = $_POST['item_cst'] ?? [];
                $unidades = $_POST['item_unidade'] ?? [];
                $quantidades = $_POST['item_quantidade'] ?? [];
                $valoresUnitarios = $_POST['item_valor_unitario'] ?? [];
                $produtoIds = $_POST['item_produto_id'] ?? [];

                foreach ($descricoes as $indice => $descricaoItem) {
                    $descricaoItem = trim((string) $descricaoItem);
                    $quantidade = (float) str_replace(',', '.', (string) ($quantidades[$indice] ?? '0'));
                    $valorUnitario = (float) str_replace(',', '.', (string) ($valoresUnitarios[$indice] ?? '0'));

                    if ($descricaoItem === '' || $quantidade <= 0) {
                        continue;
                    }

                    $valorTotalItem = round($quantidade * $valorUnitario, 2);
                    $valorTotalNota += $valorTotalItem;

                    $itensValidos[] = [
                        'produto_servico_id' => (int) ($produtoIds[$indice] ?? 0) > 0 ? (int) $produtoIds[$indice] : null,
                        'descricao' => $descricaoItem,
                        'ncm' => trim((string) ($ncms[$indice] ?? '')) ?: null,
                        'cfop' => trim((string) ($cfops[$indice] ?? '')) ?: null,
                        'cst_csosn' => trim((string) ($csts[$indice] ?? '')) ?: null,
                        'codigo_servico_municipal' => null,
                        'unidade' => trim((string) ($unidades[$indice] ?? '')) ?: 'UN',
                        'quantidade' => $quantidade,
                        'valor_unitario' => $valorUnitario,
                        'valor_total' => $valorTotalItem,
                    ];
                }
            }

            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id AND ativo = 1 LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaId]);
            $empresaSelecionada = $stmtEmpresa->fetch() ?: null;
            $stmtCliente = $dbNotas->prepare('SELECT * FROM notas_clientes WHERE id = :id LIMIT 1');
            $stmtCliente->execute(['id' => $clienteId]);
            $clienteSelecionado = $stmtCliente->fetch() ?: null;
            $ambienteNota = ($empresaSelecionada['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';
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
            } elseif (empty($itensValidos)) {
                $erro = $tipoNota === 'nfse'
                    ? 'Informe a descrição do serviço e um valor do serviço maior que zero.'
                    : 'Adicione ao menos um item com descrição e quantidade maior que zero.';
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
                        $rejeicaoLocalBloqueada = $notaBloqueada && $notaBloqueada['status'] === 'rejeitada'
                            && str_starts_with((string) ($notaBloqueada['motivo_rejeicao'] ?? ''), 'DPS não transmitida:');
                        if (!$notaBloqueada || !($notaBloqueada['status'] === 'rascunho' || $rejeicaoLocalBloqueada)) {
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
                    } else {
                    $stmt = $dbNotas->prepare(
                        'SELECT COALESCE(MAX(numero_interno), 0) + 1 FROM notas_fiscais
                         WHERE empresa_emissora_id = :empresa_id AND tipo_nota = :tipo_nota FOR UPDATE'
                    );
                    $stmt->execute(['empresa_id' => $empresaId, 'tipo_nota' => $tipoNota]);
                    $numeroInterno = (int) $stmt->fetchColumn();

                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais (
                            empresa_emissora_id, cliente_id, funcionario_id, tipo_nota, natureza_operacao,
                            numero_interno, status, ambiente, forma_pagamento, data_emissao, data_saida_entrada,
                            valor_total, informacoes_frete
                         ) VALUES (
                            :empresa_id, :cliente_id, :funcionario_id, :tipo_nota, :natureza_operacao,
                            :numero_interno, \'rascunho\', :ambiente, :forma_pagamento, :data_emissao, :data_saida_entrada,
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
                            quantidade, valor_unitario, valor_total
                         ) VALUES (
                            :nota_id, :produto_servico_id, :descricao, :ncm, :cfop, :cst_csosn, :codigo_servico_municipal, :unidade,
                            :quantidade, :valor_unitario, :valor_total
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
                                exigibilidade_issqn_suspensa, tipo_suspensao_issqn, numero_processo_suspensao, issqn_retido, issqn_retido_por, beneficio_municipal, codigo_beneficio_municipal, deducao_reducao_base_calculo,
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
                                :exigibilidade_issqn_suspensa, :tipo_suspensao_issqn, :numero_processo_suspensao, :issqn_retido, :issqn_retido_por, :beneficio_municipal, :codigo_beneficio_municipal, :deducao_reducao_base_calculo,
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

                    registrarLogNota($dbNotas, $notaId, $funcionarioId, $salvandoEdicao ? 'editada' : 'criada', ($salvandoEdicao ? 'Correção salva; nota retornou a rascunho com ' : 'Rascunho criado com ') . count($itensValidos) . ' item(ns).');

                    $dbNotas->commit();
                    if ($salvandoEdicao) {
                        $sucesso = 'Correções salvas na nota nº ' . $numeroInterno . '. Ela voltou para rascunho e pode ser marcada como pronta para envio.';
                        $notaEmEdicao = null;
                        $nfseEmEdicao = null;
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
        'SELECT id, empresa_emissora_id, tipo, descricao, ncm, cfop, cst_csosn, codigo_servico_municipal, unidade, valor_unitario_padrao
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
$catalogoJson = h(json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]');
$edicaoJson = json_encode(['nota' => $notaEmEdicao, 'nfse' => $nfseEmEdicao, 'itens' => $itensEmEdicao], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{"nota":null,"nfse":null,"itens":[]}';
$restaurarJson = json_encode($dadosRestaurar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
$codigosTributacaoNacionalNfse = obterCodigosTributacaoNacionalNfse();
$variacoesComplementarBH = obterVariacoesCodigoComplementarBH();
$correlacaoNbsNfse = catalogoCorrelacaoNbsNfse();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir Nota Fiscal | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'emitir'; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1>Emitir Nota Fiscal</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de produto (NF-e) ou serviço (NFS-e) para outras empresas. As notas ficam como rascunho até a integração com a SEFAZ e o Portal Nacional da NFS-e ser habilitada — acompanhe tudo em <a href="notas-fiscais" style="text-decoration:underline;">Notas fiscais</a>.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (empty($empresasAtivas)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. <?php echo $podeAdministrar ? 'Cadastre uma em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a>.' : 'Peça para um administrador cadastrar em Empresas emissoras.'; ?></div>
        <?php else: ?>

            <details class="panel" id="cadastroCliente">
                <summary>Cadastrar novo cliente (destinatário)</summary>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="cadastrar_cliente">
                    <div class="form-grid">
                        <div class="field">
                            <label for="tipo_pessoa">Tipo de pessoa</label>
                            <select id="tipo_pessoa" name="tipo_pessoa">
                                <option value="PJ">Pessoa jurídica</option>
                                <option value="PF">Pessoa física</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nome_razao_social">Nome / Razão social</label>
                            <input id="nome_razao_social" name="nome_razao_social" type="text" required>
                        </div>
                        <div class="field">
                            <label for="cnpj_cpf">CNPJ / CPF</label>
                            <div class="row-actions">
                                <input id="cnpj_cpf" name="cnpj_cpf" type="text" maxlength="18" style="flex: 1;">
                                <button class="btn btn-outline btn-small" type="button" id="btnBuscarCnpjCliente"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                            </div>
                            <span class="muted" id="statusBuscaCnpjCliente" style="font-size: 0.78rem;"></span>
                        </div>
                        <div class="field">
                            <label for="cliente_inscricao_estadual">Inscrição Estadual (ou "ISENTO")</label>
                            <input id="cliente_inscricao_estadual" name="cliente_inscricao_estadual" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_email">E-mail</label>
                            <input id="cliente_email" name="cliente_email" type="email">
                        </div>
                        <div class="field">
                            <label for="cliente_logradouro">Logradouro</label>
                            <input id="cliente_logradouro" name="cliente_logradouro" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_numero">Número</label>
                            <input id="cliente_numero" name="cliente_numero" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_complemento">Complemento</label>
                            <input id="cliente_complemento" name="cliente_complemento" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_bairro">Bairro</label>
                            <input id="cliente_bairro" name="cliente_bairro" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_cep">CEP</label>
                            <input id="cliente_cep" name="cliente_cep" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_municipio">Município</label>
                            <input id="cliente_municipio" name="cliente_municipio" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_codigo_ibge_municipio">Código IBGE do município</label>
                            <input id="cliente_codigo_ibge_municipio" name="cliente_codigo_ibge_municipio" type="text" inputmode="numeric" pattern="\d{7}" maxlength="7">
                        </div>
                        <div class="field">
                            <label for="cliente_codigo_pais">Código do país</label>
                            <input id="cliente_codigo_pais" name="cliente_codigo_pais" type="text" inputmode="numeric" maxlength="4" value="1058">
                        </div>
                        <div class="field">
                            <label for="cliente_nif">NIF (destinatário exterior)</label>
                            <input id="cliente_nif" name="cliente_nif" type="text" maxlength="40">
                        </div>
                        <div class="field">
                            <label for="cliente_motivo_nao_nif">Motivo da ausência de NIF</label>
                            <input id="cliente_motivo_nao_nif" name="cliente_motivo_nao_nif" type="text" maxlength="2" placeholder="Código oficial">
                        </div>
                        <div class="field">
                            <label for="cliente_uf">UF</label>
                            <input id="cliente_uf" name="cliente_uf" type="text" maxlength="2">
                        </div>
                        <label class="check-row">
                            <input type="checkbox" name="indicador_consumidor_final" checked>
                            Consumidor final
                        </label>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn" type="submit"><i class="fa-solid fa-user-plus"></i> Cadastrar cliente</button>
                        </div>
                    </div>
                </form>
            </details>

            <section class="panel">
                <h2><?php echo $notaEmEdicao ? 'Corrigir NFS-e nº ' . h((string) $notaEmEdicao['numero_interno']) : 'Nova nota (rascunho)'; ?></h2>
                <form method="post" id="formNota">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="<?php echo $notaEmEdicao ? 'salvar_edicao' : 'criar_nota'; ?>">
                    <?php if ($notaEmEdicao): ?>
                        <input type="hidden" name="nota_id_edicao" value="<?php echo h((string) $notaEmEdicao['id']); ?>">
                        <input type="hidden" name="nota_atualizada_em" value="<?php echo h((string) $notaEmEdicao['atualizado_em']); ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <select id="empresa_emissora_id" name="empresa_emissora_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($empresasAtivas as $empresa): ?>
                                    <option value="<?php echo h((string) $empresa['id']); ?>" data-ibge="<?php echo h($empresa['codigo_ibge_municipio'] ?? ''); ?>" data-ambiente="<?php echo h($empresa['ambiente_emissao'] ?? 'homologacao'); ?>"><?php echo h($empresa['razao_social']); ?> (<?php echo h(($empresa['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="tipo_nota">Tipo de nota</label>
                            <select id="tipo_nota" name="tipo_nota">
                                <option value="nfe">NF-e (produto)</option>
                                <option value="nfse">NFS-e (serviço)</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="busca_cliente_documento">Buscar cliente por CNPJ/CPF</label>
                            <div class="row-actions">
                                <input id="busca_cliente_documento" type="text" style="flex: 1;" placeholder="Digite o CNPJ ou CPF do cliente já cadastrado">
                                <button class="btn btn-outline btn-small" type="button" id="btnBuscarClienteDocumento"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                            </div>
                            <span class="muted" id="statusBuscaClienteDocumento" style="font-size: 0.78rem;"></span>
                        </div>
                        <div class="field">
                            <label for="cliente_id">Cliente destinatário</label>
                            <select id="cliente_id" name="cliente_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?php echo h((string) $cliente['id']); ?>" data-documento="<?php echo h(preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? ''))); ?>" data-inscricao-municipal="<?php echo h((string) ($cliente['inscricao_municipal'] ?? '')); ?>"><?php echo h($cliente['nome_razao_social'] . (($cliente['cnpj_cpf'] ?? '') !== '' ? ' - ' . $cliente['cnpj_cpf'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="natureza_operacao">Natureza da operação</label>
                            <input id="natureza_operacao" name="natureza_operacao" type="text" placeholder="Ex.: Venda de mercadoria / Prestação de serviço" required>
                        </div>
                        <div class="field">
                            <label for="forma_pagamento">Forma de pagamento</label>
                            <input id="forma_pagamento" name="forma_pagamento" type="text" placeholder="Pix, boleto, cartão...">
                        </div>
                        <div class="field">
                            <label for="data_emissao">Data de emissão</label>
                            <input id="data_emissao" name="data_emissao" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="field">
                            <label for="data_saida_entrada">Data de saída/entrada</label>
                            <input id="data_saida_entrada" name="data_saida_entrada" type="date">
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="informacoes_frete">Frete/transporte (opcional)</label>
                            <textarea id="informacoes_frete" name="informacoes_frete" placeholder="Transportadora, placa, volume, peso..."></textarea>
                        </div>
                    </div>

                    <div id="secaoNfe">
                        <details class="form-section" id="secaoItens" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-boxes-stacked"></i> Itens (produtos)</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <table class="itens-table" id="tabelaItens">
                            <thead>
                                <tr>
                                    <th style="min-width: 220px;">Catálogo (opcional)</th>
                                    <th style="min-width: 200px;">Descrição</th>
                                    <th>NCM</th>
                                    <th>CFOP</th>
                                    <th>CST/CSOSN</th>
                                    <th>Unid.</th>
                                    <th>Qtd.</th>
                                    <th>Valor unit.</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="corpoItens"></tbody>
                        </table>
                        <button class="btn btn-outline btn-small" type="button" id="btnAddItem"><i class="fa-solid fa-plus"></i> Adicionar item</button>

                        <div class="totais" id="totalNota" style="margin-top: 1rem;">Total estimado: R$ 0,00</div>
                            </div>
                        </details>
                    </div>

                    <div id="secaoNfse">
                        <div class="form-jump" id="atalhosNfse">
                            <button type="button" data-form-jump="secaoCompetencia"><i class="fa-solid fa-calendar-days"></i> Competência</button>
                            <button type="button" data-form-jump="secaoTomador"><i class="fa-solid fa-user"></i> Tomador</button>
                            <button type="button" data-form-jump="secaoIntermediario"><i class="fa-solid fa-people-arrows"></i> Intermediário</button>
                            <button type="button" data-form-jump="secaoLocal"><i class="fa-solid fa-location-dot"></i> Local</button>
                            <button type="button" data-form-jump="secaoServico"><i class="fa-solid fa-briefcase"></i> Serviço</button>
                            <button type="button" data-form-jump="secaoIbscbs"><i class="fa-solid fa-scale-balanced"></i> IBS/CBS</button>
                            <button type="button" data-form-jump="secaoComplementares"><i class="fa-solid fa-circle-info"></i> Complementares</button>
                            <button type="button" data-form-jump="secaoValores"><i class="fa-solid fa-sack-dollar"></i> Valores</button>
                            <button type="button" data-form-jump="secaoTributacaoMunicipal"><i class="fa-solid fa-city"></i> Trib. municipal</button>
                            <button type="button" data-form-jump="secaoTributacaoFederal"><i class="fa-solid fa-landmark"></i> Trib. federal</button>
                            <button type="button" data-form-jump="secaoTributosAproximados"><i class="fa-solid fa-percent"></i> Tributos aprox.</button>
                        </div>

                        <details class="form-section" id="secaoCompetencia" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-calendar-days"></i> Competência e DPS</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_data_competencia">Data de competência</label>
                                <input id="nfse_data_competencia" name="nfse_data_competencia" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                            </div>
                            <label class="check-row">
                                <input type="checkbox" id="nfse_informar_dps" name="nfse_informar_dps">
                                Informar série e número da DPS
                            </label>
                        </div>
                        <div class="form-grid" id="camposDpsManual" style="display:none;">
                            <div class="field">
                                <label for="nfse_serie_dps">Série da DPS</label>
                                <input id="nfse_serie_dps" name="nfse_serie_dps" type="text" maxlength="5">
                            </div>
                            <div class="field">
                                <label for="nfse_numero_dps">Número da DPS</label>
                                <input id="nfse_numero_dps" name="nfse_numero_dps" type="text" maxlength="15">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoTomador" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-user"></i> Tomador do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <p class="muted" style="margin-bottom: 0.75rem;">Documento, nome e e-mail do tomador vêm do cliente destinatário selecionado acima.</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_tomador_local">Onde está localizado o estabelecimento/domicílio?</label>
                                <select id="nfse_tomador_local" name="nfse_tomador_local">
                                    <option value="nao_informado">Tomador não informado</option>
                                    <option value="brasil" selected>Brasil</option>
                                    <option value="exterior">Exterior</option>
                                </select>
                            </div>
                            <div class="field" id="campoTomadorInscricaoMunicipal">
                                <label for="nfse_tomador_inscricao_municipal">Inscrição Municipal do tomador</label>
                                <input id="nfse_tomador_inscricao_municipal" name="nfse_tomador_inscricao_municipal" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tomador_telefone">Telefone (opcional)</label>
                                <input id="nfse_tomador_telefone" name="nfse_tomador_telefone" type="text">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoIntermediario">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-people-arrows"></i> Intermediário do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <label class="check-row" style="margin-top: 0;">
                            <input type="checkbox" id="nfse_intermediario_incluido" name="nfse_intermediario_incluido">
                            Esta NFS-e tem intermediário
                        </label>
                        <div class="form-grid" id="camposIntermediario" style="display:none; margin-top: 1rem;">
                            <div class="field">
                                <label for="nfse_intermediario_local">Onde está localizado o estabelecimento/domicílio?</label>
                                <select id="nfse_intermediario_local" name="nfse_intermediario_local">
                                    <option value="nao_informado">Intermediário não informado</option>
                                    <option value="brasil">Brasil</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_intermediario_cpf_cnpj">CPF/CNPJ do intermediário</label>
                                <input id="nfse_intermediario_cpf_cnpj" name="nfse_intermediario_cpf_cnpj" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_intermediario_nome">Nome/Razão social do intermediário</label>
                                <input id="nfse_intermediario_nome" name="nfse_intermediario_nome" type="text">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoLocal" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-location-dot"></i> Local da prestação do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_pais_prestacao">País</label>
                                <input id="nfse_pais_prestacao" name="nfse_pais_prestacao" type="text" value="Brasil">
                            </div>
                            <div class="field municipio-autocomplete">
                                <label for="nfse_municipio_prestacao_busca">Município</label>
                                <input id="nfse_municipio_prestacao_busca" type="search" placeholder="Digite o início do município" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_municipio_prestacao_opcoes" aria-expanded="false">
                                <input id="nfse_municipio_prestacao" name="nfse_municipio_prestacao" type="hidden">
                                <div id="nfse_municipio_prestacao_opcoes" class="municipio-sugestoes" role="listbox"></div>
                                <span class="muted" id="nfse_municipio_prestacao_status" style="font-size: 0.78rem;">Pesquise pelo início do nome e selecione o município.</span>
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoServico" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-briefcase"></i> Serviço prestado</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_codigo_tributacao_nacional">Código de Tributação Nacional (LC 116)</label>
                                <input id="nfse_codigo_tributacao_nacional" name="nfse_codigo_tributacao_nacional" type="text" placeholder="Digite para buscar. Ex.: 17.19.01" list="datalistCodigosNacionais" autocomplete="off">
                                <datalist id="datalistCodigosNacionais">
                                    <?php foreach ($codigosTributacaoNacionalNfse as $codigoNacional): ?>
                                        <option value="<?php echo h($codigoNacional['codigo']); ?>"><?php echo h($codigoNacional['codigo'] . ' - ' . $codigoNacional['descricao']); ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="field">
                                <label for="nfse_codigo_tributacao_municipal_opcoes">Código Complementar Municipal</label>
                                <select id="nfse_codigo_tributacao_municipal_opcoes" style="display:none;"></select>
                                <input id="nfse_codigo_tributacao_municipal" name="nfse_codigo_tributacao_municipal" type="hidden">
                            </div>

                            <div class="field">
                                <label for="nfse_item_nbs">NBS do serviço (cNBS)</label>
                                <select id="nfse_item_nbs" name="nfse_item_nbs" disabled>
                                    <option value="">Escolha primeiro o serviço prestado</option>
                                </select>
                                <span class="muted" id="nfse_item_nbs_status" style="font-size: 0.78rem;">A NBS será definida conforme a correlação oficial da NFS-e.</span>
                            </div>
                            <div class="field">
                                <label for="nfse_codigo_interno_contribuinte">Código interno do contribuinte</label>
                                <input id="nfse_codigo_interno_contribuinte" name="nfse_codigo_interno_contribuinte" type="text" placeholder="Seu código de controle interno para este serviço">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_descricao_servico">Descrição do serviço</label>
                                <textarea id="nfse_descricao_servico" name="nfse_descricao_servico" maxlength="2000" placeholder="Descreva o serviço prestado"></textarea>
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoIbscbs">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-scale-balanced"></i> IBS/CBS — Reforma Tributária (NT 004)</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <p class="muted">Pesquise e selecione os códigos das tabelas oficiais. Ao escolher a classificação tributária, o CST correspondente é preenchido automaticamente.</p>
                        <div class="form-grid">
                            <div class="field"><label for="nfse_ibscbs_finalidade">Finalidade</label><select id="nfse_ibscbs_finalidade" name="nfse_ibscbs_finalidade"><option value="0" selected>0 - NFS-e regular</option><option value="1">1 - Crédito</option><option value="2">2 - Débito</option></select></div>
                            <div class="field"><label for="nfse_ibscbs_ind_final">Operação com consumidor final?</label><select id="nfse_ibscbs_ind_final" name="nfse_ibscbs_ind_final"><option value="">Selecione</option><option value="0">0 - Não</option><option value="1">1 - Sim</option></select></div>
                            <div class="field catalogo-autocomplete">
                                <label for="nfse_ibscbs_codigo_indicador_operacao_busca">Código do indicador da operação (cIndOp)</label>
                                <input id="nfse_ibscbs_codigo_indicador_operacao_busca" type="search" placeholder="Pesquise pelo código ou descrição" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_ibscbs_codigo_indicador_operacao_opcoes" aria-expanded="false">
                                <input id="nfse_ibscbs_codigo_indicador_operacao" name="nfse_ibscbs_codigo_indicador_operacao" type="hidden">
                                <div id="nfse_ibscbs_codigo_indicador_operacao_opcoes" class="catalogo-sugestoes" role="listbox"></div>
                                <span class="muted" id="nfse_ibscbs_codigo_indicador_operacao_status" style="font-size: 0.78rem;">Selecione uma opção da tabela oficial.</span>
                            </div>
                            <div class="field"><label for="nfse_ibscbs_ind_destinatario">Indicador do destinatário</label><select id="nfse_ibscbs_ind_destinatario" name="nfse_ibscbs_ind_destinatario"><option value="">Selecione</option><option value="0">0 - Destinatário é o tomador</option><option value="1">1 - Destinatário diferente do tomador</option></select></div>
                            <div class="field"><label for="nfse_ibscbs_cst">CST IBS/CBS</label><input id="nfse_ibscbs_cst" name="nfse_ibscbs_cst" type="text" inputmode="numeric" pattern="\d{3}" maxlength="3" placeholder="Preenchido pelo cClassTrib" readonly aria-readonly="true"><span class="muted" id="nfse_ibscbs_cst_status" style="font-size: 0.78rem;">Será definido automaticamente.</span></div>
                            <div class="field catalogo-autocomplete">
                                <label for="nfse_ibscbs_classificacao_tributaria_busca">Classificação tributária (cClassTrib)</label>
                                <input id="nfse_ibscbs_classificacao_tributaria_busca" type="search" placeholder="Pesquise pelo código ou descrição" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_ibscbs_classificacao_tributaria_opcoes" aria-expanded="false">
                                <input id="nfse_ibscbs_classificacao_tributaria" name="nfse_ibscbs_classificacao_tributaria" type="hidden">
                                <div id="nfse_ibscbs_classificacao_tributaria_opcoes" class="catalogo-sugestoes" role="listbox"></div>
                                <span class="muted" id="nfse_ibscbs_classificacao_tributaria_status" style="font-size: 0.78rem;">Selecione uma opção vigente e permitida para NFS-e.</span>
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoComplementares">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-circle-info"></i> Informações complementares</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_documento_responsabilidade_tecnica">Nº do documento de responsabilidade técnica</label>
                                <input id="nfse_documento_responsabilidade_tecnica" name="nfse_documento_responsabilidade_tecnica" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_numero_pedido_b2b">Nº do Pedido/OC/OS/Projeto (B2B)</label>
                                <input id="nfse_numero_pedido_b2b" name="nfse_numero_pedido_b2b" type="text">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_documento_referencia">Documento de referência</label>
                                <textarea id="nfse_documento_referencia" name="nfse_documento_referencia"></textarea>
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_informacoes_complementares">Informações complementares</label>
                                <textarea id="nfse_informacoes_complementares" name="nfse_informacoes_complementares" maxlength="2000"></textarea>
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoValores" open>
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-sack-dollar"></i> Valores do serviço prestado</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_valor_servico">Valor do serviço prestado</label>
                                <input id="nfse_valor_servico" name="nfse_valor_servico" type="text" value="0,00">
                            </div>
                            <div class="field">
                                <label for="nfse_valor_recebido_intermediario">Valor recebido pelo intermediário</label>
                                <input id="nfse_valor_recebido_intermediario" name="nfse_valor_recebido_intermediario" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_desconto_incondicionado">Desconto incondicionado</label>
                                <input id="nfse_desconto_incondicionado" name="nfse_desconto_incondicionado" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_desconto_condicionado">Desconto condicionado</label>
                                <input id="nfse_desconto_condicionado" name="nfse_desconto_condicionado" type="text">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoTributacaoMunicipal">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-city"></i> Tributação municipal</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_exigibilidade_suspensa">A exigibilidade do ISSQN está suspensa?</label>
                                <select id="nfse_exigibilidade_suspensa" name="nfse_exigibilidade_suspensa">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field"><label for="nfse_tipo_suspensao_issqn">Tipo de suspensão (se suspensa)</label><input id="nfse_tipo_suspensao_issqn" name="nfse_tipo_suspensao_issqn" type="text" maxlength="2" placeholder="Código oficial"></div>
                            <div class="field"><label for="nfse_numero_processo_suspensao">Número do processo de suspensão</label><input id="nfse_numero_processo_suspensao" name="nfse_numero_processo_suspensao" type="text" maxlength="60"></div>
                            <div class="field">
                                <label for="nfse_issqn_retido">Há retenção do ISSQN pelo Tomador ou Intermediário?</label>
                                <select id="nfse_issqn_retido" name="nfse_issqn_retido">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field" id="campoIssqnRetidoPor" style="display:none;">
                                <label for="nfse_issqn_retido_por">Retido por</label>
                                <select id="nfse_issqn_retido_por" name="nfse_issqn_retido_por">
                                    <option value="tomador">Tomador</option>
                                    <option value="intermediario">Intermediário</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_beneficio_municipal">Este serviço está amparado por algum benefício municipal?</label>
                                <select id="nfse_beneficio_municipal" name="nfse_beneficio_municipal">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field"><label for="nfse_codigo_beneficio_municipal">Código do benefício municipal (se amparado)</label><input id="nfse_codigo_beneficio_municipal" name="nfse_codigo_beneficio_municipal" type="text" maxlength="30"></div>
                            <div class="field">
                                <label for="nfse_deducao_reducao_base">Dedução/redução da base de cálculo do ISSQN (opcional)</label>
                                <input id="nfse_deducao_reducao_base" name="nfse_deducao_reducao_base" type="text">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoTributacaoFederal">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-landmark"></i> Tributação federal</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_situacao_pis_cofins">Situação Tributária do PIS/COFINS</label>
                                <select id="nfse_situacao_pis_cofins" name="nfse_situacao_pis_cofins">
                                    <option value="">Selecione...</option>
                                    <option value="01">01 - Tributável (alíquota básica)</option>
                                    <option value="04">04 - Tributável (alíquota zero)</option>
                                    <option value="06">06 - Não tributável</option>
                                    <option value="07">07 - Isenta</option>
                                    <option value="08">08 - Sem incidência</option>
                                    <option value="09">09 - Com suspensão</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_tipo_retencao_pis_cofins_csll">Tipo de retenção do PIS/COFINS/CSLL</label>
                                <select id="nfse_tipo_retencao_pis_cofins_csll" name="nfse_tipo_retencao_pis_cofins_csll">
                                    <option value="">Selecione...</option>
                                    <option value="nenhuma">Sem retenção</option>
                                    <option value="lei_10833">Retenção conforme Lei 10.833/2003</option>
                                    <option value="outras">Outras retenções</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_irrf">IRRF (opcional)</label>
                                <input id="nfse_irrf" name="nfse_irrf" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_contribuicoes_sociais_retidas">Contribuições Sociais - Retidas (opcional)</label>
                                <input id="nfse_contribuicoes_sociais_retidas" name="nfse_contribuicoes_sociais_retidas" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_contribuicao_previdenciaria_retida">Contribuição Previdenciária - Retida (opcional)</label>
                                <input id="nfse_contribuicao_previdenciaria_retida" name="nfse_contribuicao_previdenciaria_retida" type="text">
                            </div>
                        </div>
                            </div>
                        </details>

                        <details class="form-section" id="secaoTributosAproximados">
                            <summary><span class="form-section-titulo"><i class="fa-solid fa-percent"></i> Valor aproximado dos tributos</span><i class="fa-solid fa-chevron-down"></i></summary>
                            <div class="form-section-corpo">
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_tributos_modo">Como informar</label>
                                <select id="nfse_tributos_modo" name="nfse_tributos_modo">
                                    <option value="percentuais" selected>Configurar os valores percentuais</option>
                                    <option value="valores">Preencher os valores monetários</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid" id="tributosPercentuais">
                            <div class="field">
                                <label for="nfse_tributos_federal_percentual">Federal (%)</label>
                                <input id="nfse_tributos_federal_percentual" name="nfse_tributos_federal_percentual" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_estadual_percentual">Estadual (%)</label>
                                <input id="nfse_tributos_estadual_percentual" name="nfse_tributos_estadual_percentual" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_municipal_percentual">Municipal (%)</label>
                                <input id="nfse_tributos_municipal_percentual" name="nfse_tributos_municipal_percentual" type="text">
                            </div>
                        </div>
                        <div class="form-grid" id="tributosValores" style="display:none;">
                            <div class="field">
                                <label for="nfse_tributos_federal_valor">Federal (R$)</label>
                                <input id="nfse_tributos_federal_valor" name="nfse_tributos_federal_valor" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_estadual_valor">Estadual (R$)</label>
                                <input id="nfse_tributos_estadual_valor" name="nfse_tributos_estadual_valor" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_municipal_valor">Municipal (R$)</label>
                                <input id="nfse_tributos_municipal_valor" name="nfse_tributos_municipal_valor" type="text">
                            </div>
                        </div>
                            </div>
                        </details>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php echo $notaEmEdicao ? 'Salvar correções' : 'Salvar rascunho'; ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        const dadosEdicaoNota = <?php echo $edicaoJson; ?>;
        const dadosRestaurar = <?php echo $restaurarJson; ?>;
        function aplicarDadosEdicao() {
            if (!dadosEdicaoNota || !dadosEdicaoNota.nota) return;
            const form = document.getElementById('formNota');
            const nota = dadosEdicaoNota.nota;
            const nfse = dadosEdicaoNota.nfse || {};
            const itens = Array.isArray(dadosEdicaoNota.itens) ? dadosEdicaoNota.itens : [];
            const notaCampos = {
                empresa_emissora_id: nota.empresa_emissora_id,
                cliente_id: nota.cliente_id,
                tipo_nota: nota.tipo_nota,
                natureza_operacao: nota.natureza_operacao,
                forma_pagamento: nota.forma_pagamento,
                data_emissao: nota.data_emissao,
                data_saida_entrada: nota.data_saida_entrada,
                informacoes_frete: nota.informacoes_frete
            };
            Object.keys(notaCampos).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = notaCampos[nome] == null ? '' : notaCampos[nome];
            });
            const especiais = {
                deducao_reducao_base_calculo: 'nfse_deducao_reducao_base',
                exigibilidade_issqn_suspensa: 'nfse_exigibilidade_suspensa',
                situacao_tributaria_pis_cofins: 'nfse_situacao_pis_cofins'
            };
            Object.keys(nfse).forEach(function (chave) {
                const nome = especiais[chave] || ('nfse_' + chave);
                const campo = form.elements.namedItem(nome);
                if (!campo) return;
                if (campo.type === 'checkbox') campo.checked = Number(nfse[chave]) === 1;
                else campo.value = nfse[chave] == null ? '' : nfse[chave];
            });
            const informarDps = form.elements.namedItem('nfse_informar_dps');
            if (informarDps) informarDps.checked = Boolean(nfse.serie_dps || nfse.numero_dps);
            const itemServico = itens[0] || null;
            if (itemServico && form.elements.namedItem('nfse_valor_servico')) form.elements.namedItem('nfse_valor_servico').value = itemServico.valor_total;
            const empresa = form.elements.namedItem('empresa_emissora_id');
            const tipo = form.elements.namedItem('tipo_nota');
            if (empresa) { empresa.disabled = true; empresa.title = 'A empresa emissora não pode ser alterada nesta correção.'; }
            if (tipo) { tipo.disabled = true; tipo.title = 'O tipo da nota não pode ser alterado nesta correção.'; }
            const cindopBusca = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_busca');
            if (cindopBusca && nfse.ibscbs_codigo_indicador_operacao) cindopBusca.value = nfse.ibscbs_codigo_indicador_operacao;
            const cclassBusca = document.getElementById('nfse_ibscbs_classificacao_tributaria_busca');
            if (cclassBusca && nfse.ibscbs_classificacao_tributaria) cclassBusca.value = nfse.ibscbs_classificacao_tributaria;
            CAMPOS_MOEDA_NFSE.forEach(function (id) { formatarCampoMoeda(document.getElementById(id)); });
        }

        const CAMPOS_MOEDA_NFSE = [
            'nfse_valor_servico',
            'nfse_valor_recebido_intermediario',
            'nfse_desconto_incondicionado',
            'nfse_desconto_condicionado',
            'nfse_deducao_reducao_base',
            'nfse_irrf',
            'nfse_contribuicoes_sociais_retidas',
            'nfse_contribuicao_previdenciaria_retida',
            'nfse_tributos_federal_valor',
            'nfse_tributos_estadual_valor',
            'nfse_tributos_municipal_valor'
        ];

        function formatarCampoMoeda(campo) {
            if (!campo) return;
            const bruto = campo.value.trim();
            if (bruto === '') return;
            const normalizado = bruto.includes(',') ? bruto.replace(/\./g, '').replace(',', '.') : bruto;
            const numero = parseFloat(normalizado);
            if (!isFinite(numero)) return;
            campo.value = numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        CAMPOS_MOEDA_NFSE.forEach(function (id) {
            const campo = document.getElementById(id);
            if (!campo) return;
            campo.setAttribute('inputmode', 'decimal');
            campo.addEventListener('blur', function () { formatarCampoMoeda(campo); });
            formatarCampoMoeda(campo);
        });

        aplicarDadosEdicao();

        function restaurarCamposSimplesDoErro() {
            if (!dadosRestaurar) return;
            const form = document.getElementById('formNota');
            const nota = dadosRestaurar.nota || {};
            const nfse = dadosRestaurar.nfse || {};

            Object.keys(nota).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = nota[nome] == null ? '' : nota[nome];
            });

            const especiais = {
                deducao_reducao_base_calculo: 'nfse_deducao_reducao_base',
                exigibilidade_issqn_suspensa: 'nfse_exigibilidade_suspensa',
                situacao_tributaria_pis_cofins: 'nfse_situacao_pis_cofins'
            };
            Object.keys(nfse).forEach(function (chave) {
                const nome = especiais[chave] || ('nfse_' + chave);
                const campo = form.elements.namedItem(nome);
                if (!campo) return;
                if (campo.type === 'checkbox') campo.checked = Number(nfse[chave]) === 1;
                else campo.value = nfse[chave] == null ? '' : nfse[chave];
            });
            const informarDps = form.elements.namedItem('nfse_informar_dps');
            if (informarDps) informarDps.checked = Boolean(nfse.serie_dps || nfse.numero_dps);

            const cindopBusca = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_busca');
            if (cindopBusca && nfse.ibscbs_codigo_indicador_operacao) cindopBusca.value = nfse.ibscbs_codigo_indicador_operacao;
            const cclassBusca = document.getElementById('nfse_ibscbs_classificacao_tributaria_busca');
            if (cclassBusca && nfse.ibscbs_classificacao_tributaria) cclassBusca.value = nfse.ibscbs_classificacao_tributaria;

            // O valor do serviço fica no item, não na tabela nfse — restaura separadamente.
            const itensRestaurar = Array.isArray(dadosRestaurar.itens) ? dadosRestaurar.itens : [];
            if (nota.tipo_nota === 'nfse' && itensRestaurar[0] && form.elements.namedItem('nfse_valor_servico')) {
                form.elements.namedItem('nfse_valor_servico').value = itensRestaurar[0].valor_total;
            }

            CAMPOS_MOEDA_NFSE.forEach(function (id) { formatarCampoMoeda(document.getElementById(id)); });
        }
        restaurarCamposSimplesDoErro();

        function formatarCnpjOuCpf(valor, tipoPessoa) {
            const digitos = (valor || '').replace(/\D/g, '');
            if (tipoPessoa === 'PF') {
                const d = digitos.slice(0, 11);
                if (d.length > 9) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2})$/, '$1.$2.$3-$4').replace(/-$/, '');
                if (d.length > 6) return d.replace(/^(\d{3})(\d{3})(\d{0,3})$/, '$1.$2.$3');
                if (d.length > 3) return d.replace(/^(\d{3})(\d{0,3})$/, '$1.$2');
                return d;
            }
            const d = digitos.slice(0, 14);
            if (d.length > 12) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})$/, '$1.$2.$3/$4-$5').replace(/-$/, '');
            if (d.length > 8) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})$/, '$1.$2.$3/$4');
            if (d.length > 5) return d.replace(/^(\d{2})(\d{3})(\d{0,3})$/, '$1.$2.$3');
            if (d.length > 2) return d.replace(/^(\d{2})(\d{0,3})$/, '$1.$2');
            return d;
        }

        function formatarCepCliente(valor) {
            const d = (valor || '').replace(/\D/g, '').slice(0, 8);
            return d.length > 5 ? d.replace(/^(\d{5})(\d{0,3})$/, '$1-$2') : d;
        }

        const campoCnpjCpf = document.getElementById('cnpj_cpf');
        const campoTipoPessoa = document.getElementById('tipo_pessoa');
        const campoCepCliente = document.getElementById('cliente_cep');
        const btnBuscarCnpjCliente = document.getElementById('btnBuscarCnpjCliente');

        if (campoCnpjCpf && campoTipoPessoa) {
            campoCnpjCpf.addEventListener('input', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
            });
            campoTipoPessoa.addEventListener('change', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
                if (btnBuscarCnpjCliente) {
                    btnBuscarCnpjCliente.style.display = campoTipoPessoa.value === 'PF' ? 'none' : '';
                }
            });
        }

        if (campoCepCliente) {
            campoCepCliente.addEventListener('input', function () {
                campoCepCliente.value = formatarCepCliente(campoCepCliente.value);
            });
        }

        if (btnBuscarCnpjCliente) {
            btnBuscarCnpjCliente.addEventListener('click', function () {
                const statusEl = document.getElementById('statusBuscaCnpjCliente');
                const digitos = (campoCnpjCpf.value || '').replace(/\D/g, '');

                if (digitos.length !== 14) {
                    statusEl.textContent = 'Informe um CNPJ com 14 dígitos antes de buscar.';
                    statusEl.style.color = '#FFD1CE';
                    return;
                }

                statusEl.textContent = 'Buscando...';
                statusEl.style.color = '';
                btnBuscarCnpjCliente.disabled = true;

                fetch('buscar-cnpj?cnpj=' + digitos)
                    .then(function (resposta) { return resposta.json().then(function (dados) { return { ok: resposta.ok, dados: dados }; }); })
                    .then(function (resultado) {
                        if (!resultado.ok) {
                            statusEl.textContent = resultado.dados.erro || 'Não foi possível buscar o CNPJ.';
                            statusEl.style.color = '#FFD1CE';
                            return;
                        }

                        const dados = resultado.dados;
                        document.getElementById('nome_razao_social').value = dados.razao_social || '';
                        document.getElementById('cliente_logradouro').value = dados.logradouro || '';
                        document.getElementById('cliente_numero').value = dados.numero || '';
                        document.getElementById('cliente_complemento').value = dados.complemento || '';
                        document.getElementById('cliente_bairro').value = dados.bairro || '';
                        document.getElementById('cliente_cep').value = formatarCepCliente(dados.cep || '');
                        document.getElementById('cliente_municipio').value = dados.municipio || '';
                        document.getElementById('cliente_codigo_ibge_municipio').value = dados.codigo_ibge_municipio || '';
                        document.getElementById('cliente_uf').value = dados.uf || '';

                        statusEl.style.color = 'var(--primary)';
                        statusEl.textContent = 'Dados preenchidos (' + (dados.situacao_cadastral || 'situação não informada') + '). Confira antes de cadastrar.';
                    })
                    .catch(function () {
                        statusEl.textContent = 'Erro ao buscar o CNPJ. Tente novamente.';
                        statusEl.style.color = '#FFD1CE';
                    })
                    .finally(function () {
                        btnBuscarCnpjCliente.disabled = false;
                    });
            });
        }

        function formatarDocumentoBusca(valor) {
            const digitos = (valor || '').replace(/\D/g, '');
            return formatarCnpjOuCpf(digitos, digitos.length <= 11 ? 'PF' : 'PJ');
        }

        const campoBuscaClienteDocumento = document.getElementById('busca_cliente_documento');
        const btnBuscarClienteDocumento = document.getElementById('btnBuscarClienteDocumento');
        const selectClienteId = document.getElementById('cliente_id');

        if (campoBuscaClienteDocumento) {
            campoBuscaClienteDocumento.addEventListener('input', function () {
                campoBuscaClienteDocumento.value = formatarDocumentoBusca(campoBuscaClienteDocumento.value);
            });
        }

        function buscarClientePorDocumento() {
            const statusEl = document.getElementById('statusBuscaClienteDocumento');
            const digitos = (campoBuscaClienteDocumento.value || '').replace(/\D/g, '');

            if (digitos.length < 11) {
                statusEl.textContent = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) para buscar.';
                statusEl.style.color = '#FFD1CE';
                return;
            }

            const opcao = Array.from(selectClienteId.options).find(function (candidata) {
                return candidata.dataset.documento && candidata.dataset.documento === digitos;
            });

            if (opcao) {
                selectClienteId.value = opcao.value;
                selectClienteId.dispatchEvent(new Event('change'));
                statusEl.style.color = 'var(--primary)';
                statusEl.textContent = 'Cliente encontrado e selecionado: ' + opcao.textContent;
            } else {
                statusEl.style.color = '#FFD1CE';
                statusEl.innerHTML = 'Nenhum cliente cadastrado com esse documento. <a href="notas-emitir#cadastroCliente" style="text-decoration:underline; color:#FFD1CE;">Cadastre um novo cliente</a>.';
            }
        }

        if (btnBuscarClienteDocumento) {
            btnBuscarClienteDocumento.addEventListener('click', buscarClientePorDocumento);
        }

        if (campoBuscaClienteDocumento) {
            campoBuscaClienteDocumento.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter') {
                    evento.preventDefault();
                    buscarClientePorDocumento();
                }
            });
        }

        if (selectClienteId) {
            selectClienteId.addEventListener('change', function () {
                const opcaoSelecionada = selectClienteId.options[selectClienteId.selectedIndex];
                const campoInscricaoMunicipal = document.getElementById('nfse_tomador_inscricao_municipal');
                if (campoInscricaoMunicipal && opcaoSelecionada) {
                    campoInscricaoMunicipal.value = opcaoSelecionada.dataset.inscricaoMunicipal || '';
                }
            });
        }

        const btnMenuHamburguer = document.getElementById('btnMenuHamburguer');
        const menuDropdown = document.getElementById('menuDropdown');
        if (btnMenuHamburguer && menuDropdown) {
            btnMenuHamburguer.addEventListener('click', function (evento) {
                evento.stopPropagation();
                const aberto = menuDropdown.classList.toggle('aberto');
                btnMenuHamburguer.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
            document.addEventListener('click', function (evento) {
                if (!menuDropdown.contains(evento.target) && evento.target !== btnMenuHamburguer) {
                    menuDropdown.classList.remove('aberto');
                    btnMenuHamburguer.setAttribute('aria-expanded', 'false');
                }
            });
        }

        if (window.location.hash === '#cadastroCliente') {
            const detalhesCliente = document.getElementById('cadastroCliente');
            if (detalhesCliente) {
                detalhesCliente.open = true;
                detalhesCliente.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        const catalogo = JSON.parse(<?php echo json_encode($catalogoJson); ?>);
        const codigosTributacaoNacional = <?php echo json_encode($codigosTributacaoNacionalNfse, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mapaCodigosTributacaoNacional = {};
        codigosTributacaoNacional.forEach(function (item) {
            mapaCodigosTributacaoNacional[item.codigo] = item.descricao;
        });
        const variacoesComplementarBH = <?php echo json_encode($variacoesComplementarBH, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const correlacaoNbsPorItemLc116 = <?php echo json_encode($correlacaoNbsNfse['itens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const campoCodigoTributacaoNacional = document.getElementById('nfse_codigo_tributacao_nacional');
        const campoDescricaoServico = document.getElementById('nfse_descricao_servico');
        const campoCodigoTributacaoMunicipal = document.getElementById('nfse_codigo_tributacao_municipal');
        const selectCodigoTributacaoMunicipal = document.getElementById('nfse_codigo_tributacao_municipal_opcoes');
        const campoNbs = document.getElementById('nfse_item_nbs');
        const statusNbs = document.getElementById('nfse_item_nbs_status');
        const nbsSalvaEdicao = String(
            (dadosEdicaoNota && dadosEdicaoNota.nfse && dadosEdicaoNota.nfse.item_nbs)
            || (dadosRestaurar && dadosRestaurar.nfse && dadosRestaurar.nfse.item_nbs)
            || ''
        ).replace(/\D/g, '');
        function atualizarNbsPorServico() {
            if (!campoNbs || !campoCodigoTributacaoNacional) return;
            const partes = campoCodigoTributacaoNacional.value.trim().split('.');
            const itemLc116 = partes.length >= 2 ? partes[0].padStart(2, '0') + '.' + partes[1].padStart(2, '0') : '';
            const correlacao = correlacaoNbsPorItemLc116[itemLc116] || null;
            const opcoes = correlacao && Array.isArray(correlacao.nbs) ? correlacao.nbs : [];
            const valorAnterior = String(campoNbs.value || nbsSalvaEdicao).replace(/\D/g, '');

            campoNbs.innerHTML = '';
            campoNbs.disabled = opcoes.length === 0;
            campoNbs.required = opcoes.length > 0;

            const inicial = document.createElement('option');
            inicial.value = '';
            inicial.textContent = opcoes.length === 0
                ? 'Sem NBS aplicável na correlação oficial'
                : (opcoes.length === 1 ? 'NBS definida automaticamente' : 'Selecione a NBS específica do serviço');
            campoNbs.appendChild(inicial);

            opcoes.forEach(function (item) {
                const opcao = document.createElement('option');
                opcao.value = item.codigo;
                opcao.textContent = item.codigo_formatado + ' - ' + item.descricao;
                campoNbs.appendChild(opcao);
            });

            const valorPermitido = opcoes.some(function (item) { return item.codigo === valorAnterior; });
            if (opcoes.length === 1) {
                campoNbs.value = opcoes[0].codigo;
                if (statusNbs) statusNbs.textContent = 'Preenchida automaticamente pela correlação oficial para ' + itemLc116 + '.';
            } else if (valorPermitido) {
                campoNbs.value = valorAnterior;
                if (statusNbs) statusNbs.textContent = 'NBS salva e compatível com o serviço selecionado.';
            } else if (opcoes.length > 1) {
                campoNbs.value = '';
                if (statusNbs) statusNbs.textContent = opcoes.length + ' NBS oficiais são possíveis. Escolha a descrição exata do serviço.';
            } else if (statusNbs) {
                statusNbs.textContent = 'Este item não possui NBS aplicável no Anexo VIII oficial.';
            }
        }
        function preencherVariacoesComplementarBH(codigo) {
            if (!selectCodigoTributacaoMunicipal) return;
            const partes = codigo.split('.');
            const subitem = partes.length >= 2 ? partes[0] + '.' + partes[1] : null;
            const variacoes = subitem ? variacoesComplementarBH[subitem] : null;
            selectCodigoTributacaoMunicipal.innerHTML = '';
            if (campoCodigoTributacaoMunicipal) campoCodigoTributacaoMunicipal.value = '';
            if (!variacoes || variacoes.length === 0) {
                selectCodigoTributacaoMunicipal.style.display = 'none';
                return;
            }
            const inicial = document.createElement('option');
            inicial.value = '';
            inicial.textContent = 'Catálogo BH: escolha uma descrição para preencher o serviço';
            selectCodigoTributacaoMunicipal.appendChild(inicial);
            variacoes.forEach(function (descricao) {
                const opcao = document.createElement('option');
                opcao.value = descricao;
                opcao.textContent = descricao;
                selectCodigoTributacaoMunicipal.appendChild(opcao);
            });
            selectCodigoTributacaoMunicipal.style.display = '';
        }

        if (campoCodigoTributacaoNacional && campoDescricaoServico) {
            campoCodigoTributacaoNacional.addEventListener('input', function () {
                const codigo = campoCodigoTributacaoNacional.value.trim();
                const descricaoPadrao = mapaCodigosTributacaoNacional[codigo];

                if (descricaoPadrao && campoDescricaoServico.value.trim() === '') {
                    campoDescricaoServico.value = descricaoPadrao;
                }

                if (descricaoPadrao) {
                    preencherVariacoesComplementarBH(codigo);
                } else if (selectCodigoTributacaoMunicipal) {
                    selectCodigoTributacaoMunicipal.style.display = 'none';
                    selectCodigoTributacaoMunicipal.innerHTML = '';
            if (campoCodigoTributacaoMunicipal) campoCodigoTributacaoMunicipal.value = '';
                }
                atualizarNbsPorServico();
            });
            atualizarNbsPorServico();
        }

        if (selectCodigoTributacaoMunicipal && campoDescricaoServico) {
            selectCodigoTributacaoMunicipal.addEventListener('change', function () {
                const selecao = selectCodigoTributacaoMunicipal.value;
                const codigoSelecionado = selecao.match(/^([0-9]{2}\.[0-9]{2}\.[0-9]{2}\.[0-9]{3})(?:\s*-|$)/);
                if (campoCodigoTributacaoMunicipal) {
                    campoCodigoTributacaoMunicipal.value = codigoSelecionado ? codigoSelecionado[1] : '';
                }
                if (selecao && campoDescricaoServico.value.trim() === '') {
                    campoDescricaoServico.value = selecao.replace(/^[0-9.]+\s*-\s*/, '');
                }
            });
        }

        const corpoItens = document.getElementById('corpoItens');
        const empresaSelect = document.getElementById('empresa_emissora_id');
        const totalNotaEl = document.getElementById('totalNota');

        function formatarMoeda(valor) {
            return 'Total estimado: R$ ' + valor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcularTotal() {
            let total = 0;
            corpoItens.querySelectorAll('tr').forEach(function (linha) {
                const qtd = parseFloat((linha.querySelector('.item-quantidade').value || '0').replace(',', '.')) || 0;
                const valorUnit = parseFloat((linha.querySelector('.item-valor').value || '0').replace(',', '.')) || 0;
                total += qtd * valorUnit;
            });
            totalNotaEl.textContent = formatarMoeda(total);
        }

        function montarOpcoesCatalogo(empresaId) {
            let opcoes = '<option value="">Digitar manualmente</option>';
            catalogo.filter(function (item) {
                return String(item.empresa_emissora_id) === String(empresaId);
            }).forEach(function (item) {
                opcoes += '<option value="' + item.id + '">' + item.descricao + '</option>';
            });
            return opcoes;
        }

        function adicionarLinhaItem() {
            const empresaId = empresaSelect ? empresaSelect.value : '';
            const linha = document.createElement('tr');
            linha.innerHTML =
                '<td><select class="item-catalogo">' + montarOpcoesCatalogo(empresaId) + '</select></td>' +
                '<td><input type="text" name="item_descricao[]" class="item-descricao" required></td>' +
                '<td><input type="text" name="item_ncm[]" class="item-ncm"></td>' +
                '<td><input type="text" name="item_cfop[]" class="item-cfop"></td>' +
                '<td><input type="text" name="item_cst[]" class="item-cst"></td>' +
                '<td><input type="text" name="item_unidade[]" class="item-unidade" value="UN"></td>' +
                '<td><input type="text" name="item_quantidade[]" class="item-quantidade" value="1"></td>' +
                '<td><input type="text" name="item_valor_unitario[]" class="item-valor" value="0,00"></td>' +
                '<td><input type="hidden" name="item_produto_id[]" class="item-produto-id" value="0">' +
                '<button type="button" class="btn btn-danger btn-small btn-remover-item"><i class="fa-solid fa-trash"></i></button></td>';

            corpoItens.appendChild(linha);

            const selectCatalogo = linha.querySelector('.item-catalogo');
            selectCatalogo.addEventListener('change', function () {
                const item = catalogo.find(function (candidato) {
                    return String(candidato.id) === selectCatalogo.value;
                });
                if (item) {
                    linha.querySelector('.item-descricao').value = item.descricao || '';
                    linha.querySelector('.item-ncm').value = item.ncm || '';
                    linha.querySelector('.item-cfop').value = item.cfop || '';
                    linha.querySelector('.item-cst').value = item.cst_csosn || '';
                    linha.querySelector('.item-unidade').value = item.unidade || 'UN';
                    linha.querySelector('.item-valor').value = Number(item.valor_unitario_padrao || 0).toFixed(2).replace('.', ',');
                    linha.querySelector('.item-produto-id').value = item.id;
                } else {
                    linha.querySelector('.item-produto-id').value = 0;
                }
                recalcularTotal();
            });

            linha.querySelector('.item-quantidade').addEventListener('input', recalcularTotal);
            linha.querySelector('.item-valor').addEventListener('input', recalcularTotal);
            linha.querySelector('.btn-remover-item').addEventListener('click', function () {
                linha.remove();
                recalcularTotal();
            });

            recalcularTotal();
        }

        const btnAddItem = document.getElementById('btnAddItem');
        if (btnAddItem) {
            btnAddItem.addEventListener('click', adicionarLinhaItem);
            adicionarLinhaItem();
        }

        (function restaurarItensNfeDoErro() {
            const nota = dadosRestaurar && dadosRestaurar.nota ? dadosRestaurar.nota : null;
            const itens = dadosRestaurar && Array.isArray(dadosRestaurar.itens) ? dadosRestaurar.itens : [];
            if (!nota || nota.tipo_nota !== 'nfe' || itens.length === 0 || !corpoItens) return;
            corpoItens.innerHTML = '';
            itens.forEach(function (item) {
                adicionarLinhaItem();
                const linha = corpoItens.lastElementChild;
                if (!linha) return;
                const mapa = {
                    '.item-descricao': item.descricao,
                    '.item-ncm': item.ncm,
                    '.item-cfop': item.cfop,
                    '.item-cst': item.cst_csosn,
                    '.item-unidade': item.unidade,
                    '.item-quantidade': item.quantidade,
                    '.item-valor': item.valor_unitario
                };
                Object.keys(mapa).forEach(function (seletor) {
                    const campoItem = linha.querySelector(seletor);
                    if (campoItem && mapa[seletor] != null) campoItem.value = mapa[seletor];
                });
            });
            recalcularTotal();
        })();

        if (empresaSelect) {
            empresaSelect.addEventListener('change', function () {
                corpoItens.querySelectorAll('.item-catalogo').forEach(function (select) {
                    select.innerHTML = montarOpcoesCatalogo(empresaSelect.value);
                });
                const opcao = empresaSelect.options[empresaSelect.selectedIndex];
                if (opcao && opcao.dataset.ibge) selecionarMunicipioPorCodigo(opcao.dataset.ibge);
            });
        }

        document.querySelectorAll('[data-form-jump]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const alvo = document.getElementById(botao.dataset.formJump);
                if (!alvo) return;
                alvo.open = true;
                alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        const tipoNotaSelect = document.getElementById('tipo_nota');
        const secaoNfe = document.getElementById('secaoNfe');
        const secaoNfse = document.getElementById('secaoNfse');

        const selectTomadorLocal = document.getElementById('nfse_tomador_local');
        const campoTomadorInscricaoMunicipal = document.getElementById('nfse_tomador_inscricao_municipal');

        function atualizarObrigatoriedadeTomador() {
            if (!campoTomadorInscricaoMunicipal || !tipoNotaSelect || !selectTomadorLocal) return;
            campoTomadorInscricaoMunicipal.required = tipoNotaSelect.value === 'nfse' && selectTomadorLocal.value === 'brasil';
        }

        function alternarTipoNota() {
            if (!tipoNotaSelect) return;
            const ehNfse = tipoNotaSelect.value === 'nfse';

            if (secaoNfe) secaoNfe.style.display = ehNfse ? 'none' : '';
            if (secaoNfse) secaoNfse.style.display = ehNfse ? '' : 'none';

            document.querySelectorAll('.item-descricao').forEach(function (campo) {
                campo.required = !ehNfse;
            });

            const descricaoServico = document.getElementById('nfse_descricao_servico');
            const valorServico = document.getElementById('nfse_valor_servico');
            const municipioPrestacao = document.getElementById('nfse_municipio_prestacao_busca');
            const codigoTributacaoNacional = document.getElementById('nfse_codigo_tributacao_nacional');
            const codigoInternoContribuinte = document.getElementById('nfse_codigo_interno_contribuinte');
            [descricaoServico, valorServico, municipioPrestacao, codigoTributacaoNacional, codigoInternoContribuinte].forEach(function (campo) {
                if (campo) campo.required = ehNfse;
            });
            if (municipioPrestacao) {
                const codigoMunicipioPrestacao = document.getElementById('nfse_municipio_prestacao');
                const selecaoInvalida = ehNfse && municipioPrestacao.value.trim() !== '' && (!codigoMunicipioPrestacao || codigoMunicipioPrestacao.value === '');
                municipioPrestacao.setCustomValidity(selecaoInvalida ? 'Selecione um município da lista.' : '');
            }

            atualizarObrigatoriedadeTomador();
        }

        if (tipoNotaSelect) {
            tipoNotaSelect.addEventListener('change', alternarTipoNota);
            alternarTipoNota();
        }

        if (selectTomadorLocal) {
            selectTomadorLocal.addEventListener('change', atualizarObrigatoriedadeTomador);
        }

        const municipioCodigo = document.getElementById('nfse_municipio_prestacao');
        const municipioBusca = document.getElementById('nfse_municipio_prestacao_busca');
        const municipioOpcoes = document.getElementById('nfse_municipio_prestacao_opcoes');
        const municipioStatus = document.getElementById('nfse_municipio_prestacao_status');
        let municipiosIbge = [];

        function normalizarMunicipio(valor) {
            return String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
        }

        function rotuloMunicipio(municipio) {
            return municipio.nome + '/' + municipio.uf;
        }

        function fecharMunicipios() {
            if (!municipioOpcoes || !municipioBusca) return;
            municipioOpcoes.classList.remove('aberto');
            municipioBusca.setAttribute('aria-expanded', 'false');
        }

        function escolherMunicipio(municipio) {
            if (!municipioCodigo || !municipioBusca || !municipioStatus) return;
            municipioCodigo.value = municipio.codigo;
            municipioBusca.value = rotuloMunicipio(municipio);
            municipioStatus.textContent = 'Código IBGE: ' + municipio.codigo;
            municipioBusca.setCustomValidity('');
            fecharMunicipios();
        }

        function selecionarMunicipioPorCodigo(codigo) {
            if (!municipioCodigo) return;
            municipioCodigo.value = String(codigo || '');
            const municipio = municipiosIbge.find(function (item) {
                return String(item.codigo) === String(codigo);
            });
            if (municipio) {
                escolherMunicipio(municipio);
            } else if (municipioBusca && municipioStatus) {
                municipioBusca.value = '';
                municipioBusca.setCustomValidity('');
                municipioStatus.textContent = 'Pesquise pelo início do nome e selecione o município.';
            }
        }

        function renderizarMunicipios() {
            if (!municipioBusca || !municipioOpcoes || !municipioCodigo || !municipioStatus) return;
            const termo = normalizarMunicipio(municipioBusca.value);
            municipioCodigo.value = '';
            municipioStatus.textContent = 'Selecione um município da lista.';
            municipioBusca.setCustomValidity('Selecione um município da lista.');
            municipioOpcoes.innerHTML = '';
            if (termo === '') {
                fecharMunicipios();
                return;
            }

            const encontrados = municipiosIbge.filter(function (municipio) {
                return normalizarMunicipio(municipio.nome).startsWith(termo);
            }).slice(0, 40);

            encontrados.forEach(function (municipio) {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'municipio-opcao';
                botao.setAttribute('role', 'option');
                botao.textContent = rotuloMunicipio(municipio);
                botao.addEventListener('click', function () {
                    escolherMunicipio(municipio);
                });
                municipioOpcoes.appendChild(botao);
            });

            if (encontrados.length === 0) {
                const vazio = document.createElement('span');
                vazio.className = 'muted';
                vazio.style.padding = '0.65rem 0.75rem';
                vazio.textContent = 'Nenhum município encontrado.';
                municipioOpcoes.appendChild(vazio);
            }
            municipioOpcoes.classList.add('aberto');
            municipioBusca.setAttribute('aria-expanded', 'true');
        }

        if (municipioBusca && municipioCodigo && municipioOpcoes) {
            fetch('ibge-municipios.json', { cache: 'force-cache' })
                .then(function (resposta) {
                    if (!resposta.ok) throw new Error('Catálogo de municípios indisponível.');
                    return resposta.json();
                })
                .then(function (municipios) {
                    municipiosIbge = Array.isArray(municipios) ? municipios : [];
                    const opcaoEmpresa = empresaSelect ? empresaSelect.options[empresaSelect.selectedIndex] : null;
                    const municipioSalvo = (dadosEdicaoNota && dadosEdicaoNota.nfse && dadosEdicaoNota.nfse.municipio_prestacao)
                        || (dadosRestaurar && dadosRestaurar.nfse && dadosRestaurar.nfse.municipio_prestacao)
                        || '';
                    if (municipioSalvo) {
                        selecionarMunicipioPorCodigo(municipioSalvo);
                    } else if (opcaoEmpresa && opcaoEmpresa.dataset.ibge) {
                        selecionarMunicipioPorCodigo(opcaoEmpresa.dataset.ibge);
                    }
                })
                .catch(function () {
                    municipioStatus.textContent = 'Não foi possível carregar o catálogo de municípios.';
                });

            municipioBusca.addEventListener('input', renderizarMunicipios);
            municipioBusca.addEventListener('focus', function () {
                if (!municipioCodigo.value) renderizarMunicipios();
            });
            municipioBusca.addEventListener('keydown', function (evento) {
                if (evento.key === 'Escape') fecharMunicipios();
                if (evento.key === 'Enter') {
                    const primeiraOpcao = municipioOpcoes.querySelector('.municipio-opcao');
                    if (primeiraOpcao) {
                        evento.preventDefault();
                        primeiraOpcao.click();
                    }
                }
            });
            document.addEventListener('click', function (evento) {
                if (!evento.target.closest('.municipio-autocomplete')) fecharMunicipios();
            });
        }
        function criarAutocompleteCatalogo(configuracao) {
            const busca = document.getElementById(configuracao.buscaId);
            const codigo = document.getElementById(configuracao.codigoId);
            const opcoes = document.getElementById(configuracao.opcoesId);
            const status = document.getElementById(configuracao.statusId);
            let itens = [];

            function fechar() {
                if (!busca || !opcoes) return;
                opcoes.classList.remove('aberto');
                busca.setAttribute('aria-expanded', 'false');
            }

            function limpar() {
                if (codigo) codigo.value = '';
                if (status) status.textContent = configuracao.textoInicial;
                if (configuracao.aoLimpar) configuracao.aoLimpar();
            }

            function escolher(item) {
                if (!busca || !codigo || !status) return;
                codigo.value = item.codigo;
                busca.value = configuracao.rotulo(item);
                status.textContent = configuracao.detalhe(item);
                busca.setCustomValidity('');
                if (configuracao.aoEscolher) configuracao.aoEscolher(item);
                fechar();
            }

            function renderizar() {
                if (!busca || !codigo || !opcoes || !status) return;
                const termo = normalizarMunicipio(busca.value);
                limpar();
                busca.setCustomValidity(termo === '' ? '' : 'Selecione uma opção da lista oficial.');
                opcoes.innerHTML = '';
                const encontrados = itens.filter(function (item) {
                    return termo === '' || normalizarMunicipio(configuracao.rotulo(item)).includes(termo);
                }).slice(0, 40);

                encontrados.forEach(function (item) {
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'catalogo-opcao';
                    botao.setAttribute('role', 'option');
                    const linhaPrincipal = document.createElement('span');
                    linhaPrincipal.textContent = configuracao.rotulo(item);
                    botao.appendChild(linhaPrincipal);
                    const exemplo = configuracao.exemplo ? configuracao.exemplo(item) : '';
                    if (exemplo) {
                        const linhaExemplo = document.createElement('span');
                        linhaExemplo.className = 'catalogo-opcao-exemplo';
                        linhaExemplo.textContent = exemplo;
                        botao.appendChild(linhaExemplo);
                    }
                    botao.addEventListener('click', function () { escolher(item); });
                    opcoes.appendChild(botao);
                });

                if (encontrados.length === 0) {
                    const vazio = document.createElement('span');
                    vazio.className = 'muted';
                    vazio.style.padding = '0.65rem 0.75rem';
                    vazio.textContent = 'Nenhum código encontrado.';
                    opcoes.appendChild(vazio);
                }
                opcoes.classList.add('aberto');
                busca.setAttribute('aria-expanded', 'true');
            }

            if (busca && codigo && opcoes) {
                busca.addEventListener('input', renderizar);
                busca.addEventListener('focus', renderizar);
                busca.addEventListener('keydown', function (evento) {
                    if (evento.key === 'Escape') fechar();
                    if (evento.key === 'Enter') {
                        const primeira = opcoes.querySelector('.catalogo-opcao');
                        if (primeira) {
                            evento.preventDefault();
                            primeira.click();
                        }
                    }
                });
            }

            return {
                definirItens: function (novosItens) { itens = Array.isArray(novosItens) ? novosItens : []; },
                fechar: fechar
            };
        }

        const campoCstIbsCbs = document.getElementById('nfse_ibscbs_cst');
        const statusCstIbsCbs = document.getElementById('nfse_ibscbs_cst_status');
        let descricoesCstIbsCbs = {};
        const autocompleteCindop = criarAutocompleteCatalogo({
            buscaId: 'nfse_ibscbs_codigo_indicador_operacao_busca',
            codigoId: 'nfse_ibscbs_codigo_indicador_operacao',
            opcoesId: 'nfse_ibscbs_codigo_indicador_operacao_opcoes',
            statusId: 'nfse_ibscbs_codigo_indicador_operacao_status',
            textoInicial: 'Selecione uma opção da tabela oficial.',
            rotulo: function (item) { return item.codigo + ' - ' + item.tipo_operacao + ' — ' + item.local_fornecimento; },
            detalhe: function (item) { return 'cIndOp ' + item.codigo + ': ' + item.caracteristica; },
            exemplo: function (item) { return item.exemplos || ''; }
        });
        const autocompleteCclass = criarAutocompleteCatalogo({
            buscaId: 'nfse_ibscbs_classificacao_tributaria_busca',
            codigoId: 'nfse_ibscbs_classificacao_tributaria',
            opcoesId: 'nfse_ibscbs_classificacao_tributaria_opcoes',
            statusId: 'nfse_ibscbs_classificacao_tributaria_status',
            textoInicial: 'Selecione uma opção vigente e permitida para NFS-e.',
            rotulo: function (item) { return item.codigo + ' - ' + item.nome; },
            detalhe: function (item) { return item.tipo_aliquota + (item.reducao_ibs || item.reducao_cbs ? ' • Redução IBS/CBS: ' + item.reducao_ibs + '%/' + item.reducao_cbs + '%' : ''); },
            aoEscolher: function (item) {
                if (campoCstIbsCbs) campoCstIbsCbs.value = item.cst;
                if (statusCstIbsCbs) statusCstIbsCbs.textContent = item.cst + ' - ' + (descricoesCstIbsCbs[item.cst] || 'CST vinculado à classificação');
            },
            aoLimpar: function () {
                if (campoCstIbsCbs) campoCstIbsCbs.value = '';
                if (statusCstIbsCbs) statusCstIbsCbs.textContent = 'Será definido automaticamente.';
            }
        });

        fetch('nfse-ibs-catalogos.json?v=<?php echo (int) @filemtime(__DIR__ . '/nfse-ibs-catalogos.json'); ?>', { cache: 'force-cache' })
            .then(function (resposta) {
                if (!resposta.ok) throw new Error('Catálogo fiscal indisponível.');
                return resposta.json();
            })
            .then(function (catalogoFiscal) {
                (catalogoFiscal.cst || []).forEach(function (item) { descricoesCstIbsCbs[item.codigo] = item.descricao; });
                autocompleteCindop.definirItens(catalogoFiscal.cindop || []);
                autocompleteCclass.definirItens(catalogoFiscal.cclass || []);
            })
            .catch(function () {
                const statusCindop = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_status');
                const statusCclass = document.getElementById('nfse_ibscbs_classificacao_tributaria_status');
                if (statusCindop) statusCindop.textContent = 'Não foi possível carregar a tabela oficial de cIndOp.';
                if (statusCclass) statusCclass.textContent = 'Não foi possível carregar a tabela oficial de cClassTrib.';
            });

        document.addEventListener('click', function (evento) {
            if (!evento.target.closest('.catalogo-autocomplete')) {
                autocompleteCindop.fechar();
                autocompleteCclass.fechar();
            }
        });
        const checkboxInformarDps = document.getElementById('nfse_informar_dps');
        const camposDpsManual = document.getElementById('camposDpsManual');
        if (checkboxInformarDps && camposDpsManual) {
            checkboxInformarDps.addEventListener('change', function () {
                camposDpsManual.style.display = checkboxInformarDps.checked ? '' : 'none';
            });
            camposDpsManual.style.display = checkboxInformarDps.checked ? '' : 'none';
        }

        const checkboxIntermediario = document.getElementById('nfse_intermediario_incluido');
        const camposIntermediario = document.getElementById('camposIntermediario');
        if (checkboxIntermediario && camposIntermediario) {
            checkboxIntermediario.addEventListener('change', function () {
                camposIntermediario.style.display = checkboxIntermediario.checked ? '' : 'none';
            });
            camposIntermediario.style.display = checkboxIntermediario.checked ? '' : 'none';
        }

        const selectIssqnRetido = document.getElementById('nfse_issqn_retido');
        const campoIssqnRetidoPor = document.getElementById('campoIssqnRetidoPor');
        if (selectIssqnRetido && campoIssqnRetidoPor) {
            selectIssqnRetido.addEventListener('change', function () {
                campoIssqnRetidoPor.style.display = selectIssqnRetido.value === 'sim' ? '' : 'none';
            });
            campoIssqnRetidoPor.style.display = selectIssqnRetido.value === 'sim' ? '' : 'none';
        }

        const selectTributosModo = document.getElementById('nfse_tributos_modo');
        const blocoTributosPercentuais = document.getElementById('tributosPercentuais');
        const blocoTributosValores = document.getElementById('tributosValores');
        if (selectTributosModo && blocoTributosPercentuais && blocoTributosValores) {
            selectTributosModo.addEventListener('change', function () {
                const ehValores = selectTributosModo.value === 'valores';
                blocoTributosPercentuais.style.display = ehValores ? 'none' : '';
                blocoTributosValores.style.display = ehValores ? '' : 'none';
            });
            const ehValoresInicial = selectTributosModo.value === 'valores';
            blocoTributosPercentuais.style.display = ehValoresInicial ? 'none' : '';
            blocoTributosValores.style.display = ehValoresInicial ? '' : 'none';
        }

        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true })
                .finally(() => {
                    window.location.href = '/';
                });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1')
                .then(() => {
                    window.location.href = '/';
                });
        }
    </script>
</body>
</html>
