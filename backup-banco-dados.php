<?php
// Gera backup completo (estrutura + dados) dos dois bancos do sistema (principal e fiscal) e
// envia pro Google Drive já configurado (google_drive_service.php), sem depender do binário
// `mysqldump` nem de shell_exec (indisponível na hospedagem compartilhada) — o dump é montado via
// PDO (SHOW CREATE TABLE + SELECT em lotes), comprimido em .sql.gz e enviado.
//
// Mantém só os BACKUP_RETENCAO_POR_BANCO arquivos mais recentes de cada banco no Drive, pra não
// acumular espaço indefinidamente. Se o envio ao Drive falhar, o dump local NÃO é apagado — fica em
// backups/ como rede de segurança até o próximo backup funcionar.
//
// Pode rodar via navegador (admin, botão manual) ou via CLI/cron:
//   php backup-banco-dados.php --cli
//
// Recomendado no cron do Hostinger (hPanel > Avançado > Cron Jobs), uma vez por dia (de madrugada):
//   php /caminho/do/site/backup-banco-dados.php --cli

const BACKUP_RETENCAO_POR_BANCO = 14;

$viaCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/google_drive_service.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function backupDiretorio(): string
{
    $diretorio = __DIR__ . '/backups';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de backups.');
    }

    return $diretorio;
}

function backupStatusArquivo(): string
{
    return backupDiretorio() . '/status.json';
}

function backupLerStatus(): array
{
    $arquivo = backupStatusArquivo();
    if (!is_file($arquivo)) {
        return [];
    }

    $dados = json_decode((string) file_get_contents($arquivo), true);

    return is_array($dados) ? $dados : [];
}

function backupSalvarStatus(string $chave, array $situacao): void
{
    $status = backupLerStatus();
    $status[$chave] = $situacao + ['quando' => date('Y-m-d H:i:s')];
    @file_put_contents(backupStatusArquivo(), json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Monta o dump direto no arquivo .gz (sem acumular tudo em memória) — SHOW CREATE TABLE pra
// estrutura e SELECT * em lotes de 200 linhas pra dados, com valores escapados via PDO::quote().
function backupGerarDumpGz(PDO $db, string $caminhoGz): void
{
    $handle = gzopen($caminhoGz, 'wb9');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível criar o arquivo de backup.');
    }

    gzwrite($handle, "-- Backup gerado em " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tabelas = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabelas as $tabela) {
        $tabelaEsc = '`' . str_replace('`', '``', $tabela) . '`';

        $criar = $db->query("SHOW CREATE TABLE {$tabelaEsc}")->fetch(PDO::FETCH_ASSOC);
        gzwrite($handle, "DROP TABLE IF EXISTS {$tabelaEsc};\n" . $criar['Create Table'] . ";\n\n");

        $stmt = $db->query("SELECT * FROM {$tabelaEsc}");
        $colunas = null;
        $lote = [];
        while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($colunas === null) {
                $colunas = implode(',', array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($linha)));
            }

            $valores = array_map(function ($valor) use ($db) {
                if ($valor === null) {
                    return 'NULL';
                }
                if (is_int($valor)) {
                    return (string) $valor;
                }

                return $db->quote((string) $valor);
            }, $linha);
            $lote[] = '(' . implode(',', $valores) . ')';

            if (count($lote) >= 200) {
                gzwrite($handle, "INSERT INTO {$tabelaEsc} ({$colunas}) VALUES\n" . implode(",\n", $lote) . ";\n\n");
                $lote = [];
            }
        }
        if ($lote !== []) {
            gzwrite($handle, "INSERT INTO {$tabelaEsc} ({$colunas}) VALUES\n" . implode(",\n", $lote) . ";\n\n");
        }
    }

    gzwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($handle);
}

function backupRotacionarDrive(string $prefixo, int $manter): void
{
    $arquivos = listarArquivosGoogleDrivePorPrefixo($prefixo);
    foreach (array_slice($arquivos, $manter) as $antigo) {
        try {
            excluirArquivoGoogleDrive($antigo['id']);
        } catch (Throwable $e) {
            // Se a limpeza de um arquivo antigo falhar (ex.: token expirou no meio do processo),
            // não vale travar o backup atual por causa disso — o próximo backup tenta de novo.
        }
    }
}

function backupExecutar(string $chave, string $rotulo, PDO $db): array
{
    $nomeArquivo = 'backup-' . $chave . '-' . date('Y-m-d-His') . '.sql.gz';
    $caminhoLocal = backupDiretorio() . '/' . $nomeArquivo;

    try {
        backupGerarDumpGz($db, $caminhoLocal);
        $tamanho = filesize($caminhoLocal);

        $drive = enviarArquivoGoogleDrive($caminhoLocal, $nomeArquivo, 'application/gzip');
        @unlink($caminhoLocal);
        backupRotacionarDrive('backup-' . $chave . '-', BACKUP_RETENCAO_POR_BANCO);

        $situacao = [
            'sucesso' => true,
            'arquivo' => $nomeArquivo,
            'tamanho_bytes' => $tamanho,
            'drive_link' => $drive['webViewLink'] ?? null,
            'erro' => null,
        ];
    } catch (Throwable $e) {
        $situacao = [
            'sucesso' => false,
            'arquivo' => is_file($caminhoLocal) ? $nomeArquivo : null,
            'tamanho_bytes' => is_file($caminhoLocal) ? filesize($caminhoLocal) : null,
            'drive_link' => null,
            'erro' => $e->getMessage(),
        ];
    }

    backupSalvarStatus($chave, $situacao + ['rotulo' => $rotulo]);

    return $situacao;
}

