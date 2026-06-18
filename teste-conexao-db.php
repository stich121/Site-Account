<?php
/**
 * Script para testar conexão com banco de dados
 * Acesse: http://seu-site.com/teste-conexao-db.php
 */

require_once __DIR__ . '/config_db.php';

echo "<h1>Teste de Conexão com Banco de Dados</h1>";
echo "<style>body { font-family: Arial; margin: 20px; }</style>";

echo "<h2>Credenciais configuradas:</h2>";
echo "<pre>";
echo "Host: " . DB_HOST . "\n";
echo "User: " . DB_USER . "\n";
echo "Database: " . DB_NAME . "\n";
echo "Charset: " . DB_CHARSET . "\n";
echo "</pre>";

echo "<h2>Tentando conectar...</h2>";

try {
    $db = obterConexao();
    echo "<p style='color: green;'><strong>✓ Conexão bem-sucedida!</strong></p>";

    // Teste 1: Verificar tabela funcionarios
    echo "<h3>Teste 1: Tabela 'funcionarios'</h3>";
    $stmt = $db->query("SELECT COUNT(*) as total FROM funcionarios");
    $resultado = $stmt->fetch();
    echo "<p>Total de funcionários: " . $resultado['total'] . "</p>";

    // Teste 2: Verificar colunas necessárias
    echo "<h3>Teste 2: Colunas da tabela 'funcionarios'</h3>";
    $stmt = $db->query("DESCRIBE funcionarios");
    $colunas = $stmt->fetchAll();
    echo "<ul>";
    foreach ($colunas as $coluna) {
        echo "<li>" . $coluna['Field'] . " (" . $coluna['Type'] . ")</li>";
    }
    echo "</ul>";

    // Teste 3: Versão do MySQL
    echo "<h3>Teste 3: Versão do MySQL</h3>";
    $stmt = $db->query("SELECT VERSION() as version");
    $versao = $stmt->fetch();
    echo "<p>" . $versao['version'] . "</p>";

    echo "<p style='color: green;'><strong>✓ Todos os testes passaram!</strong></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ Erro ao conectar:</strong></p>";
    echo "<pre style='background: #fee; padding: 10px; border-radius: 5px;'>";
    echo htmlspecialchars($e->getMessage());
    echo "</pre>";

    echo "<h3>Dicas para resolver:</h3>";
    echo "<ul>";
    echo "<li>Verifique se MySQL está rodando</li>";
    echo "<li>Verifique as credenciais em <code>config_db.php</code></li>";
    echo "<li>Verifique se o banco <code>" . DB_NAME . "</code> existe</li>";
    echo "<li>Verifique se o usuário <code>" . DB_USER . "</code> tem permissão</li>";
    echo "</ul>";
}
?>
