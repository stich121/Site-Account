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

function prepararColunaBloqueioEmpresasEmissoras(PDO $db): void
{
    if (!colunaExisteNotasAdn($db, 'empresas_emissoras', 'nfse_adn_bloqueado_ate')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfse_adn_bloqueado_ate DATETIME NULL AFTER nfse_adn_sincronizado_em');
    }
}

function prepararColunasCancelamentoNfseAdn(PDO $db): void
{
    if (!colunaExisteNotasAdn($db, 'notas_fiscais_nfse_adn', 'cancelada')) {
        $db->exec('ALTER TABLE notas_fiscais_nfse_adn ADD COLUMN cancelada TINYINT(1) NOT NULL DEFAULT 0 AFTER codigo_status');
    }
    if (!colunaExisteNotasAdn($db, 'notas_fiscais_nfse_adn', 'data_cancelamento')) {
        $db->exec('ALTER TABLE notas_fiscais_nfse_adn ADD COLUMN data_cancelamento DATETIME NULL AFTER cancelada');
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
    // Flag própria: a tabela notas_fiscais_nfse_adn já podia estar marcada como "preparada" em
    // servidores onde o buscador rodou antes dessas colunas existirem, então essa migração
    // precisa de um gate independente do de cima para não ficar pra sempre sem rodar.
    if (!schemaJaPreparada('notas_fiscais_nfse_adn_cancelamento')) {
        prepararColunasCancelamentoNfseAdn($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfse_adn_cancelamento');
    }
    if (!schemaJaPreparada('notas_fiscais_nfse_adn_bloqueio')) {
        prepararColunaBloqueioEmpresasEmissoras($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfse_adn_bloqueio');
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
        'SELECT id, razao_social, cnpj, ambiente_emissao, nfse_adn_sincronizado_em, nfse_adn_bloqueado_ate FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    )->fetchAll();

    // Sincronização automática: em toda visita normal à página (não em download nem em POST de uma
    // ação manual), tenta colocar em dia UMA empresa com certificado digital válido - a que está há
    // mais tempo sem sincronizar - sem precisar clicar em nada. Só uma por vez (e com intervalo
    // mínimo) pra não deixar a página lenta nem estourar o rate limit do ADN; ao longo de algumas
    // visitas (ou via cron, ver processar-nfse-adn-automatico.php) todas ficam em dia sozinhas.
    // Empresas com bloqueio de rate-limit em vigor ficam de fora até o prazo passar.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['xml_adn']) && !isset($_GET['pdf_adn']) && !isset($_GET['zip_export'])) {
        [$integracaoAutoOk] = integracaoNfseDisponivel();
        if ($integracaoAutoOk) {
            $candidatasAuto = array_values(array_filter(
                empresasComCertificadoValidoAdn($dbNotas),
                static fn (array $e): bool => empty($e['nfse_adn_bloqueado_ate']) || strtotime($e['nfse_adn_bloqueado_ate']) <= time()
            ));
            usort($candidatasAuto, static fn (array $a, array $b): int => strtotime($a['nfse_adn_sincronizado_em'] ?? '1970-01-01') <=> strtotime($b['nfse_adn_sincronizado_em'] ?? '1970-01-01'));

            $empresaAuto = $candidatasAuto[0] ?? null;
            $cooldownAutoSegundos = 300;
            if ($empresaAuto !== null && (empty($empresaAuto['nfse_adn_sincronizado_em']) || (time() - strtotime($empresaAuto['nfse_adn_sincronizado_em'])) > $cooldownAutoSegundos)) {
                $resultadoAuto = sincronizarNfseAdn($dbNotas, $empresaAuto);
                if ($resultadoAuto['sucesso'] && $resultadoAuto['total'] > 0) {
                    $sucesso = "Sincronização automática de {$empresaAuto['razao_social']}: {$resultadoAuto['total']} documento(s) novo(s).";
                }
            }
        }
    }

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

    // DANFSe: renderizado localmente no leiaute oficial NT 008/2026 (mesmo gerador já usado
    // em notas-fiscais.php para as notas emitidas pelo sistema), a partir do XML já salvo aqui
    // — sem chamar o Portal Nacional de novo (o endpoint de PDF do ADN foi descontinuado).
    if (isset($_GET['pdf_adn'])) {
        $documentoId = (int) $_GET['pdf_adn'];
        $stmtPdf = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfse_adn WHERE id = :id LIMIT 1');
        $stmtPdf->execute(['id' => $documentoId]);
        $documentoPdf = $stmtPdf->fetch();
        if (!$documentoPdf || empty($documentoPdf['xml_completo'])) {
            http_response_code(404);
            echo 'Documento não encontrado.';
            exit;
        }

        try {
            if (!class_exists(\PhpNfseNacional\Services\DanfseService::class)) {
                throw new RuntimeException('Gerador local de DANFSe não instalado no vendor/.');
            }
            $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml((string) $documentoPdf['xml_completo']);
            if (!str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('Não foi possível gerar o DANFSe a partir do XML salvo.');
            }
        } catch (Throwable $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'DANFSe indisponível para este documento: ' . $e->getMessage() . ' Baixe o XML fiscal como alternativa.';
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="DANFSe' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoPdf['chave_acesso']) . '.pdf"');
        echo $pdf;
        exit;
    }

    // Exportação em lote: ZIP com XML e/ou PDF de todo um período (data exata a data exata),
    // sobre a cópia local já sincronizada. O usuário escolhe o formato pelo botão clicado:
    // "ambos" (XML e PDF juntos no mesmo ZIP), "xml" ou "pdf" (cada um em ZIP separado).
    if (isset($_GET['zip_export'])) {
        $zipDataInicio = trim((string) ($_GET['zip_data_inicio'] ?? ''));
        $zipDataFim = trim((string) ($_GET['zip_data_fim'] ?? ''));
        $dataValidaAdn = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (!$dataValidaAdn($zipDataInicio) || !$dataValidaAdn($zipDataFim)) {
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

        $condicoesZip = ['e.ativo = 1', 'a.data_emissao BETWEEN :data_inicio AND :data_fim'];
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
             FROM notas_fiscais_nfse_adn a
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
            echo 'Nenhum documento sincronizado encontrado para esse período. Sincronize a empresa antes de exportar.';
            exit;
        }

        $danfseDisponivelZip = class_exists(\PhpNfseNacional\Services\DanfseService::class);
        $arquivoZipTempAdn = tempnam(sys_get_temp_dir(), 'nfse_adn_zip_');
        $zipAdn = new ZipArchive();
        if ($zipAdn->open($arquivoZipTempAdn, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Não foi possível gerar o arquivo ZIP.';
            exit;
        }

        $totalArquivosZipAdn = 0;
        foreach ($documentosZip as $documentoZip) {
            if (empty($documentoZip['xml_completo']) || empty($documentoZip['chave_acesso'])) {
                continue;
            }
            $prefixoZip = ($documentoZip['tipo_documento'] === 'emitida' ? 'EMITIDA' : 'RECEBIDA') . '-' . preg_replace('/\D/', '', (string) $documentoZip['chave_acesso']);

            if ($zipFormato === 'ambos' || $zipFormato === 'xml') {
                $zipAdn->addFromString($prefixoZip . '.xml', (string) $documentoZip['xml_completo']);
                $totalArquivosZipAdn++;
            }

            if (($zipFormato === 'ambos' || $zipFormato === 'pdf') && $danfseDisponivelZip) {
                try {
                    $pdfZip = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml((string) $documentoZip['xml_completo']);
                    if (str_starts_with($pdfZip, '%PDF-')) {
                        $zipAdn->addFromString($prefixoZip . '.pdf', $pdfZip);
                        $totalArquivosZipAdn++;
                    }
                } catch (Throwable $e) {
                    // DANFSe pode falhar num documento pontual (XML incompleto); mantem so o XML dessa nota.
                }
            }
        }

        $zipAdn->close();

        if ($totalArquivosZipAdn === 0) {
            unlink($arquivoZipTempAdn);
            http_response_code(409);
            echo 'Nenhum XML ou PDF pôde ser gerado para esse período.';
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="nfse-portal-nacional-' . $zipDataInicio . '_a_' . $zipDataFim . '.zip"');
        header('Content-Length: ' . filesize($arquivoZipTempAdn));
        readfile($arquivoZipTempAdn);
        unlink($arquivoZipTempAdn);
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
        } elseif (($_POST['acao'] ?? '') === 'reparar_eventos') {
            $empresaSincronizarId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaSincronizarId]);
            $empresaReparar = $stmtEmpresa->fetch();
            if (!$empresaReparar) {
                $erro = 'Selecione uma empresa emissora válida para reparar.';
            } else {
                $resultadoReparo = repararNotasCorrompidasPorEventoAdn($dbNotas, $empresaReparar);
                if ($resultadoReparo['sucesso']) {
                    $sucesso = $resultadoReparo['mensagem'];
                } else {
                    $erro = $resultadoReparo['mensagem'];
                }
            }
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
                    <a class="btn btn-outline" href="notas-fiscais-nfe-dfe"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NF-e</a>
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas fiscais</a>
                    <?php if ($podeAdministrar): ?>
                        <a class="btn btn-outline" href="processar-nfse-adn-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (ADN)</a>
                    <?php endif; ?>
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
            <strong>Como funciona:</strong> o Portal Nacional não permite buscar por data ou nome diretamente — só por lote sequencial (NSU), com limite de requisições por CNPJ. Toda empresa com certificado digital A1 válido já é sincronizada sozinha (uma por visita a esta página, ou continuamente se houver o cron de <a href="processar-nfse-adn-automatico">sincronização automática</a> configurado) — não é preciso clicar em nada. Use o botão "Sincronizar agora" abaixo só se quiser forçar uma empresa específica na hora. O botão <strong>XML</strong> baixa o arquivo fiscal original; o <strong>PDF</strong> é o DANFSe gerado localmente no leiaute oficial (NT 008/2026), a partir do XML já sincronizado — sem depender do endpoint do ADN, que o governo descontinuou em 01/07/2026.
        </div>

        <section class="panel">
            <h2><i class="fa-solid fa-rotate"></i> Sincronizar manualmente com o Portal Nacional</h2>
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

            <details style="margin-top:1rem;">
                <summary class="muted" style="cursor:pointer;">Ferramentas de manutenção (uso ocasional)</summary>
                <div class="row-actions" style="margin-top:0.75rem; flex-wrap:wrap;">
                    <form method="post" onsubmit="return confirm('Reprocessar todos os documentos já baixados a partir do XML salvo? Não consulta o Portal Nacional, só recalcula os dados exibidos.');">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="acao" value="reprocessar_local">
                        <button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Reprocessar documentos já baixados</button>
                    </form>
                    <form method="post" class="row-actions" style="flex-wrap:wrap; align-items:center;">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="acao" value="reparar_eventos">
                        <select class="select-filtro" name="empresa_emissora_id" required>
                            <option value="">Selecione a empresa para reparar</option>
                            <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                                <option value="<?php echo (int) $empresaOpcao['id']; ?>"><?php echo h($empresaOpcao['razao_social']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-wrench"></i> Corrigir documentos sem dados (afetados por eventos)</button>
                    </form>
                </div>
                <p class="muted" style="font-size:0.8rem; margin-top:0.5rem;">Versões antigas dessa tela podiam gravar o evento de cancelamento por cima dos dados da nota original, deixando a linha sem prestador/tomador/valor. O botão "Corrigir" acima refaz a consulta por chave e restaura os dados certos, marcando também a nota como cancelada.</p>
            </details>
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
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-file-zipper"></i> Exportar em lote</h2>
            <p class="muted" style="margin-top:0;">Baixe em ZIP todos os documentos sincronizados de uma data exata até outra. Escolha se quer o XML e o PDF juntos no mesmo ZIP, ou cada formato em um ZIP separado.</p>
            <form method="get" action="notas-fiscais-nfse-adn" class="row-actions" style="flex-wrap:wrap; align-items:center;">
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
                <button class="btn btn-small" type="submit" name="zip_formato" value="ambos"><i class="fa-solid fa-file-zipper"></i> ZIP: XML + PDF juntos</button>
                <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="xml"><i class="fa-solid fa-file-zipper"></i> ZIP: só XML</button>
                <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="pdf"><i class="fa-solid fa-file-zipper"></i> ZIP: só PDF</button>
            </form>
        </section>

        <section class="panel">
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
                                    <?php if (!empty($documento['cancelada'])): ?>
                                        <div class="muted" style="font-size:0.7rem; margin-top:0.25rem;">
                                            <span class="status-pill status-rejeitada">Cancelada</span>
                                            <?php if (!empty($documento['data_cancelamento'])): ?>
                                                <div><?php echo h(date('d/m/Y H:i', strtotime($documento['data_cancelamento']))); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
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
