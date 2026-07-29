<?php
// Diagnóstico do ambiente para a emissão de NF-e (SEFAZ). Confere o que o processo
// de instalação local não consegue garantir: extensões PHP habilitadas no servidor
// real e presença das dependências do Composer relacionadas à NF-e.

require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

$autoload = __DIR__ . '/vendor/autoload.php';
$autoloadPresente = is_file($autoload);
if ($autoloadPresente) {
    require_once $autoload;
}

$extensoes = ['soap', 'curl', 'openssl', 'mbstring', 'dom', 'gd', 'zlib', 'bcmath'];
$statusExtensoes = [];
foreach ($extensoes as $extensao) {
    $statusExtensoes[$extensao] = extension_loaded($extensao);
}

$classesNecessarias = [
    'nfephp-org/sped-nfe (Make)' => '\NFePHP\NFe\Make',
    'nfephp-org/sped-nfe (Tools)' => '\NFePHP\NFe\Tools',
    'nfephp-org/sped-nfe (Complements)' => '\NFePHP\NFe\Complements',
    'nfephp-org/sped-common (Certificate)' => '\NFePHP\Common\Certificate',
    'nfephp-org/sped-da (Danfe)' => '\NFePHP\DA\NFe\Danfe',
];
$statusClasses = [];
foreach ($classesNecessarias as $rotulo => $classe) {
    $statusClasses[$rotulo] = $autoloadPresente && class_exists($classe);
}

$tudoOk = $autoloadPresente
    && !in_array(false, $statusExtensoes, true)
    && !in_array(false, $statusClasses, true);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico NF-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = ''; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1>Diagnóstico do ambiente NF-e</h1>
            <p class="muted">Confere se este servidor tem tudo que a emissão de NF-e (modelo 55) precisa: extensões PHP e bibliotecas do Composer. Rode isto no ambiente real (Hostinger) antes de confiar no envio de verdade — instalação local não garante o mesmo ambiente do servidor.</p>
        </section>

        <div class="notice <?php echo $tudoOk ? '' : 'error'; ?>">
            <i class="fa-solid <?php echo $tudoOk ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <?php echo $tudoOk ? 'Tudo pronto: extensões e bibliotecas necessárias estão disponíveis.' : 'Faltam itens abaixo antes de emitir NF-e de verdade.'; ?>
        </div>

        <section class="panel">
            <h2><i class="fa-solid fa-plug"></i> Extensões PHP</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Extensão</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($statusExtensoes as $extensao => $ok): ?>
                            <tr>
                                <td><?php echo h($extensao); ?></td>
                                <td class="<?php echo $ok ? 'ok' : 'falhou'; ?>"><?php echo $ok ? 'Habilitada' : 'Ausente'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-cubes"></i> Dependências do Composer</h2>
            <p class="muted" style="margin-bottom:0.75rem;">vendor/autoload.php: <strong class="<?php echo $autoloadPresente ? 'ok' : 'falhou'; ?>"><?php echo $autoloadPresente ? 'presente' : 'ausente (rode "composer install")'; ?></strong></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Classe</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($statusClasses as $rotulo => $ok): ?>
                            <tr>
                                <td><?php echo h($rotulo); ?></td>
                                <td class="<?php echo $ok ? 'ok' : 'falhou'; ?>"><?php echo $ok ? 'Disponível' : 'Ausente'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <style>
        .ok { color: var(--primary); font-weight: 700; }
        .falhou { color: var(--danger); font-weight: 700; }
    </style>

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
