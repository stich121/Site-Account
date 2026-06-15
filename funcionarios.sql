-- Importe este arquivo no phpMyAdmin com o banco de dados já selecionado.
-- Em hospedagens compartilhadas, normalmente você não pode usar CREATE DATABASE.
-- Crie/seleciona o banco pelo painel da hospedagem e importe este arquivo dentro dele.

CREATE TABLE IF NOT EXISTS funcionarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario VARCHAR(80) NOT NULL,
    empresa_nome VARCHAR(120) NULL,
    empresa_cnpj VARCHAR(20) NULL,
    cpf VARCHAR(14) NULL,
    pis_pasep VARCHAR(20) NULL,
    email VARCHAR(180) NOT NULL,
    cargo VARCHAR(120) NULL,
    data_admissao DATE NULL,
    departamento VARCHAR(120) NULL,
    numero_folha VARCHAR(30) NULL,
    centro_custo VARCHAR(120) NULL,
    nivel_acesso TINYINT UNSIGNED NOT NULL DEFAULT 1,
    permite_ponto TINYINT(1) NOT NULL DEFAULT 1,
    senha VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_funcionarios_usuario (usuario),
    UNIQUE KEY uq_funcionarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemplo de usuário inicial.
-- Login: admin
-- E-mail: admin@accountassessoria.com.br
-- Senha: altere-esta-senha
-- No primeiro login correto, o PHP troca esta senha por um hash seguro automaticamente.
-- Troque esta senha após validar o acesso.
INSERT INTO funcionarios (usuario, empresa_nome, empresa_cnpj, cpf, pis_pasep, email, cargo, data_admissao, departamento, numero_folha, centro_custo, nivel_acesso, permite_ponto, senha, ativo)
VALUES (
    'admin',
    'Account',
    '09.334.718/0001-99',
    NULL,
    NULL,
    'admin@accountassessoria.com.br',
    'Administrador',
    NULL,
    'Administrador',
    NULL,
    NULL,
    3,
    0,
    'altere-esta-senha',
    1
)
ON DUPLICATE KEY UPDATE
    empresa_nome = VALUES(empresa_nome),
    empresa_cnpj = VALUES(empresa_cnpj),
    cpf = VALUES(cpf),
    pis_pasep = VALUES(pis_pasep),
    email = VALUES(email),
    cargo = VALUES(cargo),
    data_admissao = VALUES(data_admissao),
    departamento = VALUES(departamento),
    numero_folha = VALUES(numero_folha),
    centro_custo = VALUES(centro_custo),
    nivel_acesso = VALUES(nivel_acesso),
    permite_ponto = VALUES(permite_ponto),
    ativo = VALUES(ativo);

CREATE TABLE IF NOT EXISTS registros_ponto (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    funcionario_id INT UNSIGNED NOT NULL,
    tipo ENUM('chegada','saida_almoco','volta_escritorio','saida_lanche','volta_lanche','saida_escritorio') NOT NULL,
    data_referencia DATE NOT NULL,
    marcado_em DATETIME NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    hash_comprovante CHAR(64) NULL,
    foto_comprovante MEDIUMTEXT NULL,
    foto_mime VARCHAR(40) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    precisao_metros DECIMAL(10,2) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registros_ponto_dia_tipo (funcionario_id, data_referencia, tipo),
    KEY idx_registros_ponto_funcionario_data (funcionario_id, data_referencia),
    CONSTRAINT fk_registros_ponto_funcionario
        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registros de ponto devem ser preservados por pelo menos 5 anos.

CREATE TABLE IF NOT EXISTS solicitacoes_ajuste_ponto (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    funcionario_id INT UNSIGNED NOT NULL,
    tipo_solicitado VARCHAR(40) NOT NULL,
    data_referencia DATE NOT NULL,
    horario_solicitado DATETIME NOT NULL,
    justificativa TEXT NOT NULL,
    status ENUM('pendente','aprovada','recusada') NOT NULL DEFAULT 'pendente',
    registro_ponto_id BIGINT UNSIGNED NULL,
    avaliado_por INT UNSIGNED NULL,
    parecer_admin TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avaliado_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_solicitacoes_funcionario (funcionario_id, criado_em),
    KEY idx_solicitacoes_status (status, criado_em),
    CONSTRAINT fk_solicitacoes_funcionario
        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ajustes_manuais_ponto (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registro_ponto_id BIGINT UNSIGNED NOT NULL,
    funcionario_id INT UNSIGNED NOT NULL,
    operador_id INT UNSIGNED NOT NULL,
    tipo_ponto VARCHAR(40) NOT NULL,
    data_referencia DATE NOT NULL,
    horario_ajustado DATETIME NOT NULL,
    motivo VARCHAR(80) NOT NULL,
    observacoes TEXT NOT NULL,
    tipo_documento VARCHAR(80) NULL,
    documento_nome VARCHAR(180) NULL,
    documento_caminho VARCHAR(255) NULL,
    acao ENUM('criado','alterado') NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ajustes_manuais_funcionario_data (funcionario_id, data_referencia),
    KEY idx_ajustes_manuais_operador (operador_id, criado_em),
    CONSTRAINT fk_ajustes_manuais_registro
        FOREIGN KEY (registro_ponto_id) REFERENCES registros_ponto(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ajustes_manuais_funcionario
        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ajustes_manuais_operador
        FOREIGN KEY (operador_id) REFERENCES funcionarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historico_downloads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    solicitado_por INT UNSIGNED NULL,
    funcionario_id INT UNSIGNED NULL,
    empresa_nome VARCHAR(120) NULL,
    tipo_arquivo VARCHAR(20) NOT NULL,
    item_baixado VARCHAR(120) NOT NULL,
    escopo VARCHAR(30) NOT NULL,
    filtros TEXT NULL,
    arquivo_nome VARCHAR(180) NULL,
    caminho_local VARCHAR(255) NULL,
    drive_status VARCHAR(30) NOT NULL DEFAULT 'pendente',
    drive_file_id VARCHAR(120) NULL,
    drive_link VARCHAR(255) NULL,
    drive_erro TEXT NULL,
    total_registros INT UNSIGNED NOT NULL DEFAULT 0,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_historico_downloads_criado (criado_em),
    KEY idx_historico_downloads_solicitado_por (solicitado_por),
    CONSTRAINT fk_historico_downloads_solicitante
        FOREIGN KEY (solicitado_por) REFERENCES funcionarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_historico_downloads_funcionario
        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS afastamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    funcionario_id INT UNSIGNED NOT NULL,
    criado_por INT UNSIGNED NULL,
    tipo_afastamento VARCHAR(80) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    tipo_documento VARCHAR(80) NULL,
    documento_nome VARCHAR(180) NULL,
    documento_caminho VARCHAR(255) NULL,
    bloquear_usuario TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_afastamentos_funcionario_periodo (funcionario_id, data_inicio, data_fim),
    CONSTRAINT fk_afastamentos_funcionario
        FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipos_afastamento (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(80) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipos_afastamento_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tipos_afastamento (nome) VALUES
    ('Férias'),
    ('Atestado médico'),
    ('Licença'),
    ('Falta justificada'),
    ('Banco de horas'),
    ('Outro');

CREATE TABLE IF NOT EXISTS tipos_documento_afastamento (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(80) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipos_documento_afastamento_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tipos_documento_afastamento (nome) VALUES
    ('Atestado'),
    ('Comunicado'),
    ('Documento interno'),
    ('Outros');
