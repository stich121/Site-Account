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
require_once __DIR__ . '/includes/xlsx-writer.php';
require_once __DIR__ . '/includes/impostos-xml.php';

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
            manifestada TINYINT(1) NOT NULL DEFAULT 0,
            data_manifestacao DATETIME NULL,
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

function prepararColunaBloqueioNfeEmpresasEmissoras(PDO $db): void
{
    if (!colunaExisteNfeDfe($db, 'empresas_emissoras', 'nfe_dfe_bloqueado_ate')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN nfe_dfe_bloqueado_ate DATETIME NULL AFTER nfe_dfe_sincronizado_em');
    }
}

function prepararColunasManifestacaoNfeDfe(PDO $db): void
{
    if (!colunaExisteNfeDfe($db, 'notas_fiscais_nfe_dfe', 'manifestada')) {
        $db->exec('ALTER TABLE notas_fiscais_nfe_dfe ADD COLUMN manifestada TINYINT(1) NOT NULL DEFAULT 0 AFTER tem_documento_completo');
    }
    if (!colunaExisteNfeDfe($db, 'notas_fiscais_nfe_dfe', 'data_manifestacao')) {
        $db->exec('ALTER TABLE notas_fiscais_nfe_dfe ADD COLUMN data_manifestacao DATETIME NULL AFTER manifestada');
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
    // Flag própria: essa tabela pode já ter sido criada por uma visita anterior à página, antes
    // dessas colunas de manifestação existirem - mesmo cuidado tomado para o buscador de NFS-e.
    if (!schemaJaPreparada('notas_fiscais_nfe_dfe_manifestacao')) {
        prepararColunasManifestacaoNfeDfe($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfe_dfe_manifestacao');
    }
    if (!schemaJaPreparada('notas_fiscais_nfe_dfe_bloqueio')) {
        prepararColunaBloqueioNfeEmpresasEmissoras($dbNotas);
        marcarSchemaPreparada('notas_fiscais_nfe_dfe_bloqueio');
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
        'SELECT id, razao_social, cnpj, uf, ambiente_emissao, nfe_dfe_sincronizado_em, nfe_dfe_bloqueado_ate FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    )->fetchAll();

    // Sincronização automática: mesma lógica da NFS-e - uma empresa por visita normal à página,
    // a que estiver há mais tempo sem sincronizar, respeitando um intervalo mínimo. Empresas que
    // levaram um bloqueio de rate-limit da SEFAZ (ex.: "tente após 1 hora") ficam de fora até o
    // prazo do bloqueio passar - nada de insistir na mesma trava a cada poucos minutos.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['xml_nfe_dfe']) && !isset($_GET['pdf_nfe_dfe']) && !isset($_GET['zip_export']) && !isset($_GET['excel_export'])) {
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
                if ($resultadoAuto['sucesso'] && ($resultadoAuto['total_nfe'] ?? 0) > 0) {
                    // Mostra só a contagem de NF-e: a mesma chamada pode ter trazido NFC-e junto
                    // (ver comentário em sincronizarNfeDfe()), mas isso já aparece no Buscador de NFC-e.
                    $sucesso = "Sincronização automática de {$empresaAuto['razao_social']}: {$resultadoAuto['total_nfe']} NF-e nova(s).";
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

    // Relatorio Excel com os impostos de cada NF-e sincronizada do periodo (leitura melhor-esforco
    // do XML completo, ja que estas copias sincronizadas nao guardam colunas proprias de imposto).
    if (isset($_GET['excel_export'])) {
        $excelDataInicio = trim((string) ($_GET['excel_data_inicio'] ?? ''));
        $excelDataFim = trim((string) ($_GET['excel_data_fim'] ?? ''));
        $dataValidaExcelNfe = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (!$dataValidaExcelNfe($excelDataInicio) || !$dataValidaExcelNfe($excelDataFim)) {
            http_response_code(400);
            echo 'Informe uma data inicial e final válidas para o relatório Excel.';
            exit;
        }
        if ($excelDataFim < $excelDataInicio) {
            [$excelDataInicio, $excelDataFim] = [$excelDataFim, $excelDataInicio];
        }

        $excelEmpresaId = (int) ($_GET['excel_empresa_emissora_id'] ?? 0);
        $excelTipo = in_array($_GET['excel_tipo'] ?? '', ['emitida', 'recebida'], true) ? $_GET['excel_tipo'] : '';

        $condicoesExcel = ['e.ativo = 1', 'a.data_emissao BETWEEN :data_inicio AND :data_fim'];
        $bindExcel = ['data_inicio' => $excelDataInicio . ' 00:00:00', 'data_fim' => $excelDataFim . ' 23:59:59'];
        if ($excelEmpresaId > 0) {
            $condicoesExcel[] = 'a.empresa_emissora_id = :empresa_emissora_id';
            $bindExcel['empresa_emissora_id'] = $excelEmpresaId;
        }
        if ($excelTipo !== '') {
            $condicoesExcel[] = 'a.tipo_documento = :tipo_documento';
            $bindExcel['tipo_documento'] = $excelTipo;
        }

        $stmtExcelNfe = $dbNotas->prepare(
            'SELECT a.data_emissao, a.tipo_documento, a.numero_nfe, a.serie, a.nome_emitente, a.nome_destinatario,
                    a.valor_nfe, a.xml_completo
             FROM notas_fiscais_nfe_dfe a
             INNER JOIN empresas_emissoras e ON e.id = a.empresa_emissora_id
             WHERE ' . implode(' AND ', $condicoesExcel) . '
             ORDER BY a.data_emissao ASC'
        );
        foreach ($bindExcel as $chaveBindExcel => $valorBindExcel) {
            $stmtExcelNfe->bindValue($chaveBindExcel, $valorBindExcel);
        }
        $stmtExcelNfe->execute();
        $documentosExcelNfe = $stmtExcelNfe->fetchAll();

        $cabecalhosExcelNfe = [
            'Data emissão', 'Tipo', 'Nº NF-e', 'Série', 'Emitente', 'Destinatário',
            'Valor da NF-e (R$)', 'Base ICMS (R$)', 'ICMS (R$)', 'ICMS-ST (R$)', 'IPI (R$)',
            'PIS (R$)', 'COFINS (R$)', 'Frete (R$)', 'Desconto (R$)', 'Outras despesas (R$)',
            'Tributos aprox. (Lei da Transparência) (R$)',
            'Base IBS/CBS (R$)', 'IBS (R$)', 'CBS (R$)',
        ];
        $linhasExcelNfe = [];
        foreach ($documentosExcelNfe as $documentoExcelNfe) {
            $impostos = extrairImpostosNfeXml($documentoExcelNfe['xml_completo'] ?? null);
            $linhasExcelNfe[] = [
                $documentoExcelNfe['data_emissao'] !== null ? date('d/m/Y H:i', strtotime((string) $documentoExcelNfe['data_emissao'])) : '',
                $documentoExcelNfe['tipo_documento'] === 'emitida' ? 'Emitida' : 'Recebida',
                (string) ($documentoExcelNfe['numero_nfe'] ?? ''),
                (string) ($documentoExcelNfe['serie'] ?? ''),
                (string) ($documentoExcelNfe['nome_emitente'] ?? ''),
                (string) ($documentoExcelNfe['nome_destinatario'] ?? ''),
                $documentoExcelNfe['valor_nfe'] !== null ? (float) $documentoExcelNfe['valor_nfe'] : null,
                $impostos['vBC'],
                $impostos['vICMS'],
                $impostos['vICMSST'],
                $impostos['vIPI'],
                $impostos['vPIS'],
                $impostos['vCOFINS'],
                $impostos['vFrete'],
                $impostos['vDesc'],
                $impostos['vOutro'],
                $impostos['vTotTrib'],
                $impostos['vBCIBSCBS'],
                $impostos['vIBS'],
                $impostos['vCBS'],
            ];
        }

        gerarXlsxDownload(
            'impostos-nfe-sefaz-' . $excelDataInicio . '_a_' . $excelDataFim . '.xlsx',
            'Impostos NFe',
            $cabecalhosExcelNfe,
            $linhasExcelNfe
        );
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
            } elseif (!empty($empresaSincronizar['nfe_dfe_bloqueado_ate']) && strtotime($empresaSincronizar['nfe_dfe_bloqueado_ate']) > time()) {
                $erro = "A SEFAZ ainda está bloqueando essa empresa por excesso de requisições (aguarde até " . date('d/m/Y H:i', strtotime($empresaSincronizar['nfe_dfe_bloqueado_ate'])) . "). Tentar de novo antes disso só reinicia o bloqueio.";
            } elseif (sincronizacaoDfeMuitoRecente($empresaSincronizar)) {
                $erro = 'Essa empresa acabou de ser sincronizada há menos de 1 minuto (automaticamente, ao abrir esta página ou o Buscador de NFC-e, que compartilha a mesma consulta). Aguarde um pouco e tente de novo — chamar a SEFAZ de novo rápido demais é o que causa o bloqueio de "Consumo Indevido", mesmo numa empresa nunca sincronizada antes.';
            } else {
                $resultadoSync = sincronizarNfeDfe($dbNotas, $empresaSincronizar);
                if ($resultadoSync['sucesso']) {
                    // A conclusão "de verdade" (com contagem) só existe quando o retorno tem
                    // 'mais_documentos_pendentes' - os demais casos (bloqueio, sincronização já em
                    // andamento, fila vazia, erro da SEFAZ) já vêm com a mensagem certa pra mostrar
                    // direto, sem misturar contagem de NF-e com NFC-e.
                    if (array_key_exists('mais_documentos_pendentes', $resultadoSync)) {
                        $sucesso = "Sincronização concluída: {$resultadoSync['total_nfe']} NF-e nova(s)/atualizada(s).";
                        if (!empty($resultadoSync['mais_documentos_pendentes'])) {
                            $sucesso .= ' Se ainda houver documentos mais antigos pendentes, clique em "Sincronizar agora" de novo para continuar.';
                        }
                        if (($resultadoSync['total_nfce'] ?? 0) > 0) {
                            $sucesso .= " (A mesma consulta também trouxe {$resultadoSync['total_nfce']} NFC-e, já refletida no Buscador de NFC-e.)";
                        }
                    } else {
                        $sucesso = $resultadoSync['mensagem'];
                    }
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
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade" id="logoTopo">
            </a>
            <?php include __DIR__ . '/includes/menu-completo.php'; ?>
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

        <details class="notice warning">
            <summary style="cursor:pointer;"><strong>Como funciona</strong> (clique para ver)</summary>
            <p style="margin-top:0.75rem;">A SEFAZ não permite buscar por data ou nome diretamente — só por lote sequencial (NSU), com limite de requisições por CNPJ. Toda empresa com certificado digital A1 válido já é sincronizada sozinha (uma por visita a esta página, ou continuamente se houver o cron de <a href="processar-nfe-dfe-automatico">sincronização automática</a> configurado). Para NF-e que a própria empresa emitiu, a SEFAZ manda o documento completo (XML + DANFE disponíveis) direto. Para NF-e recebidas de terceiros, a SEFAZ manda primeiro só um <strong>resumo</strong> (chave, emitente, valor, data) — a sincronização já envia sozinha a <strong>Ciência da Operação</strong> (evento oficial que declara que a empresa está ciente da nota) pra cada resumo pendente, e a partir daí o XML completo passa a vir numa sincronização seguinte, liberando XML e DANFE. Até isso acontecer, a nota aparece com "Só resumo" na tabela. Essa mesma sincronização também alimenta o <a href="notas-fiscais-nfce-dfe">Buscador de NFC-e</a> — a SEFAZ devolve os dois tipos de documento juntos, e o sistema separa cada um pela chave de acesso.</p>
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
                    <form method="post" class="filtro-form" onsubmit="return confirm('Reiniciar a sincronização dessa empresa do zero (NSU = 0)? Isso vai buscar todo o histórico de novo desde o início, aos poucos, respeitando o limite de requisições da SEFAZ.');">
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
                    <p class="muted" style="font-size:0.8rem;">Um bug já corrigido fazia notas emitidas (que vêm como documento completo, não resumo) serem descartadas silenciosamente em algumas sincronizações antigas - e como o ponteiro de posição (NSU) já tinha avançado, elas ficaram pra trás e não vão aparecer sozinhas. Use "Reiniciar sincronização do zero" na empresa afetada para buscar tudo de novo desde o início.</p>
                </div>
            </details>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-magnifying-glass"></i> Buscar documentos</h2>
            <form method="get" class="filtro-form">
                <div class="field field-lg">
                    <label for="filtroBusca">Nome, CNPJ, nº ou chave</label>
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
            <p class="muted secao-descricao">Baixe em ZIP os documentos com XML completo sincronizados de uma data exata até outra (resumos sem XML completo não entram). Escolha se quer o XML e o DANFE juntos no mesmo ZIP, ou cada formato em um ZIP separado.</p>
            <form method="get" action="notas-fiscais-nfe-dfe" class="filtro-form">
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
                    <button class="btn btn-small" type="submit" name="zip_formato" value="ambos"><i class="fa-solid fa-file-zipper"></i> XML + DANFE</button>
                    <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="xml"><i class="fa-solid fa-file-zipper"></i> Só XML</button>
                    <button class="btn btn-outline btn-small" type="submit" name="zip_formato" value="pdf"><i class="fa-solid fa-file-zipper"></i> Só DANFE</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2><i class="fa-solid fa-file-excel"></i> Relatório de impostos (Excel)</h2>
            <p class="muted secao-descricao">Gera uma planilha com os impostos (ICMS, ICMS-ST, IPI, PIS, COFINS) de cada NF-e sincronizada de uma data exata até outra, lidos do XML completo do documento.</p>
            <form method="get" action="notas-fiscais-nfe-dfe" class="filtro-form">
                <input type="hidden" name="excel_export" value="1">
                <div class="field field-sm">
                    <label for="excelDataInicio">Data inicial</label>
                    <input id="excelDataInicio" type="date" name="excel_data_inicio" required>
                </div>
                <div class="field field-sm">
                    <label for="excelDataFim">Data final</label>
                    <input id="excelDataFim" type="date" name="excel_data_fim" required>
                </div>
                <div class="field">
                    <label for="excelEmpresaId">Empresa</label>
                    <select id="excelEmpresaId" name="excel_empresa_emissora_id">
                        <option value="">Todas</option>
                        <?php foreach ($empresasEmissorasFiltro as $empresaOpcao): ?>
                            <option value="<?php echo (int) $empresaOpcao['id']; ?>"><?php echo h($empresaOpcao['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="excelTipo">Tipo</label>
                    <select id="excelTipo" name="excel_tipo">
                        <option value="">Emitidas e recebidas</option>
                        <option value="emitida">Somente emitidas</option>
                        <option value="recebida">Somente recebidas</option>
                    </select>
                </div>
                <div class="row-actions" style="flex:none;">
                    <button class="btn btn-small" type="submit"><i class="fa-solid fa-file-excel"></i> Baixar Excel</button>
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
                                        <span class="cel-sub">
                                            <span class="status-pill status-rejeitada"><?php echo !empty($documento['cancelada']) ? 'Cancelada' : ucfirst((string) $documento['situacao']); ?></span>
                                            <?php if (!empty($documento['data_cancelamento'])): ?>
                                                <?php echo h(date('d/m/Y H:i', strtotime($documento['data_cancelamento']))); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (empty($documento['tem_documento_completo'])): ?>
                                        <span class="cel-sub"><?php echo !empty($documento['manifestada']) ? 'Só resumo (ciência enviada, aguardando XML)' : 'Só resumo'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cel-principal"><?php echo h($documento['nome_emitente'] ?? '—'); ?></span>
                                    <span class="cel-sub"><?php echo h($documento['cnpj_emitente'] ?? ''); ?></span>
                                </td>
                                <td>
                                    <span class="cel-principal"><?php echo h($documento['nome_destinatario'] ?? '—'); ?></span>
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
                <div class="paginacao">
                    <?php $paginaAnterior = 0; ?>
                    <?php foreach ($paginasExibir as $p): ?>
                        <?php if ($p - $paginaAnterior > 1): ?>
                            <span class="reticencias">…</span>
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
