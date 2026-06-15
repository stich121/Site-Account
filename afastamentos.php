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
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
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

function prepararCamposFuncionarios(PDO $db): void
{
    $campos = [
        'empresa_nome' => "ALTER TABLE funcionarios ADD COLUMN empresa_nome VARCHAR(120) NULL AFTER usuario",
        'empresa_cnpj' => "ALTER TABLE funcionarios ADD COLUMN empresa_cnpj VARCHAR(20) NULL AFTER empresa_nome",
        'cpf' => "ALTER TABLE funcionarios ADD COLUMN cpf VARCHAR(14) NULL AFTER empresa_cnpj",
        'pis_pasep' => "ALTER TABLE funcionarios ADD COLUMN pis_pasep VARCHAR(20) NULL AFTER cpf",
        'cargo' => "ALTER TABLE funcionarios ADD COLUMN cargo VARCHAR(120) NULL AFTER email",
        'data_admissao' => "ALTER TABLE funcionarios ADD COLUMN data_admissao DATE NULL AFTER cargo",
        'departamento' => "ALTER TABLE funcionarios ADD COLUMN departamento VARCHAR(120) NULL AFTER data_admissao",
        'numero_folha' => "ALTER TABLE funcionarios ADD COLUMN numero_folha VARCHAR(30) NULL AFTER departamento",
        'centro_custo' => "ALTER TABLE funcionarios ADD COLUMN centro_custo VARCHAR(120) NULL AFTER numero_folha",
        'nivel_acesso' => "ALTER TABLE funcionarios ADD COLUMN nivel_acesso TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER cargo",
        'permite_ponto' => "ALTER TABLE funcionarios ADD COLUMN permite_ponto TINYINT(1) NOT NULL DEFAULT 1 AFTER nivel_acesso",
    ];

    foreach ($campos as $campo => $sql) {
        if (!colunaExiste($db, 'funcionarios', $campo)) {
            $db->exec($sql);
        }
    }
}

function prepararTabelaAfastamentos(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS afastamentos (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelasTiposAfastamento(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS tipos_afastamento (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(80) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tipos_afastamento_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS tipos_documento_afastamento (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(80) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tipos_documento_afastamento_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach (['Férias', 'Atestado médico', 'Licença', 'Falta justificada', 'Banco de horas', 'Outro'] as $nome) {
        $stmt = $db->prepare('INSERT IGNORE INTO tipos_afastamento (nome) VALUES (:nome)');
        $stmt->execute(['nome' => $nome]);
    }

    foreach (['Atestado', 'Comunicado', 'Documento interno', 'Outros'] as $nome) {
        $stmt = $db->prepare('INSERT IGNORE INTO tipos_documento_afastamento (nome) VALUES (:nome)');
        $stmt->execute(['nome' => $nome]);
    }
}

function salvarDocumentoAfastamento(array $arquivo): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível carregar o documento do afastamento.');
    }

    if (($arquivo['size'] ?? 0) > 6 * 1024 * 1024) {
        throw new RuntimeException('O documento deve ter no máximo 6 MB.');
    }

    $nomeOriginal = basename((string) ($arquivo['name'] ?? 'documento'));
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if (!in_array($extensao, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Use documento em PDF, JPG, PNG ou WEBP.');
    }

    $diretorio = __DIR__ . '/uploads/afastamentos';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de documentos de afastamento.');
    }

    $nomeSeguro = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($nomeOriginal, PATHINFO_FILENAME));
    $nomeArquivo = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . trim($nomeSeguro, '-') . '.' . $extensao;
    $destino = $diretorio . '/' . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o documento no servidor.');
    }

    return [$nomeOriginal, 'uploads/afastamentos/' . $nomeArquivo];
}

if (empty($_SESSION['csrf_ponto'])) {
    $_SESSION['csrf_ponto'] = bin2hex(random_bytes(32));
}

