-- Importe este arquivo no phpMyAdmin se quiser criar os cadastros de tipos manualmente.
-- Ele não apaga dados existentes.

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
