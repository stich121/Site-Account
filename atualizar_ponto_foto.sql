-- Execute este arquivo no phpMyAdmin somente se o painel mostrar erro ao salvar a foto.
-- Ele adiciona os campos de foto na tabela registros_ponto sem apagar registros antigos.

ALTER TABLE registros_ponto
    ADD COLUMN foto_comprovante MEDIUMTEXT NULL AFTER hash_comprovante,
    ADD COLUMN foto_mime VARCHAR(40) NULL AFTER foto_comprovante,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER foto_mime,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN precisao_metros DECIMAL(10,2) NULL AFTER longitude;
