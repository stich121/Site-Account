-- ============================================================
-- BANCO DE NOTAS FISCAIS (u654041352_NFSe) — rode isso lá
-- Este arquivo deve ser importado somente nesse banco.
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
    nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1,
    nfse_regime_apuracao_sn TINYINT UNSIGNED NULL,
    nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel',
    nfse_regime_especial_tributacao VARCHAR(60) NULL,
    ambiente_emissao ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
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
    inscricao_municipal VARCHAR(20) NULL,
    email VARCHAR(180) NULL,
    logradouro VARCHAR(180) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(120) NULL,
    bairro VARCHAR(120) NULL,
    cep VARCHAR(12) NULL,
    municipio VARCHAR(120) NULL,
    uf CHAR(2) NULL,
    codigo_ibge_municipio VARCHAR(7) NULL,
    codigo_pais VARCHAR(4) NULL,
    nif VARCHAR(40) NULL,
    motivo_nao_nif VARCHAR(2) NULL,
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
    ADD COLUMN IF NOT EXISTS certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em,
    ADD COLUMN IF NOT EXISTS certificado_validade DATE NULL AFTER certificado_atualizado_por;

-- ============================================================
-- FASE 3 — dados especificos da NFS-e (telas do Portal Nacional / DPS)
-- Tabela 1:1 com notas_fiscais, preenchida só quando tipo_nota = 'nfse'.
-- ============================================================

CREATE TABLE IF NOT EXISTS notas_fiscais_nfse (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FASE 3b — alinhamento com o Guia do Emissor Publico Nacional Web:
-- o campo do tomador e "Inscricao Municipal" (obrigatorio quando o
-- tomador e do Brasil), nao "indicador municipal"; e o bloco "Servico
-- Prestado" tem um "Codigo interno do contribuinte" obrigatorio.
-- Rode isto só se a tabela notas_fiscais_nfse já existia antes desta fase
-- (instalação nova já nasce correta com o CREATE TABLE acima).
-- ============================================================

ALTER TABLE notas_fiscais_nfse
    CHANGE COLUMN IF EXISTS tomador_indicador_municipal tomador_inscricao_municipal VARCHAR(20) NULL;

ALTER TABLE notas_fiscais_nfse
    ADD COLUMN IF NOT EXISTS codigo_interno_contribuinte VARCHAR(60) NULL AFTER codigo_tributacao_municipal;

-- ============================================================
-- FASE 3c — memoriza a Inscricao Municipal do tomador por cliente, para
-- autopreencher o campo na proxima vez que o cliente for buscado/selecionado.
-- ============================================================

ALTER TABLE notas_clientes
    ADD COLUMN IF NOT EXISTS inscricao_municipal VARCHAR(20) NULL AFTER inscricao_estadual;

-- ============================================================
-- FASE 4 — campos fiscais obrigatorios para emissão nacional completa
-- Todos os ALTERs são idempotentes para instalações já existentes.
-- ============================================================

ALTER TABLE empresas_emissoras
    ADD COLUMN IF NOT EXISTS nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER crt,
    ADD COLUMN IF NOT EXISTS nfse_regime_apuracao_sn TINYINT UNSIGNED NULL AFTER nfse_opcao_simples_nacional,
    ADD COLUMN IF NOT EXISTS nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel' AFTER nfse_regime_apuracao_sn,
    ADD COLUMN IF NOT EXISTS nfse_regime_especial_tributacao VARCHAR(60) NULL AFTER nfse_tributacao_issqn;

ALTER TABLE notas_clientes
    ADD COLUMN IF NOT EXISTS codigo_ibge_municipio VARCHAR(7) NULL AFTER uf,
    ADD COLUMN IF NOT EXISTS codigo_pais VARCHAR(4) NULL AFTER codigo_ibge_municipio,
    ADD COLUMN IF NOT EXISTS nif VARCHAR(40) NULL AFTER codigo_pais,
    ADD COLUMN IF NOT EXISTS motivo_nao_nif VARCHAR(2) NULL AFTER nif;

ALTER TABLE notas_fiscais_nfse
    ADD COLUMN IF NOT EXISTS tipo_suspensao_issqn VARCHAR(2) NULL AFTER exigibilidade_issqn_suspensa,
    ADD COLUMN IF NOT EXISTS numero_processo_suspensao VARCHAR(60) NULL AFTER tipo_suspensao_issqn,
    ADD COLUMN IF NOT EXISTS codigo_beneficio_municipal VARCHAR(30) NULL AFTER beneficio_municipal,
    ADD COLUMN IF NOT EXISTS ibscbs_finalidade VARCHAR(2) NOT NULL DEFAULT '0' AFTER tributos_municipal_valor,
    ADD COLUMN IF NOT EXISTS ibscbs_ind_final TINYINT(1) NULL AFTER ibscbs_finalidade,
    ADD COLUMN IF NOT EXISTS ibscbs_codigo_indicador_operacao VARCHAR(10) NULL AFTER ibscbs_ind_final,
    ADD COLUMN IF NOT EXISTS ibscbs_ind_destinatario VARCHAR(2) NULL AFTER ibscbs_codigo_indicador_operacao,
    ADD COLUMN IF NOT EXISTS ibscbs_cst VARCHAR(3) NULL AFTER ibscbs_ind_destinatario,
    ADD COLUMN IF NOT EXISTS ibscbs_classificacao_tributaria VARCHAR(10) NULL AFTER ibscbs_cst;
