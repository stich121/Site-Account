<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db_notas.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);

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

try {
    $db = obterConexaoNotas();
    prepararTabelaEmpresasEmissorasCatalogo($db);
    prepararTabelaProdutosServicos($db);

    if (empty($_SESSION['csrf_notas_produtos_servicos'])) {
        $_SESSION['csrf_notas_produtos_servicos'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_produtos_servicos'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'adicionar') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $tipo = ($_POST['tipo'] ?? 'produto') === 'servico' ? 'servico' : 'produto';
            $descricao = trim($_POST['descricao'] ?? '');
            $codigoInterno = trim($_POST['codigo_interno'] ?? '');
            $ncm = trim($_POST['ncm'] ?? '');
            $cfop = trim($_POST['cfop'] ?? '');
            $cstCsosn = trim($_POST['cst_csosn'] ?? '');
            $codigoServicoMunicipal = trim($_POST['codigo_servico_municipal'] ?? '');
            $unidade = trim($_POST['unidade'] ?? 'UN');
            $valorUnitario = (float) str_replace(',', '.', (string) ($_POST['valor_unitario_padrao'] ?? '0'));
            $aliquotaIcms = trim((string) ($_POST['aliquota_icms'] ?? ''));
            $aliquotaPis = trim((string) ($_POST['aliquota_pis'] ?? ''));
            $aliquotaCofins = trim((string) ($_POST['aliquota_cofins'] ?? ''));

            if ($empresaId <= 0 || $descricao === '') {
                $erro = 'Selecione a empresa emissora e informe a descrição.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO notas_produtos_servicos (
                        empresa_emissora_id, tipo, descricao, codigo_interno, ncm, cfop, cst_csosn,
                        codigo_servico_municipal, unidade, valor_unitario_padrao, aliquota_icms, aliquota_pis, aliquota_cofins, ativo
                     ) VALUES (
                        :empresa_emissora_id, :tipo, :descricao, :codigo_interno, :ncm, :cfop, :cst_csosn,
                        :codigo_servico_municipal, :unidade, :valor_unitario_padrao, :aliquota_icms, :aliquota_pis, :aliquota_cofins, 1
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
                ]);

                $sucesso = 'Item cadastrado no catálogo.';
            }
        } elseif (($_POST['acao'] ?? '') === 'desativar') {
            $id = (int) ($_POST['item_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE notas_produtos_servicos SET ativo = 0 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Item desativado.';
            }
        } elseif (($_POST['acao'] ?? '') === 'reativar') {
            $id = (int) ($_POST['item_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE notas_produtos_servicos SET ativo = 1 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Item reativado.';
            }
        }
    }

    $stmt = $db->query('SELECT id, razao_social FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC');
    $empresasAtivas = $stmt->fetchAll();

    $stmt = $db->query(
        'SELECT ps.id, ps.empresa_emissora_id, ps.tipo, ps.descricao, ps.codigo_interno, ps.ncm, ps.cfop,
                ps.cst_csosn, ps.codigo_servico_municipal, ps.unidade, ps.valor_unitario_padrao,
                ps.aliquota_icms, ps.aliquota_pis, ps.aliquota_cofins, ps.ativo,
                e.razao_social AS empresa_razao_social
         FROM notas_produtos_servicos ps
         INNER JOIN empresas_emissoras e ON e.id = ps.empresa_emissora_id
         ORDER BY e.razao_social ASC, ps.ativo DESC, ps.descricao ASC'
    );
    $itens = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar catálogo: ' . $e->getMessage();
    $empresasAtivas = [];
    $itens = [];
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
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --danger: #FF453A;
            --text-white: #FFFFFF;
            --text-light: #F5F5F7;
            --text-muted: #A1A1A6;
            --border: rgba(255,255,255,0.1);
            --font-titles: 'Montserrat', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: var(--font-body);
            background: var(--bg-main);
            color: var(--text-light);
            padding: 2rem;
        }

        .shell { width: min(1180px, 100%); margin: 0 auto; }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .brand img { height: 34px; width: auto; display: block; }
        a { color: inherit; text-decoration: none; }

        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        h1, h2 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
        }

        h1 { font-size: clamp(2rem, 5vw, 3.2rem); margin-bottom: 0.8rem; }
        h2 { font-size: 1.25rem; margin-bottom: 1rem; }
        .muted { color: var(--text-muted); line-height: 1.6; }

        .btn {
            border: 0;
            border-radius: 4px;
            padding: 0.85rem 1rem;
            color: var(--bg-main);
            background: var(--primary);
            font-family: var(--font-titles);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }

        .btn:hover { background: var(--primary-hover); }

        .btn-outline {
            background: transparent;
            color: var(--text-white);
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: transparent;
            color: #FFD1CE;
            border: 1px solid rgba(255, 69, 58, 0.35);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .field { display: grid; gap: 0.4rem; }
        .field label { color: var(--text-muted); font-size: 0.85rem; font-weight: 700; }

        .field input,
        .field select {
            width: 100%;
            padding: 0.85rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: #0A0A0A;
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .notice {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid rgba(116, 201, 44, 0.3);
            background: rgba(116, 201, 44, 0.08);
        }

        .notice.error {
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.8rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { color: var(--text-white); font-family: var(--font-titles); font-size: 0.75rem; text-transform: uppercase; }
        .status-active { color: var(--primary); font-weight: 700; }
        .status-inactive { color: var(--danger); font-weight: 700; }
        .row-actions { display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap; }

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .form-grid { grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="painel" aria-label="Voltar para o painel">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
                <a class="btn btn-outline" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

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
                <h2>Adicionar item</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="adicionar">
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <select id="empresa_emissora_id" name="empresa_emissora_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($empresasAtivas as $empresa): ?>
                                    <option value="<?php echo h((string) $empresa['id']); ?>"><?php echo h($empresa['razao_social']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="tipo">Tipo</label>
                            <select id="tipo" name="tipo">
                                <option value="produto">Produto (NF-e)</option>
                                <option value="servico">Serviço (NFS-e)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="descricao">Descrição</label>
                            <input id="descricao" name="descricao" type="text" required>
                        </div>
                        <div class="field">
                            <label for="codigo_interno">Código interno</label>
                            <input id="codigo_interno" name="codigo_interno" type="text">
                        </div>
                        <div class="field">
                            <label for="ncm">NCM</label>
                            <input id="ncm" name="ncm" type="text" placeholder="Só para produto">
                        </div>
                        <div class="field">
                            <label for="cfop">CFOP</label>
                            <input id="cfop" name="cfop" type="text" placeholder="Só para produto">
                        </div>
                        <div class="field">
                            <label for="cst_csosn">CST/CSOSN</label>
                            <input id="cst_csosn" name="cst_csosn" type="text" placeholder="Só para produto">
                        </div>
                        <div class="field">
                            <label for="codigo_servico_municipal">Código de serviço (LC 116)</label>
                            <input id="codigo_servico_municipal" name="codigo_servico_municipal" type="text" placeholder="Só para serviço">
                        </div>
                        <div class="field">
                            <label for="unidade">Unidade</label>
                            <input id="unidade" name="unidade" type="text" value="UN">
                        </div>
                        <div class="field">
                            <label for="valor_unitario_padrao">Valor unitário padrão</label>
                            <input id="valor_unitario_padrao" name="valor_unitario_padrao" type="text" placeholder="0,00">
                        </div>
                        <div class="field">
                            <label for="aliquota_icms">Alíquota ICMS (%)</label>
                            <input id="aliquota_icms" name="aliquota_icms" type="text">
                        </div>
                        <div class="field">
                            <label for="aliquota_pis">Alíquota PIS (%)</label>
                            <input id="aliquota_pis" name="aliquota_pis" type="text">
                        </div>
                        <div class="field">
                            <label for="aliquota_cofins">Alíquota COFINS (%)</label>
                            <input id="aliquota_cofins" name="aliquota_cofins" type="text">
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn" type="submit"><i class="fa-solid fa-plus"></i> Adicionar ao catálogo</button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Itens cadastrados</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
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
                                <td><?php echo h($item['empresa_razao_social']); ?></td>
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
                                    <form method="post" class="row-actions">
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
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
