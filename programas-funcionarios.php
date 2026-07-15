<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programas dos Funcionários | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #74C92C;
            --primary-dark: #5FA623;
            --bg-main: #0A0A0A;
            --bg-card: #141414;
            --border: rgba(255, 255, 255, 0.12);
            --text-white: #FFFFFF;
            --text-light: #D7D7D7;
            --text-muted: #A7A7AC;
            --font-body: 'Inter', sans-serif;
            --font-titles: 'Montserrat', sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 8% 0%, rgba(116, 201, 44, 0.18), transparent 28rem),
                linear-gradient(135deg, #050705 0%, var(--bg-main) 52%, #101010 100%);
            color: var(--text-light);
            font-family: var(--font-body);
        }

        a {
            color: inherit;
        }

        .shell {
            width: min(1180px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 1.5rem 0 3rem;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .brand img {
            width: 154px;
            max-width: 42vw;
            display: block;
        }

        .top-actions,
        .program-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 44px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--primary);
            border-radius: 6px;
            background: var(--primary);
            color: #071006;
            font-weight: 800;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0;
            cursor: pointer;
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-white);
            border-color: var(--border);
        }

        .btn:hover,
        .btn:focus {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            outline: none;
        }

        .btn-outline:hover,
        .btn-outline:focus {
            background: rgba(116, 201, 44, 0.1);
            border-color: rgba(116, 201, 44, 0.45);
            color: var(--primary);
        }

        .panel {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.98), rgba(16, 17, 16, 0.96));
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
            padding: clamp(1.25rem, 3vw, 2rem);
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        h1,
        h2,
        h3 {
            margin: 0;
            font-family: var(--font-titles);
            color: var(--text-white);
            letter-spacing: 0;
        }

        h1 {
            font-size: clamp(2rem, 5vw, 4.4rem);
            line-height: 0.98;
            text-transform: uppercase;
        }

        h2 {
            font-size: clamp(1.35rem, 2.5vw, 2rem);
            text-transform: uppercase;
        }

        p {
            margin: 0;
            line-height: 1.65;
        }

        .muted {
            color: var(--text-muted);
        }

        .hero {
            margin-bottom: 1.25rem;
        }

        .hero p {
            max-width: 780px;
            margin-top: 0.9rem;
            font-size: 1.02rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            width: fit-content;
            padding: 0.45rem 0.75rem;
            border-radius: 999px;
            background: rgba(116, 201, 44, 0.12);
            border: 1px solid rgba(116, 201, 44, 0.25);
            color: var(--primary);
            font-weight: 800;
            text-transform: uppercase;
        }

        .programs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .program-card {
            display: grid;
            gap: 0.95rem;
            align-content: start;
            padding: 1.15rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
        }

        .program-card h3 {
            font-size: 1.15rem;
        }

        .program-card ul {
            display: grid;
            gap: 0.55rem;
            margin: 0;
            padding: 0;
            list-style: none;
            color: var(--text-muted);
            line-height: 1.45;
        }

        .program-card li {
            display: flex;
            gap: 0.55rem;
            align-items: flex-start;
        }

        .program-card li i {
            margin-top: 0.22rem;
            color: var(--primary);
        }

        @media (max-width: 640px) {
            .topbar,
            .section-title {
                align-items: stretch;
                flex-direction: column;
            }

            .btn {
                width: 100%;
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
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="hero panel">
            <span class="badge"><i class="fa-solid fa-laptop-code"></i> Programas internos</span>
            <h1>Fiscal e Contábil</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Baixe o pacote do setor, extraia a pasta no Windows e abra o arquivo <code>.pyw</code> com duplo clique.</p>
        </section>

        <main class="panel">
            <div class="section-title">
                <h2>Programas dos funcionários</h2>
                <span class="muted">Downloads nativos</span>
            </div>

            <div class="programs-grid">
                <article class="program-card">
                    <span class="badge"><i class="fa-solid fa-file-invoice-dollar"></i> Fiscal</span>
                    <h3>Verificador Fiscal ZIP</h3>
                    <p class="muted">Confere XMLs de notas fiscais dentro de um arquivo ZIP e gera uma planilha com impostos, status e alertas.</p>
                    <ul>
                        <li><i class="fa-solid fa-check"></i><span>Arquivo principal: VerificadorFiscalZIP.pyw</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Entrada: ZIP com XMLs de NF-e, NFC-e, CT-e ou NFS-e.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Saída: Excel de conferência fiscal.</span></li>
                    </ul>
                    <div class="program-actions">
                        <a class="btn" href="downloads/programa-fiscal-verificador-zip.zip" download><i class="fa-solid fa-download"></i> Baixar Fiscal</a>
                    </div>
                </article>

                <article class="program-card">
                    <span class="badge"><i class="fa-solid fa-building-columns"></i> Contábil</span>
                    <h3>OFX para Excel e PDF</h3>
                    <p class="muted">Converte extratos bancários OFX brasileiros para Excel e também permite gerar PDF pelo script incluído no pacote.</p>
                    <ul>
                        <li><i class="fa-solid fa-check"></i><span>Arquivo principal: OFX2Excel.pyw</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Entrada: extrato bancário em formato OFX.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Saída: Excel no layout Data, d, c, Valor, Vazio e histórico.</span></li>
                    </ul>
                    <div class="program-actions">
                        <a class="btn" href="downloads/programa-contabil-ofx2excel.zip" download><i class="fa-solid fa-download"></i> Baixar Contábil</a>
                    </div>
                </article>
            </div>
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
</body>
</html>
