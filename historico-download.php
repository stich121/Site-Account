<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;
$erro = '';
$downloads = [];

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function formatarDataHora(?string $valor): string
{
    if (!$valor) {
        return '';
    }

    return (new DateTimeImmutable($valor))->format('d/m/Y H:i:s');
}

function colunaExiste(PDO $db, string $tabela, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute([
        'tabela' => $tabela,
        'coluna' => $coluna,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararTabelaHistoricoDownloads(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS historico_downloads (
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
            KEY idx_historico_downloads_solicitado_por (solicitado_por)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $campos = [
        'caminho_local' => "ALTER TABLE historico_downloads ADD COLUMN caminho_local VARCHAR(255) NULL AFTER arquivo_nome",
        'drive_status' => "ALTER TABLE historico_downloads ADD COLUMN drive_status VARCHAR(30) NOT NULL DEFAULT 'pendente' AFTER caminho_local",
        'drive_file_id' => "ALTER TABLE historico_downloads ADD COLUMN drive_file_id VARCHAR(120) NULL AFTER drive_status",
        'drive_link' => "ALTER TABLE historico_downloads ADD COLUMN drive_link VARCHAR(255) NULL AFTER drive_file_id",
        'drive_erro' => "ALTER TABLE historico_downloads ADD COLUMN drive_erro TEXT NULL AFTER drive_link",
    ];

    foreach ($campos as $campo => $sql) {
        if (!colunaExiste($db, 'historico_downloads', $campo)) {
            $db->exec($sql);
        }
    }

    $db->exec("DELETE FROM historico_downloads WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}

try {
    $db = obterConexao();
    prepararTabelaHistoricoDownloads($db);

    $stmt = $db->prepare('SELECT nivel_acesso FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosAcesso = $stmt->fetch();
    if ($dadosAcesso) {
        $nivelAcesso = (int) ($dadosAcesso['nivel_acesso'] ?? $nivelAcesso);
        $podeAdministrar = $nivelAcesso >= 3;
        $_SESSION['funcionario_nivel_acesso'] = $nivelAcesso;
    }

    if (!$podeAdministrar) {
        header('Location: painel');
        exit;
    }

    $where = ['hd.criado_em >= DATE_SUB(NOW(), INTERVAL 1 MONTH)'];
    $bind = [];
    $itemFiltro = trim($_GET['item'] ?? '');
    $tipoFiltro = trim($_GET['tipo_arquivo'] ?? '');
    $solicitanteFiltro = (int) ($_GET['solicitado_por'] ?? 0);

    if ($itemFiltro !== '') {
        $where[] = 'hd.item_baixado LIKE :item';
        $bind['item'] = '%' . $itemFiltro . '%';
    }

    if (in_array($tipoFiltro, ['csv', 'pdf'], true)) {
        $where[] = 'hd.tipo_arquivo = :tipo_arquivo';
        $bind['tipo_arquivo'] = $tipoFiltro;
    }

    if ($solicitanteFiltro > 0) {
        $where[] = 'hd.solicitado_por = :solicitado_por';
        $bind['solicitado_por'] = $solicitanteFiltro;
    }

    $stmt = $db->prepare(
        'SELECT hd.*, s.usuario AS solicitante_usuario, f.usuario AS funcionario_usuario
         FROM historico_downloads hd
         LEFT JOIN funcionarios s ON s.id = hd.solicitado_por
         LEFT JOIN funcionarios f ON f.id = hd.funcionario_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY hd.criado_em DESC
         LIMIT 500'
    );
    $stmt->execute($bind);
    $downloads = $stmt->fetchAll();

    $solicitantes = $db->query(
        'SELECT DISTINCT f.id, f.usuario
         FROM historico_downloads hd
         INNER JOIN funcionarios f ON f.id = hd.solicitado_por
         WHERE hd.criado_em >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
         ORDER BY f.usuario ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao preparar o histórico de download. Confira o banco de dados e o config_db.php.';
    $solicitantes = [];
}

$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Download | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-main:#0A0A0A; --bg-card:#161616; --primary:#74C92C; --primary-hover:#5EA522; --danger:#FF453A; --text-white:#FFFFFF; --text-light:#F5F5F7; --text-muted:#A1A1A6; --border:rgba(255,255,255,0.09); --font-titles:'Montserrat',sans-serif; --font-body:'Inter',sans-serif; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { min-height:100vh; font-family:var(--font-body); background:linear-gradient(135deg,#070807 0%,#0b0d0b 48%,#050605 100%); color:var(--text-light); padding:2rem; }
        a { color:inherit; text-decoration:none; }
        .shell { width:min(1180px,100%); margin:0 auto; }
        .topbar,.section-title,.top-actions { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .topbar { margin-bottom:2rem; }
        .brand img { height:34px; display:block; }
        .top-actions { justify-content:flex-start; }
        .btn { border:0; border-radius:4px; padding:0.9rem 1.2rem; color:var(--bg-main); background:var(--primary); font-family:var(--font-titles); font-size:0.82rem; font-weight:700; text-transform:uppercase; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:0.55rem; }
        .btn:hover { background:var(--primary-hover); transform:translateY(-2px); }
        .btn-outline { background:transparent; color:var(--text-white); border:1px solid var(--border); }
        .btn-outline:hover { color:var(--primary); border-color:rgba(116,201,44,0.4); background:rgba(255,255,255,0.04); }
        .panel { min-width:0; margin-bottom:1.25rem; padding:2rem; border-radius:8px; border:1px solid var(--border); background:linear-gradient(145deg,rgba(24,24,24,0.96),rgba(17,18,17,0.94)); box-shadow:0 18px 45px rgba(0,0,0,0.22); }
        h1,h2 { font-family:var(--font-titles); color:var(--text-white); text-transform:uppercase; }
        h1 { font-size:clamp(2rem,5vw,3.2rem); line-height:1; margin-bottom:0.75rem; }
        h2 { font-size:1.35rem; }
        .muted { color:var(--text-muted); line-height:1.6; }
        .notice { margin-bottom:1rem; padding:1rem; border-radius:8px; border:1px solid rgba(255,69,58,0.35); background:rgba(255,69,58,0.08); color:#FFD1CE; }
        .filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:0.85rem; align-items:end; margin-top:1.25rem; }
        .field label { display:block; margin-bottom:0.35rem; color:var(--text-muted); font-size:0.78rem; font-weight:700; text-transform:uppercase; }
        .field input,.field select { width:100%; padding:0.8rem; border-radius:4px; border:1px solid var(--border); background:var(--bg-main); color:var(--text-white); font-family:var(--font-body); }
        .table-wrap { width:100%; overflow-x:auto; }
        table { width:100%; min-width:980px; border-collapse:collapse; font-size:0.88rem; }
        th,td { padding:0.75rem; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }
        th { color:var(--text-white); font-family:var(--font-titles); font-size:0.75rem; text-transform:uppercase; }
        tbody tr:hover { background:rgba(116,201,44,0.045); }
        .pill { display:inline-flex; padding:0.3rem 0.55rem; border-radius:999px; background:rgba(116,201,44,0.12); color:var(--primary); font-size:0.74rem; font-weight:800; text-transform:uppercase; }
        .pill.error { background:rgba(255,69,58,0.12); color:#FFD1CE; }
        .pill.pending { background:rgba(255,255,255,0.08); color:var(--text-muted); }
        @media (max-width:700px) { body{padding:1rem;} .panel{padding:1.2rem;} .btn{width:100%;} .top-actions{width:100%;} }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Voltar para o site">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-arrow-left"></i> Voltar ao painel</a>
                <a class="btn btn-outline" href="historico-espelho"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de espelho</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Histórico de Download</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Esta subpágina guarda por 1 mês os downloads solicitados, com item baixado, formato, filtros usados e solicitante.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <h2>Buscar downloads</h2>
                <span class="muted"><?php echo h((string) count($downloads)); ?> registro(s)</span>
            </div>
            <form class="filters" method="get">
                <div class="field">
                    <label for="item">Item baixado</label>
                    <input id="item" name="item" value="<?php echo h($_GET['item'] ?? ''); ?>" placeholder="Espelho de ponto">
                </div>
                <div class="field">
                    <label for="tipo_arquivo">Formato</label>
                    <select id="tipo_arquivo" name="tipo_arquivo">
                        <option value="">Todos</option>
                        <option value="csv" <?php echo ($_GET['tipo_arquivo'] ?? '') === 'csv' ? 'selected' : ''; ?>>CSV</option>
                        <option value="pdf" <?php echo ($_GET['tipo_arquivo'] ?? '') === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                    </select>
                </div>
                <div class="field">
                    <label for="solicitado_por">Solicitante</label>
                    <select id="solicitado_por" name="solicitado_por">
                        <option value="">Todos</option>
                        <?php foreach ($solicitantes as $solicitante): ?>
                            <option value="<?php echo h((string) $solicitante['id']); ?>" <?php echo (string) ($_GET['solicitado_por'] ?? '') === (string) $solicitante['id'] ? 'selected' : ''; ?>>
                                <?php echo h(nomeExibicao($solicitante['usuario'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </form>
        </section>

        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Solicitante</th>
                            <th>Item</th>
                            <th>Arquivo</th>
                            <th>Google Drive</th>
                            <th>Escopo</th>
                            <th>Filtros</th>
                            <th>Registros</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($downloads)): ?>
                            <tr><td colspan="8">Nenhum download encontrado no período de 1 mês.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($downloads as $download): ?>
                            <tr>
                                <td><?php echo h(formatarDataHora($download['criado_em'])); ?></td>
                                <td><?php echo h(nomeExibicao($download['solicitante_usuario'] ?? '')); ?></td>
                                <td><?php echo h($download['item_baixado']); ?><br><span class="pill"><?php echo h(strtoupper($download['tipo_arquivo'])); ?></span></td>
                                <td><?php echo h($download['arquivo_nome'] ?? ''); ?></td>
                                <td>
                                    <?php $driveStatus = (string) ($download['drive_status'] ?? 'pendente'); ?>
                                    <span class="pill <?php echo $driveStatus === 'erro' ? 'error' : ($driveStatus === 'pendente' ? 'pending' : ''); ?>">
                                        <?php echo h($driveStatus); ?>
                                    </span>
                                    <?php if (!empty($download['drive_link'])): ?>
                                        <br><a class="muted" href="<?php echo h($download['drive_link']); ?>" target="_blank" rel="noopener">Abrir no Drive</a>
                                    <?php elseif (!empty($download['drive_erro'])): ?>
                                        <br><span class="muted"><?php echo h($download['drive_erro']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($download['escopo']); ?><br><span class="muted"><?php echo h($download['empresa_nome'] ?? ''); ?></span></td>
                                <td><span class="muted"><?php echo h($download['filtros'] ?? ''); ?></span></td>
                                <td><?php echo h((string) $download['total_registros']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true }).finally(() => { window.location.href = '/'; });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1').then(() => { window.location.href = '/'; });
        }
    </script>
</body>
</html>
