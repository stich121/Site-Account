<?php
// Coloca em dia, com TODAS as empresas emissoras que já têm certificado A1 válido, a cópia local
// do buscador de NFS-e (notas_fiscais_nfse_adn), sem precisar que ninguém abra a página.
// Pode rodar via navegador (admin, botão manual) ou via CLI/cron:
//   php processar-nfse-adn-automatico.php --cli
//
// Recomendado no cron do Hostinger (hPanel > Avançado > Cron Jobs), a cada 10-15 minutos:
//   php /caminho/do/site/processar-nfse-adn-automatico.php --cli

$viaCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfse-nacional-integracao.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------
// Modo CLI (cron): sincroniza direto e sai, sem sessão/HTML.
// ------------------------------------------------------------
if ($viaCli) {
    $dbNotas = obterConexaoNotas();
    [$integracaoDisponivelCli, $motivoCli] = integracaoNfseDisponivel();
    if (!$integracaoDisponivelCli) {
        echo "Integração indisponível: {$motivoCli}\n";
        exit(0);
    }

    // CLI não tem o limite de ~30s de uma requisição via navegador. sincronizarNfseAdn() já espaça
    // as chamadas internamente (sleep) pra não levar um bloqueio de rate limit do Portal Nacional -
    // o catch-up de um histórico grande é feito aos poucos, ao longo de várias execuções do cron.
    set_time_limit(180);
    $resultados = sincronizarTodasEmpresasNfseAdn($dbNotas, 10, 150);
    if (empty($resultados)) {
        echo "Nenhuma empresa com certificado A1 válido para sincronizar.\n";
        exit(0);
    }

    foreach ($resultados as $linha) {
        $situacao = $linha['sucesso'] ? 'OK' : 'FALHOU';
        echo "{$linha['empresa']}: {$situacao} — {$linha['total']} documento(s). {$linha['mensagem']}\n";
    }

    exit(0);
}

// ------------------------------------------------------------
// Modo navegador: página admin com botão manual.
// ------------------------------------------------------------
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);

if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

$erro = '';
$sucesso = '';
$resultados = [];

try {
    $dbNotas = obterConexaoNotas();

    if (empty($_SESSION['csrf_processar_nfse_adn'])) {
        $_SESSION['csrf_processar_nfse_adn'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'processar') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_processar_nfse_adn'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            // Orçamento de tempo curto aqui: requisição via navegador costuma ter limite de
            // execução de ~30s. Pra um catch-up retroativo grande, use o cron via CLI (mais acima
            // nesta página) ou clique em "Sincronizar todas agora" várias vezes.
            $resultados = sincronizarTodasEmpresasNfseAdn($dbNotas, 3, 20);
            $sucesso = !empty($resultados)
                ? ('Sincronização concluída: ' . count($resultados) . ' empresa(s) verificada(s).')
                : 'Nenhuma empresa com certificado A1 válido para sincronizar.';
        }
    }

    $empresasElegiveis = empresasComCertificadoValidoAdn($dbNotas);
    [$integracaoDisponivel, $motivoIndisponivel] = integracaoNfseDisponivel();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar a sincronização automática: ' . $e->getMessage();
    $empresasElegiveis = [];
    $integracaoDisponivel = false;
    $motivoIndisponivel = '';
}

$csrf = h($_SESSION['csrf_processar_nfse_adn'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronização Automática do Buscador de NFS-e | ACCOUNT Contabilidade</title>
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
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="menu-hamburguer">
                <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
                    <i class="fa-solid fa-bars"></i> Menu
                </button>
                <div class="menu-dropdown" id="menuDropdown">
                    <a class="btn btn-outline" href="notas-fiscais-nfse-adn"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NFS-e</a>
                    <a class="btn btn-outline" href="notas-fiscais-nfe-dfe"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NF-e</a>
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas fiscais</a>
                    <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                    <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                    <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
                </div>
            </div>
        </header>

        <section class="panel">
            <h1>Sincronização Automática do Buscador de NFS-e</h1>
            <p class="muted">O buscador de NFS-e já sincroniza sozinho uma empresa por visita à página (a que estiver há mais tempo sem sincronizar). Esta tela é só pra rodar manualmente ou configurar num cron — assim todas as empresas ficam em dia mesmo que ninguém abra o buscador.</p>
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

        <div class="notice warning">
            <strong>Para rodar sozinho, sem depender de ninguém abrir esta página:</strong> configure no hPanel da Hostinger (Avançado &gt; Cron Jobs) uma tarefa a cada 10-15 minutos executando:
            <code>php <?php echo h(__DIR__); ?>/processar-nfse-adn-automatico.php --cli</code>
Cada execução do cron avança um pouco mais que uma sincronização manual, mas de forma espaçada (com pausas reais entre as chamadas) pra não levar um bloqueio de rate limit do Portal Nacional. Um histórico retroativo grande (ex.: 3 meses numa empresa que nunca sincronizou) fica em dia ao longo de várias execuções do cron, sem precisar de nada manual.
        </div>

        <?php if (!empty($resultados)): ?>
            <section class="panel">
                <h2>Resultado da última sincronização</h2>
                <div class="table-wrap">
                    <table class="lista">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th>Resultado</th>
                                <th>Documentos novos</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $linha): ?>
                                <tr>
                                    <td><?php echo h($linha['empresa']); ?></td>
                                    <td>
                                        <span class="status-pill <?php echo $linha['sucesso'] ? 'status-autorizada' : 'status-rejeitada'; ?>">
                                            <?php echo $linha['sucesso'] ? 'Em dia' : 'Falhou'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int) $linha['total']; ?></td>
                                    <td><?php echo h($linha['mensagem']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;">Empresas com certificado A1 válido (<?php echo count($empresasElegiveis); ?>)</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="processar">
                    <button class="btn" type="submit" <?php echo empty($empresasElegiveis) ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-rotate"></i> Sincronizar todas agora
                    </button>
                </form>
            </div>
            <div class="table-wrap" style="margin-top: 1rem;">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Ambiente</th>
                            <th>Última sincronização</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresasElegiveis as $empresaLinha): ?>
                            <tr>
                                <td><?php echo h($empresaLinha['razao_social']); ?></td>
                                <td><?php echo ($empresaLinha['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'; ?></td>
                                <td><?php echo !empty($empresaLinha['nfse_adn_sincronizado_em']) ? h(date('d/m/Y H:i', strtotime($empresaLinha['nfse_adn_sincronizado_em']))) : 'ainda não sincronizada'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($empresasElegiveis)): ?>
                            <tr><td colspan="3" class="muted">Nenhuma empresa ativa com certificado A1 válido cadastrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <script>
        const btnMenuHamburguer = document.getElementById('btnMenuHamburguer');
        const menuDropdown = document.getElementById('menuDropdown');
        if (btnMenuHamburguer && menuDropdown) {
            btnMenuHamburguer.addEventListener('click', function (evento) {
                evento.stopPropagation();
                const aberto = menuDropdown.classList.toggle('aberto');
                btnMenuHamburguer.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
            document.addEventListener('click', function (evento) {
                if (!menuDropdown.contains(evento.target) && evento.target !== btnMenuHamburguer) {
                    menuDropdown.classList.remove('aberto');
                    btnMenuHamburguer.setAttribute('aria-expanded', 'false');
                }
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