$funcionariosAdmin = [];
$afastamentosAdmin = [];
$tiposAfastamento = [];
$tiposDocumento = [];

try {
    $db = obterConexao();
    prepararCamposFuncionarios($db);
    prepararTabelaAfastamentos($db);
    prepararTabelasTiposAfastamento($db);

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $afastadoId = (int) ($_POST['funcionario_id'] ?? 0);
            $tipoAfastamento = trim($_POST['tipo_afastamento'] ?? '');
            $motivo = trim($_POST['motivo'] ?? '');
            $dataInicioRaw = trim($_POST['data_inicio'] ?? '');
            $dataFimRaw = trim($_POST['data_fim'] ?? '');
            $tipoDocumento = trim($_POST['tipo_documento'] ?? '');
            $bloquearUsuario = isset($_POST['bloquear_usuario']) ? 1 : 0;

            $dataInicio = DateTimeImmutable::createFromFormat('Y-m-d', $dataInicioRaw);
            $dataFim = DateTimeImmutable::createFromFormat('Y-m-d', $dataFimRaw);

            if ($afastadoId <= 0 || $tipoAfastamento === '' || $motivo === '' || !$dataInicio || !$dataFim) {
                $erro = 'Preencha colaborador, tipo, motivo e período do afastamento.';
            } elseif ($dataFim < $dataInicio) {
                $erro = 'A data final do afastamento não pode ser menor que a data inicial.';
            } else {
                try {
                    [$documentoNome, $documentoCaminho] = salvarDocumentoAfastamento($_FILES['documento'] ?? []);

                    $insert = $db->prepare(
                        'INSERT INTO afastamentos (
                            funcionario_id, criado_por, tipo_afastamento, motivo, data_inicio, data_fim,
                            tipo_documento, documento_nome, documento_caminho, bloquear_usuario
                         ) VALUES (
                            :funcionario_id, :criado_por, :tipo_afastamento, :motivo, :data_inicio, :data_fim,
                            :tipo_documento, :documento_nome, :documento_caminho, :bloquear_usuario
                         )'
                    );
                    $insert->execute([
                        'funcionario_id' => $afastadoId,
                        'criado_por' => $funcionarioId,
                        'tipo_afastamento' => $tipoAfastamento,
                        'motivo' => $motivo,
                        'data_inicio' => $dataInicio->format('Y-m-d'),
                        'data_fim' => $dataFim->format('Y-m-d'),
                        'tipo_documento' => $tipoDocumento !== '' ? $tipoDocumento : null,
                        'documento_nome' => $documentoNome,
                        'documento_caminho' => $documentoCaminho,
                        'bloquear_usuario' => $bloquearUsuario,
                    ]);
                    $sucesso = 'Afastamento cadastrado com sucesso.';
                } catch (RuntimeException $e) {
                    $erro = $e->getMessage();
                }
            }
        }
    }

    $stmt = $db->query(
        'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, cargo, nivel_acesso, permite_ponto, ativo
         FROM funcionarios
         ORDER BY empresa_nome ASC, usuario ASC'
    );
    $funcionariosAdmin = $stmt->fetchAll();

    $tiposAfastamento = $db->query(
        'SELECT nome FROM tipos_afastamento WHERE ativo = 1 ORDER BY nome ASC'
    )->fetchAll();

    $tiposDocumento = $db->query(
        'SELECT nome FROM tipos_documento_afastamento WHERE ativo = 1 ORDER BY nome ASC'
    )->fetchAll();

    $stmt = $db->query(
        'SELECT a.id, a.funcionario_id, a.tipo_afastamento, a.motivo, a.data_inicio, a.data_fim,
                a.tipo_documento, a.documento_nome, a.documento_caminho, a.bloquear_usuario, a.ativo, a.criado_em,
                f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo
         FROM afastamentos a
         INNER JOIN funcionarios f ON f.id = a.funcionario_id
         ORDER BY a.data_inicio DESC, a.id DESC
         LIMIT 200'
    );
    $afastamentosAdmin = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao preparar a página de afastamentos. Confira o banco de dados e o config_db.php.';
}

