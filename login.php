<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
header('Content-Type: application/json; charset=utf-8');

function responderJson(array $dados): void
{
    echo json_encode($dados);
    exit;
}

function garantirCamposFuncionarios(PDO $db): void
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
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "funcionarios"
               AND COLUMN_NAME = :campo'
        );
        $stmt->execute(['campo' => $campo]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec($sql);
        }
    }
}

function garantirTabelaAfastamentos(PDO $db): void
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

function chaveTentativaLogin(string $usuario): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'sem-ip';
    return hash('sha256', strtolower(trim($usuario)) . '|' . $ip);
}

function obterEstadoLogin(string $chave): array
{
    if (!isset($_SESSION['anti_bruteforce_login'][$chave]) || !is_array($_SESSION['anti_bruteforce_login'][$chave])) {
        $_SESSION['anti_bruteforce_login'][$chave] = [
            'tentativas' => 0,
            'bloqueado_ate' => 0,
            'desafio' => null,
        ];
    }

    return $_SESSION['anti_bruteforce_login'][$chave];
}

function gerarDesafioLogin(string $chave): array
{
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $_SESSION['anti_bruteforce_login'][$chave]['desafio'] = [
        'pergunta' => "Quanto é {$a} + {$b}?",
        'resposta' => (string) ($a + $b),
    ];

    return $_SESSION['anti_bruteforce_login'][$chave]['desafio'];
}

function desafioAtualLogin(string $chave): array
{
    $estado = obterEstadoLogin($chave);
    if (empty($estado['desafio']['pergunta']) || empty($estado['desafio']['resposta'])) {
        return gerarDesafioLogin($chave);
    }

    return $estado['desafio'];
}

function respostaDesafioValida(string $chave, string $resposta): bool
{
    $estado = obterEstadoLogin($chave);
    $esperado = trim((string) ($estado['desafio']['resposta'] ?? ''));

    return $esperado !== '' && hash_equals($esperado, trim($resposta));
}

function registrarFalhaLogin(string $chave): array
{
    $estado = obterEstadoLogin($chave);
    $tentativas = (int) ($estado['tentativas'] ?? 0) + 1;
    $_SESSION['anti_bruteforce_login'][$chave]['tentativas'] = $tentativas;

    if ($tentativas >= 8) {
        $_SESSION['anti_bruteforce_login'][$chave]['bloqueado_ate'] = time() + 900;
    }

    if ($tentativas >= 3) {
        gerarDesafioLogin($chave);
    }

    return $_SESSION['anti_bruteforce_login'][$chave];
}

