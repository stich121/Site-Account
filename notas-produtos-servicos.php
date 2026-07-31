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

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = atualizarNivelAcessoSessao(obterConexao(), $funcionarioId);

if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

$erro = '';
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function formatarNcmExibicao(?string $ncm): string
{
    $digitos = preg_replace('/\D+/', '', (string) $ncm);
    if (strlen($digitos) !== 8) {
        return (string) $ncm;
    }

    return substr($digitos, 0, 4) . '.' . substr($digitos, 4, 2) . '.' . substr($digitos, 6, 2);
}

function prepararTabelaEmpresasEmissorasCatalogo(PDO $db): void
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
            certificado_arquivo VARCHAR(255) NULL,
            certificado_senha_cifrada VARCHAR(512) NULL,
            certificado_atualizado_em TIMESTAMP NULL,
            certificado_atualizado_por INT UNSIGNED NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaProdutosServicos(PDO $db): void
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
            CONSTRAINT fk_produtos_servicos_empresa
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function colunaExisteProdutosServicos(PDO $db, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'notas_produtos_servicos\'
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunasImpostoProdutosServicos(PDO $db): void
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
        if (!colunaExisteProdutosServicos($db, $coluna)) {
            $db->exec($sql);
        }
    }
}

try {
    $db = obterConexaoNotas();
    if (!schemaJaPreparada('notas_produtos_servicos')) {
        prepararTabelaEmpresasEmissorasCatalogo($db);
        prepararTabelaProdutosServicos($db);
        prepararColunasImpostoProdutosServicos($db);
        marcarSchemaPreparada('notas_produtos_servicos');
    }

    if (empty($_SESSION['csrf_notas_produtos_servicos'])) {
        $_SESSION['csrf_notas_produtos_servicos'] = bin2hex(random_bytes(32));
    }

    // Cada empresa emissora só vê (e só cadastra) os próprios itens: o catálogo fica sempre
    // travado na empresa ativa da sessão ("Emitindo por", no topo), igual em notas-fiscais.php.
    $idsEmpresasAtivas = array_map(
        'intval',
        array_column($db->query('SELECT id FROM empresas_emissoras WHERE ativo = 1')->fetchAll(), 'id')
    );
    if (
        empty($_SESSION['nfse_empresa_emissora_ativa_id'])
        || !in_array((int) $_SESSION['nfse_empresa_emissora_ativa_id'], $idsEmpresasAtivas, true)
    ) {
        $_SESSION['nfse_empresa_emissora_ativa_id'] = $idsEmpresasAtivas[0] ?? 0;
    }
    $empresaEmissoraAtivaId = (int) $_SESSION['nfse_empresa_emissora_ativa_id'];
    $stmtEmpresaAtiva = $db->prepare('SELECT razao_social FROM empresas_emissoras WHERE id = :id LIMIT 1');
    $stmtEmpresaAtiva->execute(['id' => $empresaEmissoraAtivaId]);
    $empresaEmissoraAtivaNome = (string) ($stmtEmpresaAtiva->fetchColumn() ?: '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_produtos_servicos'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'adicionar') {
            $empresaId = $empresaEmissoraAtivaId;
            $tipo = ($_POST['tipo'] ?? 'produto') === 'servico' ? 'servico' : 'produto';
            $descricao = trim($_POST['descricao'] ?? '');
            $codigoInterno = trim($_POST['codigo_interno'] ?? '');
            $ncm = preg_replace('/\D+/', '', (string) ($_POST['ncm'] ?? ''));
            $cfop = trim($_POST['cfop'] ?? '');
            $cstCsosn = trim($_POST['cst_csosn'] ?? '');
            $codigoServicoMunicipal = trim($_POST['codigo_servico_municipal'] ?? '');
            $unidade = trim($_POST['unidade'] ?? 'UN');
            $valorUnitario = (float) str_replace(',', '.', (string) ($_POST['valor_unitario_padrao'] ?? '0'));
            $aliquotaIcms = trim((string) ($_POST['aliquota_icms'] ?? ''));
            $aliquotaPis = trim((string) ($_POST['aliquota_pis'] ?? ''));
            $aliquotaCofins = trim((string) ($_POST['aliquota_cofins'] ?? ''));
            $ipiCst = trim((string) ($_POST['ipi_cst'] ?? ''));
            $aliquotaIpi = trim((string) ($_POST['aliquota_ipi'] ?? ''));
            $cean = trim((string) ($_POST['cean'] ?? ''));
            $icmsOrigem = trim((string) ($_POST['icms_origem'] ?? ''));
            $cest = trim((string) ($_POST['cest'] ?? ''));
            $cnpjFabricante = preg_replace('/\D+/', '', (string) ($_POST['cnpj_fabricante'] ?? ''));
            $indicadorEscalaRelevante = in_array($_POST['indicador_escala_relevante'] ?? '', ['S', 'N'], true) ? $_POST['indicador_escala_relevante'] : null;
            $codigoBeneficioFiscal = trim((string) ($_POST['codigo_beneficio_fiscal'] ?? ''));

            if ($empresaId <= 0 || $descricao === '') {
                $erro = 'Selecione a empresa emissora e informe a descrição.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO notas_produtos_servicos (
                        empresa_emissora_id, tipo, descricao, codigo_interno, ncm, cfop, cst_csosn,
                        codigo_servico_municipal, unidade, valor_unitario_padrao, aliquota_icms, aliquota_pis, aliquota_cofins,
                        ipi_cst, aliquota_ipi, cean, icms_origem, cest, cnpj_fabricante, indicador_escala_relevante,
                        codigo_beneficio_fiscal, ativo
                     ) VALUES (
                        :empresa_emissora_id, :tipo, :descricao, :codigo_interno, :ncm, :cfop, :cst_csosn,
                        :codigo_servico_municipal, :unidade, :valor_unitario_padrao, :aliquota_icms, :aliquota_pis, :aliquota_cofins,
                        :ipi_cst, :aliquota_ipi, :cean, :icms_origem, :cest, :cnpj_fabricante, :indicador_escala_relevante,
                        :codigo_beneficio_fiscal, 1
                     )'
                );
                $stmt->execute([
                    'empresa_emissora_id' => $empresaId,
                    'tipo' => $tipo,
                    'descricao' => $descricao,
                    'codigo_interno' => $codigoInterno !== '' ? $codigoInterno : null,
                    'ncm' => $ncm !== '' ? $ncm : null,
                    'cfop' => $cfop !== '' ? $cfop : null,
                    'cst_csosn' => $cstCsosn !== '' ? $cstCsosn : null,
                    'codigo_servico_municipal' => $codigoServicoMunicipal !== '' ? $codigoServicoMunicipal : null,
                    'unidade' => $unidade !== '' ? $unidade : 'UN',
                    'valor_unitario_padrao' => $valorUnitario,
                    'aliquota_icms' => $aliquotaIcms !== '' ? $aliquotaIcms : null,
                    'aliquota_pis' => $aliquotaPis !== '' ? $aliquotaPis : null,
                    'aliquota_cofins' => $aliquotaCofins !== '' ? $aliquotaCofins : null,
                    'ipi_cst' => $ipiCst !== '' ? $ipiCst : null,
                    'aliquota_ipi' => $aliquotaIpi !== '' ? $aliquotaIpi : null,
                    'cean' => $cean !== '' ? $cean : null,
                    'icms_origem' => $icmsOrigem !== '' ? $icmsOrigem : null,
                    'cest' => $cest !== '' ? $cest : null,
                    'cnpj_fabricante' => $cnpjFabricante !== '' ? $cnpjFabricante : null,
                    'indicador_escala_relevante' => $indicadorEscalaRelevante,
                    'codigo_beneficio_fiscal' => $codigoBeneficioFiscal !== '' ? $codigoBeneficioFiscal : null,
                ]);

                $sucesso = 'Item cadastrado no catálogo.';
            }
        } elseif (($_POST['acao'] ?? '') === 'atualizar') {
            $itemId = (int) ($_POST['item_id_edicao'] ?? 0);
            $tipo = ($_POST['tipo'] ?? 'produto') === 'servico' ? 'servico' : 'produto';
            $descricao = trim($_POST['descricao'] ?? '');
            $codigoInterno = trim($_POST['codigo_interno'] ?? '');
            $ncm = preg_replace('/\D+/', '', (string) ($_POST['ncm'] ?? ''));
            $cfop = trim($_POST['cfop'] ?? '');
            $cstCsosn = trim($_POST['cst_csosn'] ?? '');
            $codigoServicoMunicipal = trim($_POST['codigo_servico_municipal'] ?? '');
            $unidade = trim($_POST['unidade'] ?? 'UN');
            $valorUnitario = (float) str_replace(',', '.', (string) ($_POST['valor_unitario_padrao'] ?? '0'));
            $aliquotaIcms = trim((string) ($_POST['aliquota_icms'] ?? ''));
            $aliquotaPis = trim((string) ($_POST['aliquota_pis'] ?? ''));
            $aliquotaCofins = trim((string) ($_POST['aliquota_cofins'] ?? ''));
            $ipiCst = trim((string) ($_POST['ipi_cst'] ?? ''));
            $aliquotaIpi = trim((string) ($_POST['aliquota_ipi'] ?? ''));
            $cean = trim((string) ($_POST['cean'] ?? ''));
            $icmsOrigem = trim((string) ($_POST['icms_origem'] ?? ''));
            $cest = trim((string) ($_POST['cest'] ?? ''));
            $cnpjFabricante = preg_replace('/\D+/', '', (string) ($_POST['cnpj_fabricante'] ?? ''));
            $indicadorEscalaRelevante = in_array($_POST['indicador_escala_relevante'] ?? '', ['S', 'N'], true) ? $_POST['indicador_escala_relevante'] : null;
            $codigoBeneficioFiscal = trim((string) ($_POST['codigo_beneficio_fiscal'] ?? ''));

            if ($itemId <= 0 || $descricao === '') {
                $erro = 'Item inválido ou descrição em branco.';
            } else {
                $stmt = $db->prepare(
                    'UPDATE notas_produtos_servicos SET
                        tipo = :tipo, descricao = :descricao, codigo_interno = :codigo_interno, ncm = :ncm, cfop = :cfop,
                        cst_csosn = :cst_csosn, codigo_servico_municipal = :codigo_servico_municipal, unidade = :unidade,
                        valor_unitario_padrao = :valor_unitario_padrao, aliquota_icms = :aliquota_icms, aliquota_pis = :aliquota_pis,
                        aliquota_cofins = :aliquota_cofins, ipi_cst = :ipi_cst, aliquota_ipi = :aliquota_ipi, cean = :cean,
                        icms_origem = :icms_origem, cest = :cest, cnpj_fabricante = :cnpj_fabricante,
                        indicador_escala_relevante = :indicador_escala_relevante, codigo_beneficio_fiscal = :codigo_beneficio_fiscal
                     WHERE id = :id AND empresa_emissora_id = :empresa_emissora_id'
                );
                $stmt->execute([
                    'empresa_emissora_id' => $empresaEmissoraAtivaId,
                    'tipo' => $tipo,
                    'descricao' => $descricao,
                    'codigo_interno' => $codigoInterno !== '' ? $codigoInterno : null,
                    'ncm' => $ncm !== '' ? $ncm : null,
                    'cfop' => $cfop !== '' ? $cfop : null,
                    'cst_csosn' => $cstCsosn !== '' ? $cstCsosn : null,
                    'codigo_servico_municipal' => $codigoServicoMunicipal !== '' ? $codigoServicoMunicipal : null,
                    'unidade' => $unidade !== '' ? $unidade : 'UN',
                    'valor_unitario_padrao' => $valorUnitario,
                    'aliquota_icms' => $aliquotaIcms !== '' ? $aliquotaIcms : null,
                    'aliquota_pis' => $aliquotaPis !== '' ? $aliquotaPis : null,
                    'aliquota_cofins' => $aliquotaCofins !== '' ? $aliquotaCofins : null,
                    'ipi_cst' => $ipiCst !== '' ? $ipiCst : null,
                    'aliquota_ipi' => $aliquotaIpi !== '' ? $aliquotaIpi : null,
                    'cean' => $cean !== '' ? $cean : null,
                    'icms_origem' => $icmsOrigem !== '' ? $icmsOrigem : null,
                    'cest' => $cest !== '' ? $cest : null,
                    'cnpj_fabricante' => $cnpjFabricante !== '' ? $cnpjFabricante : null,
                    'indicador_escala_relevante' => $indicadorEscalaRelevante,
                    'codigo_beneficio_fiscal' => $codigoBeneficioFiscal !== '' ? $codigoBeneficioFiscal : null,
                    'id' => $itemId,
                ]);

                $sucesso = 'Item atualizado.';
            }
        } elseif (($_POST['acao'] ?? '') === 'desativar') {
            $id = (int) ($_POST['item_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE notas_produtos_servicos SET ativo = 0 WHERE id = :id AND empresa_emissora_id = :empresa_emissora_id');
                $stmt->execute(['id' => $id, 'empresa_emissora_id' => $empresaEmissoraAtivaId]);
                $sucesso = 'Item desativado.';
            }
        } elseif (($_POST['acao'] ?? '') === 'reativar') {
            $id = (int) ($_POST['item_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE notas_produtos_servicos SET ativo = 1 WHERE id = :id AND empresa_emissora_id = :empresa_emissora_id');
                $stmt->execute(['id' => $id, 'empresa_emissora_id' => $empresaEmissoraAtivaId]);
                $sucesso = 'Item reativado.';
            }
        }
    }

    $stmt = $db->query('SELECT id, razao_social FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC');
    $empresasAtivas = $stmt->fetchAll();

    $stmt = $db->prepare(
        'SELECT ps.id, ps.empresa_emissora_id, ps.tipo, ps.descricao, ps.codigo_interno, ps.ncm, ps.cfop,
                ps.cst_csosn, ps.codigo_servico_municipal, ps.unidade, ps.valor_unitario_padrao,
                ps.aliquota_icms, ps.aliquota_pis, ps.aliquota_cofins, ps.ipi_cst, ps.aliquota_ipi, ps.cean, ps.ativo,
                ps.icms_origem, ps.cest, ps.cnpj_fabricante, ps.indicador_escala_relevante, ps.codigo_beneficio_fiscal,
                e.razao_social AS empresa_razao_social
         FROM notas_produtos_servicos ps
         INNER JOIN empresas_emissoras e ON e.id = ps.empresa_emissora_id
         WHERE ps.empresa_emissora_id = :empresa_emissora_id
         ORDER BY ps.ativo DESC, ps.descricao ASC'
    );
    $stmt->execute(['empresa_emissora_id' => $empresaEmissoraAtivaId]);
    $itens = $stmt->fetchAll();

    $itemEmEdicao = null;
    $idEdicao = (int) ($_GET['editar'] ?? 0);
    if ($idEdicao > 0) {
        foreach ($itens as $itemLista) {
            if ((int) $itemLista['id'] === $idEdicao) {
                $itemEmEdicao = $itemLista;
                break;
            }
        }
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar catálogo: ' . $e->getMessage();
    $empresasAtivas = [];
    $itens = [];
    $itemEmEdicao = null;
    $empresaEmissoraAtivaNome = $empresaEmissoraAtivaNome ?? '';
}

