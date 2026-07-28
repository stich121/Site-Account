<?php
// Processa a fila de NFS-e pendentes de envio ao Portal Nacional (SEFIN Nacional).
// Pode rodar via navegador (admin, botão manual) ou via CLI/cron:
//   php processar-fila-nfse.php --cli

$viaCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfse-operacoes.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function rotuloStatusNotaFila(string $status): string
{
    return [
        'rascunho' => 'Rascunho',
        'pendente_envio' => 'Pendente de envio',
        'autorizada' => 'Autorizada',
        'rejeitada' => 'Rejeitada',
        'cancelada' => 'Cancelada',
    ][$status] ?? $status;
}

function buscarNotasPendentesNfse(PDO $dbNotas): array
{
    $stmt = $dbNotas->query(
        "SELECT n.id, n.numero_interno, n.empresa_emissora_id, n.cliente_id, n.funcionario_id, n.ambiente,
                n.natureza_operacao, n.data_emissao, n.valor_total,
                e.razao_social AS empresa_razao_social
         FROM notas_fiscais n
         INNER JOIN empresas_emissoras e ON e.id = n.empresa_emissora_id
         WHERE n.tipo_nota = 'nfse' AND n.status = 'pendente_envio'
         ORDER BY n.criado_em ASC"
    );

    return $stmt->fetchAll();
}

function obterLockNotaNfse(PDO $dbNotas, int $notaId): bool
{
    $stmt = $dbNotas->prepare('SELECT GET_LOCK(:nome, 0)');
    $stmt->execute(['nome' => 'account_nfse_nota_' . $notaId]);
    return (int) $stmt->fetchColumn() === 1;
}

function liberarLockNotaNfse(PDO $dbNotas, int $notaId): void
{
    $stmt = $dbNotas->prepare('SELECT RELEASE_LOCK(:nome)');
    $stmt->execute(['nome' => 'account_nfse_nota_' . $notaId]);
}

function quantidadeTentativasNotaNfse(PDO $dbNotas, int $notaId): int
{
    $stmt = $dbNotas->prepare("SELECT COUNT(*) FROM notas_fiscais_log WHERE nota_id = :nota_id AND acao IN ('tentativa_envio', 'reconciliacao_dps')");
    $stmt->execute(['nota_id' => $notaId]);
    return (int) $stmt->fetchColumn();
}

