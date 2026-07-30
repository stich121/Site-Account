<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/nfse-nacional-integracao.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = atualizarNivelAcessoSessao(obterConexao(), $funcionarioId);
$podeAdministrar = $nivelAcesso >= 3;

$erro = '';
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function escaparPdfTextoAdn(string $texto): string
{
    $semAcento = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) : $texto;
    $semAcento = $semAcento !== false ? $semAcento : $texto;

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $semAcento);
}

function gerarPdfSimplesAdn(array $linhas): string
{
    $linhasPorPagina = 54;
    $paginas = array_chunk($linhas, $linhasPorPagina);
    if (empty($paginas)) {
        $paginas = [[]];
    }

    $fontObjNum = 3;
    $objetos = [];
    $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objetos[$fontObjNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

    $nextObj = 4;
    $pageObjNums = [];
    foreach ($paginas as $paginaLinhas) {
        $contentObjNum = $nextObj++;
        $pageObjNum = $nextObj++;
        $comandos = ['BT', '/F1 10 Tf', '40 800 Td', '12 TL'];
        foreach ($paginaLinhas as $linha) {
            $comandos[] = '(' . escaparPdfTextoAdn((string) $linha) . ') Tj T*';
        }
        $comandos[] = 'ET';
        $stream = implode("\n", $comandos);
        $streamLen = strlen($stream);
        $objetos[$contentObjNum] = "<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream";
        $objetos[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 {$fontObjNum} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
        $pageObjNums[] = $pageObjNum;
    }

    $kidsRefs = implode(' ', array_map(static fn (int $n): string => "{$n} 0 R", $pageObjNums));
    $objetos[2] = '<< /Type /Pages /Kids [' . $kidsRefs . '] /Count ' . count($pageObjNums) . ' >>';

    ksort($objetos);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objetos as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    $totalObjs = max(array_keys($objetos)) + 1;
    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 {$totalObjs}\n0000000000 65535 f \n";
    for ($i = 1; $i < $totalObjs; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size {$totalObjs} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

    return $pdf;
}

function linhasPdfComQuebraAdn(string $texto, int $largura = 92): array
{
    return explode("\n", wordwrap($texto, $largura, "\n", true));
}

function paginasParaExibirAdn(int $paginaAtual, int $totalPaginas): array
{
    $paginas = array_unique(array_filter(array_merge(
        [1, 2],
        range(max(1, $paginaAtual - 1), min($totalPaginas, $paginaAtual + 1)),
        range(max(1, $totalPaginas - 2), $totalPaginas)
    ), static fn (int $p): bool => $p >= 1 && $p <= $totalPaginas));
    sort($paginas);

    return $paginas;
}

function colunaExisteNotasAdn(PDO $db, string $tabela, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['tabela' => $tabela, 'coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararTabelaNfseAdn(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfse_adn (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            chave_acesso VARCHAR(60) NOT NULL,
            nsu BIGINT UNSIGNED NULL,
            tipo_documento ENUM('emitida','recebida') NOT NULL,
            numero_nfse VARCHAR(30) NULL,
            codigo_status INT UNSIGNED NULL,
            cnpj_prestador VARCHAR(20) NULL,
            nome_prestador VARCHAR(180) NULL,
            cnpj_tomador VARCHAR(20) NULL,
            nome_tomador VARCHAR(180) NULL,
            descricao_servico VARCHAR(255) NULL,
            data_emissao DATETIME NULL,
            competencia DATE NULL,
            valor_servico DECIMAL(14,2) NULL,
            valor_liquido DECIMAL(14,2) NULL,
            xml_completo MEDIUMTEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_nfse_adn_chave (chave_acesso),
            KEY idx_nfse_adn_empresa (empresa_emissora_id, tipo_documento, data_emissao),
            CONSTRAINT fk_nfse_adn_empresa
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararColunasNsuEmpresasEmissoras(PDO $db): void
{
    if (!colunaExisteNotasAdn($db, 'empresas_emissoras', 'nfse_adn_ultimo_nsu')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfse_adn_ultimo_nsu BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER certificado_validade');
    }
    if (!colunaExisteNotasAdn($db, 'empresas_emissoras', 'nfse_adn_sincronizado_em')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfse_adn_sincronizado_em TIMESTAMP NULL AFTER nfse_adn_ultimo_nsu');
    }
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();

    if (!schemaJaPreparada('notas_fiscais_nfse_adn')) {
        prepararTabelaNfseAdn($dbNotas);
        prepararColunasNsuEmpresasEmissoras($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfse_adn');
    }

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_nfse_adn'])) {
        $_SESSION['csrf_notas_nfse_adn'] = bin2hex(random_bytes(32));
    }

    $empresasEmissorasFiltro = $dbNotas->query(
        'SELECT id, razao_social, cnpj, ambiente_emissao, nfse_adn_sincronizado_em FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    )->fetchAll();

    // Download do XML já baixado e guardado localmente (não faz nova chamada ao Portal Nacional).
    if (isset($_GET['xml_adn'])) {
        $documentoId = (int) $_GET['xml_adn'];
        $stmtXml = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfse_adn WHERE id = :id LIMIT 1');
        $stmtXml->execute(['id' => $documentoId]);
        $documentoXml = $stmtXml->fetch();
        if (!$documentoXml || empty($documentoXml['xml_completo'])) {
            http_response_code(404);
            echo 'Documento não encontrado.';
            exit;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="NFSe' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoXml['chave_acesso']) . '.xml"');
        echo $documentoXml['xml_completo'];
        exit;
    }

    // PDF de conferência: resumo interno gerado a partir dos dados já sincronizados, sem
    // valor fiscal (o DANFSe oficial exige o renderizador NT 008, que este projeto ainda não tem).
    if (isset($_GET['pdf_adn'])) {
        $documentoId = (int) $_GET['pdf_adn'];
        $stmtPdf = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfse_adn WHERE id = :id LIMIT 1');
        $stmtPdf->execute(['id' => $documentoId]);
        $documentoPdf = $stmtPdf->fetch();
        if (!$documentoPdf) {
            http_response_code(404);
            echo 'Documento não encontrado.';
            exit;
        }

        $linhasPdf = array_merge(
            ['RESUMO DE NFS-e (PORTAL NACIONAL) - SEM VALOR FISCAL', ''],
            ['Tipo: ' . ($documentoPdf['tipo_documento'] === 'emitida' ? 'Emitida' : 'Recebida')],
            ['Numero NFS-e: ' . ($documentoPdf['numero_nfse'] ?? '-')],
            ['Chave de acesso: ' . $documentoPdf['chave_acesso']],
            [''],
            linhasPdfComQuebraAdn('Prestador: ' . ($documentoPdf['nome_prestador'] ?? '-')),
            ['CNPJ prestador: ' . ($documentoPdf['cnpj_prestador'] ?? '-')],
            [''],
            linhasPdfComQuebraAdn('Tomador: ' . ($documentoPdf['nome_tomador'] ?? '-')),
            ['CNPJ tomador: ' . ($documentoPdf['cnpj_tomador'] ?? '-')],
            [''],
            ['Data de emissão: ' . (!empty($documentoPdf['data_emissao']) ? date('d/m/Y H:i', strtotime($documentoPdf['data_emissao'])) : '-')],
            ['Competência: ' . (!empty($documentoPdf['competencia']) ? date('m/Y', strtotime($documentoPdf['competencia'])) : '-')],
            [''],
            linhasPdfComQuebraAdn('Descrição do serviço: ' . ($documentoPdf['descricao_servico'] ?? '-')),
            [''],
            ['Valor do serviço: R$ ' . number_format((float) ($documentoPdf['valor_servico'] ?? 0), 2, ',', '.')],
            ['Valor líquido: R$ ' . number_format((float) ($documentoPdf['valor_liquido'] ?? 0), 2, ',', '.')],
            ['', 'Documento gerado a partir da cópia local sincronizada do Portal Nacional (ADN).'],
            ['Resumo interno sem valor fiscal - não substitui o DANFSe oficial. Para a nota', 'autenticada, baixe o XML fiscal.'],
        );

        $pdf = gerarPdfSimplesAdn($linhasPdf);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="NFSe' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoPdf['chave_acesso']) . '.pdf"');
        echo $pdf;
        exit;
    }

    $empresaSincronizarId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfse_adn'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'sincronizar') {
            $empresaSincronizarId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaSincronizarId]);
            $empresaSincronizar = $stmtEmpresa->fetch();
            if (!$empresaSincronizar) {
                $erro = 'Selecione uma empresa emissora válida para sincronizar.';
            } else {
                $resultadoSync = sincronizarNfseAdn($dbNotas, $empresaSincronizar);
                if ($resultadoSync['sucesso']) {
                    $sucesso = $resultadoSync['mensagem'];
                } else {
                    $erro = $resultadoSync['mensagem'];
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'reprocessar_local') {
            $totalReprocessado = reprocessarNfseAdnLocal($dbNotas);
            $sucesso = "{$totalReprocessado} documento(s) reprocessado(s) a partir do XML já salvo (sem consultar o Portal Nacional de novo).";
        }
    }

    // Filtros de busca (aplicados sobre a cópia local sincronizada do Portal Nacional).
    $filtroEmpresaId = (int) ($_GET['empresa_emissora_id'] ?? 0);
    $filtroTipo = in_array($_GET['tipo'] ?? '', ['emitida', 'recebida'], true) ? $_GET['tipo'] : '';
    $filtroDataInicio = trim($_GET['data_inicio'] ?? '');
    $filtroDataFim = trim($_GET['data_fim'] ?? '');
    $filtroBusca = trim($_GET['busca'] ?? '');
    $paginaAtual = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = 25;

    $condicoes = ['e.ativo = 1'];
    $bind = [];
    if ($filtroEmpresaId > 0) {
        $condicoes[] = 'a.empresa_emissora_id = :empresa_emissora_id';
        $bind['empresa_emissora_id'] = $filtroEmpresaId;
    }
    if ($filtroTipo !== '') {
        $condicoes[] = 'a.tipo_documento = :tipo_documento';
        $bind['tipo_documento'] = $filtroTipo;
    }
    if ($filtroDataInicio !== '') {
        $condicoes[] = 'a.data_emissao >= :data_inicio';
        $bind['data_inicio'] = $filtroDataInicio . ' 00:00:00';
    }
    if ($filtroDataFim !== '') {
        $condicoes[] = 'a.data_emissao <= :data_fim';
        $bind['data_fim'] = $filtroDataFim . ' 23:59:59';
    }
    if ($filtroBusca !== '') {
        $condicoes[] = '(a.nome_prestador LIKE :busca OR a.nome_tomador LIKE :busca OR a.cnpj_prestador LIKE :busca_doc OR a.cnpj_tomador LIKE :busca_doc OR a.numero_nfse LIKE :busca_doc OR a.chave_acesso LIKE :busca_doc)';
        $bind['busca'] = '%' . $filtroBusca . '%';
        $bind['busca_doc'] = '%' . preg_replace('/\D+/', '', $filtroBusca) . '%';
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $condicoes);

    $stmtTotal = $dbNotas->prepare(
        "SELECT COUNT(*) FROM notas_fiscais_nfse_adn a INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id {$sqlWhere}"
    );
    foreach ($bind as $chaveBind => $valorBind) {
        $stmtTotal->bindValue($chaveBind, $valorBind);
    }
    $stmtTotal->execute();
    $totalDocumentos = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalDocumentos / $porPagina));
    $paginaAtual = min($paginaAtual, $totalPaginas);
    $offset = ($paginaAtual - 1) * $porPagina;

    $stmt = $dbNotas->prepare(
        "SELECT a.*, e.razao_social AS empresa_razao_social
         FROM notas_fiscais_nfse_adn a
         INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id
         {$sqlWhere}
         ORDER BY a.data_emissao DESC
         LIMIT :limite OFFSET :offset"
    );
    foreach ($bind as $chaveBind => $valorBind) {
        $stmt->bindValue($chaveBind, $valorBind);
    }
    $stmt->bindValue('limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $documentos = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar o buscador de NFS-e: ' . $e->getMessage();
    $documentos = [];
    $totalDocumentos = 0;
    $totalPaginas = 1;
    $paginaAtual = 1;
}

$csrf = h($_SESSION['csrf_notas_nfse_adn'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscador de NFS-e (Portal Nacional) | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="painel" aria-label="Voltar para o painel">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="menu-hamburguer">
                <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
                    <i class="fa-solid fa-bars"></i> Menu
                </button>
                <div class="menu-dropdown" id="menuDropdown">
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas fiscais</a>
                    <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                    <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                    <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
                </div>
            </div>
        </header>

        <section class="panel">
            <h1>Buscador de NFS-e (Portal Nacional)</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Ferramenta independente do emissor de notas: consulta direto o Ambiente de Dados Nacional (ADN) e mostra todas as NFS-e ligadas ao CNPJ de cada empresa — as que ela emitiu e as que ela recebeu como tomadora.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <div class="notice warning">
            <strong>Como funciona:</strong> o Portal Nacional não permite buscar por data ou nome diretamente — só por lote sequencial (NSU). Por isso, clique em "Sincronizar agora" para baixar os documentos novos de uma empresa; eles ficam guardados aqui e os filtros abaixo pesquisam nessa cópia local. O botão <strong>XML</strong> baixa o arquivo fiscal original; o <strong>PDF</strong> é um resumo interno gerado a partir desses dados, sem valor fiscal (o gerador oficial de DANFSe foi descontinuado pelo governo em 01/07/2026).
        </div>

        <section class="panel">
            <h2><i class="fa-solid fa-rotate"></i> Sincronizar com o Portal Nacional</h2>
            <form method="post" class="row-actions" style="flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="acao" value="sincronizar">
                <select class="select-filtro" name="empresa_emissora_id" required>
                    <option value="">Selecione a empresa para sincronizar</option>
                    <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                        <option value="<?php echo (int) $empresaOpcao['id']; ?>" <?php echo $empresaSincronizarId === (int) $empresaOpcao['id'] ? 'selected' : ''; ?>><?php echo h($empresaOpcao['razao_social']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit"><i class="fa-solid fa-cloud-arrow-down"></i> Sincronizar agora</button>
            </form>
            <div class="row-actions" style="margin-top:0.75rem; flex-wrap:wrap; gap:0.5rem 1rem;">
                <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                    <span class="muted" style="font-size:0.8rem;">
                        <strong><?php echo h($empresaOpcao['razao_social']); ?>:</strong>
                        <?php echo !empty($empresaOpcao['nfse_adn_sincronizado_em']) ? 'última sincronização em ' . h(date('d/m/Y H:i', strtotime($empresaOpcao['nfse_adn_sincronizado_em']))) : 'ainda não sincronizada'; ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="row-actions" style="margin-top:0.75rem;">
                <form method="post" onsubmit="return confirm('Reprocessar todos os documentos já baixados a partir do XML salvo? Não consulta o Portal Nacional, só recalcula os dados exibidos.');">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="reprocessar_local">
                    <button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Reprocessar documentos já baixados</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;"><i class="fa-solid fa-magnifying-glass"></i> Buscar documentos</h2>
                <form method="get" class="row-actions" style="flex-wrap:wrap;">
                    <input class="select-filtro" type="text" name="busca" value="<?php echo h($filtroBusca); ?>" placeholder="Nome, CNPJ, nº NFS-e ou chave" style="min-width:220px;">
                    <input class="select-filtro" type="date" name="data_inicio" value="<?php echo h($filtroDataInicio); ?>" aria-label="Data inicial">
                    <span class="muted">até</span>
                    <input class="select-filtro" type="date" name="data_fim" value="<?php echo h($filtroDataFim); ?>" aria-label="Data final">
                    <select class="select-filtro" name="empresa_emissora_id">
                        <option value="">Todas as empresas</option>
                        <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                            <option value="<?php echo (int) $empresaOpcao['id']; ?>" <?php echo $filtroEmpresaId === (int) $empresaOpcao['id'] ? 'selected' : ''; ?>><?php echo h($empresaOpcao['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="select-filtro" name="tipo">
                        <option value="">Emitidas e recebidas</option>
                        <option value="emitida" <?php echo $filtroTipo === 'emitida' ? 'selected' : ''; ?>>Somente emitidas</option>
                        <option value="recebida" <?php echo $filtroTipo === 'recebida' ? 'selected' : ''; ?>>Somente recebidas</option>
                    </select>
                    <button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                </form>
            </div>

            <div class="table-wrap">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Data emissão</th>
                            <th>Tipo</th>
                            <th>Prestador</th>
                            <th>Tomador</th>
                            <th>Nº NFS-e</th>
                            <th>Valor líquido</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documentos)): ?>
                            <tr><td colspan="7" class="muted">Nenhum documento encontrado. Sincronize a empresa acima ou ajuste os filtros.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($documentos as $documento): ?>
                            <tr>
                                <td><?php echo !empty($documento['data_emissao']) ? h(date('d/m/Y H:i', strtotime($documento['data_emissao']))) : '—'; ?></td>
                                <td>
                                    <span class="status-pill <?php echo $documento['tipo_documento'] === 'emitida' ? 'status-autorizada' : 'status-pendente_envio'; ?>">
                                        <?php echo $documento['tipo_documento'] === 'emitida' ? 'Emitida' : 'Recebida'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo h($documento['nome_prestador'] ?? '—'); ?>
                                    <div class="muted" style="font-size:0.7rem;"><?php echo h($documento['cnpj_prestador'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php echo h($documento['nome_tomador'] ?? '—'); ?>
                                    <div class="muted" style="font-size:0.7rem;"><?php echo h($documento['cnpj_tomador'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php echo h($documento['numero_nfse'] ?? '—'); ?>
                                    <div class="muted" style="font-size:0.65rem;">Chave: <?php echo h($documento['chave_acesso']); ?></div>
                                </td>
                                <td>R$ <?php echo number_format((float) ($documento['valor_liquido'] ?? 0), 2, ',', '.'); ?></td>
                                <td>
                                    <div class="row-actions" style="flex-wrap:nowrap;">
                                        <a class="btn btn-outline btn-small" href="notas-fiscais-nfse-adn?xml_adn=<?php echo (int) $documento['id']; ?>"><i class="fa-solid fa-code"></i> XML</a>
                                        <a class="btn btn-outline btn-small" href="notas-fiscais-nfse-adn?pdf_adn=<?php echo (int) $documento['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <?php $paginasExibir = paginasParaExibirAdn($paginaAtual, $totalPaginas); ?>
                <div class="row-actions" style="justify-content:center; margin-top:1rem;">
                    <?php $paginaAnterior = 0; ?>
                    <?php foreach ($paginasExibir as $p): ?>
                        <?php if ($p - $paginaAnterior > 1): ?>
                            <span class="muted" style="padding:0 0.25rem;">…</span>
                        <?php endif; ?>
                        <a class="btn <?php echo $p === $paginaAtual ? '' : 'btn-outline'; ?> btn-small"
                           href="notas-fiscais-nfse-adn?<?php echo h(http_build_query(array_filter([
                               'busca' => $filtroBusca,
                               'data_inicio' => $filtroDataInicio,
                               'data_fim' => $filtroDataFim,
                               'empresa_emissora_id' => $filtroEmpresaId ?: null,
                               'tipo' => $filtroTipo,
                               'pagina' => $p,
                           ], static fn ($v) => $v !== null && $v !== ''))); ?>"><?php echo $p; ?></a>
                        <?php $paginaAnterior = $p; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <script>
        const btnMenuHamburguer = document.getElementById('btnMenuHamburguer');
        const menuDropdown = document.getElementById('menuDropdown');
        if (btnMenuHamburguer && menuDropdown) {
            btnMenuHamburguer.addEventListener('click', function (evento) {
                evento.stopPropagation();
                const aberto = menuDropdown.classList.toggle('aberto');
                btnMenuHamburguer.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
            document.addEventListener('click', function (evento) {
                if (!menuDropdown.contains(evento.target) && evento.target !== btnMenuHamburguer) {
                    menuDropdown.classList.remove('aberto');
                    btnMenuHamburguer.setAttribute('aria-expanded', 'false');
                }
            });
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
