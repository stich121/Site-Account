<?php
/**
 * Teste simples de MySQL
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Teste MySQL</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .box { background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; margin: 10px 0; }
        .ok { border-left: 5px solid green; background: #f0fff0; }
        .erro { border-left: 5px solid red; background: #fff0f0; }
        code { background: #eee; padding: 2px 5px; }
    </style>
</head>
<body>

<h1>Teste de MySQL</h1>

<?php
// Teste 1: Verificar se config_db.php existe
$configFile = __DIR__ . '/config_db.php';
echo '<div class="box">';
echo '<h2>1. Arquivo config_db.php</h2>';
if (file_exists($configFile)) {
    echo '<div class="ok">✓ Arquivo encontrado</div>';
    require_once $configFile;
} else {
    echo '<div class="erro">✗ Arquivo NÃO encontrado!</div>';
    echo '<p>Procurando em: <code>' . $configFile . '</code></p>';
    exit;
}
echo '</div>';

// Teste 2: Tentar conectar
echo '<div class="box">';
echo '<h2>2. Conectar ao MySQL</h2>';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    echo '<div class="ok">✓ Conexão bem-sucedida!</div>';

    // Teste 3: Verificar tabela funcionarios
    echo '<div class="box">';
    echo '<h2>3. Tabela funcionarios</h2>';
    try {
        $stmt = $pdo->query('SELECT COUNT(*) as total FROM funcionarios');
        $result = $stmt->fetch();
        echo '<div class="ok">✓ Tabela existe com ' . $result['total'] . ' registros</div>';

        // Teste 4: Listar alguns usuários
        if ($result['total'] > 0) {
            echo '<div class="box"><h3>Usuários disponíveis:</h3>';
            $stmt = $pdo->query('SELECT usuario, email FROM funcionarios LIMIT 5');
            echo '<ul>';
            foreach ($stmt->fetchAll() as $user) {
                echo '<li>' . $user['usuario'] . ' (' . $user['email'] . ')</li>';
            }
            echo '</ul></div>';
        }
    } catch (Exception $e) {
        echo '<div class="erro">✗ Erro: ' . $e->getMessage() . '</div>';
    }
    echo '</div>';

} catch (PDOException $e) {
    echo '<div class="erro"><strong>✗ ERRO na conexão:</strong></div>';
    echo '<p><code>' . $e->getCode() . ': ' . htmlspecialchars($e->getMessage()) . '</code></p>';

    echo '<div class="box"><h3>Diagnóstico:</h3>';

    $code = $e->getCode();
    if ($code == 1045 || strpos($e->getMessage(), 'Access denied') !== false) {
        echo '<p><strong>Erro 1045:</strong> Usuário/senha incorretos</p>';
        echo '<p>Usuário: <code>' . DB_USER . '</code></p>';
    } elseif ($code == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
        echo '<p><strong>Erro 1049:</strong> Banco não existe</p>';
        echo '<p>Banco: <code>' . DB_NAME . '</code></p>';
    } elseif ($code == 2002 || $code == 2003 || strpos($e->getMessage(), 'Connect') !== false) {
        echo '<p><strong>Erro ' . $code . ':</strong> Não consegue conectar</p>';
        echo '<p>Host: <code>' . DB_HOST . '</code></p>';
        echo '<p><strong>Soluções:</strong></p>';
        echo '<ul>';
        echo '<li>MySQL não está rodando → Inicie MySQL</li>';
        echo '<li>Host/porta errados → Verifique config_db.php</li>';
        echo '<li>Servidor indisponível → Aguarde ou contacte admin</li>';
        echo '</ul>';
    }

    echo '</div>';
}
?>

<div class="box">
    <h3>Resumo</h3>
    <p>Se todos os testes passaram com ✓, o banco de dados está OK.</p>
    <p>Se algum teste falhou com ✗, siga as instruções acima.</p>
</div>

</body>
</html>