function processarNotaNfse(PDO $dbNotas, array $notaResumo, int $operadorId): array
{
    $notaId = (int) $notaResumo['id'];
    if (!obterLockNotaNfse($dbNotas, $notaId)) {
        return ['sucesso' => false, 'status' => 'pendente_envio', 'motivo_rejeicao' => 'Nota já está sendo processada por outro trabalhador.', 'ignorada' => true];
    }

    try {
        $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch();
        if (!$nota || $nota['status'] !== 'pendente_envio' || $nota['tipo_nota'] !== 'nfse') {
            return ['sucesso' => false, 'status' => $nota['status'] ?? 'pendente_envio', 'motivo_rejeicao' => 'Nota deixou de estar pendente antes do processamento.', 'ignorada' => true];
        }

        $stmt = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $nota['empresa_emissora_id']]);
        $empresa = $stmt->fetch();
        $stmt = $dbNotas->prepare('SELECT * FROM notas_clientes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $nota['cliente_id']]);
        $cliente = $stmt->fetch();
        $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_itens WHERE nota_id = :nota_id ORDER BY id ASC');
        $stmt->execute(['nota_id' => $notaId]);
        $itens = $stmt->fetchAll();
        $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfse WHERE nota_id = :nota_id LIMIT 1');
        $stmt->execute(['nota_id' => $notaId]);
        $nfse = $stmt->fetch() ?: null;
        if (!$empresa || !$cliente || empty($itens)) {
            $motivo = 'Empresa, cliente ou itens não encontrados.';
            $dbNotas->prepare('UPDATE notas_fiscais SET status = \'rejeitada\', motivo_rejeicao = :motivo WHERE id = :id AND status = \'pendente_envio\'')->execute(['motivo' => $motivo, 'id' => $notaId]);
            registrarLogNota($dbNotas, $notaId, $operadorId, 'rejeitada', $motivo);
            return ['sucesso' => false, 'status' => 'rejeitada', 'motivo_rejeicao' => $motivo];
        }

        $tentativa = quantidadeTentativasNotaNfse($dbNotas, $notaId) + 1;
        registrarLogNota($dbNotas, $notaId, $operadorId, 'tentativa_envio', 'Tentativa #' . $tentativa . ' iniciada com lock exclusivo.');
        $dps = dpsAPartirDaNota($nota, $empresa, $cliente, $itens, $nfse);
        $idDps = (string) ($dps['idDps'] ?? '');

        if (!empty($dps['erros_validacao']) || !nfseIdDpsValido($idDps)) {
            $errosDps = array_values(array_unique(array_filter(array_map('strval', $dps['erros_validacao'] ?? []))));
            if (!nfseIdDpsValido($idDps)) {
                $errosDps[] = 'Identificador da DPS inválido.';
            }
            $motivo = 'DPS não transmitida: ' . implode(' ', array_unique($errosDps));
            $resultado = [
                'sucesso' => false,
                'status' => 'rejeitada',
                'motivo_rejeicao' => $motivo,
                'chave_acesso' => null,
                'protocolo_autorizacao' => null,
                'xml_gerado' => null,
            ];
            $stmt = $dbNotas->prepare("UPDATE notas_fiscais SET status = 'rejeitada', motivo_rejeicao = :motivo WHERE id = :id AND status = 'pendente_envio'");
            $stmt->execute(['motivo' => $motivo, 'id' => $notaId]);
            registrarLogNota($dbNotas, $notaId, $operadorId, 'rejeitada', mb_substr($motivo, 0, 255));
            return $resultado;
        }

        try {
            $reconciliada = reconciliarDpsNfse($empresa, $nota['ambiente'], $idDps);
        } catch (Throwable $e) {
            registrarLogNota($dbNotas, $notaId, $operadorId, 'reconciliacao_dps', mb_substr('Consulta inconclusiva: ' . $e->getMessage(), 0, 255));
            return ['sucesso' => false, 'status' => 'pendente_envio', 'motivo_rejeicao' => 'Não foi seguro retransmitir: ' . $e->getMessage()];
        }

        if ($reconciliada !== null) {
            $resultado = ['sucesso' => true, 'status' => 'autorizada', 'motivo_rejeicao' => null, 'chave_acesso' => $reconciliada['chave_acesso'], 'protocolo_autorizacao' => null, 'xml_gerado' => $reconciliada['xml']];
            registrarLogNota($dbNotas, $notaId, $operadorId, 'reconciliacao_dps', 'DPS já existente reconciliada sem retransmissão.');
        } else {
            $resultado = enviarNfseNacional($dps, $nota['ambiente'], $empresa);
            if (($resultado['sucesso'] ?? false) && empty($resultado['xml_gerado'])) {
                try {
                    $posEmissao = reconciliarDpsNfse($empresa, $nota['ambiente'], $idDps);
                    if ($posEmissao !== null) {
                        $resultado['chave_acesso'] = $posEmissao['chave_acesso'];
                        $resultado['xml_gerado'] = $posEmissao['xml'];
                    }
                } catch (Throwable $e) {
                    registrarLogNota($dbNotas, $notaId, $operadorId, 'xml_pendente', mb_substr($e->getMessage(), 0, 255));
                }
            }
        }

        if (($resultado['status'] ?? 'pendente_envio') === 'pendente_envio') {
            registrarLogNota($dbNotas, $notaId, $operadorId, 'envio_adiado', mb_substr((string) ($resultado['motivo_rejeicao'] ?? ''), 0, 255));
            return $resultado;
        }

        $stmt = $dbNotas->prepare('UPDATE notas_fiscais SET status = :status, chave_acesso = :chave_acesso, protocolo_autorizacao = :protocolo_autorizacao, xml_gerado = :xml_gerado, motivo_rejeicao = :motivo_rejeicao WHERE id = :id AND status = \'pendente_envio\'');
        $stmt->execute(['status' => $resultado['status'], 'chave_acesso' => $resultado['chave_acesso'], 'protocolo_autorizacao' => $resultado['protocolo_autorizacao'], 'xml_gerado' => $resultado['xml_gerado'], 'motivo_rejeicao' => $resultado['motivo_rejeicao'], 'id' => $notaId]);
        if ($stmt->rowCount() !== 1) {
            return ['sucesso' => false, 'status' => 'pendente_envio', 'motivo_rejeicao' => 'A nota mudou de estado durante o processamento; o retorno remoto não foi sobrescrito.'];
        }
        registrarLogNota($dbNotas, $notaId, $operadorId, $resultado['sucesso'] ? 'autorizada' : 'rejeitada', $resultado['sucesso'] ? ('Chave de acesso: ' . $resultado['chave_acesso']) : ('Rejeitada: ' . $resultado['motivo_rejeicao']));
        return $resultado;
    } catch (Throwable $e) {
        registrarLogNota($dbNotas, $notaId, $operadorId, 'falha_processamento', mb_substr($e->getMessage(), 0, 255));
        return ['sucesso' => false, 'status' => 'pendente_envio', 'motivo_rejeicao' => 'Falha recuperável no processamento: ' . $e->getMessage()];
    } finally {
        liberarLockNotaNfse($dbNotas, $notaId);
    }
}
function registrarLogNota(PDO $db, int $notaId, int $funcionarioId, string $acao, string $detalhe = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO notas_fiscais_log (nota_id, funcionario_id, acao, detalhe) VALUES (:nota_id, :funcionario_id, :acao, :detalhe)'
    );
    $stmt->execute([
        'nota_id' => $notaId,
        'funcionario_id' => $funcionarioId,
        'acao' => $acao,
        'detalhe' => $detalhe !== '' ? $detalhe : null,
    ]);
}

