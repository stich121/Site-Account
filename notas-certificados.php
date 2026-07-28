<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/config_app_key.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicaoCertificados(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function colunaExisteEmpresasCert(PDO $db, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'empresas_emissoras\'
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunasCertificadoPagina(PDO $db): void
{
    $campos = [
        'certificado_arquivo' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao",
        'certificado_senha_cifrada' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo",
        'certificado_atualizado_em' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada",
        'certificado_atualizado_por' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em",
    ];

    foreach ($campos as $coluna => $sql) {
        if (!colunaExisteEmpresasCert($db, $coluna)) {
            $db->exec($sql);
        }
    }
}

function pastaCertificados(): string
{
    return __DIR__ . '/certificados-nfse';
}

function salvarArquivoCertificado(array $arquivo): string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Selecione o arquivo do certificado (.pfx ou .p12).');
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível carregar o arquivo do certificado.');
    }

    if (($arquivo['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('O certificado deve ter no máximo 8 MB.');
    }

    $extensao = strtolower(pathinfo((string) ($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extensao, ['pfx', 'p12'], true)) {
        throw new RuntimeException('Envie um arquivo .pfx ou .p12.');
    }

    $diretorio = pastaCertificados();
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de certificados no servidor.');
    }

    $htaccess = $diretorio . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensao;
    $destino = $diretorio . '/' . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o certificado no servidor.');
    }

    return $nomeArquivo;
}

function validarCertificadoPfx(string $caminho, string $senha): void
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Não foi possível ler o arquivo do certificado salvo.');
    }

    $certs = [];
    if (!openssl_pkcs12_read($conteudo, $certs, $senha)) {
        throw new RuntimeException('Certificado inválido ou senha incorreta. Confira o arquivo e a senha e tente novamente.');
    }
}

