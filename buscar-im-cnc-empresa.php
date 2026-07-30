<?php
// Consulta a Inscrição Municipal de um CNPJ no CNC (Cadastro Nacional de Contribuintes) do Portal
// Nacional da NFS-e - usado pra autopreencher a IM no cadastro de EMPRESA EMISSORA (ver
// notas-empresas-emissoras.php), logo após a busca de dados do CNPJ.
//
// Diferente de buscar-im-cnc.php (que consulta a IM do tomador usando o certificado da própria
// empresa emissora ativa), aqui a empresa sendo cadastrada pode ainda não ter certificado A1
// próprio - então a consulta usa emprestado o certificado de qualquer outra empresa já
// configurada no sistema, só para autenticar no gateway do ADN (ver
// empresaComCertificadoParaConsultaCnc() em nfse-nacional-integracao.php).
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

$cnpj = preg_replace('/\D+/', '', (string) ($_GET['cnpj'] ?? ''));
$codMunicipio = preg_replace('/\D+/', '', (string) ($_GET['codigo_ibge_municipio'] ?? ''));

if (strlen($cnpj) !== 14) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um CNPJ com 14 dígitos.']);
    exit;
}
if ($codMunicipio === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'Código IBGE do município não informado (busque o CNPJ primeiro).']);
    exit;
}

try {
    $dbNotas = obterConexaoNotas();
    $empresaCertificado = empresaComCertificadoParaConsultaCnc($dbNotas);

    if (!$empresaCertificado) {
        http_response_code(422);
        echo json_encode(['erro' => 'Nenhuma empresa do sistema tem certificado A1 válido cadastrado ainda para emprestar a consulta ao CNC.']);
        exit;
    }

    $resultado = consultarContribuinteCnc($empresaCertificado, $cnpj, $codMunicipio);

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