// ------------------------------------------------------------
// Modo CLI (cron): processa a fila direto e sai, sem sessão/HTML.
// ------------------------------------------------------------
if ($viaCli) {
    $dbNotas = obterConexaoNotas();
    $pendentes = buscarNotasPendentesNfse($dbNotas);
    echo count($pendentes) . " nota(s) pendente(s) de envio.\n";

    foreach ($pendentes as $notaResumo) {
        $resultado = processarNotaNfse($dbNotas, $notaResumo, 0);
        $situacao = $resultado['sucesso'] ? 'OK' : 'FALHOU';
        echo "Nota #{$notaResumo['numero_interno']} ({$notaResumo['empresa_razao_social']}): {$situacao} — " . ($resultado['motivo_rejeicao'] ?? '') . "\n";
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

    if (empty($_SESSION['csrf_processar_fila_nfse'])) {
        $_SESSION['csrf_processar_fila_nfse'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'processar') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_processar_fila_nfse'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $pendentes = buscarNotasPendentesNfse($dbNotas);
            foreach ($pendentes as $notaResumo) {
                $resultado = processarNotaNfse($dbNotas, $notaResumo, $funcionarioId);
                $resultados[] = [
                    'numero_interno' => $notaResumo['numero_interno'],
                    'empresa' => $notaResumo['empresa_razao_social'],
                    'resultado' => $resultado,
                ];
            }

            $sucesso = count($pendentes) > 0
                ? ('Processamento concluído: ' . count($pendentes) . ' nota(s) avaliada(s).')
                : 'Não havia notas pendentes de envio.';
        }
    }

    $pendentes = buscarNotasPendentesNfse($dbNotas);
    [$integracaoDisponivel, $motivoIndisponivel] = integracaoNfseDisponivel();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar a fila de NFS-e: ' . $e->getMessage();
    $pendentes = [];
    $integracaoDisponivel = false;
    $motivoIndisponivel = '';
}

