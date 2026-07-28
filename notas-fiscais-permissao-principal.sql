-- ============================================================
-- BANCO PRINCIPAL (u654041352_Clientes) — rode este arquivo lá
-- ============================================================

ALTER TABLE funcionarios
    ADD COLUMN IF NOT EXISTS permite_notas_fiscais TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto;
