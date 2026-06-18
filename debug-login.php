<?php
/**
 * Script de debug - teste APENAS conexão MySQL
 * Acesse: http://seu-site.com/debug-login.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Login</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; border-radius: 5px; margin: 10px 0; }
        .success { border-left: 5px solid green; background: #f0fff0; }
        .error { border-left: 5px solid red; background: #fff0f0; }
        .warning { border-left: 5px solid orange; background: #fffaf0; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>

<h1>🔍 Debug - Diagnóstico de Login</h1>

<div class="box">
    <h2>1️⃣ Testando arquivo config_db.php</h2>
    <?php
    if (file_exists('config_db.php')) {
        echo '<div class="success"><strong>✓</strong> Arquivo config_db.php existe</div>';
        require_once 'config_db.php';
        echo '<div class="box"><strong>Credenciais configuradas:</strong><ul>';
        echo '<li>Host: <code>' . DB_HOST . '</code></li>';
        echo '<li>User: <code>' . DB_USER . '</code></li>';
        echo '<li>Database: <code>' . DB_NAME . '</code></li>';
        echo '<li>Charset: <code>' . DB_CHARSET . '</code></li>';
        echo '</ul></div>';
    } else {
        echo '<div class="error"><strong>✗</strong> Arquivo config_db.php NÃO encontrado!</div>';
    }
    ?>
</div>

<div class="box">
    <h2>2️⃣ Testando conexão com MySQL</h2>
    <?php
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo '<div class="success"><strong>✓ Conexão com MySQL bem-sucedida!</strong></div>';

        // Testar tabela funcionarios
        $stmt = $pdo->query("SELECT COUNT(*) FROM funcionarios");
        $count = $stmt->fetchColumn();
        echo '<div class="success"><strong>✓ Tabela funcionarios existe com ' . $count . ' registros</strong></div>';

        // Testar versão MySQL
        $stmt = $pdo->query("SELECT VERSION()");
        $version = $stmt->fetchColumn();
        echo '<div class="box">Versão MySQL: <code>' . $version . '</code></div>';

    } catch (PDOException $e) {
        echo '<div class="error"><strong>✗ ERRO na conexão:</strong></div>';
        echo '<pre style="background: #fee; padding: 10px; border-radius: 5px; overflow-x: auto;">';
        echo htmlspecialchars($e->getMessage());
        echo '</pre>';

        $code = $e->getCode();
        echo '<div class="box"><strong>Código de erro:</strong> ' . $code . '</div>';

        // Diagnosticar erro comum
        if ($code == 1045) {
            echo '<div class="warning"><strong>⚠️ Erro 1045: Acesso negado</strong><br>Verifique usuário/senha em config_db.php</div>';
        } elseif ($code == 1049) {
            echo '<div class="warning"><strong>⚠️ Erro 1049: Banco desconhecido</strong><br>Banco "' . DB_NAME . '" não existe</div>';
        } elseif ($code == 2002 || $code == 2003) {
            echo '<div class="warning"><strong>⚠️ Erro ' . $code . ': Conexão recusada</strong><br>MySQL pode não estar rodando ou host incorreto</div>';
        }
    }
    ?>
</div>

<div class="box">
    <h2>3️⃣ Testando função obterConexao()</h2>
    <?php
    try {
        // Testar exatamente como login.php faz
        function obterConexao(): PDO
        {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        $db = obterConexao();
        echo '<div class="success"><strong>✓ Função obterConexao() funcionou!</strong></div>';

    } catch (Throwable $e) {
        echo '<div class="error"><strong>✗ ERRO em obterConexao():</strong></div>';
        echo '<pre style="background: #fee; padding: 10px; border-radius: 5px;">';
        echo htmlspecialchars($e->getMessage());
        echo '</pre>';
    }
    ?>
</div>

<div class="box warning">
    <h2>📝 Checklist de Verificação</h2>
    <ul>
        <li>☐ config_db.php existe?</li>
        <li>☐ MySQL está rodando?</li>
        <li>☐ Host está correto (127.0.0.1 ou localhost)?</li>
        <li>☐ Usuário e senha estão corretos?</li>
        <li>☐ Banco de dados existe?</li>
        <li>☐ Tabela funcionarios existe?</li>
    </ul>
</div>

<div class="box">
    <h2>🔧 Se Tudo Passou</h2>
    <p>Se todos os testes acima passaram, o erro pode estar em outro lugar:</p>
    <ul>
        <li>Verifique <code>seguranca.php</code></li>
        <li>Verifique se há erros em PHP logs</li>
        <li>Tente acessar painel ou outro arquivo PHP</li>
    </ul>
</div>

</body>
</html>