$erro = '';
$sucesso = '';

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararColunasCertificadoPagina($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    if ((int) ($stmt->fetchColumn() ?: 0) !== 1) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_certificados'])) {
        $_SESSION['csrf_notas_certificados'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_certificados'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'salvar') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $senha = (string) ($_POST['senha_certificado'] ?? '');

            $stmt = $dbNotas->prepare('SELECT certificado_arquivo FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $empresaId]);
            $empresaAtual = $stmt->fetch();

            if (!$empresaAtual) {
                $erro = 'Empresa emissora não encontrada.';
            } elseif ($senha === '') {
                $erro = 'Informe a senha do certificado.';
            } else {
                try {
                    $nomeArquivo = salvarArquivoCertificado($_FILES['certificado'] ?? []);
                    validarCertificadoPfx(pastaCertificados() . '/' . $nomeArquivo, $senha);

                    $stmt = $dbNotas->prepare(
                        'UPDATE empresas_emissoras
                         SET certificado_arquivo = :certificado_arquivo,
                             certificado_senha_cifrada = :certificado_senha_cifrada,
                             certificado_atualizado_em = NOW(),
                             certificado_atualizado_por = :funcionario_id
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'certificado_arquivo' => $nomeArquivo,
                        'certificado_senha_cifrada' => criptografarSegredo($senha),
                        'funcionario_id' => $funcionarioId,
                        'id' => $empresaId,
                    ]);

                    if (!empty($empresaAtual['certificado_arquivo'])) {
                        $arquivoAntigo = pastaCertificados() . '/' . basename($empresaAtual['certificado_arquivo']);
                        if (is_file($arquivoAntigo)) {
                            unlink($arquivoAntigo);
                        }
                    }

                    $sucesso = 'Certificado cadastrado com sucesso para a empresa.';
                } catch (RuntimeException $e) {
                    $erro = $e->getMessage();
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'remover') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);

            $stmt = $dbNotas->prepare('SELECT certificado_arquivo FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $empresaId]);
            $empresaAtual = $stmt->fetch();

            if ($empresaAtual) {
                if (!empty($empresaAtual['certificado_arquivo'])) {
                    $arquivoAntigo = pastaCertificados() . '/' . basename($empresaAtual['certificado_arquivo']);
                    if (is_file($arquivoAntigo)) {
                        unlink($arquivoAntigo);
                    }
                }

                $stmt = $dbNotas->prepare(
                    'UPDATE empresas_emissoras
                     SET certificado_arquivo = NULL, certificado_senha_cifrada = NULL,
                         certificado_atualizado_em = NULL, certificado_atualizado_por = NULL
                     WHERE id = :id'
                );
                $stmt->execute(['id' => $empresaId]);
                $sucesso = 'Certificado removido.';
            }
        }
    }

    $stmt = $dbNotas->query(
        'SELECT id, razao_social, cnpj, certificado_arquivo, certificado_atualizado_em, certificado_atualizado_por
         FROM empresas_emissoras
         WHERE ativo = 1
         ORDER BY razao_social ASC'
    );
    $empresas = $stmt->fetchAll();

    $funcionarioIds = array_values(array_unique(array_filter(array_map(
        static fn (array $e): int => (int) ($e['certificado_atualizado_por'] ?? 0),
        $empresas
    ))));
    $usuariosPorId = [];
    if (!empty($funcionarioIds)) {
        $placeholders = implode(',', array_fill(0, count($funcionarioIds), '?'));
        $stmt = $db->prepare("SELECT id, usuario FROM funcionarios WHERE id IN ({$placeholders})");
        $stmt->execute($funcionarioIds);
        $usuariosPorId = array_column($stmt->fetchAll(), 'usuario', 'id');
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar certificados: ' . $e->getMessage();
    $empresas = [];
    $usuariosPorId = [];
}

$csrf = h($_SESSION['csrf_notas_certificados'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado Digital | ACCOUNT Contabilidade</title>
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

        .btn-small { padding: 0.55rem 0.75rem; font-size: 0.72rem; }

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

        .notice.warning {
            border-color: rgba(255, 191, 0, 0.35);
            background: rgba(255, 191, 0, 0.08);
            color: #FFE8A3;
        }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 780px; border-collapse: collapse; font-size: 0.9rem; }
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
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Certificado digital</h1>
            <p class="muted">Cadastre o certificado A1 (.pfx/.p12) de cada empresa emissora. O arquivo fica numa pasta bloqueada por senha de servidor (não acessível por URL) e a senha do certificado é guardada cifrada — nunca em texto puro.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (empty($empresas)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. Cadastre uma em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a> primeiro.</div>
        <?php else: ?>
            <section class="panel">
                <h2>Cadastrar / substituir certificado</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="salvar">
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <select id="empresa_emissora_id" name="empresa_emissora_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo h((string) $empresa['id']); ?>"><?php echo h($empresa['razao_social']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="certificado">Arquivo do certificado (.pfx/.p12)</label>
                            <input id="certificado" name="certificado" type="file" accept=".pfx,.p12" required>
                        </div>
                        <div class="field">
                            <label for="senha_certificado">Senha do certificado</label>
                            <input id="senha_certificado" name="senha_certificado" type="password" required autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn" type="submit"><i class="fa-solid fa-upload"></i> Salvar certificado</button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Certificados cadastrados</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>CNPJ</th>
                            <th>Status</th>
                            <th>Atualizado em</th>
                            <th>Atualizado por</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $empresa): ?>
                            <tr>
                                <td><?php echo h($empresa['razao_social']); ?></td>
                                <td><?php echo h($empresa['cnpj'] ?? '—'); ?></td>
                                <td>
                                    <?php if (!empty($empresa['certificado_arquivo'])): ?>
                                        <span class="status-active">Configurado</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Não configurado</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($empresa['certificado_atualizado_em'] ? (new DateTimeImmutable($empresa['certificado_atualizado_em']))->format('d/m/Y H:i') : '—'); ?></td>
                                <td><?php echo h(nomeExibicaoCertificados($usuariosPorId[$empresa['certificado_atualizado_por']] ?? null) ?: '—'); ?></td>
                                <td>
                                    <?php if (!empty($empresa['certificado_arquivo'])): ?>
                                        <form method="post" class="row-actions">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="empresa_emissora_id" value="<?php echo h((string) $empresa['id']); ?>">
                                            <input type="hidden" name="acao" value="remover">
                                            <button class="btn btn-danger btn-small" type="submit"><i class="fa-solid fa-trash"></i> Remover</button>
                                        </form>
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