function backupRodarTodos(): array
{
    return [
        'principal' => backupExecutar('principal', 'Banco principal (funcionários e ponto)', obterConexao()),
        'notas' => backupExecutar('notas', 'Banco fiscal (notas, empresas e clientes)', obterConexaoNotas()),
    ];
}

// ------------------------------------------------------------
// Modo CLI (cron): roda os dois backups direto e sai, sem sessão/HTML.
// ------------------------------------------------------------
if ($viaCli) {
    set_time_limit(300);
    $resultados = backupRodarTodos();
    foreach ($resultados as $chave => $situacao) {
        $status = $situacao['sucesso'] ? 'OK' : 'FALHOU';
        $detalhe = $situacao['sucesso']
            ? ($situacao['arquivo'] . ', ' . round(($situacao['tamanho_bytes'] ?? 0) / 1024, 1) . ' KB')
            : $situacao['erro'];
        echo "{$chave}: {$status} — {$detalhe}\n";
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

$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

$erro = '';
$sucesso = '';

if (empty($_SESSION['csrf_backup_banco'])) {
    $_SESSION['csrf_backup_banco'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'backup') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_backup_banco'], $csrf)) {
        $erro = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        set_time_limit(300);
        $resultados = backupRodarTodos();
        $falhas = array_keys(array_filter($resultados, fn ($r) => !$r['sucesso']));
        if ($falhas === []) {
            $sucesso = 'Backup concluído e enviado ao Google Drive.';
        } else {
            $erro = 'Backup concluído com falha em: ' . implode(', ', $falhas) . '. Veja o detalhe abaixo.';
        }
    }
}

$statusAtual = backupLerStatus();
$csrf = h($_SESSION['csrf_backup_banco']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup dos Bancos de Dados | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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
            <h1>Backup dos Bancos de Dados</h1>
            <p class="muted">Gera um dump completo (estrutura + dados) do banco principal e do banco fiscal, comprime e envia pro Google Drive já configurado no sistema. Mantém os últimos <?php echo BACKUP_RETENCAO_POR_BANCO; ?> backups de cada banco no Drive e apaga os mais antigos sozinho.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <div class="notice warning">
            <strong>Para rodar sozinho, todo dia, sem depender de ninguém abrir esta página:</strong> configure no hPanel da Hostinger (Avançado &gt; Cron Jobs) uma tarefa diária (de madrugada) executando:
            <code>php <?php echo h(__DIR__); ?>/backup-banco-dados.php --cli</code>
        </div>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;">Situação atual</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="backup">
                    <button class="btn" type="submit">
                        <i class="fa-solid fa-database"></i> Gerar backup agora
                    </button>
                </form>
            </div>
            <div class="table-wrap" style="margin-top: 1rem;">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Banco</th>
                            <th>Último backup</th>
                            <th>Resultado</th>
                            <th>Tamanho</th>
                            <th>Detalhe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['principal' => 'Banco principal (funcionários e ponto)', 'notas' => 'Banco fiscal (notas, empresas e clientes)'] as $chave => $rotulo): ?>
                            <?php $situacao = $statusAtual[$chave] ?? null; ?>
                            <tr>
                                <td><?php echo h($rotulo); ?></td>
                                <td><?php echo $situacao ? h(date('d/m/Y H:i', strtotime($situacao['quando']))) : 'nunca rodou'; ?></td>
                                <td>
                                    <?php if ($situacao): ?>
                                        <span class="status-pill <?php echo $situacao['sucesso'] ? 'status-autorizada' : 'status-rejeitada'; ?>">
                                            <?php echo $situacao['sucesso'] ? 'OK' : 'Falhou'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ($situacao && $situacao['sucesso'] && !empty($situacao['tamanho_bytes'])) ? round($situacao['tamanho_bytes'] / 1024, 1) . ' KB' : '—'; ?></td>
                                <td>
                                    <?php if ($situacao && $situacao['sucesso'] && !empty($situacao['drive_link'])): ?>
                                        <a href="<?php echo h($situacao['drive_link']); ?>" target="_blank" rel="noopener">Ver no Drive</a>
                                    <?php elseif ($situacao && !$situacao['sucesso']): ?>
                                        <?php echo h((string) ($situacao['erro'] ?? 'Falha desconhecida.')); ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
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
</body>
</html>
