<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);

try {
    $db = obterConexao();
    $stmt = $db->prepare('SELECT nivel_acesso FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosAcesso = $stmt->fetch();
    if ($dadosAcesso) {
        $nivelAcesso = (int) ($dadosAcesso['nivel_acesso'] ?? $nivelAcesso);
        $_SESSION['funcionario_nivel_acesso'] = $nivelAcesso;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erro ao validar acesso.');
}

if ($nivelAcesso < 3) {
    http_response_code(403);
    exit('Acesso negado.');
}

$pastasPermitidas = ['ajustes-ponto', 'afastamentos'];

$caminho = (string) ($_GET['caminho'] ?? '');
if (!preg_match('#^uploads/([a-z0-9_-]+)/([A-Za-z0-9._-]+)$#', $caminho, $partes)) {
    http_response_code(400);
    exit('Caminho inválido.');
}

[$caminhoCompleto, $pasta, $nomeArquivo] = $partes;
if (!in_array($pasta, $pastasPermitidas, true)) {
    http_response_code(400);
    exit('Caminho inválido.');
}

$baseDir = realpath(__DIR__ . '/uploads/' . $pasta);
$destino = $baseDir !== false ? realpath($baseDir . '/' . $nomeArquivo) : false;

if ($baseDir === false || $destino === false || strncmp($destino, $baseDir, strlen($baseDir)) !== 0 || !is_file($destino)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$extensao = strtolower(pathinfo($destino, PATHINFO_EXTENSION));
$tiposMime = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $tiposMime[$extensao] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($destino) . '"');
header('Content-Length: ' . filesize($destino));
header('X-Content-Type-Options: nosniff');
readfile($destino);