$csrf = h($_SESSION['csrf_notas_produtos_servicos'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos e Serviços | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'produtos'; $podeAdministrar = true; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1>Produtos e serviços</h1>
            <p class="muted">Catálogo por empresa emissora, usado como atalho ao montar os itens de uma nota. Não é obrigatório: um item também pode ser digitado manualmente na hora de criar a nota.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (empty($empresasAtivas)): ?>
            <div class="notice error">Cadastre pelo menos uma <a href="notas-empresas-emissoras" style="text-decoration: underline;">empresa emissora</a> antes de adicionar itens ao catálogo.</div>
        <?php else: ?>
            <section class="panel">
                <h2><?php echo $itemEmEdicao ? 'Editar item' : 'Adicionar item'; ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="<?php echo $itemEmEdicao ? 'atualizar' : 'adicionar'; ?>">
                    <?php if ($itemEmEdicao): ?>
                        <input type="hidden" name="item_id_edicao" value="<?php echo h((string) $itemEmEdicao['id']); ?>">
                    <?php endif; ?>
                    <h3><i class="fa-solid fa-tag"></i> Identificação</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <div class="campo-fixo"><?php echo h($empresaEmissoraAtivaNome); ?></div>
                            <p class="muted" style="margin-top:0.35rem;font-size:0.78rem;">O item é cadastrado na empresa ativa (selecionada em "Emitindo por", no topo).</p>
                        </div>
                        <div class="field">
                            <label for="tipo">Tipo</label>
                            <select id="tipo" name="tipo">
                                <option value="produto" <?php echo ($itemEmEdicao['tipo'] ?? '') === 'produto' ? 'selected' : ''; ?>>Produto (NF-e)</option>
                                <option value="servico" <?php echo ($itemEmEdicao['tipo'] ?? '') === 'servico' ? 'selected' : ''; ?>>Serviço (NFS-e)</option>
                            </select>
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="descricao">Descrição</label>
                            <input id="descricao" name="descricao" type="text" required value="<?php echo h($itemEmEdicao['descricao'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="codigo_interno">Código interno</label>
                            <input id="codigo_interno" name="codigo_interno" type="text" value="<?php echo h($itemEmEdicao['codigo_interno'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="cean">GTIN/EAN (código de barras)</label>
                            <input id="cean" name="cean" type="text" placeholder="Opcional" value="<?php echo h($itemEmEdicao['cean'] ?? ''); ?>">
                        </div>
                    </div>

                    <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-file-invoice"></i> Fiscal — produto (NF-e)</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label for="ncm">NCM</label>
                            <input id="ncm" name="ncm" type="text" maxlength="10" placeholder="Digite o código ou o nome do produto" inputmode="numeric" list="datalistNcm" autocomplete="off" value="<?php echo h(formatarNcmExibicao($itemEmEdicao['ncm'] ?? '')); ?>">
                            <datalist id="datalistNcm"></datalist>
                        </div>
                        <div class="field">
                            <label for="cfop">CFOP</label>
                            <input id="cfop" name="cfop" type="text" value="<?php echo h($itemEmEdicao['cfop'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="cst_csosn">CST/CSOSN</label>
                            <input id="cst_csosn" name="cst_csosn" type="text" value="<?php echo h($itemEmEdicao['cst_csosn'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="icms_origem">Origem da mercadoria</label>
                            <?php $origemAtual = $itemEmEdicao !== null && $itemEmEdicao['icms_origem'] !== null ? (int) $itemEmEdicao['icms_origem'] : 0; ?>
                            <select id="icms_origem" name="icms_origem">
                                <option value="0" <?php echo $origemAtual === 0 ? 'selected' : ''; ?>>0 - Nacional</option>
                                <option value="1" <?php echo $origemAtual === 1 ? 'selected' : ''; ?>>1 - Estrangeira, importação direta</option>
                                <option value="2" <?php echo $origemAtual === 2 ? 'selected' : ''; ?>>2 - Estrangeira, mercado interno</option>
                                <option value="3" <?php echo $origemAtual === 3 ? 'selected' : ''; ?>>3 - Nacional, conteúdo import. &gt;40%</option>
                                <option value="4" <?php echo $origemAtual === 4 ? 'selected' : ''; ?>>4 - Nacional, PPB</option>
                                <option value="5" <?php echo $origemAtual === 5 ? 'selected' : ''; ?>>5 - Nacional, conteúdo import. &lt;=40%</option>
                                <option value="6" <?php echo $origemAtual === 6 ? 'selected' : ''; ?>>6 - Estrangeira, imp. direta s/ similar</option>
                                <option value="7" <?php echo $origemAtual === 7 ? 'selected' : ''; ?>>7 - Estrangeira, mercado interno s/ similar</option>
                                <option value="8" <?php echo $origemAtual === 8 ? 'selected' : ''; ?>>8 - Nacional, conteúdo import. &gt;70%</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="cest">CEST</label>
                            <input id="cest" name="cest" type="text" maxlength="7" placeholder="Só se houver ICMS-ST" value="<?php echo h($itemEmEdicao['cest'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="indicador_escala_relevante">Indicador de escala relevante</label>
                            <?php $escalaAtual = $itemEmEdicao['indicador_escala_relevante'] ?? ''; ?>
                            <select id="indicador_escala_relevante" name="indicador_escala_relevante">
                                <option value="">Não se aplica</option>
                                <option value="S" <?php echo $escalaAtual === 'S' ? 'selected' : ''; ?>>Sim, fabricante em escala relevante</option>
                                <option value="N" <?php echo $escalaAtual === 'N' ? 'selected' : ''; ?>>Não</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="cnpj_fabricante">CNPJ do fabricante da mercadoria</label>
                            <input id="cnpj_fabricante" name="cnpj_fabricante" type="text" placeholder="Só se diferente do emitente" value="<?php echo h($itemEmEdicao['cnpj_fabricante'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="codigo_beneficio_fiscal">Código de benefício fiscal na UF</label>
                            <input id="codigo_beneficio_fiscal" name="codigo_beneficio_fiscal" type="text" placeholder="Opcional" value="<?php echo h($itemEmEdicao['codigo_beneficio_fiscal'] ?? ''); ?>">
                        </div>
                    </div>

                    <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-briefcase"></i> Fiscal — serviço (NFS-e)</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label for="codigo_servico_municipal">Código de serviço (LC 116)</label>
                            <input id="codigo_servico_municipal" name="codigo_servico_municipal" type="text" value="<?php echo h($itemEmEdicao['codigo_servico_municipal'] ?? ''); ?>">
                        </div>
                    </div>

                    <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-sack-dollar"></i> Preço e impostos</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label for="unidade">Unidade comercial</label>
                            <input id="unidade" name="unidade" type="text" value="<?php echo h($itemEmEdicao['unidade'] ?? 'UN'); ?>">
                        </div>
                        <div class="field">
                            <label for="valor_unitario_padrao">Preço de venda</label>
                            <input id="valor_unitario_padrao" name="valor_unitario_padrao" type="text" placeholder="0,00" value="<?php echo $itemEmEdicao ? h(number_format((float) $itemEmEdicao['valor_unitario_padrao'], 2, ',', '')) : ''; ?>">
                        </div>
                        <div class="field">
                            <label for="aliquota_icms">Alíquota ICMS (%)</label>
                            <input id="aliquota_icms" name="aliquota_icms" type="text" value="<?php echo h((string) ($itemEmEdicao['aliquota_icms'] ?? '')); ?>">
                        </div>
                        <div class="field">
                            <label for="aliquota_pis">Alíquota PIS (%)</label>
                            <input id="aliquota_pis" name="aliquota_pis" type="text" value="<?php echo h((string) ($itemEmEdicao['aliquota_pis'] ?? '')); ?>">
                        </div>
                        <div class="field">
                            <label for="aliquota_cofins">Alíquota COFINS (%)</label>
                            <input id="aliquota_cofins" name="aliquota_cofins" type="text" value="<?php echo h((string) ($itemEmEdicao['aliquota_cofins'] ?? '')); ?>">
                        </div>
                        <div class="field">
                            <label for="ipi_cst">IPI - CST</label>
                            <input id="ipi_cst" name="ipi_cst" type="text" placeholder="Ex.: 50, 99" value="<?php echo h($itemEmEdicao['ipi_cst'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label for="aliquota_ipi">Alíquota IPI (%)</label>
                            <input id="aliquota_ipi" name="aliquota_ipi" type="text" value="<?php echo h((string) ($itemEmEdicao['aliquota_ipi'] ?? '')); ?>">
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem;" class="row-actions">
                        <button class="btn" type="submit"><i class="fa-solid <?php echo $itemEmEdicao ? 'fa-floppy-disk' : 'fa-plus'; ?>"></i> <?php echo $itemEmEdicao ? 'Salvar alterações' : 'Adicionar ao catálogo'; ?></button>
                        <?php if ($itemEmEdicao): ?>
                            <a class="btn btn-outline" href="notas-produtos-servicos">Cancelar edição</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Itens cadastrados <span class="muted" style="font-weight:400; text-transform:none;">— <?php echo h($empresaEmissoraAtivaNome !== '' ? $empresaEmissoraAtivaNome : 'nenhuma empresa selecionada'); ?></span></h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descrição</th>
                            <th>NCM/CFOP/CST</th>
                            <th>Cód. serviço</th>
                            <th>Unid.</th>
                            <th>Valor padrão</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td><?php echo $item['tipo'] === 'servico' ? 'Serviço' : 'Produto'; ?></td>
                                <td><?php echo h($item['descricao']); ?></td>
                                <td><?php echo h(($item['ncm'] ?? '—') . ' / ' . ($item['cfop'] ?? '—') . ' / ' . ($item['cst_csosn'] ?? '—')); ?></td>
                                <td><?php echo h($item['codigo_servico_municipal'] ?? '—'); ?></td>
                                <td><?php echo h($item['unidade']); ?></td>
                                <td><?php echo 'R$ ' . number_format((float) $item['valor_unitario_padrao'], 2, ',', '.'); ?></td>
                                <td>
                                    <?php if ((int) $item['ativo'] === 1): ?>
                                        <span class="status-active">Ativo</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-outline" href="notas-produtos-servicos?editar=<?php echo h((string) $item['id']); ?>"><i class="fa-solid fa-pen-to-square"></i> Editar</a>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="item_id" value="<?php echo h((string) $item['id']); ?>">
                                            <?php if ((int) $item['ativo'] === 1): ?>
                                                <input type="hidden" name="acao" value="desativar">
                                                <button class="btn btn-danger" type="submit"><i class="fa-solid fa-ban"></i> Desativar</button>
                                            <?php else: ?>
                                                <input type="hidden" name="acao" value="reativar">
                                                <button class="btn btn-outline" type="submit"><i class="fa-solid fa-rotate-left"></i> Reativar</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        function formatarNcmCatalogo(valor) {
            const d = (valor || '').replace(/\D/g, '').slice(0, 8);
            if (d.length > 6) return d.replace(/^(\d{4})(\d{2})(\d{0,2})$/, '$1.$2.$3').replace(/\.$/, '');
            if (d.length > 4) return d.replace(/^(\d{4})(\d{0,2})$/, '$1.$2');
            return d;
        }

        function escaparHtmlCatalogo(texto) {
            return String(texto).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        let ncmCodigosCatalogo = [];
        fetch('ncm-codigos.json', { cache: 'force-cache' })
            .then(function (resposta) { return resposta.json(); })
            .then(function (dados) { ncmCodigosCatalogo = dados; })
            .catch(function () { ncmCodigosCatalogo = []; });

        const datalistNcmCatalogo = document.getElementById('datalistNcm');
        let buscaNcmCatalogoTimeout = null;
        function buscarNcmCatalogo(textoDigitado) {
            if (!datalistNcmCatalogo) return;
            clearTimeout(buscaNcmCatalogoTimeout);
            buscaNcmCatalogoTimeout = setTimeout(function () {
                const termo = (textoDigitado || '').trim().toLowerCase();
                if (termo.length < 2) {
                    datalistNcmCatalogo.innerHTML = '';
                    return;
                }
                const termoDigitos = termo.replace(/\D/g, '');
                const encontrados = [];
                for (let i = 0; i < ncmCodigosCatalogo.length && encontrados.length < 40; i++) {
                    const codigo = ncmCodigosCatalogo[i][0];
                    const descricao = ncmCodigosCatalogo[i][1];
                    const codigoDigitos = codigo.replace(/\D/g, '');
                    const bateCodigo = termoDigitos.length >= 2 && codigoDigitos.indexOf(termoDigitos) === 0;
                    const bateDescricao = descricao.toLowerCase().indexOf(termo) !== -1;
                    if (bateCodigo || bateDescricao) {
                        encontrados.push([codigo, descricao]);
                    }
                }
                datalistNcmCatalogo.innerHTML = encontrados.map(function (item) {
                    return '<option value="' + item[0] + '">' + item[0] + ' - ' + escaparHtmlCatalogo(item[1]) + '</option>';
                }).join('');
            }, 200);
        }

        const campoNcmCatalogo = document.getElementById('ncm');
        if (campoNcmCatalogo) {
            campoNcmCatalogo.addEventListener('input', function () {
                if (/^[\d.]*$/.test(campoNcmCatalogo.value)) {
                    campoNcmCatalogo.value = formatarNcmCatalogo(campoNcmCatalogo.value);
                }
                buscarNcmCatalogo(campoNcmCatalogo.value);
            });
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
