<?php
/**
 * Bootstrap de schema do módulo NFC-e (modelo 65) — isolado do bootstrap de NF-e/NFS-e
 * (includes/notas-emitir-motor.php). Este arquivo NUNCA inclui nem é incluído por
 * includes/notas-emitir-motor.php; roda de forma idempotente (mesmo padrão
 * colunaExisteNotas()/prepararTabela*()/prepararColuna*() usado lá) a partir do topo de
 * toda página NFC-e.
 *
 * Pressupõe que as tabelas base do emissor fiscal (empresas_emissoras, notas_clientes,
 * notas_fiscais, notas_fiscais_itens, notas_fiscais_log, notas_produtos_servicos) já
 * existem — criadas pelo bootstrap de includes/notas-emitir-motor.php na primeira visita a
 * qualquer tela de NF-e/NFS-e. Este arquivo só adiciona o que é específico de NFC-e:
 * o widen do ENUM tipo_nota, as colunas de série/numeração/CSC em empresas_emissoras e as
 * duas tabelas novas (notas_fiscais_nfce / notas_fiscais_nfce_pagamentos).
 */

require_once __DIR__ . '/../seguranca.php';

function colunaExisteNotasNfce(PDO $db, string $tabela, string $coluna): bool
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

/**
 * Amplia o ENUM notas_fiscais.tipo_nota para incluir 'nfce', preservando os valores
 * 'nfe'/'nfse' já existentes (mudança aditiva, não quebra os === 'nfe'/=== 'nfse' do
 * módulo NF-e/NFS-e).
 */
function prepararEnumTipoNotaNfce(PDO $db): void
{
    $stmt = $db->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_fiscais' AND COLUMN_NAME = 'tipo_nota'"
    );
    $stmt->execute();
    $tipoColuna = (string) $stmt->fetchColumn();

    if ($tipoColuna !== '' && strpos($tipoColuna, "'nfce'") === false) {
        $db->exec("ALTER TABLE notas_fiscais MODIFY COLUMN tipo_nota ENUM('nfe','nfse','nfce') NOT NULL");
    }
}

/**
 * Amplia o ENUM notas_produtos_servicos.tipo para incluir 'nfce' — o catálogo de produtos
 * da NFC-e (notas-nfce-produtos.php) fica em linhas próprias, com tipo='nfce', totalmente
 * separadas das linhas tipo='produto'/'servico' usadas por notas-produtos-servicos.php
 * (NF-e/NFS-e). Mudança aditiva, não quebra os === 'produto'/=== 'servico' existentes.
 */
function prepararEnumTipoProdutoNfce(PDO $db): void
{
    $stmt = $db->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_produtos_servicos' AND COLUMN_NAME = 'tipo'"
    );
    $stmt->execute();
    $tipoColuna = (string) $stmt->fetchColumn();

    if ($tipoColuna !== '' && strpos($tipoColuna, "'nfce'") === false) {
        $db->exec("ALTER TABLE notas_produtos_servicos MODIFY COLUMN tipo ENUM('produto','servico','nfce') NOT NULL");
    }
}

/**
 * Colunas de série/numeração/CSC da NFC-e em empresas_emissoras, paralelas às
 * nfe_serie/nfe_numero_base já existentes para NF-e. nfce_csc_cifrado guarda o token CSC
 * cifrado com o mesmo padrão de certificado_senha_cifrada (criptografarSegredo()/
 * descriptografarSegredo() de config_app_key.php).
 */
function prepararColunasNfceEmpresasEmissoras(PDO $db): void
{
    if (!colunaExisteNotasNfce($db, 'empresas_emissoras', 'nfce_serie')) {
        $db->exec("ALTER TABLE empresas_emissoras ADD COLUMN nfce_serie VARCHAR(3) NOT NULL DEFAULT '1'");
    }
    if (!colunaExisteNotasNfce($db, 'empresas_emissoras', 'nfce_numero_base')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfce_numero_base INT UNSIGNED NOT NULL DEFAULT 0 AFTER nfce_serie');
    }
    if (!colunaExisteNotasNfce($db, 'empresas_emissoras', 'nfce_csc_id')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfce_csc_id VARCHAR(6) NULL AFTER nfce_numero_base');
    }
    if (!colunaExisteNotasNfce($db, 'empresas_emissoras', 'nfce_csc_cifrado')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfce_csc_cifrado VARCHAR(512) NULL AFTER nfce_csc_id');
    }
    if (!colunaExisteNotasNfce($db, 'empresas_emissoras', 'nfce_csc_atualizado_em')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfce_csc_atualizado_em TIMESTAMP NULL AFTER nfce_csc_cifrado');
    }
}

/**
 * Dados específicos de venda de balcão (1:1 com notas_fiscais), incluindo o controle de
 * contingência EPEC (ver includes/notas-nfce-... e nfce-sefaz-integracao.php).
 */
function prepararTabelaNotasFiscaisNfce(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfce (
            nota_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            indicador_presenca TINYINT UNSIGNED NOT NULL DEFAULT 4,
            consumidor_identificado TINYINT UNSIGNED NOT NULL DEFAULT 0,
            consumidor_cpf_cnpj VARCHAR(20) NULL,
            consumidor_nome VARCHAR(180) NULL,
            informacoes_complementares TEXT NULL,
            qrcode_url TEXT NULL,
            modo_emissao ENUM('normal','contingencia_epec') NOT NULL DEFAULT 'normal',
            epec_status ENUM('nenhum','pendente_transmissao','transmitido','vinculado') NOT NULL DEFAULT 'nenhum',
            epec_protocolo VARCHAR(20) NULL,
            epec_enviado_em TIMESTAMP NULL,
            epec_vinculado_em TIMESTAMP NULL,
            CONSTRAINT fk_notas_fiscais_nfce_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Pagamento dividido (N formas de pagamento por venda), ligado 1:N a notas_fiscais.
 */
function prepararTabelaNotasFiscaisNfcePagamentos(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfce_pagamentos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nota_id BIGINT UNSIGNED NOT NULL,
            forma_pagamento_codigo VARCHAR(2) NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            indicador_pagamento TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ordem TINYINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_notas_fiscais_nfce_pagamentos_nota (nota_id),
            CONSTRAINT fk_notas_fiscais_nfce_pagamentos_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Ponto único de entrada: chame isto no topo de toda página NFC-e (mesmo padrão
 * schemaJaPreparada()/marcarSchemaPreparada() usado em includes/notas-emitir-motor.php,
 * mas com uma chave de cache própria para não colidir com o bootstrap de NF-e/NFS-e).
 */
function prepararSchemaNfce(PDO $db): void
{
    if (schemaJaPreparada('notas_nfce_schema')) {
        return;
    }

    prepararEnumTipoNotaNfce($db);
    prepararEnumTipoProdutoNfce($db);
    prepararColunasNfceEmpresasEmissoras($db);
    prepararTabelaNotasFiscaisNfce($db);
    prepararTabelaNotasFiscaisNfcePagamentos($db);

    marcarSchemaPreparada('notas_nfce_schema');
}
