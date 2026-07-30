<?php
// Consulta a Inscrição Municipal de um CPF/CNPJ no CNC (Cadastro Nacional de Contribuintes) do
// Portal Nacional da NFS-e, no município da empresa emissora - usado pra autopreencher a IM do
// tomador na tela de emissão (ver notas-emitir-servico.php).
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfse-nacional-integracao.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['funcionario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Sessão expirada. Atualize a página e faça login novamente.']);
    exit;
}

try {
    $db = obterConexao();
    $stmt = $db->prepare('SELECT permite_notas_fiscais FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['funcionario_id']]);
    if ((int) ($stmt->fetchColumn() ?: 0) !== 1) {
        http_response_code(403);
        echo json_encode(['erro' => 'Sem permissão para consultar o CNC.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao checar permissão: ' . $e->getMessage()]);
    exit;
}

$empresaId = (int) ($_GET['empresa_emissora_id'] ?? 0);
$documento = preg_replace('/\D+/', '', (string) ($_GET['documento'] ?? ''));

if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Empresa emissora inválida.']);
    exit;
}
if (!in_array(strlen($documento), [11, 14], true)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.']);
    exit;
}

try {
    $dbNotas = obterConexaoNotas();
    $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
    $stmtEmpresa->execute(['id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch();

    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['erro' => 'Empresa emissora não encontrada.']);
        exit;
    }

    $resultado = consultarContribuinteCnc($empresa, $documento);

    if (!$resultado['sucesso']) {
        http_response_code(502);
        echo json_encode(['erro' => $resultado['mensagem']]);
        exit;
    }

    echo json_encode([
        'im' => $resultado['im'],
        'razao_social' => $resultado['razao_social'] ?? null,
        'mensagem' => $resultado['mensagem'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
?>
