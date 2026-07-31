<?php
/**
 * Listagem própria de NFC-e (venda no balcão) — página independente de notas-fiscais.php
 * (que passa a excluir tipo_nota='nfce' dos seus três pontos de filtro). Reimpressão de
 * DANFCE, consulta e cancelamento remoto.
 */

require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/includes/notas-nfce-schema.php';
require_once __DIR__ . '/nfce-operacoes.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function rotuloStatusNotaNfce(string $status): string
{
    return [
        'rascunho' => 'Rascunho',
        'pendente_envio' => 'Pendente de envio',
        'autorizada' => 'Autorizada',
        'rejeitada' => 'Rejeitada',
        'cancelada' => 'Cancelada',
    ][$status] ?? $status;
}

function buscarNotaNfceCompleta(PDO $dbNotas, int $notaId): ?array
{
    $stmt = $dbNotas->prepare(
        'SELECT n.*, nf.consumidor_identificado, nf.consumidor_cpf_cnpj, nf.consumidor_nome, nf.modo_emissao, nf.epec_status,
                e.razao_social AS empresa_razao_social, e.cnpj AS empresa_cnpj, e.uf AS empresa_uf,
                e.certificado_arquivo, e.certificado_senha_cifrada
         FROM notas_fiscais n
         INNER JOIN notas_fiscais_nfce nf ON nf.nota_id = n.id
         INNER JOIN empresas_emissoras e ON e.id = n.empresa_emissora_id
         WHERE n.id = :id AND n.tipo_nota = \'nfce\' LIMIT 1'
    );
    $stmt->execute(['id' => $notaId]);

    return $stmt->fetch() ?: null;
}

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;
$erro = '';
$sucesso = '';

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararSchemaNfce($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;
    $usuarioRaw = (string) ($dadosFuncionario['usuario'] ?? 'Funcionário');

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    $idsEmpresasAtivas = array_map('intval', array_column($dbNotas->query('SELECT id FROM empresas_emissoras WHERE ativo = 1')->fetchAll(), 'id'));
    if (empty($_SESSION['nfse_empresa_emissora_ativa_id']) || !in_array((int) $_SESSION['nfse_empresa_emissora_ativa_id'], $idsEmpresasAtivas, true)) {
        $_SESSION['nfse_empresa_emissora_ativa_id'] = $idsEmpresasAtivas[0] ?? 0;
    }
    $empresaEmissoraAtivaId = (int) $_SESSION['nfse_empresa_emissora_ativa_id'];

    // Documentos fiscais: somente notas autorizadas e dentro do escopo do usuário.
    if (isset($_GET['xml']) || isset($_GET['danfce'])) {
        $notaId = (int) ($_GET['xml'] ?? $_GET['danfce']);
        $notaDocumento = buscarNotaNfceCompleta($dbNotas, $notaId);
        if (!$notaDocumento || (int) $notaDocumento['empresa_emissora_id'] !== $empresaEmissoraAtivaId || (!$podeAdministrar && (int) $notaDocumento['funcionario_id'] !== $funcionarioId)) {
            http_response_code(404);
            echo 'NFC-e não encontrada.';
            exit;
        }
        if (!in_array($notaDocumento['status'], ['autorizada'], true) || empty($notaDocumento['xml_gerado'])) {
            http_response_code(409);
            echo 'Documento fiscal disponível somente para NFC-e autorizada.';
            exit;
        }

        try {
            if (isset($_GET['xml'])) {
                header('Content-Type: application/xml; charset=UTF-8');
                header('Content-Disposition: attachment; filename="nfce-' . preg_replace('/\D/', '', (string) $notaDocumento['chave_acesso']) . '.xml"');
                header('X-Content-Type-Options: nosniff');
                echo $notaDocumento['xml_gerado'];
                exit;
            }
            if (isset($_GET['danfce'])) {
                $pdf = gerarDanfcePdf((string) $notaDocumento['xml_gerado']);
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="danfce-' . preg_replace('/\D/', '', (string) $notaDocumento['chave_acesso']) . '.pdf"');
                echo $pdf;
                exit;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Falha ao gerar o documento: ' . h($e->getMessage());
            exit;
        }
    }

    if (empty($_SESSION['csrf_notas_nfce_vendas'])) {
        $_SESSION['csrf_notas_nfce_vendas'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfce_vendas'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $notaId = (int) ($_POST['nota_id'] ?? 0);
            $acao = (string) ($_POST['acao'] ?? '');
            $notaAtual = buscarNotaNfceCompleta($dbNotas, $notaId);
            if (!$notaAtual || (int) $notaAtual['empresa_emissora_id'] !== $empresaEmissoraAtivaId || (!$podeAdministrar && (int) $notaAtual['funcionario_id'] !== $funcionarioId)) {
                $erro = 'NFC-e não encontrada.';
            } elseif ($acao === 'consultar' && $notaAtual['status'] === 'autorizada') {
                try {
                    $empresaStmt = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
                    $empresaStmt->execute(['id' => $notaAtual['empresa_emissora_id']]);
                    $empresaNota = $empresaStmt->fetch();
                    $consulta = consultarNfceRemota($empresaNota, (string) $notaAtual['chave_acesso']);
                    $sucesso = 'Situação na SEFAZ: [' . h($consulta['cStat']) . '] ' . h($consulta['xMotivo']);
                } catch (Throwable $e) {
                    $erro = 'Falha ao consultar: ' . $e->getMessage();
                }
            } elseif ($acao === 'cancelar' && $notaAtual['status'] === 'autorizada') {
                $justificativa = trim((string) ($_POST['motivo_cancelamento'] ?? ''));
                try {
                    $empresaStmt = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
                    $empresaStmt->execute(['id' => $notaAtual['empresa_emissora_id']]);
                    $empresaNota = $empresaStmt->fetch();
                    cancelarNfceRemota($empresaNota, (string) $notaAtual['chave_acesso'], (string) $notaAtual['protocolo_autorizacao'], $justificativa);
                    $dbNotas->prepare("UPDATE notas_fiscais SET status = 'cancelada' WHERE id = :id")->execute(['id' => $notaId]);
                    $sucesso = 'NFC-e cancelada com sucesso.';
                } catch (Throwable $e) {
                    $erro = 'Falha ao cancelar: ' . $e->getMessage();
                }
            } else {
                $erro = 'Ação inválida para o status atual da nota.';
            }
        }
    }

    $where = ["n.tipo_nota = 'nfce'", 'n.empresa_emissora_id = :empresa_emissora_id'];
    $bind = ['empresa_emissora_id' => $empresaEmissoraAtivaId];
    if (!$podeAdministrar) {
        $where[] = 'n.funcionario_id = :funcionario_id';
        $bind['funcionario_id'] = $funcionarioId;
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $stmt = $dbNotas->prepare(
        'SELECT n.id, n.numero_interno, n.serie, n.status, n.valor_total, n.data_emissao, n.criado_em, n.funcionario_id,
                n.chave_acesso, n.motivo_rejeicao, nf.consumidor_identificado, nf.consumidor_cpf_cnpj, nf.consumidor_nome, nf.modo_emissao, nf.epec_status
         FROM notas_fiscais n
         INNER JOIN notas_fiscais_nfce nf ON nf.nota_id = n.id
         ' . $sqlWhere . '
         ORDER BY n.criado_em DESC
         LIMIT 100'
    );
    $stmt->execute($bind);
    $vendas = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar as vendas NFC-e: ' . $e->getMessage();
    $vendas = [];
    $usuarioRaw = 'Funcionário';
}

$csrf = h($_SESSION['csrf_notas_nfce_vendas'] ?? '');
$usuario = h(trim(str_replace('.', ' ', $usuarioRaw ?? '')));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendas (NFC-e) | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotasNfce = 'vendas'; include __DIR__ . '/includes/notas-nfce-nav.php'; ?>

        <section class="panel">
            <h1>Vendas (NFC-e)</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Histórico das notas de consumidor (venda no balcão) emitidas pela empresa ativa. Para vender, use <a href="notas-nfce-emitir" style="text-decoration:underline;">Emitir NFC-e</a>.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo $sucesso; ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2>Notas emitidas</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nº</th>
                            <th>Data</th>
                            <th>Consumidor</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vendas as $venda): ?>
                            <tr>
                                <td>#<?php echo h((string) $venda['numero_interno']); ?> / série <?php echo h((string) $venda['serie']); ?></td>
                                <td><?php echo h((string) $venda['data_emissao']); ?></td>
                                <td>
                                    <?php echo $venda['consumidor_identificado'] ? h((string) ($venda['consumidor_cpf_cnpj'] ?? '')) . ($venda['consumidor_nome'] ? ' — ' . h((string) $venda['consumidor_nome']) : '') : 'Não identificado'; ?>
                                </td>
                                <td>R$ <?php echo number_format((float) $venda['valor_total'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="status-pill status-<?php echo h($venda['status']); ?>"><?php echo h(rotuloStatusNotaNfce($venda['status'])); ?></span>
                                    <?php if ($venda['modo_emissao'] === 'contingencia_epec'): ?>
                                        <div class="muted" style="font-size: 0.7rem; margin-top: 0.3rem;">Contingência EPEC: <?php echo h($venda['epec_status']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($venda['status'] === 'rejeitada' && ($venda['motivo_rejeicao'] ?? '') !== ''): ?>
                                        <div class="muted" style="font-size: 0.7rem; margin-top: 0.3rem; color: #FFD1CE;"><?php echo h($venda['motivo_rejeicao']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ($venda['status'] === 'autorizada'): ?>
                                            <a class="btn btn-outline btn-small" href="notas-nfce-vendas?danfce=<?php echo h((string) $venda['id']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> DANFCE</a>
                                            <a class="btn btn-outline btn-small" href="notas-nfce-vendas?xml=<?php echo h((string) $venda['id']); ?>"><i class="fa-solid fa-code"></i> XML</a>
                                            <form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="nota_id" value="<?php echo h((string) $venda['id']); ?>"><input type="hidden" name="acao" value="consultar"><button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-rotate"></i> Consultar</button></form>
                                            <form method="post" onsubmit="return prepararCancelamentoNfce(this);"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="nota_id" value="<?php echo h((string) $venda['id']); ?>"><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="motivo_cancelamento" value=""><button class="btn btn-danger btn-small" type="submit"><i class="fa-solid fa-ban"></i> Cancelar</button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($vendas)): ?>
                            <tr><td colspan="6" class="muted">Nenhuma venda NFC-e encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        function prepararCancelamentoNfce(formulario) {
            var motivo = prompt('Justificativa do cancelamento (15 a 255 caracteres):');
            if (motivo === null) return false;
            motivo = motivo.trim();
            if (motivo.length < 15 || motivo.length > 255) {
                alert('A justificativa precisa ter entre 15 e 255 caracteres.');
                return false;
            }
            formulario.querySelector('input[name="motivo_cancelamento"]').value = motivo;
            return true;
        }

        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true })
                .finally(() => {
                    window.location.href = '/';
                });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1')
                .then(() => {
                    window.location.href = '/';
                });
        }
    </script>
</body>
</html>