$csrf = h($_SESSION['csrf_processar_fila_nfse'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processar Fila de NFS-e | ACCOUNT Contabilidade</title>
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
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-outline {
            background: transparent;
            color: var(--text-white);
            border: 1px solid var(--border);
        }

        .menu-hamburguer { position: relative; }

        .menu-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            display: none;
            flex-direction: column;
            gap: 0.4rem;
            min-width: 240px;
            padding: 0.6rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
            z-index: 60;
        }

        .menu-dropdown.aberto { display: flex; }
        .menu-dropdown .btn { justify-content: flex-start; width: 100%; }

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

        .notice.warning {
            border-color: rgba(255, 191, 0, 0.35);
            background: rgba(255, 191, 0, 0.08);
            color: #FFE8A3;
        }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 780px; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.8rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { color: var(--text-white); font-family: var(--font-titles); font-size: 0.75rem; text-transform: uppercase; }
        .ok { color: var(--primary); font-weight: 700; }
        .falhou { color: var(--danger); font-weight: 700; }

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
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
            <div class="menu-hamburguer">
                <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
                    <i class="fa-solid fa-bars"></i> Menu
                </button>
                <div class="menu-dropdown" id="menuDropdown">
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
                    <a class="btn btn-outline" href="notas-emitir"><i class="fa-solid fa-file-circle-plus"></i> Emitir nota fiscal</a>
                    <a class="btn btn-outline" href="notas-emitir#cadastroCliente"><i class="fa-solid fa-user-plus"></i> Cadastrar clientes</a>
                    <a class="btn btn-outline" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
                    <a class="btn btn-outline" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
                    <a class="btn btn-outline" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
                    <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Processar fila NFS-e</a>
                    <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                    <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                    <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
                </div>
            </div>
        </header>

        <section class="panel">
            <h1>Fila de NFS-e</h1>
            <p class="muted">Processa as notas de serviço marcadas como "pendente de envio", tentando transmitir cada uma ao Portal Nacional da NFS-e (SEFIN Nacional).</p>
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
                As notas continuam acumulando aqui como "pendente de envio" até as dependências do Composer e o certificado digital A1 da empresa serem configurados.
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
                                <th>Empresa</th>
                                <th>Resultado</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $linha): ?>
                                <tr>
                                    <td>#<?php echo h((string) $linha['numero_interno']); ?></td>
                                    <td><?php echo h($linha['empresa']); ?></td>
                                    <td class="<?php echo $linha['resultado']['sucesso'] ? 'ok' : 'falhou'; ?>">
                                        <?php echo $linha['resultado']['sucesso'] ? 'Autorizada' : 'Falhou'; ?>
                                    </td>
                                    <td><?php echo h((string) ($linha['resultado']['chave_acesso'] ?? $linha['resultado']['motivo_rejeicao'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;">Notas pendentes de envio (<?php echo count($pendentes); ?>)</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="processar">
                    <button class="btn" type="submit" <?php echo empty($pendentes) ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-paper-plane"></i> Processar fila agora
                    </button>
                </form>
            </div>
            <div class="table-wrap" style="margin-top: 1rem;">
                <table>
                    <thead>
                        <tr>
                            <th>Nº interno</th>
                            <th>Empresa</th>
                            <th>Natureza da operação</th>
                            <th>Data emissão</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendentes as $notaResumo): ?>
                            <tr>
                                <td>#<?php echo h((string) $notaResumo['numero_interno']); ?></td>
                                <td><?php echo h($notaResumo['empresa_razao_social']); ?></td>
                                <td><?php echo h($notaResumo['natureza_operacao']); ?></td>
                                <td><?php echo h($notaResumo['data_emissao']); ?></td>
                                <td>R$ <?php echo number_format((float) $notaResumo['valor_total'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($pendentes)): ?>
                            <tr><td colspan="5" class="muted">Nenhuma nota pendente de envio.</td></tr>
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
