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

if (empty($_SESSION['csrf_ponto'])) {
    $_SESSION['csrf_ponto'] = bin2hex(random_bytes(32));
}

$tiposAfastamento = [];
$tiposDocumento = [];

try {
    $db = obterConexao();
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
            $acao = $_POST['acao'] ?? '';
            $nome = trim($_POST['nome'] ?? '');

            if ($nome === '' || strlen($nome) > 80) {
                $erro = 'Informe um nome válido com até 80 caracteres.';
            } elseif ($acao === 'novo_tipo_afastamento') {
                $stmt = $db->prepare('INSERT INTO tipos_afastamento (nome) VALUES (:nome) ON DUPLICATE KEY UPDATE ativo = 1');
                $stmt->execute(['nome' => $nome]);
                $sucesso = 'Tipo de afastamento salvo com sucesso.';
            } elseif ($acao === 'novo_tipo_documento') {
                $stmt = $db->prepare('INSERT INTO tipos_documento_afastamento (nome) VALUES (:nome) ON DUPLICATE KEY UPDATE ativo = 1');
                $stmt->execute(['nome' => $nome]);
                $sucesso = 'Tipo de documento salvo com sucesso.';
            } else {
                $erro = 'Ação inválida.';
            }
        }
    }

    $tiposAfastamento = $db->query(
        'SELECT id, nome, ativo, criado_em FROM tipos_afastamento ORDER BY ativo DESC, nome ASC'
    )->fetchAll();

    $tiposDocumento = $db->query(
        'SELECT id, nome, ativo, criado_em FROM tipos_documento_afastamento ORDER BY ativo DESC, nome ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao preparar os tipos. Confira o banco de dados e o config_db.php.';
}

$usuario = h(nomeExibicao($usuarioRaw));
$csrf = h($_SESSION['csrf_ponto'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tipos de Afastamento | ACCOUNT Contabilidade</title>
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

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.25rem;
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

        h2 { font-size: 1.25rem; }

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

        .form-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        input {
            width: 100%;
            min-width: 0;
            padding: 0.8rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--bg-main);
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .list {
            display: grid;
            gap: 0.65rem;
            margin-top: 1rem;
        }

        .item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.03);
        }

        .pill {
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            background: rgba(116, 201, 44, 0.12);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
        }

        input,
        select {
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        input:focus,
        select:focus {
            border-color: rgba(116, 201, 44, 0.58);
            box-shadow: 0 0 0 3px rgba(116, 201, 44, 0.12);
            outline: none;
        }

        .list-item {
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .list-item:hover {
            transform: translateX(3px);
            border-color: rgba(116, 201, 44, 0.24);
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
            .top-actions,
            .form-row { grid-template-columns: 1fr; width: 100%; }
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
        .list-item,
        .pill {
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
                <a class="btn btn-outline" href="afastamentos"><i class="fa-solid fa-arrow-left"></i> Voltar aos afastamentos</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Tipos de afastamento</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Cadastre novas opções para os campos de tipo de afastamento e tipo de documento.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <main class="grid">
            <section class="panel">
                <div class="section-title">
                    <h2>Tipos de afastamento</h2>
                    <span class="muted"><?php echo h((string) count($tiposAfastamento)); ?> opção(ões)</span>
                </div>

                <form class="form-row" method="post">
                    <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="novo_tipo_afastamento">
                    <input name="nome" type="text" maxlength="80" placeholder="Ex.: Licença maternidade" required>
                    <button class="btn" type="submit"><i class="fa-solid fa-plus"></i> Adicionar</button>
                </form>

                <div class="list">
                    <?php foreach ($tiposAfastamento as $tipo): ?>
                        <div class="item">
                            <strong><?php echo h($tipo['nome']); ?></strong>
                            <span class="pill"><?php echo (int) $tipo['ativo'] === 1 ? 'Ativo' : 'Inativo'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel">
                <div class="section-title">
                    <h2>Tipos de documento</h2>
                    <span class="muted"><?php echo h((string) count($tiposDocumento)); ?> opção(ões)</span>
                </div>

                <form class="form-row" method="post">
                    <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="novo_tipo_documento">
                    <input name="nome" type="text" maxlength="80" placeholder="Ex.: Declaração médica" required>
                    <button class="btn" type="submit"><i class="fa-solid fa-plus"></i> Adicionar</button>
                </form>

                <div class="list">
                    <?php foreach ($tiposDocumento as $tipo): ?>
                        <div class="item">
                            <strong><?php echo h($tipo['nome']); ?></strong>
                            <span class="pill"><?php echo (int) $tipo['ativo'] === 1 ? 'Ativo' : 'Inativo'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
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