function limparFalhasLogin(string $chave): void
{
    unset($_SESSION['anti_bruteforce_login'][$chave]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    if (isset($_SESSION['funcionario_id'])) {
        responderJson([
            'status' => 'logged_in',
            'id' => $_SESSION['funcionario_id'],
            'usuario' => $_SESSION['funcionario_usuario'],
            'email' => $_SESSION['funcionario_email'],
            'empresa_nome' => $_SESSION['funcionario_empresa_nome'] ?? '',
            'empresa_cnpj' => $_SESSION['funcionario_empresa_cnpj'] ?? '',
            'cpf' => $_SESSION['funcionario_cpf'] ?? '',
            'cargo' => $_SESSION['funcionario_cargo'] ?? '',
            'nivel_acesso' => $_SESSION['funcionario_nivel_acesso'] ?? 1,
            'permite_ponto' => $_SESSION['funcionario_permite_ponto'] ?? 1,
        ]);
    } else {
        responderJson(['status' => 'logged_out']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['logout'])) {
    session_destroy();
    responderJson(['status' => 'success', 'message' => 'Logout realizado com sucesso.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['status' => 'error', 'message' => 'Método não permitido.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$usuario = trim($input['usuario'] ?? '');
$senha = $input['senha'] ?? '';
$respostaAntiBruteforce = trim((string) ($input['anti_bruteforce'] ?? ''));

if ($usuario === '' || $senha === '') {
    responderJson(['status' => 'error', 'message' => 'Informe usuário/e-mail e senha.']);
}

$chaveLogin = chaveTentativaLogin($usuario);
$estadoLogin = obterEstadoLogin($chaveLogin);
$bloqueadoAte = (int) ($estadoLogin['bloqueado_ate'] ?? 0);
if ($bloqueadoAte > time()) {
    $minutos = max(1, (int) ceil(($bloqueadoAte - time()) / 60));
    responderJson([
        'status' => 'error',
        'message' => 'Muitas tentativas incorretas. Tente novamente em aproximadamente ' . $minutos . ' minuto(s).',
        'locked' => true,
    ]);
}

if ((int) ($estadoLogin['tentativas'] ?? 0) >= 3) {
    $desafio = desafioAtualLogin($chaveLogin);
    if (!respostaDesafioValida($chaveLogin, $respostaAntiBruteforce)) {
        responderJson([
            'status' => 'error',
            'message' => 'Confirme o desafio de segurança para continuar: ' . $desafio['pergunta'],
            'requires_challenge' => true,
            'challenge_question' => $desafio['pergunta'],
        ]);
    }
}

try {
    require_once __DIR__ . '/config_db.php';
    $db = obterConexao();
    garantirCamposFuncionarios($db);
    garantirTabelaAfastamentos($db);

    $stmt = $db->prepare(
        'SELECT id, usuario, email, senha, empresa_nome, empresa_cnpj, cpf, cargo, nivel_acesso, permite_ponto
         FROM funcionarios
         WHERE ativo = 1 AND (usuario = :usuario OR email = :email)
         LIMIT 1'
    );
    $stmt->execute([
        'usuario' => $usuario,
        'email' => $usuario,
    ]);
    $funcionario = $stmt->fetch();

    if (!$funcionario) {
        $estadoFalha = registrarFalhaLogin($chaveLogin);
        $resposta = ['status' => 'error', 'message' => 'Login ou senha incorretos.'];
        if ((int) ($estadoFalha['tentativas'] ?? 0) >= 3) {
            $desafio = desafioAtualLogin($chaveLogin);
            $resposta['requires_challenge'] = true;
            $resposta['challenge_question'] = $desafio['pergunta'];
            $resposta['message'] .= ' Desafio de segurança: ' . $desafio['pergunta'];
        }
        responderJson($resposta);
    }

    $senhaArmazenada = $funcionario['senha'];
    $senhaCorreta = password_verify($senha, $senhaArmazenada);
    $senhaPrecisaAtualizar = false;

    if (!$senhaCorreta && hash_equals($senhaArmazenada, $senha)) {
        $senhaCorreta = true;
        $senhaPrecisaAtualizar = true;
    }

    if (!$senhaCorreta) {
        $estadoFalha = registrarFalhaLogin($chaveLogin);
        $resposta = ['status' => 'error', 'message' => 'Login ou senha incorretos.'];
        if ((int) ($estadoFalha['tentativas'] ?? 0) >= 3) {
            $desafio = desafioAtualLogin($chaveLogin);
            $resposta['requires_challenge'] = true;
            $resposta['challenge_question'] = $desafio['pergunta'];
            $resposta['message'] .= ' Desafio de segurança: ' . $desafio['pergunta'];
        }
        responderJson($resposta);
    }

    $bloqueio = $db->prepare(
        'SELECT tipo_afastamento, motivo, data_inicio, data_fim
         FROM afastamentos
         WHERE funcionario_id = :funcionario_id
           AND bloquear_usuario = 1
           AND ativo = 1
           AND CURDATE() BETWEEN data_inicio AND data_fim
         ORDER BY data_inicio DESC
         LIMIT 1'
    );
    $bloqueio->execute(['funcionario_id' => (int) $funcionario['id']]);
    $afastamentoAtivo = $bloqueio->fetch();
    if ($afastamentoAtivo) {
        $inicio = (new DateTimeImmutable($afastamentoAtivo['data_inicio']))->format('d/m/Y');
        $fim = (new DateTimeImmutable($afastamentoAtivo['data_fim']))->format('d/m/Y');
        responderJson([
            'status' => 'error',
            'message' => 'Usuário bloqueado por afastamento no período de ' . $inicio . ' a ' . $fim . '.',
        ]);
    }

    if ($senhaPrecisaAtualizar || password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE funcionarios SET senha = :senha WHERE id = :id');
        $update->execute([
            'senha' => $novoHash,
            'id' => $funcionario['id'],
        ]);
    }

    limparFalhasLogin($chaveLogin);
    session_regenerate_id(true);
    $_SESSION['funcionario_id'] = (int) $funcionario['id'];
    $_SESSION['funcionario_usuario'] = $funcionario['usuario'];
    $_SESSION['funcionario_email'] = $funcionario['email'];
    $_SESSION['funcionario_empresa_nome'] = $funcionario['empresa_nome'] ?? '';
    $_SESSION['funcionario_empresa_cnpj'] = $funcionario['empresa_cnpj'] ?? '';
    $_SESSION['funcionario_cpf'] = $funcionario['cpf'] ?? '';
    $_SESSION['funcionario_cargo'] = $funcionario['cargo'] ?? '';
    $_SESSION['funcionario_nivel_acesso'] = (int) ($funcionario['nivel_acesso'] ?? 1);
    $_SESSION['funcionario_permite_ponto'] = (int) ($funcionario['permite_ponto'] ?? 1);

    responderJson([
        'status' => 'success',
        'message' => 'Login e senha corretos. Acesso liberado.',
        'redirect' => 'painel',
        'usuario' => $funcionario['usuario'],
        'email' => $funcionario['email'],
        'empresa_nome' => $funcionario['empresa_nome'] ?? '',
        'empresa_cnpj' => $funcionario['empresa_cnpj'] ?? '',
        'cpf' => $funcionario['cpf'] ?? '',
        'cargo' => $funcionario['cargo'] ?? '',
        'nivel_acesso' => (int) ($funcionario['nivel_acesso'] ?? 1),
        'permite_ponto' => (int) ($funcionario['permite_ponto'] ?? 1),
    ]);
} catch (Throwable $e) {
    error_log('[login.php] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());

    // Debug: Mostrar erro mais específico se for erro de conexão
    $mensagemErro = 'Erro interno ao processar o login. Verifique a conexão com o banco de dados e tente novamente.';
    if (strpos($e->getMessage(), 'Connect') !== false || strpos($e->getMessage(), '2002') !== false || strpos($e->getMessage(), '2003') !== false) {
        $mensagemErro = 'Erro de conexão com o banco de dados. O servidor MySQL pode estar desligado.';
    } elseif (strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), '1045') !== false) {
        $mensagemErro = 'Erro de autenticação no banco de dados. Verifique as credenciais em config_db.php.';
    } elseif (strpos($e->getMessage(), 'Unknown database') !== false || strpos($e->getMessage(), '1049') !== false) {
        $mensagemErro = 'Banco de dados não encontrado. Verifique o nome do banco em config_db.php.';
    }

    responderJson([
        'status' => 'error',
        'message' => $mensagemErro,
        'debug_error' => $_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1' ? $e->getMessage() : null,
    ]);
}
?>