$usuario = h(nomeExibicao($usuarioRaw));
$csrf = h($_SESSION['csrf_ponto'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Afastamentos | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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
            --border: rgba(255, 255, 255, 0.09);
            --font-titles: 'Montserrat', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            scrollbar-color: var(--primary) transparent;
            scrollbar-width: thin;
        }

        *::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        *::-webkit-scrollbar-button {
            display: none;
            width: 0;
            height: 0;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 999px;
        }

        *::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: 2px solid var(--bg-main);
            border-radius: 999px;
        }

        *::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        *::-webkit-scrollbar-corner {
            background: var(--bg-main);
        }

        body {
            min-height: 100vh;
            font-family: var(--font-body);
            background:
                radial-gradient(circle at 18% 6%, rgba(116, 201, 44, 0.15), transparent 26rem),
                radial-gradient(circle at 82% 0%, rgba(255, 255, 255, 0.07), transparent 22rem),
                linear-gradient(135deg, #070807 0%, #0b0d0b 48%, #050605 100%);
            color: var(--text-light);
            padding: 2rem;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: -20%;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 24% 24%, rgba(116, 201, 44, 0.11), transparent 20rem),
                radial-gradient(circle at 76% 18%, rgba(116, 201, 44, 0.07), transparent 18rem);
            filter: blur(18px);
            animation: ambientShift 14s ease-in-out infinite alternate;
        }

        @keyframes ambientShift {
            from { transform: translate3d(-1.5%, -1%, 0) scale(1); opacity: 0.78; }
            to { transform: translate3d(1.5%, 1%, 0) scale(1.04); opacity: 1; }
        }

        @keyframes pageIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        a { color: inherit; text-decoration: none; }

        .shell {
            width: min(1180px, 100%);
            margin: 0 auto;
            overflow: hidden;
            animation: pageIn 0.55s ease both;
        }

        .topbar,
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar { margin-bottom: 2rem; }

        .brand img {
            height: 34px;
            width: auto;
            display: block;
        }

        .top-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            border: 0;
            border-radius: 4px;
            padding: 0.9rem 1.2rem;
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
            gap: 0.55rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(116, 201, 44, 0.12);
        }

        .btn::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, transparent 0%, rgba(255,255,255,0.24) 45%, transparent 70%);
            transform: translateX(-120%);
            transition: transform 0.55s ease;
        }

        .btn:hover { background: var(--primary-hover); transform: translateY(-2px); }
        .btn:hover::after { transform: translateX(120%); }
        .btn:active { transform: translateY(0); }

        .btn-outline {
            background: transparent;
            color: var(--text-white);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            color: var(--primary);
            border-color: rgba(116, 201, 44, 0.4);
            background: rgba(255,255,255,0.04);
        }

        .panel {
            min-width: 0;
            margin-bottom: 1.25rem;
            padding: 2rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.96), rgba(17, 18, 17, 0.94));
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .panel:hover {
            transform: translateY(-2px);
            border-color: rgba(116, 201, 44, 0.24);
            box-shadow: 0 22px 58px rgba(0, 0, 0, 0.3);
        }

        h1,
        h2 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
        }

        h1 {
            font-size: clamp(2rem, 5vw, 3.2rem);
            line-height: 1;
            margin-bottom: 0.75rem;
        }

        h2 { font-size: 1.35rem; }

        .muted {
            color: var(--text-muted);
            line-height: 1.6;
        }

        .notice {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid rgba(116, 201, 44, 0.3);
            background: rgba(116, 201, 44, 0.08);
            color: var(--text-light);
        }

        .notice.error {
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
            align-items: end;
            margin-top: 1.25rem;
        }

        .field { min-width: 0; }
        .field.wide { grid-column: span 2; }
        .field.full { grid-column: 1 / -1; }

        .field label {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .field select,
        .field input,
        .field textarea {
            width: 100%;
            min-width: 0;
            padding: 0.8rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--bg-main);
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            min-height: 44px;
            color: var(--text-light);
        }

        .checkbox-row input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 920px;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        th,
        td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        th {
            color: var(--text-white);
            font-family: var(--font-titles);
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.5rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-pill.locked {
            background: rgba(255, 69, 58, 0.12);
            color: #FFD1CE;
        }

        input,
        select,
        textarea {
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(116, 201, 44, 0.58);
            box-shadow: 0 0 0 3px rgba(116, 201, 44, 0.12);
            outline: none;
        }

        table tbody tr {
            transition: background 0.18s ease;
        }

        table tbody tr:hover {
            background: rgba(116, 201, 44, 0.045);
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }

        @media (max-width: 700px) {
            body { padding: 1rem; }
            .panel { padding: 1.2rem; }
            .btn { width: 100%; }
            .top-actions { width: 100%; }
            .field.wide { grid-column: auto; }
        }
        /* === FUNDO INTERATIVO COM O MOUSE === */
        :root {
            --cursor-x: 50vw;
            --cursor-y: 28vh;
            --cursor-glow: 0;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: var(--cursor-glow);
            background:
                radial-gradient(circle 360px at var(--cursor-x) var(--cursor-y), rgba(116, 201, 44, 0.22), transparent 68%),
                radial-gradient(circle 220px at calc(var(--cursor-x) + 9rem) calc(var(--cursor-y) + 5rem), rgba(255, 255, 255, 0.11), transparent 72%);
            mix-blend-mode: screen;
            transition: opacity 0.25s ease;
        }

        .shell {
            position: relative;
            z-index: 1;
        }

        .panel,
        .status-pill {
            will-change: transform;
        }

        @media (hover: none), (prefers-reduced-motion: reduce) {
            body::after {
                display: none;
            }
        }
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
                <a class="btn btn-outline" href="tipos-afastamentos"><i class="fa-solid fa-sliders"></i> Tipos</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Afastamentos</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Cadastre afastamentos da equipe, anexe documentos e bloqueie o acesso do funcionário durante o período quando necessário.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <h2>Novo afastamento</h2>
                <span class="muted">Disponível apenas para nível 3.</span>
            </div>

            <form class="form-grid" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">

                <div class="field wide">
                    <label for="funcionario_id">Colaborador</label>
                    <select id="funcionario_id" name="funcionario_id" required>
                        <option value="">Selecione uma opção</option>
                        <?php foreach ($funcionariosAdmin as $funcionarioOpcao): ?>
                            <option value="<?php echo h((string) $funcionarioOpcao['id']); ?>">
                                <?php echo h(($funcionarioOpcao['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($funcionarioOpcao['usuario']) . ' · ' . ($funcionarioOpcao['cargo'] ?? 'Sem cargo')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="tipo_afastamento">Tipo de afastamento</label>
                    <select id="tipo_afastamento" name="tipo_afastamento" required>
                        <option value="">Selecione uma opção</option>
                        <?php foreach ($tiposAfastamento as $tipoOpcao): ?>
                            <option value="<?php echo h($tipoOpcao['nome']); ?>"><?php echo h($tipoOpcao['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="tipo_documento">Tipo de documento</label>
                    <select id="tipo_documento" name="tipo_documento">
                        <option value="">Selecione uma opção</option>
                        <?php foreach ($tiposDocumento as $documentoOpcao): ?>
                            <option value="<?php echo h($documentoOpcao['nome']); ?>"><?php echo h($documentoOpcao['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="data_inicio">Início</label>
                    <input id="data_inicio" name="data_inicio" type="date" required>
                </div>

                <div class="field">
                    <label for="data_fim">Fim</label>
                    <input id="data_fim" name="data_fim" type="date" required>
                </div>

                <div class="field wide">
                    <label for="documento">Documento</label>
                    <input id="documento" name="documento" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp">
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="bloquear_usuario" value="1">
                    Bloquear usuário durante o período
                </label>

                <div class="field full">
                    <label for="motivo">Motivo / observação</label>
                    <textarea id="motivo" name="motivo" required placeholder="Ex.: férias aprovadas, atestado médico, licença, falta justificada..."></textarea>
                </div>

                <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar afastamento</button>
            </form>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Afastamentos cadastrados</h2>
                <span class="muted"><?php echo h((string) count($afastamentosAdmin)); ?> registro(s)</span>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empresa</th>
                            <th>Colaborador</th>
                            <th>Período</th>
                            <th>Tipo</th>
                            <th>Motivo</th>
                            <th>Bloqueio</th>
                            <th>Documento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($afastamentosAdmin)): ?>
                            <tr>
                                <td colspan="8">Nenhum afastamento cadastrado ainda.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($afastamentosAdmin as $afastamentoAdmin): ?>
                            <tr>
                                <td>#<?php echo h((string) $afastamentoAdmin['id']); ?></td>
                                <td><?php echo h($afastamentoAdmin['empresa_nome'] ?? ''); ?><br><span class="muted"><?php echo h($afastamentoAdmin['empresa_cnpj'] ?? ''); ?></span></td>
                                <td><?php echo h(nomeExibicao($afastamentoAdmin['usuario'])); ?><br><span class="muted"><?php echo h($afastamentoAdmin['cpf'] ?? ''); ?></span></td>
                                <td>
                                    <?php echo h((new DateTimeImmutable($afastamentoAdmin['data_inicio']))->format('d/m/Y')); ?>
                                    até
                                    <?php echo h((new DateTimeImmutable($afastamentoAdmin['data_fim']))->format('d/m/Y')); ?>
                                </td>
                                <td><?php echo h($afastamentoAdmin['tipo_afastamento']); ?><br><span class="muted"><?php echo h($afastamentoAdmin['tipo_documento'] ?? ''); ?></span></td>
                                <td><?php echo h($afastamentoAdmin['motivo']); ?></td>
                                <td>
                                    <?php if ((int) $afastamentoAdmin['bloquear_usuario'] === 1): ?>
                                        <span class="status-pill locked"><i class="fa-solid fa-lock"></i> Bloqueado</span>
                                    <?php else: ?>
                                        <span class="status-pill"><i class="fa-solid fa-lock-open"></i> Liberado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($afastamentoAdmin['documento_caminho'])): ?>
                                        <a class="btn btn-outline" href="<?php echo h($afastamentoAdmin['documento_caminho']); ?>" target="_blank" rel="noopener">Abrir</a>
                                    <?php else: ?>
                                        <span class="muted">Sem arquivo</span>
                                    <?php endif; ?>
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
<script>
    (() => {
        const root = document.documentElement;
        if (window.matchMedia('(hover: none), (prefers-reduced-motion: reduce)').matches) return;

        let targetX = window.innerWidth / 2;
        let targetY = window.innerHeight * 0.28;
        let currentX = targetX;
        let currentY = targetY;
        let active = false;

        const animateGlow = () => {
            currentX += (targetX - currentX) * 0.16;
            currentY += (targetY - currentY) * 0.16;
            root.style.setProperty('--cursor-x', `${currentX}px`);
            root.style.setProperty('--cursor-y', `${currentY}px`);
            root.style.setProperty('--cursor-glow', active ? '1' : '0.42');
            requestAnimationFrame(animateGlow);
        };

        window.addEventListener('pointermove', (event) => {
            targetX = event.clientX;
            targetY = event.clientY;
            active = true;
        }, { passive: true });

        window.addEventListener('pointerleave', () => {
            active = false;
        });

        animateGlow();
    })();
</script>
</body>
</html>

