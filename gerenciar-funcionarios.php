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
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);

if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

$empresas = [
    'Account' => '09.334.718/0001-99',
    'Bookkeep' => '36.154.139/0001-37',
];

$erro = '';
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
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

function gerarUsuario(string $nome): string
{
    $semAcento = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) : $nome;
    $base = preg_replace('/[^A-Za-z0-9]+/', '.', $semAcento ?: $nome);
    $base = trim((string) $base, '.');

    return $base !== '' ? $base : 'Funcionario';
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

try {
    $db = obterConexao();
    prepararCamposFuncionarios($db);

    if (empty($_SESSION['csrf_gerenciar_funcionarios'])) {
        $_SESSION['csrf_gerenciar_funcionarios'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_gerenciar_funcionarios'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'adicionar') {
            $empresaNome = trim($_POST['empresa_nome'] ?? '');
            $nome = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $cpf = trim($_POST['cpf'] ?? '');
            $senha = (string) ($_POST['senha'] ?? '');
            $cargo = trim($_POST['cargo'] ?? '');
            $departamento = trim($_POST['departamento'] ?? '');
            $nivel = (int) ($_POST['nivel_acesso'] ?? 1);
            $permitePonto = isset($_POST['permite_ponto']) ? 1 : 0;

            if (!isset($empresas[$empresaNome])) {
                $erro = 'Selecione uma empresa válida.';
            } elseif ($nome === '' || $email === '' || $cpf === '' || $senha === '' || $departamento === '') {
                $erro = 'Preencha nome, e-mail, CPF, senha e departamento.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido.';
            } elseif ($nivel < 1 || $nivel > 3) {
                $erro = 'Informe um nível de acesso válido.';
            } else {
                $usuarioBase = gerarUsuario($nome);
                $usuario = $usuarioBase;
                $contador = 2;
                while (true) {
                    $stmt = $db->prepare('SELECT id FROM funcionarios WHERE usuario = :usuario LIMIT 1');
                    $stmt->execute(['usuario' => $usuario]);
                    if (!$stmt->fetch()) {
                        break;
                    }
                    $usuario = $usuarioBase . $contador;
                    $contador++;
                }

                $stmt = $db->prepare(
                    'INSERT INTO funcionarios (usuario, empresa_nome, empresa_cnpj, cpf, email, senha, cargo, departamento, nivel_acesso, permite_ponto, ativo)
                     VALUES (:usuario, :empresa_nome, :empresa_cnpj, :cpf, :email, :senha, :cargo, :departamento, :nivel_acesso, :permite_ponto, 1)'
                );
                $stmt->execute([
                    'usuario' => $usuario,
                    'empresa_nome' => $empresaNome,
                    'empresa_cnpj' => $empresas[$empresaNome],
                    'cpf' => $cpf,
                    'email' => $email,
                    'senha' => $senha,
                    'cargo' => $cargo,
                    'departamento' => $departamento,
                    'nivel_acesso' => $nivel,
                    'permite_ponto' => $permitePonto,
                ]);

                $sucesso = 'Funcionário cadastrado com sucesso. Usuário gerado: ' . $usuario;
            }
        } elseif (($_POST['acao'] ?? '') === 'desativar') {
            $id = (int) ($_POST['funcionario_id'] ?? 0);
            if ($id <= 0 || $id === $funcionarioId) {
                $erro = 'Não foi possível remover este funcionário.';
            } else {
                $stmt = $db->prepare('UPDATE funcionarios SET ativo = 0 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Funcionário removido da lista de ativos.';
            }
        } elseif (($_POST['acao'] ?? '') === 'reativar') {
            $id = (int) ($_POST['funcionario_id'] ?? 0);
            if ($id <= 0) {
                $erro = 'Funcionário inválido.';
            } else {
                $stmt = $db->prepare('UPDATE funcionarios SET ativo = 1 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Funcionário reativado com sucesso.';
            }
        } elseif (($_POST['acao'] ?? '') === 'excluir') {
            $id = (int) ($_POST['funcionario_id'] ?? 0);
            $confirmado = ($_POST['confirmar_exclusao'] ?? '') === 'sim';

            if ($id <= 0 || $id === $funcionarioId) {
                $erro = 'Não foi possível excluir este funcionário.';
            } elseif (!$confirmado) {
                $erro = 'Marque SIM para confirmar a exclusão definitiva.';
            } else {
                $stmt = $db->prepare('SELECT ativo FROM funcionarios WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $funcionarioExcluir = $stmt->fetch();

                if (!$funcionarioExcluir) {
                    $erro = 'Funcionário não encontrado.';
                } elseif ((int) $funcionarioExcluir['ativo'] === 1) {
                    $erro = 'Remova o funcionário antes de excluir definitivamente.';
                } else {
                    $stmt = $db->prepare('DELETE FROM funcionarios WHERE id = :id AND ativo = 0');
                    $stmt->execute(['id' => $id]);
                    $sucesso = 'Funcionário excluído definitivamente.';
                }
            }
        }
    }

    $stmt = $db->query(
        'SELECT id, usuario, empresa_nome, empresa_cnpj, cpf, email, cargo, departamento, nivel_acesso, permite_ponto, ativo
         FROM funcionarios
         ORDER BY empresa_nome ASC, ativo DESC, usuario ASC'
    );
    $funcionarios = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar gestão de funcionários: ' . $e->getMessage();
    $funcionarios = [];
}

$csrf = h($_SESSION['csrf_gerenciar_funcionarios'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Funcionários | ACCOUNT Contabilidade</title>
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

        .check-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-top: 1.9rem;
            color: var(--text-muted);
            font-weight: 700;
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

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.8rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { color: var(--text-white); font-family: var(--font-titles); font-size: 0.75rem; text-transform: uppercase; }
        .status-active { color: var(--primary); font-weight: 700; }
        .status-inactive { color: var(--danger); font-weight: 700; }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .row-actions {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }

        .delete-confirm {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--danger);
            font-family: var(--font-titles);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            background: transparent;
            white-space: nowrap;
        }

        .delete-question {
            color: var(--text-muted);
            font-size: 0.78rem;
            white-space: nowrap;
        }

        .delete-confirm input {
            width: 16px;
            height: 16px;
            accent-color: var(--danger);
        }

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .form-grid { grid-template-columns: 1fr; }
            .check-row { margin-top: 0; }
            .row-actions { align-items: stretch; }
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
            <div class="actions">
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Gerenciar funcionários</h1>
            <p class="muted">Acesso restrito para nível 3. Remover um funcionário desativa o login, preservando o histórico de ponto.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2>Adicionar pessoa</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="acao" value="adicionar">
                <div class="form-grid">
                    <div class="field">
                        <label for="empresa_nome">Empresa</label>
                        <select id="empresa_nome" name="empresa_nome" required>
                            <option value="">Selecione</option>
                            <?php foreach ($empresas as $empresa => $cnpj): ?>
                                <option value="<?php echo h($empresa); ?>"><?php echo h($empresa . ' - ' . $cnpj); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nome">Nome da pessoa</label>
                        <input id="nome" name="nome" type="text" placeholder="Ex.: João Pedro" required>
                    </div>
                    <div class="field">
                        <label for="cpf">CPF</label>
                        <input id="cpf" name="cpf" type="text" placeholder="000.000.000-00" required>
                    </div>
                    <div class="field">
                        <label for="email">E-mail</label>
                        <input id="email" name="email" type="email" placeholder="email@empresa.com.br" required>
                    </div>
                    <div class="field">
                        <label for="senha">Senha inicial</label>
                        <input id="senha" name="senha" type="text" required>
                    </div>
                    <div class="field">
                        <label for="cargo">Cargo</label>
                        <input id="cargo" name="cargo" type="text" placeholder="Ex.: Analista Fiscal I">
                    </div>
                    <div class="field">
                        <label for="departamento">Departamento</label>
                        <select id="departamento" name="departamento" required>
                            <option value="">Selecione</option>
                            <option value="Fiscal">Fiscal</option>
                            <option value="Contábil">Contábil</option>
                            <option value="Trabalhista">Trabalhista</option>
                            <option value="Constituição">Constituição</option>
                            <option value="Administrativo">Administrativo</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nivel_acesso">Nível de acesso</label>
                        <select id="nivel_acesso" name="nivel_acesso">
                            <option value="1">1 - Próprio ponto</option>
                            <option value="2">2 - Próprio ponto</option>
                            <option value="3">3 - Painel geral</option>
                        </select>
                    </div>
                    <label class="check-row">
                        <input type="checkbox" name="permite_ponto" checked>
                        Permite bater ponto
                    </label>
                    <div class="field">
                        <label>&nbsp;</label>
                        <button class="btn" type="submit"><i class="fa-solid fa-user-plus"></i> Adicionar</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Pessoas cadastradas</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Nome</th>
                            <th>CPF</th>
                            <th>E-mail</th>
                            <th>Cargo</th>
                            <th>Departamento</th>
                            <th>Nível</th>
                            <th>Ponto</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($funcionarios as $funcionario): ?>
                            <tr>
                                <td><?php echo h(($funcionario['empresa_nome'] ?? '') . ' - ' . ($funcionario['empresa_cnpj'] ?? '')); ?></td>
                                <td><?php echo h(nomeExibicao($funcionario['usuario'])); ?></td>
                                <td><?php echo h($funcionario['cpf'] ?? ''); ?></td>
                                <td><?php echo h($funcionario['email']); ?></td>
                                <td><?php echo h($funcionario['cargo'] ?? ''); ?></td>
                                <td><?php echo h($funcionario['departamento'] ?? ''); ?></td>
                                <td><?php echo h((string) $funcionario['nivel_acesso']); ?></td>
                                <td><?php echo (int) $funcionario['permite_ponto'] === 1 ? 'Sim' : 'Não'; ?></td>
                                <td>
                                    <?php if ((int) $funcionario['ativo'] === 1): ?>
                                        <span class="status-active">Ativo</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $funcionario['id'] !== $funcionarioId): ?>
                                        <?php if ((int) $funcionario['ativo'] === 1): ?>
                                            <form method="post" class="row-actions">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="funcionario_id" value="<?php echo h((string) $funcionario['id']); ?>">
                                                <input type="hidden" name="acao" value="desativar">
                                                <button class="btn btn-danger" type="submit"><i class="fa-solid fa-user-slash"></i> Remover</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="row-actions">
                                                <form method="post">
                                                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                    <input type="hidden" name="funcionario_id" value="<?php echo h((string) $funcionario['id']); ?>">
                                                <input type="hidden" name="acao" value="reativar">
                                                    <button class="btn btn-outline" type="submit"><i class="fa-solid fa-user-check"></i> Reativar</button>
                                                </form>
                                                <form method="post" class="row-actions">
                                                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                    <input type="hidden" name="funcionario_id" value="<?php echo h((string) $funcionario['id']); ?>">
                                                    <input type="hidden" name="acao" value="excluir">
                                                    <span class="delete-question">Tem certeza?</span>
                                                    <label class="delete-confirm" title="Confirmar exclusão definitiva">
                                                        <input type="checkbox" name="confirmar_exclusao" value="sim">
                                                        SIM
                                                    </label>
                                                    <button class="btn btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Excluir</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">Seu usuário</span>
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

