<?php
// Processa a fila de reconciliação EPEC da NFC-e (o único ponto assíncrono do módulo — a
// venda em si é sempre síncrona, ver includes/notas-nfce-motor.php). Varre
// notas_fiscais_nfce.epec_status='pendente_transmissao' e tenta transmitir/vincular via
// nfceVincularEpecPendentes() (nfce-sefaz-integracao.php).
// Pode rodar via navegador (botão manual) ou via CLI/cron:
//   php processar-fila-nfce-epec.php --cli

$viaCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfce-sefaz-integracao.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function contarNotasNfcePendentesEpec(PDO $dbNotas): int
{
    $stmt = $dbNotas->query(
        "SELECT COUNT(*) FROM notas_fiscais_nfce WHERE epec_status = 'pendente_transmissao'"
    );

    return (int) $stmt->fetchColumn();
}

// ------------------------------------------------------------
// Modo CLI (cron): processa a fila direto e sai, sem sessão/HTML.
// ------------------------------------------------------------
if ($viaCli) {
    $dbNotas = obterConexaoNotas();
    $totalPendentes = contarNotasNfcePendentesEpec($dbNotas);
    echo $totalPendentes . " nota(s) NFC-e pendente(s) de vinculação EPEC.\n";

    $resultados = nfceVincularEpecPendentes($dbNotas);
    foreach ($resultados as $resultado) {
        echo "NFC-e #{$resultado['numero_interno']}: {$resultado['situacao']} — {$resultado['detalhe']}\n";
    }

    exit(0);
}

// ------------------------------------------------------------
// Modo navegador: página com botão manual.
// ------------------------------------------------------------
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

$funcionarioId = (int) $_SESSION['funcionario_id'];

$erro = '';
$sucesso = '';
$resultados = [];

try {
    $dbNotas = obterConexaoNotas();

    if (empty($_SESSION['csrf_processar_fila_nfce_epec'])) {
        $_SESSION['csrf_processar_fila_nfce_epec'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'processar') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_processar_fila_nfce_epec'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $resultados = nfceVincularEpecPendentes($dbNotas);
            $sucesso = count($resultados) > 0
                ? ('Processamento concluído: ' . count($resultados) . ' nota(s) avaliada(s).')
                : 'Não havia notas NFC-e pendentes de vinculação EPEC.';
        }
    }

    $totalPendentes = contarNotasNfcePendentesEpec($dbNotas);
    [$integracaoDisponivel, $motivoIndisponivel] = integracaoNfceDisponivel();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar a fila EPEC de NFC-e: ' . $e->getMessage();
    $totalPendentes = 0;
    $integracaoDisponivel = false;
    $motivoIndisponivel = '';
}

$csrf = h($_SESSION['csrf_processar_fila_nfce_epec'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fila EPEC de NFC-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="painel" aria-label="Voltar para o painel">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade" id="logoTopo">
            </a>
            <?php include __DIR__ . '/includes/menu-completo.php'; ?>
        </header>

        <section class="panel">
            <h1>Fila EPEC de NFC-e</h1>
            <p class="muted">Vendas emitidas em contingência (SEFAZ indisponível no momento) ficam aqui até serem vinculadas ao protocolo definitivo. A venda já valeu e o DANFCE de contingência já foi impresso — isto só reconcilia o registro fiscal.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (!$integracaoDisponivel): ?>
            <div class="notice warning">
                <strong>Integração ainda não ativa neste servidor:</strong> <?php echo h($motivoIndisponivel); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($resultados)): ?>
            <section class="panel">
                <h2>Resultado do processamento</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nº interno</th>
                                <th>Situação</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $linha): ?>
                                <tr>
                                    <td>#<?php echo h((string) $linha['numero_interno']); ?></td>
                                    <td><?php echo h($linha['situacao']); ?></td>
                                    <td><?php echo h($linha['detalhe']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;">Notas NFC-e pendentes de vinculação EPEC (<?php echo (int) $totalPendentes; ?>)</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="processar">
                    <button class="btn" type="submit" <?php echo $totalPendentes < 1 ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-paper-plane"></i> Processar fila agora
                    </button>
                </form>
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
    </script>
</body>
</html>
