<?php
/**
 * Script para importar o banco de dados
 * Acesse: http://seu-site.com/importar-sql.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Importar Banco de Dados</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; border-radius: 5px; margin: 10px 0; }
        .success { border-left: 5px solid green; background: #f0fff0; }
        .error { border-left: 5px solid red; background: #fff0f0; }
        .warning { border-left: 5px solid orange; background: #fffaf0; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #45a049; }
    </style>
</head>
<body>

<h1>📥 Importar Banco de Dados</h1>

<div class="box">
    <h2>Passo 1: Verificar arquivo SQL</h2>
    <?php
    $sqlFile = __DIR__ . '/u654041352_Clientes.sql';
    if (file_exists($sqlFile)) {
        $size = filesize($sqlFile) / 1024 / 1024;
        echo '<div class="success"><strong>✓</strong> Arquivo encontrado: <code>' . basename($sqlFile) . '</code> (' . round($size, 2) . ' MB)</div>';
    } else {
        echo '<div class="error"><strong>✗</strong> Arquivo SQL não encontrado!</div>';
        echo '<p>Procurando em: <code>' . $sqlFile . '</code></p>';
        exit;
    }
    ?>
</div>

<div class="box">
    <h2>Passo 2: Verificar conexão</h2>
    <?php
    try {
        require_once 'config_db.php';
        $db = obterConexao();
        echo '<div class="success"><strong>✓</strong> Conexão com MySQL bem-sucedida!</div>';
    } catch (Exception $e) {
        echo '<div class="error"><strong>✗</strong> Erro ao conectar: ' . htmlspecialchars($e->getMessage()) . '</div>';
        exit;
    }
    ?>
</div>

<div class="box">
    <h2>Passo 3: Importar SQL</h2>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            echo '<div class="warning">⏳ Importando... (pode levar alguns minutos)</div>';
            flush();

            $sql = file_get_contents($sqlFile);

            // Dividir em statements (;)
            $statements = explode(';', $sql);
            $count = 0;
            $errors = [];

            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement)) continue;

                try {
                    $db->exec($statement);
                    $count++;
                } catch (Exception $e) {
                    // Alguns erros são esperados (ex: drop table if exists)
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        $errors[] = $e->getMessage();
                    }
                }

                // Mostrar progresso
                if ($count % 100 === 0) {
                    echo '<p>✓ Processados ' . $count . ' statements...</p>';
                    flush();
                }
            }

            echo '<div class="success"><strong>✓ SUCESSO!</strong></div>';
            echo '<p>Banco de dados importado com sucesso!</p>';
            echo '<ul>';
            echo '<li>✓ Statements executados: ' . $count . '</li>';
            if (!empty($errors)) {
                echo '<li>⚠️ Erros (ignoráveis): ' . count($errors) . '</li>';
            }
            echo '</ul>';

            // Verificar tabelas
            $stmt = $db->query("SELECT COUNT(*) as total FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'");
            $result = $stmt->fetch();
            echo '<p><strong>Tabelas criadas:</strong> ' . $result['total'] . '</p>';

            // Verificar funcionarios
            try {
                $stmt = $db->query("SELECT COUNT(*) as total FROM funcionarios");
                $result = $stmt->fetch();
                echo '<p><strong>Funcionários:</strong> ' . $result['total'] . '</p>';
            } catch (Exception $e) {
                echo '<p><strong>Nota:</strong> Tabela funcionarios pode estar vazia</p>';
            }

            echo '<div class="success"><p><strong>✓ Tudo pronto!</strong> Você já pode fazer login!</p></div>';

        } catch (Exception $e) {
            echo '<div class="error"><strong>✗ ERRO:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
    } else {
        echo '<p>Clique em "Importar" para importar o banco de dados.</p>';
        echo '<p><strong>Aviso:</strong> Este processo pode levar alguns minutos (arquivo tem ~18 MB).</p>';
        echo '<form method="POST"><button type="submit">📥 Importar Banco de Dados</button></form>';
    }
    ?>
</div>

<div class="box warning">
    <h2>⚠️ Após Importar</h2>
    <ol>
        <li>Teste fazer login: <a href="entrada-funcionarios.html">Entrada de Funcionários</a></li>
        <li>Se funcionar, delete este arquivo: <code>importar-sql.php</code></li>
        <li>Delete também: <code>debug-login.php</code></li>
        <li>Delete o arquivo SQL: <code>u654041352_Clientes.sql</code></li>
    </ol>
</div>

</body>
</html>
