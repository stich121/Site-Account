-- ============================================================
-- BANCO PRINCIPAL (u654041352_Clientes) — rode isso lá
-- ============================================================

ALTER TABLE funcionarios
    ADD COLUMN IF NOT EXISTS permite_notas_fiscais TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto;

-- ============================================================
-- BANCO DE NOTAS FISCAIS (u654041352_NFSe) — rode isso lá
-- ============================================================

CREATE TABLE IF NOT EXISTS empresas_emissoras (
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
    certificado_arquivo VARCHAR(255) NULL,
    certificado_senha_cifrada VARCHAR(512) NULL,
    certificado_atualizado_em TIMESTAMP NULL,
    certificado_atualizado_por INT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresas_emissoras (razao_social, ambiente_emissao, ativo)
VALUES
    ('Account', 'homologacao', 1),
    ('Art Designer', 'homologacao', 1),
    ('Consplatol', 'homologacao', 1),
    ('MC', 'homologacao', 1),
    ('MC2', 'homologacao', 1),
    ('Smarky', 'homologacao', 1),
    ('Tarsos Pizzaria', 'homologacao', 1)
ON DUPLICATE KEY UPDATE razao_social = razao_social;

CREATE TABLE IF NOT EXISTS notas_produtos_servicos (
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
    CONSTRAINT fk_produtos_servicos_empresa
        FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas_clientes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_pessoa ENUM('PJ','PF') NOT NULL,
    nome_razao_social VARCHAR(180) NOT NULL,
    cnpj_cpf VARCHAR(20) NULL,
    inscricao_estadual VARCHAR(30) NULL,
    email VARCHAR(180) NULL,
    logradouro VARCHAR(180) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(120) NULL,
    bairro VARCHAR(120) NULL,
    cep VARCHAR(12) NULL,
    municipio VARCHAR(120) NULL,
    uf CHAR(2) NULL,
    indicador_consumidor_final TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT UNSIGNED NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notas_clientes_nome (nome_razao_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- funcionario_id NÃO tem FOREIGN KEY: a tabela funcionarios vive no banco
-- principal (u654041352_Clientes), diferente deste banco de notas.
CREATE TABLE IF NOT EXISTS notas_fiscais (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas_fiscais_itens (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas_fiscais_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FASE 2 — só rode se você já tinha aplicado o schema da Fase 1
-- antes (se está criando tudo do zero, os CREATE TABLE acima já
-- incluem essas colunas e estes ALTER abaixo não fazem nada de novo).
-- ============================================================

ALTER TABLE notas_fiscais
    ADD COLUMN IF NOT EXISTS chave_acesso VARCHAR(60) NULL AFTER informacoes_frete;

ALTER TABLE notas_fiscais_itens
    ADD COLUMN IF NOT EXISTS codigo_servico_municipal VARCHAR(20) NULL AFTER cst_csosn;

-- ============================================================
-- FASE 2b — certificado digital A1 por empresa emissora
-- ============================================================

ALTER TABLE empresas_emissoras
    ADD COLUMN IF NOT EXISTS certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao,
    ADD COLUMN IF NOT EXISTS certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo,
    ADD COLUMN IF NOT EXISTS certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada,
    ADD COLUMN IF NOT EXISTS certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em;
