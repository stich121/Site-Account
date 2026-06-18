<?php
/**
 * Gerador de PDF de Ponto integrado com o painel
 * Chama o script Python gerar_folha_ponto_pdf.py e retorna o PDF
 */

require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

// Parâmetros
$export = $_GET['export'] ?? 'pdf';
$funcionarioExport = (int) ($_GET['funcionario_id'] ?? 0);
$mes = $_GET['mes'] ?? null;
$dataInicio = $_GET['data_inicio'] ?? null;
$dataFim = $_GET['data_fim'] ?? null;
$scopeAll = ($_GET['scope'] ?? '') === 'all' && $podeAdministrar;

// Validar tipo de export
if ($export !== 'pdf') {
    http_response_code(400);
    echo json_encode(['erro' => 'Tipo de exportação inválido']);
    exit;
}

// Determinar funcionário
if ($scopeAll && $funcionarioExport > 0) {
    $fid = $funcionarioExport;
} else {
    $fid = $funcionarioId;
}

// Determinar período
if ($mes) {
    [$ano, $m] = explode('-', $mes);
    $dataInicio = "$ano-$m-01";
    $ultimoDia = date('t', mktime(0, 0, 0, $m, 1, $ano));
    $dataFim = "$ano-$m-$ultimoDia";
} elseif ($dataInicio && $dataFim) {
    // Usar as datas fornecidas
} else {
    // Mês atual
    $dataInicio = date('Y-m-01');
    $dataFim = date('Y-m-t');
}

// Validar datas
try {
    new DateTime($dataInicio);
    new DateTime($dataFim);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['erro' => 'Datas inválidas']);
    exit;
}

// Caminho do script Python
$toolsDir = __DIR__ . '/tools';
$scriptPython = $toolsDir . '/gerar_folha_ponto_pdf.py';

if (!file_exists($scriptPython)) {
    http_response_code(500);
    echo json_encode(['erro' => 'Script Python não encontrado em ' . $scriptPython]);
    exit;
}

// Verificar se Python está disponível
$pythonCmd = strpos(PHP_OS, 'WIN') === 0 ? 'python' : 'python3';
$checkPython = shell_exec("$pythonCmd --version 2>&1");

if ($checkPython === null || strpos($checkPython, 'Python') === false) {
    http_response_code(500);
    echo json_encode(['erro' => 'Python não está disponível no servidor']);
    exit;
}

// Criar arquivo temporário para saída
$tempFile = tempnam(sys_get_temp_dir(), 'folha_ponto_');
$tempPdf = $tempFile . '.pdf';

// Comando para executar o script Python
$cmd = sprintf(
    '%s "%s" %d --inicio %s --fim %s --saida "%s" 2>&1',
    escapeshellcmd($pythonCmd),
    escapeshellarg($scriptPython),
    $fid,
    escapeshellarg($dataInicio),
    escapeshellarg($dataFim),
    escapeshellarg($tempPdf)
);

// Executar script Python
$output = shell_exec($cmd);
$exitCode = 0;

// Verificar se o PDF foi gerado
if (!file_exists($tempPdf)) {
    // Se falhou, tentar gerar HTML em vez disso
    $tempHtml = $tempFile . '.html';
    $cmd = sprintf(
        '%s "%s" %d --inicio %s --fim %s --saida "%s" 2>&1',
        escapeshellcmd($pythonCmd),
        escapeshellarg($scriptPython),
        $fid,
        escapeshellarg($dataInicio),
        escapeshellarg($dataFim),
        escapeshellarg($tempHtml)
    );

    $output = shell_exec($cmd);

    if (!file_exists($tempHtml)) {
        http_response_code(500);
        echo json_encode([
            'erro' => 'Falha ao gerar relatório',
            'detalhes' => $output
        ]);
        exit;
    }

    // Enviar HTML (navegador vai salvar como PDF)
    $arquivo = file_get_contents($tempHtml);
    $nomeArquivo = sprintf('espelho-ponto-%s.html', $dataInicio);

    header('Content-Type: text/html; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$nomeArquivo\"");
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $arquivo;

    // Limpar temporários
    @unlink($tempFile);
    @unlink($tempHtml);
    exit;
}

// Enviar PDF gerado
$arquivo = file_get_contents($tempPdf);
$nomeArquivo = sprintf('espelho-ponto-%s.pdf', $dataInicio);

header('Content-Type: application/pdf');
header("Content-Disposition: attachment; filename=\"$nomeArquivo\"");
header('Content-Length: ' . strlen($arquivo));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $arquivo;

// Limpar temporários
@unlink($tempFile);
@unlink($tempPdf);
