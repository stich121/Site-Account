-- Importe este arquivo no phpMyAdmin se a tabela de afastamentos ainda não existir.
-- Ele não apaga dados existentes.

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
