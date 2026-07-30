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
require_once __DIR__ . '/nfe-distribuicao-integracao.php';
require_once __DIR__ . '/nfe-operacoes.php';

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

function paginasParaExibirNfeDfe(int $paginaAtual, int $totalPaginas): array
{
    $paginas = array_unique(array_filter(array_merge(
        [1, 2],
        range(max(1, $paginaAtual - 1), min($totalPaginas, $paginaAtual + 1)),
        range(max(1, $totalPaginas - 2), $totalPaginas)
    ), static fn (int $p): bool => $p >= 1 && $p <= $totalPaginas));
    sort($paginas);

    return $paginas;
}

function colunaExisteNfeDfe(PDO $db, string $tabela, string $coluna): bool
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

function prepararTabelaNfeDfe(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfe_dfe (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            chave_acesso VARCHAR(60) NOT NULL,
            nsu BIGINT UNSIGNED NULL,
            tipo_documento ENUM('emitida','recebida') NOT NULL,
            numero_nfe INT UNSIGNED NULL,
            serie SMALLINT UNSIGNED NULL,
            situacao ENUM('autorizada','cancelada','denegada') NOT NULL DEFAULT 'autorizada',
            cancelada TINYINT(1) NOT NULL DEFAULT 0,
            data_cancelamento DATETIME NULL,
            cnpj_emitente VARCHAR(20) NULL,
            nome_emitente VARCHAR(180) NULL,
            cnpj_destinatario VARCHAR(20) NULL,
            nome_destinatario VARCHAR(180) NULL,
            natureza_operacao VARCHAR(120) NULL,
            descricao_resumida VARCHAR(255) NULL,
            data_emissao DATETIME NULL,
            valor_nfe DECIMAL(14,2) NULL,
            protocolo_autorizacao VARCHAR(30) NULL,
            tem_documento_completo TINYINT(1) NOT NULL DEFAULT 0,
            xml_completo MEDIUMTEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_nfe_dfe_chave (chave_acesso),
            KEY idx_nfe_dfe_empresa (empresa_emissora_id, tipo_documento, data_emissao),
            CONSTRAINT fk_nfe_dfe_empresa
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararColunasNsuNfeEmpresasEmissoras(PDO $db): void
{
    if (!colunaExisteNfeDfe($db, 'empresas_emissoras', 'nfe_dfe_ultimo_nsu')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfe_dfe_ultimo_nsu BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER certificado_validade');
    }
    if (!colunaExisteNfeDfe($db, 'empresas_emissoras', 'nfe_dfe_sincronizado_em')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfe_dfe_sincronizado_em TIMESTAMP NULL AFTER nfe_dfe_ultimo_nsu');
    }
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();

    if (!schemaJaPreparada('notas_fiscais_nfe_dfe')) {
        prepararTabelaNfeDfe($dbNotas);
        prepararColunasNsuNfeEmpresasEmissoras($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfe_dfe');
    }

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_nfe_dfe'])) {
        $_SESSION['csrf_notas_nfe_dfe'] = bin2hex(random_bytes(32));
    }

    $empresasEmissorasFiltro = $dbNotas->query(
        'SELECT id, razao_social, cnpj, uf, ambiente_emissao, nfe_dfe_sincronizado_em FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    )->fetchAll();

    // Sincronização automática: mesma lógica da NFS-e - uma empresa por visita normal à página,
    // a que estiver há mais tempo sem sincronizar, respeitando um intervalo mínimo.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['xml_nfe_dfe']) && !isset($_GET['pdf_nfe_dfe']) && !isset($_GET['zip_export'])) {
        [$integracaoAutoOk] = integracaoNfeDisponivel();
        if ($integracaoAutoOk) {
            $candidatasAuto = empresasComCertificadoValidoAdn($dbNotas);
            usort($candidatasAuto, static fn (array $a, array $b): int => strtotime($a['nfe_dfe_sincronizado_em'] ?? '1970-01-01') <=> strtotime($b['nfe_dfe_sincronizado_em'] ?? '1970-01-01'));

            $empresaAuto = $candidatasAuto[0] ?? null;
            $cooldownAutoSegundos = 300;
            if ($empresaAuto !== null && (empty($empresaAuto['nfe_dfe_sincronizado_em']) || (time() - strtotime($empresaAuto['nfe_dfe_sincronizado_em'])) > $cooldownAutoSegundos)) {
                $resultadoAuto = sincronizarNfeDfe($dbNotas, $empresaAuto);
                if ($resultadoAuto['sucesso'] && $resultadoAuto['total'] > 0) {
                    $sucesso = "Sincronização automática de {$empresaAuto['razao_social']}: {$resultadoAuto['total']} documento(s) novo(s).";
                }
            }
        }
    }

    // Download do XML já baixado e guardado localmente. Só existe quando o documento veio
    // completo (procNFe) - resumo (resNFe) não traz o XML autorizado, só os campos principais.
    if (isset($_GET['xml_nfe_dfe'])) {
        $documentoId = (int) $_GET['xml_nfe_dfe'];
        $stmtXml = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfe_dfe WHERE id = :id LIMIT 1');
        $stmtXml->execute(['id' => $documentoId]);
        $documentoXml = $stmtXml->fetch();
        if (!$documentoXml || empty($documentoXml['xml_completo'])) {
            http_response_code(404);
            echo 'XML completo não disponível para este documento (só temos o resumo enviado pela SEFAZ).';
            exit;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="NFe' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoXml['chave_acesso']) . '.xml"');
        echo $documentoXml['xml_completo'];
        exit;
    }

    // DANFE: gerado localmente (nfephp-org/sped-da) a partir do XML completo já salvo aqui,
    // sem chamar a SEFAZ de novo. Só disponível quando temos o documento completo (procNFe).
    if (isset($_GET['pdf_nfe_dfe'])) {
        $documentoId = (int) $_GET['pdf_nfe_dfe'];
        $stmtPdf = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfe_dfe WHERE id = :id LIMIT 1');
        $stmtPdf->execute(['id' => $documentoId]);
        $documentoPdf = $stmtPdf->fetch();
        if (!$documentoPdf || empty($documentoPdf['xml_completo'])) {
            http_response_code(404);
            echo 'DANFE não disponível para este documento (só temos o resumo enviado pela SEFAZ).';
            exit;
        }

        try {
            $pdf = gerarDanfePdf((string) $documentoPdf['xml_completo']);
        } catch (Throwable $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'DANFE indisponível para este documento: ' . $e->getMessage() . ' Baixe o XML fiscal como alternativa.';
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="DANFE' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoPdf['chave_acesso']) . '.pdf"');
        echo $pdf;
        exit;
    }

    // Exportação em lote: ZIP com XML e/ou DANFE de um período (data exata a data exata). Só
    // entram documentos com XML completo salvo (resumo não gera nem XML fiscal nem DANFE).
    if (isset($_GET['zip_export'])) {
        $zipDataInicio = trim((string) ($_GET['zip_data_inicio'] ?? ''));
        $zipDataFim = trim((string) ($_GET['zip_data_fim'] ?? ''));
        $dataValidaNfe = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (!$dataValidaNfe($zipDataInicio) || !$dataValidaNfe($zipDataFim)) {
            http_response_code(400);
            echo 'Informe uma data inicial e final válidas para o ZIP.';
            exit;
        }
        if ($zipDataFim < $zipDataInicio) {
            [$zipDataInicio, $zipDataFim] = [$zipDataFim, $zipDataInicio];
        }
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo 'Geração de ZIP indisponível: a extensão PHP "zip" não está habilitada neste servidor.';
            exit;
        }

        $zipFormato = in_array($_GET['zip_formato'] ?? '', ['ambos', 'xml', 'pdf'], true) ? $_GET['zip_formato'] : 'ambos';
        $zipEmpresaId = (int) ($_GET['zip_empresa_emissora_id'] ?? 0);
        $zipTipo = in_array($_GET['zip_tipo'] ?? '', ['emitida', 'recebida'], true) ? $_GET['zip_tipo'] : '';

        $condicoesZip = ['e.ativo = 1', 'a.data_emissao BETWEEN :data_inicio AND :data_fim', 'a.xml_completo IS NOT NULL'];
        $bindZip = ['data_inicio' => $zipDataInicio . ' 00:00:00', 'data_fim' => $zipDataFim . ' 23:59:59'];
        if ($zipEmpresaId > 0) {
            $condicoesZip[] = 'a.empresa_emissora_id = :empresa_emissora_id';
            $bindZip['empresa_emissora_id'] = $zipEmpresaId;
        }
        if ($zipTipo !== '') {
            $condicoesZip[] = 'a.tipo_documento = :tipo_documento';
            $bindZip['tipo_documento'] = $zipTipo;
        }

        $stmtZip = $dbNotas->prepare(
            'SELECT a.chave_acesso, a.tipo_documento, a.xml_completo
             FROM notas_fiscais_nfe_dfe a
             INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id
             WHERE ' . implode(' AND ', $condicoesZip) . '
             ORDER BY a.data_emissao ASC'
        );
        foreach ($bindZip as $chaveBindZip => $valorBindZip) {
            $stmtZip->bindValue($chaveBindZip, $valorBindZip);
        }
        $stmtZip->execute();
        $documentosZip = $stmtZip->fetchAll();

        if (empty($documentosZip)) {
            http_response_code(404);
            echo 'Nenhum documento com XML completo encontrado para esse período. Sincronize a empresa antes de exportar.';
            exit;
        }

        $arquivoZipTempNfe = tempnam(sys_get_temp_dir(), 'nfe_dfe_zip_');
        $zipNfe = new ZipArchive();
        if ($zipNfe->open($arquivoZipTempNfe, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Não foi possível gerar o arquivo ZIP.';
            exit;
        }

        $totalArquivosZipNfe = 0;
        foreach ($documentosZip as $documentoZip) {
            $prefixoZip = ($documentoZip['tipo_documento'] === 'emitida' ? 'EMITIDA' : 'RECEBIDA') . '-' . preg_replace('/\D/', '', (string) $documentoZip['chave_acesso']);

            if ($zipFormato === 'ambos' || $zipFormato === 'xml') {
                $zipNfe->addFromString($prefixoZip . '.xml', (string) $documentoZip['xml_completo']);
                $totalArquivosZipNfe++;
            }

            if ($zipFormato === 'ambos' || $zipFormato === 'pdf') {
                try {
                    $pdfZip = gerarDanfePdf((string) $documentoZip['xml_completo']);
                    $zipNfe->addFromString($prefixoZip . '.pdf', $pdfZip);
                    $totalArquivosZipNfe++;
                } catch (Throwable $e) {
                    // DANFE pode falhar num documento pontual; mantem so o XML dessa nota no ZIP.
                }
            }
        }

        $zipNfe->close();

        if ($totalArquivosZipNfe === 0) {
            unlink($arquivoZipTempNfe);
            http_response_code(409);
            echo 'Nenhum XML ou DANFE pôde ser gerado para esse período.';
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="nfe-sefaz-' . $zipDataInicio . '_a_' . $zipDataFim . '.zip"');
        header('Content-Length: ' . filesize($arquivoZipTempNfe));
        readfile($arquivoZipTempNfe);
        unlink($arquivoZipTempNfe);
        exit;
    }

    $empresaSincronizarId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfe_dfe'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'sincronizar') {
            $empresaSincronizarId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaSincronizarId]);
            $empresaSincronizar = $stmtEmpresa->fetch();
            if (!$empresaSincronizar) {
                $erro = 'Selecione uma empresa emissora válida para sincronizar.';
            } else {
                $resultadoSync = sincronizarNfeDfe($dbNotas, $empresaSincronizar);
                if ($resultadoSync['sucesso']) {
                    $sucesso = $resultadoSync['mensagem'];
                } else {
                    $erro = $resultadoSync['mensagem'];
                }
            }
        }
    }

    // Filtros de busca (aplicados sobre a cópia local sincronizada da SEFAZ).
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
        $condicoes[] = '(a.nome_emitente LIKE :busca OR a.nome_destinatario LIKE :busca OR a.cnpj_emitente LIKE :busca_doc OR a.cnpj_destinatario LIKE :busca_doc OR a.numero_nfe LIKE :busca_doc OR a.chave_acesso LIKE :busca_doc)';
        $bind['busca'] = '%' . $filtroBusca . '%';
        $bind['busca_doc'] = '%' . preg_replace('/\D+/', '', $filtroBusca) . '%';
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $condicoes);

    $stmtTotal = $dbNotas->prepare(
        "SELECT COUNT(*) FROM notas_fiscais_nfe_dfe a INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id {$sqlWhere}"
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
         FROM notas_fiscais_nfe_dfe a
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
    $erro = 'Erro ao carregar o buscador de NF-e: ' . $e->getMessage();
    $documentos = [];
    $totalDocumentos = 0;
    $totalPaginas = 1;
    $paginaAtual = 1;
}

$csrf = h($_SESSION['csrf_notas_nfe_dfe'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscador de NF-e (SEFAZ) | ACCOUNT Contabilidade</title>
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
                    <a class="btn btn-outline" href="notas-fiscais-nfse-adn"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NFS-e</a>
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas fiscais</a>
                    <?php if ($podeAdministrar): ?>
                        <a class="btn btn-outline" href="processar-nfe-dfe-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (SEFAZ)</a>
                    <?php endif; ?>
                    <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                    <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                    <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
                </div>
            </div>
        </header>

        <section class="panel">
            <h1>Buscador de NF-e (SEFAZ)</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Ferramenta independente do emissor de notas: consulta direto a Distribuição de DFe da SEFAZ e mostra as NF-e ligadas ao CNPJ de cada empresa — as que ela emitiu e as que ela recebeu como destinatária.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <div class="notice warning">
            <strong>Como funciona:</strong> a SEFAZ não permite buscar por data ou nome diretamente — só por lote sequencial (NSU), com limite de requisições por CNPJ. Toda empresa com certificado digital A1 válido já é sincronizada sozinha (uma por visita a esta página, ou continuamente se houver o cron de <a href="processar-nfe-dfe-automatico">sincronização automática</a> configurado). Para NF-e que a própria empresa emitiu, a SEFAZ manda o documento completo (XML + DANFE disponíveis); para NF-e recebidas de terceiros, às vezes só chega um <strong>resumo</strong> (chave, emitente, valor, data) sem XML completo — nesse caso XML e DANFE não ficam disponíveis, só os dados na tabela.
        </div>

        <section class="panel">
            <h2><i class="fa-solid fa-rotate"></i> Sincronizar manualmente com a SEFAZ</h2>
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
        </section>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;"><i class="fa-solid fa-magnifying-glass"></i> Buscar documentos</h2>
                <form method="get" class="row-actions" style="flex-wrap:wrap;">
                    <input class="select-filtro" type="text" name="busca" value="<?php echo h($filtroBusca); ?>" placeholder="Nome, CNPJ, nº NF-e ou chave" style="min-width:220px;">
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
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-file-zipper"></i> Exportar em lote</h2>
            <p class="muted" style="margin-top:0;">Baixe em ZIP os documentos com XML completo sincronizados de uma data exata até outra (resumos sem XML completo não entram). Escolha se quer o XML e o DANFE juntos no mesmo ZIP, ou cada formato em um ZIP separado.</p>
            <form method="get" action="notas-fiscais-nfe-dfe" class="row-actions" style="flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="zip_export" value="1">
                <input class="select-filtro" type="date" name="zip_data_inicio" required aria-label="Data inicial da exportação">
                <span class="muted">até</span>
                <input class="select-filtro" type="date" name="zip_data_fim" required aria-label="Data final da exportação">
                <select class="select-filtro" name="zip_empresa_emissora_id">
                    <option value="">Todas as empresas</option>
                    <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                        <option value="<?php echo (int) $empresaOpcao['id']; ?>"><?php echo h($empresaOpcao['razao_social']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="select-filtro" name="zip_tipo">
                    <option value="">Emitidas e recebidas</option>
                    <option value="emitida">Somente emitidas</option>
                    <option value="recebida">Somente recebidas</option>
                </select>
                <button class="btn btn-small" type="submit" name="zip_formato" value="ambos"><i class="fa-solid fa-file-zipper"></i> ZIP: XML + DANFE juntos</button>
                <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="xml"><i class="fa-solid fa-file-zipper"></i> ZIP: só XML</button>
                <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="pdf"><i class="fa-solid fa-file-zipper"></i> ZIP: só DANFE</button>
            </form>
        </section>

        <section class="panel">
            <div class="table-wrap">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Data emissão</th>
                            <th>Tipo</th>
                            <th>Emitente</th>
                            <th>Destinatário</th>
                            <th>Nº / Série</th>
                            <th>Valor</th>
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
                                    <?php if (!empty($documento['cancelada']) || $documento['situacao'] !== 'autorizada'): ?>
                                        <div class="muted" style="font-size:0.7rem; margin-top:0.25rem;">
                                            <span class="status-pill status-rejeitada"><?php echo !empty($documento['cancelada']) ? 'Cancelada' : ucfirst((string) $documento['situacao']); ?></span>
                                            <?php if (!empty($documento['data_cancelamento'])): ?>
                                                <div><?php echo h(date('d/m/Y H:i', strtotime($documento['data_cancelamento']))); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($documento['tem_documento_completo'])): ?>
                                        <div class="muted" style="font-size:0.65rem; margin-top:0.25rem;">Só resumo</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo h($documento['nome_emitente'] ?? '—'); ?>
                                    <div class="muted" style="font-size:0.7rem;"><?php echo h($documento['cnpj_emitente'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php echo h($documento['nome_destinatario'] ?? '—'); ?>
                                    <div class="muted" style="font-size:0.7rem;"><?php echo h($documento['cnpj_destinatario'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php echo h((string) ($documento['numero_nfe'] ?? '—')); ?><?php echo !empty($documento['serie']) ? ' / ' . h((string) $documento['serie']) : ''; ?>
                                    <div class="muted" style="font-size:0.65rem;">Chave: <?php echo h($documento['chave_acesso']); ?></div>
                                </td>
                                <td>R$ <?php echo number_format((float) ($documento['valor_nfe'] ?? 0), 2, ',', '.'); ?></td>
                                <td>
                                    <?php if (!empty($documento['tem_documento_completo'])): ?>
                                        <div class="row-actions" style="flex-wrap:nowrap;">
                                            <a class="btn btn-outline btn-small" href="notas-fiscais-nfe-dfe?xml_nfe_dfe=<?php echo (int) $documento['id']; ?>"><i class="fa-solid fa-code"></i> XML</a>
                                            <a class="btn btn-outline btn-small" href="notas-fiscais-nfe-dfe?pdf_nfe_dfe=<?php echo (int) $documento['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> DANFE</a>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:0.75rem;">Sem XML completo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <?php $paginasExibir = paginasParaExibirNfeDfe($paginaAtual, $totalPaginas); ?>
                <div class="row-actions" style="justify-content:center; margin-top:1rem;">
                    <?php $paginaAnterior = 0; ?>
                    <?php foreach ($paginasExibir as $p): ?>
                        <?php if ($p - $paginaAnterior > 1): ?>
                            <span class="muted" style="padding:0 0.25rem;">…</span>
                        <?php endif; ?>
                        <a class="btn <?php echo $p === $paginaAtual ? '' : 'btn-outline'; ?> btn-small"
                           href="notas-fiscais-nfe-dfe?<?php echo h(http_build_query(array_filter([
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
