<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';

function destinoSeguroSelecionarEmpresa(string $destino): string
{
    // Aceita somente páginas locais do emissor fiscal, evitando redirecionamento aberto.
    if ($destino === '' || $destino[0] === '/' || strpos($destino, '://') !== false || strpos($destino, "\\") !== false) {
        return 'notas-fiscais';
    }
    if (!preg_match('/^notas-[a-z0-9\-]+(\.php)?(\?[^\s]*)?$/', $destino)) {
        return 'notas-fiscais';
    }
    return $destino;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf_notas_empresa_ativa']) || !hash_equals($_SESSION['csrf_notas_empresa_ativa'], $csrf)) {
        header('Location: notas-fiscais');
        exit;
    }

    $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
    $destino = destinoSeguroSelecionarEmpresa((string) ($_POST['redirecionar_para'] ?? ''));

    if ($empresaId > 0) {
        try {
            $dbNotas = obterConexaoNotas();
            $stmt = $dbNotas->prepare('SELECT id FROM empresas_emissoras WHERE id = :id AND ativo = 1 LIMIT 1');
            $stmt->execute(['id' => $empresaId]);
            if ($stmt->fetchColumn()) {
                $_SESSION['nfse_empresa_emissora_ativa_id'] = $empresaId;
            }
        } catch (PDOException $e) {
            // Mantém a empresa ativa anterior em caso de falha de conexão.
        }
    } else {
        unset($_SESSION['nfse_empresa_emissora_ativa_id']);
    }

    header('Location: ' . $destino);
    exit;
}

header('Location: notas-fiscais');
exit;
