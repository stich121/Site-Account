<?php
/**
 * Gerador de PDF de Ponto integrado com o painel
 * Gera HTML do espelho de ponto (convertível em PDF pelo navegador)
 */

require_once __DIR__ . '/seguranca.php';
require_once __DIR__ . '/painel-funcionarios.php';

iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

// Parâmetros
$export = $_GET['export'] ?? 'pdf';
$funcionarioExport = (int) ($_GET['funcionario_id'] ?? 0);
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-t');
$scopeAll = ($_GET['scope'] ?? '') === 'all' && $podeAdministrar;

// Determinar funcionário
if ($scopeAll && $funcionarioExport > 0) {
    $fid = $funcionarioExport;
} else {
    $fid = $funcionarioId;
}

// Redirecionar para painel com parâmetros de PDF
// Isso usa o código existente do painel-funcionarios.php
$_GET['export'] = 'pdf';
$_GET['scope'] = $scopeAll ? 'all' : '';
$_GET['funcionario_id'] = $fid;
$_GET['data_inicio'] = $dataInicio;
$_GET['data_fim'] = $dataFim;

// Incluir painel que já tem lógica pronta
include __DIR__ . '/painel-funcionarios.php';
