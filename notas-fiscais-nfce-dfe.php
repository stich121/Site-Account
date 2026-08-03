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
require_once __DIR__ . '/nfce-operacoes.php';

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

function paginasParaExibirNfceDfe(int $paginaAtual, int $totalPaginas): array
{
    $paginas = array_unique(array_filter(array_merge(
        [1, 2],
        range(max(1, $paginaAtual - 1), min($totalPaginas, $paginaAtual + 1)),
        range(max(1, $totalPaginas - 2), $totalPaginas)
    ), static fn (int $p): bool => $p >= 1 && $p <= $totalPaginas));
    sort($paginas);

    return $paginas;
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();

    // A tabela/colunas de suporte (notas_fiscais_nfce_dfe, nfe_dfe_ultimo_nsu etc.) já são
    // preparadas por sincronizarNfeDfe() (nfe-distribuicao-integracao.php), pois a mesma
    // sincronização por NSU alimenta NF-e e NFC-e ao mesmo tempo - ver comentário lá.
    if (!schemaJaPreparada('notas_fiscais_nfce_dfe')) {
        prepararTabelaNfceDfeCompartilhada($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfce_dfe');
    }

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_nfce_dfe'])) {
        $_SESSION['csrf_notas_nfce_dfe'] = bin2hex(random_bytes(32));
    }

    $empresasEmissorasFiltro = $dbNotas->query(
        'SELECT id, razao_social, cnpj, uf, ambiente_emissao, nfe_dfe_sincronizado_em, nfe_dfe_bloqueado_ate FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    )->fetchAll();

    // Sincronização automática: mesmo mecanismo do buscador de NF-e (notas-fiscais-nfe-dfe.php) -
    // uma chamada por visita normal à página, na empresa há mais tempo sem sincronizar. É a MESMA
    // sincronização (sincronizarNfeDfe) usada lá: não faz sentido duplicar a chamada à SEFAZ só
    // porque o usuário está olhando o buscador de NFC-e em vez do de NF-e.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['xml_nfce_dfe']) && !isset($_GET['danfce_nfce_dfe']) && !isset($_GET['zip_export'])) {
        [$integracaoAutoOk] = integracaoNfeDisponivel();
        if ($integracaoAutoOk) {
            $candidatasAuto = array_values(array_filter(
                empresasComCertificadoValidoAdn($dbNotas),
                static fn (array $e): bool => empty($e['nfe_dfe_bloqueado_ate']) || strtotime($e['nfe_dfe_bloqueado_ate']) <= time()
            ));
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
    if (isset($_GET['xml_nfce_dfe'])) {
        $documentoId = (int) $_GET['xml_nfce_dfe'];
        $stmtXml = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfce_dfe WHERE id = :id LIMIT 1');
        $stmtXml->execute(['id' => $documentoId]);
        $documentoXml = $stmtXml->fetch();
        if (!$documentoXml || empty($documentoXml['xml_completo'])) {
            http_response_code(404);
            echo 'XML completo não disponível para este documento (só temos o resumo enviado pela SEFAZ).';
            exit;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="NFCe' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoXml['chave_acesso']) . '.xml"');
        echo $documentoXml['xml_completo'];
        exit;
    }

    // DANFCE: gerado localmente (nfephp-org/sped-da) a partir do XML completo já salvo aqui, sem
    // chamar a SEFAZ de novo. Só disponível quando temos o documento completo (procNFe).
    if (isset($_GET['danfce_nfce_dfe'])) {
        $documentoId = (int) $_GET['danfce_nfce_dfe'];
        $stmtPdf = $dbNotas->prepare('SELECT chave_acesso, xml_completo FROM notas_fiscais_nfce_dfe WHERE id = :id LIMIT 1');
        $stmtPdf->execute(['id' => $documentoId]);
        $documentoPdf = $stmtPdf->fetch();
        if (!$documentoPdf || empty($documentoPdf['xml_completo'])) {
            http_response_code(404);
            echo 'DANFCE não disponível para este documento (só temos o resumo enviado pela SEFAZ).';
            exit;
        }

        try {
            $pdf = gerarDanfcePdf((string) $documentoPdf['xml_completo']);
        } catch (Throwable $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'DANFCE indisponível para este documento: ' . $e->getMessage() . ' Baixe o XML fiscal como alternativa.';
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="DANFCE' . preg_replace('/[^0-9A-Za-z]/', '', (string) $documentoPdf['chave_acesso']) . '.pdf"');
        echo $pdf;
        exit;
    }

    // Exportação em lote: ZIP com XML e/ou DANFCE de um período (data exata a data exata). Só
    // entram documentos com XML completo salvo (resumo não gera nem XML fiscal nem DANFCE).
    if (isset($_GET['zip_export'])) {
        $zipDataInicio = trim((string) ($_GET['zip_data_inicio'] ?? ''));
        $zipDataFim = trim((string) ($_GET['zip_data_fim'] ?? ''));
        $dataValidaNfce = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (!$dataValidaNfce($zipDataInicio) || !$dataValidaNfce($zipDataFim)) {
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
             FROM notas_fiscais_nfce_dfe a
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

        $arquivoZipTempNfce = tempnam(sys_get_temp_dir(), 'nfce_dfe_zip_');
        $zipNfce = new ZipArchive();
        if ($zipNfce->open($arquivoZipTempNfce, ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Não foi possível gerar o arquivo ZIP.';
            exit;
        }

        $totalArquivosZipNfce = 0;
        foreach ($documentosZip as $documentoZip) {
            $prefixoZip = ($documentoZip['tipo_documento'] === 'emitida' ? 'EMITIDA' : 'RECEBIDA') . '-' . preg_replace('/\D/', '', (string) $documentoZip['chave_acesso']);

            if ($zipFormato === 'ambos' || $zipFormato === 'xml') {
                $zipNfce->addFromString($prefixoZip . '.xml', (string) $documentoZip['xml_completo']);
                $totalArquivosZipNfce++;
            }

            if ($zipFormato === 'ambos' || $zipFormato === 'pdf') {
                try {
                    $pdfZip = gerarDanfcePdf((string) $documentoZip['xml_completo']);
                    $zipNfce->addFromString($prefixoZip . '.pdf', $pdfZip);
                    $totalArquivosZipNfce++;
                } catch (Throwable $e) {
                    // DANFCE pode falhar num documento pontual; mantem so o XML dessa nota no ZIP.
                }
            }
        }

        $zipNfce->close();

        if ($totalArquivosZipNfce === 0) {
            unlink($arquivoZipTempNfce);
            http_response_code(409);
            echo 'Nenhum XML ou DANFCE pôde ser gerado para esse período.';
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="nfce-sefaz-' . $zipDataInicio . '_a_' . $zipDataFim . '.zip"');
        header('Content-Length: ' . filesize($arquivoZipTempNfce));
        readfile($arquivoZipTempNfce);
        unlink($arquivoZipTempNfce);
        exit;
    }

    $empresaSincronizarId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfce_dfe'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'sincronizar') {
            $empresaSincronizarId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaSincronizarId]);
            $empresaSincronizar = $stmtEmpresa->fetch();
            if (!$empresaSincronizar) {
                $erro = 'Selecione uma empresa emissora válida para sincronizar.';
            } elseif (!empty($empresaSincronizar['nfe_dfe_bloqueado_ate']) && strtotime($empresaSincronizar['nfe_dfe_bloqueado_ate']) > time()) {
                $erro = "A SEFAZ ainda está bloqueando essa empresa por excesso de requisições (aguarde até " . date('d/m/Y H:i', strtotime($empresaSincronizar['nfe_dfe_bloqueado_ate'])) . "). Tentar de novo antes disso só reinicia o bloqueio.";
            } else {
                $resultadoSync = sincronizarNfeDfe($dbNotas, $empresaSincronizar);
                if ($resultadoSync['sucesso']) {
                    $sucesso = $resultadoSync['mensagem'];
                } else {
                    $erro = $resultadoSync['mensagem'];
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'reiniciar_nsu') {
            $empresaSincronizarId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $stmtEmpresa = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtEmpresa->execute(['id' => $empresaSincronizarId]);
            $empresaReiniciar = $stmtEmpresa->fetch();
            if (!$empresaReiniciar) {
                $erro = 'Selecione uma empresa emissora válida para reiniciar.';
            } else {
                $dbNotas->prepare('UPDATE empresas_emissoras SET nfe_dfe_ultimo_nsu = 0, nfe_dfe_bloqueado_ate = NULL WHERE id = :id')
                    ->execute(['id' => (int) $empresaReiniciar['id']]);
                $sucesso = "Sincronização de {$empresaReiniciar['razao_social']} reiniciada do zero. Clique em \"Sincronizar agora\" (ou aguarde a automática/cron) para buscar todo o histórico de novo.";
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
        $condicoes[] = '(a.nome_emitente LIKE :busca_nome OR a.nome_destinatario LIKE :busca_nome2 OR a.cnpj_emitente LIKE :busca_doc1 OR a.cnpj_destinatario LIKE :busca_doc2 OR a.numero_nfe LIKE :busca_doc3 OR a.chave_acesso LIKE :busca_doc4)';
        $buscaDoc = '%' . preg_replace('/\D+/', '', $filtroBusca) . '%';
        $bind['busca_nome'] = '%' . $filtroBusca . '%';
        $bind['busca_nome2'] = '%' . $filtroBusca . '%';
        $bind['busca_doc1'] = $buscaDoc;
        $bind['busca_doc2'] = $buscaDoc;
        $bind['busca_doc3'] = $buscaDoc;
        $bind['busca_doc4'] = $buscaDoc;
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $condicoes);

    $stmtTotal = $dbNotas->prepare(
        "SELECT COUNT(*) FROM notas_fiscais_nfce_dfe a INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id {$sqlWhere}"
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
         FROM notas_fiscais_nfce_dfe a
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
    $erro = 'Erro ao carregar o buscador de NFC-e: ' . $e->getMessage();
    $documentos = [];
    $totalDocumentos = 0;
    $totalPaginas = 1;
    $paginaAtual = 1;
}

$csrf = h($_SESSION['csrf_notas_nfce_dfe'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscador de NFC-e (SEFAZ) | ACCOUNT Contabilidade</title>
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
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade" id="logoTopo">
            </a>
            <?php include __DIR__ . '/includes/menu-completo.php'; ?>
        </header>

        <section class="panel">
            <h1>Buscador de NFC-e (SEFAZ)</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Ferramenta independente do emissor de notas: consulta direto a Distribuição de DFe da SEFAZ (o mesmo canal usado pelo Buscador de NF-e) e mostra as NFC-e ligadas ao CNPJ de cada empresa só com o certificado digital cadastrado — sem depender de a nota ter sido emitida por este sistema.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <details class="notice warning">
            <summary style="cursor:pointer;"><strong>Como funciona</strong> (clique para ver)</summary>
            <p style="margin-top:0.75rem;">A SEFAZ não permite buscar por data ou nome diretamente — só por lote sequencial (NSU), com limite de requisições por CNPJ. Essa busca usa exatamente a mesma sincronização do <a href="notas-fiscais-nfe-dfe">Buscador de NF-e</a> (mesmo NSU, mesma chamada à SEFAZ): cada documento retornado é separado por modelo (55 = NF-e, 65 = NFC-e) a partir da própria chave de acesso, então sincronizar aqui ou lá tem o mesmo efeito. Toda empresa com certificado digital A1 válido já é sincronizada sozinha (uma por visita a qualquer um dos dois buscadores, ou continuamente se houver o cron de <a href="processar-nfe-dfe-automatico">sincronização automática</a> configurado). Para NFC-e que a própria empresa emitiu, a SEFAZ normalmente já manda o documento completo (XML + DANFCE disponíveis) direto — sem precisar de Ciência da Operação.</p>
        </details>

        <section class="panel">
            <h2><i class="fa-solid fa-rotate"></i> Sincronizar manualmente</h2>
            <form method="post" class="filtro-form">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="acao" value="sincronizar">
                <div class="field field-lg">
                    <label for="syncEmpresaId">Empresa</label>
                    <select id="syncEmpresaId" name="empresa_emissora_id" required>
                        <option value="">Selecione a empresa</option>
                        <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                            <option value="<?php echo (int) $empresaOpcao['id']; ?>" <?php echo $empresaSincronizarId === (int) $empresaOpcao['id'] ? 'selected' : ''; ?>><?php echo h($empresaOpcao['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn" type="submit"><i class="fa-solid fa-cloud-arrow-down"></i> Sincronizar agora</button>
            </form>

            <details class="acoes-secundarias">
                <summary>Ferramentas de manutenção (uso ocasional)</summary>
                <div class="corpo">
                    <form method="post" class="filtro-form" onsubmit="return confirm('Reiniciar a sincronização dessa empresa do zero (NSU = 0)? Isso vai buscar todo o histórico de novo desde o início, aos poucos, respeitando o limite de requisições da SEFAZ. Como o NSU é compartilhado com o Buscador de NF-e, isso reinicia os dois.');">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="acao" value="reiniciar_nsu">
                        <div class="field field-lg">
                            <label for="reiniciarEmpresaId">Empresa</label>
                            <select id="reiniciarEmpresaId" name="empresa_emissora_id" required>
                                <option value="">Selecione a empresa para reiniciar</option>
                                <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                                    <option value="<?php echo (int) $empresaOpcao['id']; ?>"><?php echo h($empresaOpcao['razao_social']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-outline btn-small" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Reiniciar sincronização do zero</button>
                    </form>
                    <p class="muted" style="font-size:0.8rem;">O ponteiro de posição (NSU) é o mesmo usado pelo Buscador de NF-e — reiniciar aqui também refaz a busca de NF-e dessa empresa desde o início.</p>
                </div>
            </details>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-magnifying-glass"></i> Buscar documentos</h2>
            <form method="get" class="filtro-form">
                <div class="field field-lg">
                    <label for="filtroBusca">Nome, CPF/CNPJ, nº ou chave</label>
                    <input id="filtroBusca" type="text" name="busca" value="<?php echo h($filtroBusca); ?>" placeholder="Digite para buscar">
                </div>
                <div class="field field-sm">
                    <label for="filtroDataInicio">Data inicial</label>
                    <input id="filtroDataInicio" type="date" name="data_inicio" value="<?php echo h($filtroDataInicio); ?>">
                </div>
                <div class="field field-sm">
                    <label for="filtroDataFim">Data final</label>
                    <input id="filtroDataFim" type="date" name="data_fim" value="<?php echo h($filtroDataFim); ?>">
                </div>
                <div class="field">
                    <label for="filtroEmpresaId">Empresa</label>
                    <select id="filtroEmpresaId" name="empresa_emissora_id">
                        <option value="">Todas</option>
                        <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                            <option value="<?php echo (int) $empresaOpcao['id']; ?>" <?php echo $filtroEmpresaId === (int) $empresaOpcao['id'] ? 'selected' : ''; ?>><?php echo h($empresaOpcao['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="filtroTipo">Tipo</label>
                    <select id="filtroTipo" name="tipo">
                        <option value="">Emitidas e recebidas</option>
                        <option value="emitida" <?php echo $filtroTipo === 'emitida' ? 'selected' : ''; ?>>Somente emitidas</option>
                        <option value="recebida" <?php echo $filtroTipo === 'recebida' ? 'selected' : ''; ?>>Somente recebidas</option>
                    </select>
                </div>
                <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
            </form>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-file-zipper"></i> Exportar em lote</h2>
            <p class="muted secao-descricao">Baixe em ZIP os documentos com XML completo sincronizados de uma data exata até outra (resumos sem XML completo não entram). Escolha se quer o XML e o DANFCE juntos no mesmo ZIP, ou cada formato em um ZIP separado.</p>
            <form method="get" action="notas-fiscais-nfce-dfe" class="filtro-form">
                <input type="hidden" name="zip_export" value="1">
                <div class="field field-sm">
                    <label for="zipDataInicio">Data inicial</label>
                    <input id="zipDataInicio" type="date" name="zip_data_inicio" required>
                </div>
                <div class="field field-sm">
                    <label for="zipDataFim">Data final</label>
                    <input id="zipDataFim" type="date" name="zip_data_fim" required>
                </div>
                <div class="field">
                    <label for="zipEmpresaId">Empresa</label>
                    <select id="zipEmpresaId" name="zip_empresa_emissora_id">
                        <option value="">Todas</option>
                        <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                            <option value="<?php echo (int) $empresaOpcao['id']; ?>"><?php echo h($empresaOpcao['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="zipTipo">Tipo</label>
                    <select id="zipTipo" name="zip_tipo">
                        <option value="">Emitidas e recebidas</option>
                        <option value="emitida">Somente emitidas</option>
                        <option value="recebida">Somente recebidas</option>
                    </select>
                </div>
                <div class="row-actions" style="flex:none;">
                    <button class="btn btn-small" type="submit" name="zip_formato" value="ambos"><i class="fa-solid fa-file-zipper"></i> XML + DANFCE</button>
                    <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="xml"><i class="fa-solid fa-file-zipper"></i> Só XML</button>
                    <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="pdf"><i class="fa-solid fa-file-zipper"></i> Só DANFCE</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-list-check"></i> Resultados</h2>
            <p class="resultado-contagem"><?php echo (int) $totalDocumentos; ?> documento(s) encontrado(s).</p>
            <div class="table-wrap">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Data emissão</th>
                            <th>Tipo</th>
                            <th>Emitente</th>
                            <th>Consumidor</th>
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
                                        <span class="cel-sub">
                                            <span class="status-pill status-rejeitada"><?php echo !empty($documento['cancelada']) ? 'Cancelada' : ucfirst((string) $documento['situacao']); ?></span>
                                            <?php if (!empty($documento['data_cancelamento'])): ?>
                                                <?php echo h(date('d/m/Y H:i', strtotime($documento['data_cancelamento']))); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (empty($documento['tem_documento_completo'])): ?>
                                        <span class="cel-sub">Só resumo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cel-principal"><?php echo h($documento['nome_emitente'] ?? '—'); ?></span>
                                    <span class="cel-sub"><?php echo h($documento['cnpj_emitente'] ?? ''); ?></span>
                                </td>
                                <td>
                                    <span class="cel-principal"><?php echo h($documento['nome_destinatario'] ?? 'Não identificado'); ?></span>
                                    <span class="cel-sub"><?php echo h($documento['cnpj_destinatario'] ?? ''); ?></span>
                                </td>
                                <td>
                                    <span class="cel-principal"><?php echo h((string) ($documento['numero_nfe'] ?? '—')); ?><?php echo !empty($documento['serie']) ? ' / ' . h((string) $documento['serie']) : ''; ?></span>
                                    <span class="cel-sub">Chave: <?php echo h($documento['chave_acesso']); ?></span>
                                </td>
                                <td>R$ <?php echo number_format((float) ($documento['valor_nfe'] ?? 0), 2, ',', '.'); ?></td>
                                <td>
                                    <?php if (!empty($documento['tem_documento_completo'])): ?>
                                        <div class="tabela-acoes">
                                            <a class="btn btn-outline btn-small" href="notas-fiscais-nfce-dfe?xml_nfce_dfe=<?php echo (int) $documento['id']; ?>"><i class="fa-solid fa-code"></i> XML</a>
                                            <a class="btn btn-outline btn-small" href="notas-fiscais-nfce-dfe?danfce_nfce_dfe=<?php echo (int) $documento['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> DANFCE</a>
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
                <?php $paginasExibir = paginasParaExibirNfceDfe($paginaAtual, $totalPaginas); ?>
                <div class="paginacao">
                    <?php $paginaAnterior = 0; ?>
                    <?php foreach ($paginasExibir as $p): ?>
                        <?php if ($p - $paginaAnterior > 1): ?>
                            <span class="reticencias">…</span>
                        <?php endif; ?>
                        <a class="btn <?php echo $p === $paginaAtual ? '' : 'btn-outline'; ?> btn-small"
                           href="notas-fiscais-nfce-dfe?<?php echo h(http_build_query(array_filter([
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
