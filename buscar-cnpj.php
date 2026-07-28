<?php
// Consulta dados públicos de uma empresa pelo CNPJ (BrasilAPI, espelho gratuito e sem
// chave dos dados da Receita Federal), pra preencher o formulário de empresa emissora
// sem precisar digitar tudo na mão.
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['funcionario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Sessão expirada. Atualize a página e faça login novamente.']);
    exit;
}

$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
if ($nivelAcesso < 3) {
    http_response_code(403);
    echo json_encode(['erro' => 'Sem permissão para consultar CNPJ.']);
    exit;
}

$cnpj = preg_replace('/\D/', '', (string) ($_GET['cnpj'] ?? ''));
if (strlen($cnpj) !== 14) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um CNPJ com 14 dígitos.']);
    exit;
}

try {
    $ch = curl_init('https://brasilapi.com.br/api/cnpj/v1/' . $cnpj);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resposta = curl_exec($ch);
    $statusHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        throw new RuntimeException('Não foi possível consultar o CNPJ agora: ' . $erroCurl);
    }

    if ($statusHttp === 404) {
        http_response_code(404);
        echo json_encode(['erro' => 'CNPJ não encontrado na base da Receita Federal.']);
        exit;
    }

    if ($statusHttp !== 200) {
        http_response_code(502);
        echo json_encode(['erro' => 'Serviço de consulta de CNPJ indisponível no momento (tente novamente em instantes).']);
        exit;
    }

    $dados = json_decode($resposta, true);
    if (!is_array($dados)) {
        throw new RuntimeException('Resposta inválida do serviço de consulta de CNPJ.');
    }

    $crtSugerido = (!empty($dados['opcao_pelo_simples']) || !empty($dados['opcao_pelo_mei'])) ? 1 : 3;

    echo json_encode([
        'razao_social' => $dados['razao_social'] ?? '',
        'nome_fantasia' => $dados['nome_fantasia'] ?? '',
        'logradouro' => $dados['logradouro'] ?? '',
        'numero' => $dados['numero'] ?? '',
        'complemento' => $dados['complemento'] ?? '',
        'bairro' => $dados['bairro'] ?? '',
        'cep' => $dados['cep'] ?? '',
        'municipio' => $dados['municipio'] ?? '',
        'codigo_ibge_municipio' => $dados['codigo_municipio_ibge'] ?? '',
        'uf' => $dados['uf'] ?? '',
        'crt_sugerido' => $crtSugerido,
        'situacao_cadastral' => $dados['descricao_situacao_cadastral'] ?? '',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
?>
