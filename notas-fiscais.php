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

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
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

function escaparPdfTexto(string $texto): string
{
    $semAcento = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) : $texto;
    $semAcento = $semAcento !== false ? $semAcento : $texto;

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $semAcento);
}

function gerarPdfSimples(array $linhas): string
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
            $comandos[] = '(' . escaparPdfTexto((string) $linha) . ') Tj T*';
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

function prepararTabelaEmpresasEmissorasNotas(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS empresas_emissoras (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            razao_social VARCHAR(180) NOT NULL,
            nome_fantasia VARCHAR(180) NULL,
            cnpj VARCHAR(20) NULL,
            inscricao_estadual VARCHAR(30) NULL,
            inscricao_municipal VARCHAR(30) NULL,
            logradouro VARCHAR(180) NULL,
            numero VARCHAR(20) NULL,
            complemento VARCHAR(120) NULL,
            bairro VARCHAR(120) NULL,
            cep VARCHAR(12) NULL,
            municipio VARCHAR(120) NULL,
            codigo_ibge_municipio VARCHAR(10) NULL,
            uf CHAR(2) NULL,
            crt TINYINT UNSIGNED NULL,
            ambiente_emissao ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasClientes(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_clientes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo_pessoa ENUM('PJ','PF') NOT NULL,
            nome_razao_social VARCHAR(180) NOT NULL,
            cnpj_cpf VARCHAR(20) NULL,
            inscricao_estadual VARCHAR(30) NULL,
            email VARCHAR(180) NULL,
            logradouro VARCHAR(180) NULL,
            numero VARCHAR(20) NULL,
            complemento VARCHAR(120) NULL,
            bairro VARCHAR(120) NULL,
            cep VARCHAR(12) NULL,
            municipio VARCHAR(120) NULL,
            uf CHAR(2) NULL,
            indicador_consumidor_final TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT UNSIGNED NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_clientes_nome (nome_razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasFiscais(PDO $db): void
{
    // funcionario_id não tem FOREIGN KEY: a tabela funcionarios vive no banco principal
    // (config_db.php), diferente do banco de notas fiscais (config_db_notas.php).
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            cliente_id INT UNSIGNED NOT NULL,
            funcionario_id INT UNSIGNED NOT NULL,
            tipo_nota ENUM('nfe','nfse') NOT NULL,
            natureza_operacao VARCHAR(120) NOT NULL,
            numero_interno INT UNSIGNED NOT NULL,
            status ENUM('rascunho','pendente_envio','autorizada','rejeitada','cancelada') NOT NULL DEFAULT 'rascunho',
            ambiente ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
            forma_pagamento VARCHAR(80) NULL,
            data_emissao DATE NOT NULL,
            data_saida_entrada DATE NULL,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            informacoes_frete TEXT NULL,
            protocolo_autorizacao VARCHAR(80) NULL,
            xml_gerado LONGTEXT NULL,
            motivo_rejeicao TEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_empresa (empresa_emissora_id, tipo_nota),
            KEY idx_notas_fiscais_funcionario (funcionario_id),
            KEY idx_notas_fiscais_status (status),
            CONSTRAINT fk_notas_fiscais_empresa
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id),
            CONSTRAINT fk_notas_fiscais_cliente
                FOREIGN KEY (cliente_id) REFERENCES notas_clientes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasFiscaisItens(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_itens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nota_id BIGINT UNSIGNED NOT NULL,
            produto_servico_id INT UNSIGNED NULL,
            descricao VARCHAR(255) NOT NULL,
            ncm VARCHAR(10) NULL,
            cfop VARCHAR(6) NULL,
            cst_csosn VARCHAR(6) NULL,
            unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
            quantidade DECIMAL(12,3) NOT NULL,
            valor_unitario DECIMAL(12,2) NOT NULL,
            valor_total DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_itens_nota (nota_id),
            CONSTRAINT fk_notas_fiscais_itens_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaNotasFiscaisLog(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nota_id BIGINT UNSIGNED NOT NULL,
            funcionario_id INT UNSIGNED NOT NULL,
            acao VARCHAR(60) NOT NULL,
            detalhe VARCHAR(255) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_fiscais_log_nota (nota_id, criado_em),
            CONSTRAINT fk_notas_fiscais_log_nota
                FOREIGN KEY (nota_id) REFERENCES notas_fiscais(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function registrarLogNota(PDO $db, int $notaId, int $funcionarioId, string $acao, string $detalhe = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO notas_fiscais_log (nota_id, funcionario_id, acao, detalhe) VALUES (:nota_id, :funcionario_id, :acao, :detalhe)'
    );
    $stmt->execute([
        'nota_id' => $notaId,
        'funcionario_id' => $funcionarioId,
        'acao' => $acao,
        'detalhe' => $detalhe !== '' ? $detalhe : null,
    ]);
}

function rotuloStatusNota(string $status): string
{
    return [
        'rascunho' => 'Rascunho',
        'pendente_envio' => 'Pendente de envio',
        'autorizada' => 'Autorizada',
        'rejeitada' => 'Rejeitada',
        'cancelada' => 'Cancelada',
    ][$status] ?? $status;
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararTabelaEmpresasEmissorasNotas($dbNotas);
    prepararTabelaNotasClientes($dbNotas);
    prepararTabelaNotasFiscais($dbNotas);
    prepararTabelaNotasFiscaisItens($dbNotas);
    prepararTabelaNotasFiscaisLog($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    // Exportação de PDF de conferência (rascunho) via GET, antes de qualquer output HTML.
    if (isset($_GET['pdf'])) {
        $notaId = (int) $_GET['pdf'];
        $stmt = $dbNotas->prepare(
            'SELECT n.*, e.razao_social AS empresa_razao_social, e.cnpj AS empresa_cnpj,
                    e.inscricao_estadual AS empresa_ie, e.municipio AS empresa_municipio, e.uf AS empresa_uf,
                    c.nome_razao_social AS cliente_nome, c.cnpj_cpf AS cliente_documento,
                    c.municipio AS cliente_municipio, c.uf AS cliente_uf
             FROM notas_fiscais n
             INNER JOIN empresas_emissoras e ON e.id = n.empresa_emissora_id
             INNER JOIN notas_clientes c ON c.id = n.cliente_id
             WHERE n.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch();

        if (!$nota || (!$podeAdministrar && (int) $nota['funcionario_id'] !== $funcionarioId)) {
            http_response_code(404);
            echo 'Nota não encontrada.';
            exit;
        }

        $stmt = $db->prepare('SELECT usuario FROM funcionarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $nota['funcionario_id']]);
        $nota['criado_por_usuario'] = (string) ($stmt->fetchColumn() ?: 'Funcionário');

        $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_itens WHERE nota_id = :nota_id ORDER BY id ASC');
        $stmt->execute(['nota_id' => $notaId]);
        $itensNota = $stmt->fetchAll();

        $linhas = [
            '*** RASCUNHO - SEM VALOR FISCAL ***',
            'Documento interno de conferencia. Nao e uma nota fiscal emitida.',
            '',
            'Tipo: ' . ($nota['tipo_nota'] === 'nfse' ? 'NFS-e (servico)' : 'NF-e (produto)'),
            'Numero interno: ' . $nota['numero_interno'] . ' | Status: ' . rotuloStatusNota($nota['status']),
            'Emitido em (rascunho): ' . $nota['data_emissao'] . ' | Criado por: ' . nomeExibicao($nota['criado_por_usuario']),
            '',
            'EMITENTE',
            (string) $nota['empresa_razao_social'] . ' - CNPJ: ' . (string) ($nota['empresa_cnpj'] ?? 'nao informado'),
            'IE: ' . (string) ($nota['empresa_ie'] ?? 'nao informada') . ' | ' . (string) ($nota['empresa_municipio'] ?? '') . '/' . (string) ($nota['empresa_uf'] ?? ''),
            '',
            'DESTINATARIO',
            (string) $nota['cliente_nome'] . ' - Documento: ' . (string) ($nota['cliente_documento'] ?? 'nao informado'),
            (string) ($nota['cliente_municipio'] ?? '') . '/' . (string) ($nota['cliente_uf'] ?? ''),
            '',
            'Natureza da operacao: ' . $nota['natureza_operacao'],
            'Forma de pagamento: ' . (string) ($nota['forma_pagamento'] ?? 'nao informada'),
            str_repeat('-', 90),
            'ITENS',
            str_repeat('-', 90),
        ];

        foreach ($itensNota as $item) {
            $linhas[] = sprintf(
                '%s | Qtd: %s %s | Unit: R$ %s | Total: R$ %s',
                $item['descricao'],
                rtrim(rtrim(number_format((float) $item['quantidade'], 3, ',', '.'), '0'), ','),
                $item['unidade'],
                number_format((float) $item['valor_unitario'], 2, ',', '.'),
                number_format((float) $item['valor_total'], 2, ',', '.')
            );
        }

        $linhas[] = str_repeat('-', 90);
        $linhas[] = 'VALOR TOTAL: R$ ' . number_format((float) $nota['valor_total'], 2, ',', '.');
        if (($nota['informacoes_frete'] ?? '') !== '') {
            $linhas[] = '';
            $linhas[] = 'Frete/transporte: ' . $nota['informacoes_frete'];
        }
        $linhas[] = '';
        $linhas[] = 'Gerado em: ' . (new DateTimeImmutable('now'))->format('d/m/Y H:i:s');

        $pdf = gerarPdfSimples($linhas);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="rascunho-nota-' . $notaId . '.pdf"');
        echo $pdf;
        exit;
    }

    if (empty($_SESSION['csrf_notas_fiscais'])) {
        $_SESSION['csrf_notas_fiscais'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_fiscais'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'cadastrar_cliente') {
            $tipoPessoa = ($_POST['tipo_pessoa'] ?? 'PJ') === 'PF' ? 'PF' : 'PJ';
            $nomeRazaoSocial = trim($_POST['nome_razao_social'] ?? '');
            $cnpjCpf = trim($_POST['cnpj_cpf'] ?? '');
            $inscricaoEstadual = trim($_POST['cliente_inscricao_estadual'] ?? '');
            $email = trim($_POST['cliente_email'] ?? '');
            $logradouro = trim($_POST['cliente_logradouro'] ?? '');
            $numero = trim($_POST['cliente_numero'] ?? '');
            $complemento = trim($_POST['cliente_complemento'] ?? '');
            $bairro = trim($_POST['cliente_bairro'] ?? '');
            $cep = trim($_POST['cliente_cep'] ?? '');
            $municipio = trim($_POST['cliente_municipio'] ?? '');
            $uf = strtoupper(trim($_POST['cliente_uf'] ?? ''));
            $consumidorFinal = isset($_POST['indicador_consumidor_final']) ? 1 : 0;

            if ($nomeRazaoSocial === '') {
                $erro = 'Informe o nome/razão social do cliente.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido para o cliente ou deixe em branco.';
            } else {
                $stmt = $dbNotas->prepare(
                    'INSERT INTO notas_clientes (
                        tipo_pessoa, nome_razao_social, cnpj_cpf, inscricao_estadual, email,
                        logradouro, numero, complemento, bairro, cep, municipio, uf,
                        indicador_consumidor_final, criado_por
                     ) VALUES (
                        :tipo_pessoa, :nome_razao_social, :cnpj_cpf, :inscricao_estadual, :email,
                        :logradouro, :numero, :complemento, :bairro, :cep, :municipio, :uf,
                        :indicador_consumidor_final, :criado_por
                     )'
                );
                $stmt->execute([
                    'tipo_pessoa' => $tipoPessoa,
                    'nome_razao_social' => $nomeRazaoSocial,
                    'cnpj_cpf' => $cnpjCpf !== '' ? $cnpjCpf : null,
                    'inscricao_estadual' => $inscricaoEstadual !== '' ? $inscricaoEstadual : null,
                    'email' => $email !== '' ? $email : null,
                    'logradouro' => $logradouro !== '' ? $logradouro : null,
                    'numero' => $numero !== '' ? $numero : null,
                    'complemento' => $complemento !== '' ? $complemento : null,
                    'bairro' => $bairro !== '' ? $bairro : null,
                    'cep' => $cep !== '' ? $cep : null,
                    'municipio' => $municipio !== '' ? $municipio : null,
                    'uf' => $uf !== '' ? $uf : null,
                    'indicador_consumidor_final' => $consumidorFinal,
                    'criado_por' => $funcionarioId,
                ]);

                $sucesso = 'Cliente cadastrado. Já pode ser selecionado ao criar uma nota.';
            }
        } elseif (($_POST['acao'] ?? '') === 'criar_nota') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $clienteId = (int) ($_POST['cliente_id'] ?? 0);
            $tipoNota = ($_POST['tipo_nota'] ?? 'nfe') === 'nfse' ? 'nfse' : 'nfe';
            $naturezaOperacao = trim($_POST['natureza_operacao'] ?? '');
            $formaPagamento = trim($_POST['forma_pagamento'] ?? '');
            $dataEmissao = trim($_POST['data_emissao'] ?? '') !== '' ? trim($_POST['data_emissao']) : date('Y-m-d');
            $dataSaidaEntrada = trim($_POST['data_saida_entrada'] ?? '');
            $informacoesFrete = trim($_POST['informacoes_frete'] ?? '');

            $descricoes = $_POST['item_descricao'] ?? [];
            $ncms = $_POST['item_ncm'] ?? [];
            $cfops = $_POST['item_cfop'] ?? [];
            $csts = $_POST['item_cst'] ?? [];
            $unidades = $_POST['item_unidade'] ?? [];
            $quantidades = $_POST['item_quantidade'] ?? [];
            $valoresUnitarios = $_POST['item_valor_unitario'] ?? [];
            $produtoIds = $_POST['item_produto_id'] ?? [];

            $itensValidos = [];
            $valorTotalNota = 0.0;
            foreach ($descricoes as $indice => $descricaoItem) {
                $descricaoItem = trim((string) $descricaoItem);
                $quantidade = (float) str_replace(',', '.', (string) ($quantidades[$indice] ?? '0'));
                $valorUnitario = (float) str_replace(',', '.', (string) ($valoresUnitarios[$indice] ?? '0'));

                if ($descricaoItem === '' || $quantidade <= 0) {
                    continue;
                }

                $valorTotalItem = round($quantidade * $valorUnitario, 2);
                $valorTotalNota += $valorTotalItem;

                $itensValidos[] = [
                    'produto_servico_id' => (int) ($produtoIds[$indice] ?? 0) > 0 ? (int) $produtoIds[$indice] : null,
                    'descricao' => $descricaoItem,
                    'ncm' => trim((string) ($ncms[$indice] ?? '')) ?: null,
                    'cfop' => trim((string) ($cfops[$indice] ?? '')) ?: null,
                    'cst_csosn' => trim((string) ($csts[$indice] ?? '')) ?: null,
                    'unidade' => trim((string) ($unidades[$indice] ?? '')) ?: 'UN',
                    'quantidade' => $quantidade,
                    'valor_unitario' => $valorUnitario,
                    'valor_total' => $valorTotalItem,
                ];
            }

            if ($empresaId <= 0) {
                $erro = 'Selecione a empresa emissora.';
            } elseif ($clienteId <= 0) {
                $erro = 'Selecione o cliente destinatário.';
            } elseif ($naturezaOperacao === '') {
                $erro = 'Informe a natureza da operação.';
            } elseif (empty($itensValidos)) {
                $erro = 'Adicione ao menos um item com descrição e quantidade maior que zero.';
            } else {
                $dbNotas->beginTransaction();
                try {
                    $stmt = $dbNotas->prepare(
                        'SELECT COALESCE(MAX(numero_interno), 0) + 1 FROM notas_fiscais
                         WHERE empresa_emissora_id = :empresa_id AND tipo_nota = :tipo_nota FOR UPDATE'
                    );
                    $stmt->execute(['empresa_id' => $empresaId, 'tipo_nota' => $tipoNota]);
                    $numeroInterno = (int) $stmt->fetchColumn();

                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais (
                            empresa_emissora_id, cliente_id, funcionario_id, tipo_nota, natureza_operacao,
                            numero_interno, status, ambiente, forma_pagamento, data_emissao, data_saida_entrada,
                            valor_total, informacoes_frete
                         ) VALUES (
                            :empresa_id, :cliente_id, :funcionario_id, :tipo_nota, :natureza_operacao,
                            :numero_interno, \'rascunho\', \'homologacao\', :forma_pagamento, :data_emissao, :data_saida_entrada,
                            :valor_total, :informacoes_frete
                         )'
                    );
                    $stmt->execute([
                        'empresa_id' => $empresaId,
                        'cliente_id' => $clienteId,
                        'funcionario_id' => $funcionarioId,
                        'tipo_nota' => $tipoNota,
                        'natureza_operacao' => $naturezaOperacao,
                        'numero_interno' => $numeroInterno,
                        'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
                        'data_emissao' => $dataEmissao,
                        'data_saida_entrada' => $dataSaidaEntrada !== '' ? $dataSaidaEntrada : null,
                        'valor_total' => round($valorTotalNota, 2),
                        'informacoes_frete' => $informacoesFrete !== '' ? $informacoesFrete : null,
                    ]);
                    $notaId = (int) $dbNotas->lastInsertId();

                    $stmtItem = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais_itens (
                            nota_id, produto_servico_id, descricao, ncm, cfop, cst_csosn, unidade,
                            quantidade, valor_unitario, valor_total
                         ) VALUES (
                            :nota_id, :produto_servico_id, :descricao, :ncm, :cfop, :cst_csosn, :unidade,
                            :quantidade, :valor_unitario, :valor_total
                         )'
                    );
                    foreach ($itensValidos as $item) {
                        $stmtItem->execute([
                            'nota_id' => $notaId,
                            'produto_servico_id' => $item['produto_servico_id'],
                            'descricao' => $item['descricao'],
                            'ncm' => $item['ncm'],
                            'cfop' => $item['cfop'],
                            'cst_csosn' => $item['cst_csosn'],
                            'unidade' => $item['unidade'],
                            'quantidade' => $item['quantidade'],
                            'valor_unitario' => $item['valor_unitario'],
                            'valor_total' => $item['valor_total'],
                        ]);
                    }

                    registrarLogNota($dbNotas, $notaId, $funcionarioId, 'criada', 'Rascunho criado com ' . count($itensValidos) . ' item(ns).');

                    $dbNotas->commit();
                    $sucesso = 'Nota salva como rascunho (nº interno ' . $numeroInterno . '). Gere o PDF de conferência ou marque como pronta para envio.';
                } catch (Throwable $e) {
                    $dbNotas->rollBack();
                    $erro = 'Não foi possível salvar a nota: ' . $e->getMessage();
                }
            }
        } elseif (in_array($_POST['acao'] ?? '', ['marcar_pendente', 'cancelar'], true)) {
            $notaId = (int) ($_POST['nota_id'] ?? 0);
            $stmt = $dbNotas->prepare('SELECT funcionario_id, status FROM notas_fiscais WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $notaId]);
            $notaAtual = $stmt->fetch();

            if (!$notaAtual || (!$podeAdministrar && (int) $notaAtual['funcionario_id'] !== $funcionarioId)) {
                $erro = 'Nota não encontrada.';
            } elseif ($_POST['acao'] === 'marcar_pendente' && $notaAtual['status'] === 'rascunho') {
                $dbNotas->prepare('UPDATE notas_fiscais SET status = \'pendente_envio\' WHERE id = :id')->execute(['id' => $notaId]);
                registrarLogNota($dbNotas, $notaId, $funcionarioId, 'pendente_envio', 'Marcada como pronta para envio (envio automático ainda não configurado).');
                $sucesso = 'Nota marcada como pendente de envio. A transmissão automática para SEFAZ/Portal Nacional NFS-e ainda será configurada em uma próxima etapa.';
            } elseif ($_POST['acao'] === 'cancelar' && in_array($notaAtual['status'], ['rascunho', 'pendente_envio'], true)) {
                $dbNotas->prepare('UPDATE notas_fiscais SET status = \'cancelada\' WHERE id = :id')->execute(['id' => $notaId]);
                registrarLogNota($dbNotas, $notaId, $funcionarioId, 'cancelada');
                $sucesso = 'Nota cancelada.';
            } else {
                $erro = 'Ação não permitida para o status atual da nota.';
            }
        }
    }

    $stmt = $dbNotas->query('SELECT id, razao_social FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC');
    $empresasAtivas = $stmt->fetchAll();

    $stmt = $dbNotas->query('SELECT id, nome_razao_social, cnpj_cpf, municipio, uf FROM notas_clientes ORDER BY nome_razao_social ASC');
    $clientes = $stmt->fetchAll();

    $stmt = $dbNotas->query(
        'SELECT id, empresa_emissora_id, tipo, descricao, ncm, cfop, cst_csosn, codigo_servico_municipal, unidade, valor_unitario_padrao
         FROM notas_produtos_servicos WHERE ativo = 1 ORDER BY descricao ASC'
    );
    $catalogo = $stmt->fetchAll();

    $filtroStatus = trim($_GET['status'] ?? '');
    $where = [];
    $bind = [];
    if (!$podeAdministrar) {
        $where[] = 'n.funcionario_id = :funcionario_id';
        $bind['funcionario_id'] = $funcionarioId;
    }
    if ($filtroStatus !== '') {
        $where[] = 'n.status = :status';
        $bind['status'] = $filtroStatus;
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $dbNotas->prepare(
        'SELECT n.id, n.tipo_nota, n.numero_interno, n.status, n.valor_total, n.data_emissao, n.criado_em, n.funcionario_id,
                e.razao_social AS empresa_razao_social, c.nome_razao_social AS cliente_nome
         FROM notas_fiscais n
         INNER JOIN empresas_emissoras e ON e.id = n.empresa_emissora_id
         INNER JOIN notas_clientes c ON c.id = n.cliente_id
         ' . $sqlWhere . '
         ORDER BY n.criado_em DESC
         LIMIT 200'
    );
    $stmt->execute($bind);
    $notas = $stmt->fetchAll();

    if ($podeAdministrar && !empty($notas)) {
        $funcionarioIds = array_values(array_unique(array_map(static fn (array $nota): int => (int) $nota['funcionario_id'], $notas)));
        $placeholders = implode(',', array_fill(0, count($funcionarioIds), '?'));
        $stmt = $db->prepare("SELECT id, usuario FROM funcionarios WHERE id IN ({$placeholders})");
        $stmt->execute($funcionarioIds);
        $usuariosPorId = array_column($stmt->fetchAll(), 'usuario', 'id');

        foreach ($notas as &$nota) {
            $nota['criado_por_usuario'] = $usuariosPorId[$nota['funcionario_id']] ?? 'Funcionário';
        }
        unset($nota);
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar notas fiscais: ' . $e->getMessage();
    $empresasAtivas = [];
    $clientes = [];
    $catalogo = [];
    $notas = [];
}

$csrf = h($_SESSION['csrf_notas_fiscais'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
$catalogoJson = h(json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notas Fiscais | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --danger: #FF453A;
            --text-white: #FFFFFF;
            --text-light: #F5F5F7;
            --text-muted: #A1A1A6;
            --border: rgba(255,255,255,0.1);
            --font-titles: 'Montserrat', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: var(--font-body);
            background: var(--bg-main);
            color: var(--text-light);
            padding: 2rem;
        }

        .shell { width: min(1180px, 100%); margin: 0 auto; }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .brand img { height: 34px; width: auto; display: block; }
        a { color: inherit; text-decoration: none; }

        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        h1, h2, h3 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
        }

        h1 { font-size: clamp(2rem, 5vw, 3.2rem); margin-bottom: 0.8rem; }
        h2 { font-size: 1.25rem; margin-bottom: 1rem; }
        h3 { font-size: 1rem; margin-bottom: 0.75rem; }
        .muted { color: var(--text-muted); line-height: 1.6; }

        .btn {
            border: 0;
            border-radius: 4px;
            padding: 0.85rem 1rem;
            color: var(--bg-main);
            background: var(--primary);
            font-family: var(--font-titles);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
        }

        .btn:hover { background: var(--primary-hover); }

        .btn-outline {
            background: transparent;
            color: var(--text-white);
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: transparent;
            color: #FFD1CE;
            border: 1px solid rgba(255, 69, 58, 0.35);
        }

        .btn-small { padding: 0.55rem 0.75rem; font-size: 0.72rem; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .field { display: grid; gap: 0.4rem; }
        .field label { color: var(--text-muted); font-size: 0.85rem; font-weight: 700; }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 0.85rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: #0A0A0A;
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .field textarea { resize: vertical; min-height: 4.5rem; }

        .check-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-top: 1.9rem;
            color: var(--text-muted);
            font-weight: 700;
        }

        .notice {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid rgba(116, 201, 44, 0.3);
            background: rgba(116, 201, 44, 0.08);
        }

        .notice.error {
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        .notice.warning {
            border-color: rgba(255, 191, 0, 0.35);
            background: rgba(255, 191, 0, 0.08);
            color: #FFE8A3;
        }

        details.panel summary {
            cursor: pointer;
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
            font-size: 1.1rem;
        }

        details.panel[open] summary { margin-bottom: 1rem; }

        .itens-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
        .itens-table th, .itens-table td { padding: 0.5rem; border-bottom: 1px solid var(--border); text-align: left; }
        .itens-table th { color: var(--text-white); font-size: 0.72rem; text-transform: uppercase; font-family: var(--font-titles); }
        .itens-table input, .itens-table select { width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid var(--border); background: #0A0A0A; color: var(--text-white); }

        .table-wrap { overflow-x: auto; }
        table.lista { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 0.9rem; }
        table.lista th, table.lista td { padding: 0.8rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        table.lista th { color: var(--text-white); font-family: var(--font-titles); font-size: 0.75rem; text-transform: uppercase; }
        .status-pill { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .status-rascunho { background: rgba(161, 161, 166, 0.18); color: var(--text-muted); }
        .status-pendente_envio { background: rgba(255, 191, 0, 0.15); color: #FFE8A3; }
        .status-autorizada { background: rgba(116, 201, 44, 0.15); color: var(--primary); }
        .status-rejeitada,
        .status-cancelada { background: rgba(255, 69, 58, 0.15); color: #FFD1CE; }
        .row-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .totais { text-align: right; font-family: var(--font-titles); font-size: 1.1rem; color: var(--text-white); }

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .form-grid { grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="painel" aria-label="Voltar para o painel">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <?php if ($podeAdministrar): ?>
                    <a class="btn btn-outline" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
                    <a class="btn btn-outline" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Notas Fiscais</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de produto (NF-e) ou serviço (NFS-e) para outras empresas. As notas ficam como rascunho até a integração com a SEFAZ e o Portal Nacional da NFS-e ser habilitada.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <div class="notice warning">
            <strong>Fase 1 — sem envio automático:</strong> notas criadas aqui ficam em rascunho/pendente de envio. Não há transmissão para a SEFAZ (NF-e) nem para o Portal Nacional da NFS-e ainda. O PDF gerado é só para conferência interna.
        </div>

        <?php if (empty($empresasAtivas)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. <?php echo $podeAdministrar ? 'Cadastre uma em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a>.' : 'Peça para um administrador cadastrar em Empresas emissoras.'; ?></div>
        <?php else: ?>

            <details class="panel" id="cadastroCliente">
                <summary>Cadastrar novo cliente (destinatário)</summary>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="cadastrar_cliente">
                    <div class="form-grid">
                        <div class="field">
                            <label for="tipo_pessoa">Tipo de pessoa</label>
                            <select id="tipo_pessoa" name="tipo_pessoa">
                                <option value="PJ">Pessoa jurídica</option>
                                <option value="PF">Pessoa física</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nome_razao_social">Nome / Razão social</label>
                            <input id="nome_razao_social" name="nome_razao_social" type="text" required>
                        </div>
                        <div class="field">
                            <label for="cnpj_cpf">CNPJ / CPF</label>
                            <input id="cnpj_cpf" name="cnpj_cpf" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_inscricao_estadual">Inscrição Estadual (ou "ISENTO")</label>
                            <input id="cliente_inscricao_estadual" name="cliente_inscricao_estadual" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_email">E-mail</label>
                            <input id="cliente_email" name="cliente_email" type="email">
                        </div>
                        <div class="field">
                            <label for="cliente_logradouro">Logradouro</label>
                            <input id="cliente_logradouro" name="cliente_logradouro" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_numero">Número</label>
                            <input id="cliente_numero" name="cliente_numero" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_complemento">Complemento</label>
                            <input id="cliente_complemento" name="cliente_complemento" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_bairro">Bairro</label>
                            <input id="cliente_bairro" name="cliente_bairro" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_cep">CEP</label>
                            <input id="cliente_cep" name="cliente_cep" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_municipio">Município</label>
                            <input id="cliente_municipio" name="cliente_municipio" type="text">
                        </div>
                        <div class="field">
                            <label for="cliente_uf">UF</label>
                            <input id="cliente_uf" name="cliente_uf" type="text" maxlength="2">
                        </div>
                        <label class="check-row">
                            <input type="checkbox" name="indicador_consumidor_final" checked>
                            Consumidor final
                        </label>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn" type="submit"><i class="fa-solid fa-user-plus"></i> Cadastrar cliente</button>
                        </div>
                    </div>
                </form>
            </details>

            <section class="panel">
                <h2>Nova nota (rascunho)</h2>
                <form method="post" id="formNota">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="criar_nota">
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <select id="empresa_emissora_id" name="empresa_emissora_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($empresasAtivas as $empresa): ?>
                                    <option value="<?php echo h((string) $empresa['id']); ?>"><?php echo h($empresa['razao_social']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="tipo_nota">Tipo de nota</label>
                            <select id="tipo_nota" name="tipo_nota">
                                <option value="nfe">NF-e (produto)</option>
                                <option value="nfse">NFS-e (serviço)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="cliente_id">Cliente destinatário</label>
                            <select id="cliente_id" name="cliente_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?php echo h((string) $cliente['id']); ?>"><?php echo h($cliente['nome_razao_social'] . (($cliente['cnpj_cpf'] ?? '') !== '' ? ' - ' . $cliente['cnpj_cpf'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="natureza_operacao">Natureza da operação</label>
                            <input id="natureza_operacao" name="natureza_operacao" type="text" placeholder="Ex.: Venda de mercadoria / Prestação de serviço" required>
                        </div>
                        <div class="field">
                            <label for="forma_pagamento">Forma de pagamento</label>
                            <input id="forma_pagamento" name="forma_pagamento" type="text" placeholder="Pix, boleto, cartão...">
                        </div>
                        <div class="field">
                            <label for="data_emissao">Data de emissão</label>
                            <input id="data_emissao" name="data_emissao" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="field">
                            <label for="data_saida_entrada">Data de saída/entrada</label>
                            <input id="data_saida_entrada" name="data_saida_entrada" type="date">
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="informacoes_frete">Frete/transporte (opcional)</label>
                            <textarea id="informacoes_frete" name="informacoes_frete" placeholder="Transportadora, placa, volume, peso..."></textarea>
                        </div>
                    </div>

                    <h3 style="margin-top: 1.5rem;">Itens</h3>
                    <table class="itens-table" id="tabelaItens">
                        <thead>
                            <tr>
                                <th style="min-width: 220px;">Catálogo (opcional)</th>
                                <th style="min-width: 200px;">Descrição</th>
                                <th>NCM</th>
                                <th>CFOP</th>
                                <th>CST/CSOSN</th>
                                <th>Unid.</th>
                                <th>Qtd.</th>
                                <th>Valor unit.</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="corpoItens"></tbody>
                    </table>
                    <button class="btn btn-outline btn-small" type="button" id="btnAddItem"><i class="fa-solid fa-plus"></i> Adicionar item</button>

                    <div class="totais" id="totalNota" style="margin-top: 1rem;">Total estimado: R$ 0,00</div>

                    <div style="margin-top: 1.5rem;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar rascunho</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <h2 style="margin-bottom:0;"><?php echo $podeAdministrar ? 'Todas as notas' : 'Minhas notas'; ?></h2>
                <form method="get" class="row-actions">
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Todos os status</option>
                        <?php foreach (['rascunho', 'pendente_envio', 'autorizada', 'rejeitada', 'cancelada'] as $statusOpcao): ?>
                            <option value="<?php echo h($statusOpcao); ?>" <?php echo $filtroStatus === $statusOpcao ? 'selected' : ''; ?>><?php echo h(rotuloStatusNota($statusOpcao)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="table-wrap">
                <table class="lista">
                    <thead>
                        <tr>
                            <th>Nº interno</th>
                            <th>Tipo</th>
                            <th>Empresa</th>
                            <th>Cliente</th>
                            <?php if ($podeAdministrar): ?><th>Criado por</th><?php endif; ?>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notas as $nota): ?>
                            <tr>
                                <td>#<?php echo h((string) $nota['numero_interno']); ?></td>
                                <td><?php echo $nota['tipo_nota'] === 'nfse' ? 'NFS-e' : 'NF-e'; ?></td>
                                <td><?php echo h($nota['empresa_razao_social']); ?></td>
                                <td><?php echo h($nota['cliente_nome']); ?></td>
                                <?php if ($podeAdministrar): ?><td><?php echo h(nomeExibicao($nota['criado_por_usuario'])); ?></td><?php endif; ?>
                                <td>R$ <?php echo number_format((float) $nota['valor_total'], 2, ',', '.'); ?></td>
                                <td><span class="status-pill status-<?php echo h($nota['status']); ?>"><?php echo h(rotuloStatusNota($nota['status'])); ?></span></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-outline btn-small" href="notas-fiscais?pdf=<?php echo h((string) $nota['id']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                                        <?php if ($nota['status'] === 'rascunho'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="nota_id" value="<?php echo h((string) $nota['id']); ?>">
                                                <input type="hidden" name="acao" value="marcar_pendente">
                                                <button class="btn btn-small" type="submit"><i class="fa-solid fa-paper-plane"></i> Pronta p/ envio</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($nota['status'], ['rascunho', 'pendente_envio'], true)): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="nota_id" value="<?php echo h((string) $nota['id']); ?>">
                                                <input type="hidden" name="acao" value="cancelar">
                                                <button class="btn btn-danger btn-small" type="submit"><i class="fa-solid fa-ban"></i> Cancelar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($notas)): ?>
                            <tr><td colspan="<?php echo $podeAdministrar ? 8 : 7; ?>" class="muted">Nenhuma nota encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        const catalogo = JSON.parse(<?php echo json_encode($catalogoJson); ?>);
        const corpoItens = document.getElementById('corpoItens');
        const empresaSelect = document.getElementById('empresa_emissora_id');
        const totalNotaEl = document.getElementById('totalNota');

        function formatarMoeda(valor) {
            return 'Total estimado: R$ ' + valor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcularTotal() {
            let total = 0;
            corpoItens.querySelectorAll('tr').forEach(function (linha) {
                const qtd = parseFloat((linha.querySelector('.item-quantidade').value || '0').replace(',', '.')) || 0;
                const valorUnit = parseFloat((linha.querySelector('.item-valor').value || '0').replace(',', '.')) || 0;
                total += qtd * valorUnit;
            });
            totalNotaEl.textContent = formatarMoeda(total);
        }

        function montarOpcoesCatalogo(empresaId) {
            let opcoes = '<option value="">Digitar manualmente</option>';
            catalogo.filter(function (item) {
                return String(item.empresa_emissora_id) === String(empresaId);
            }).forEach(function (item) {
                opcoes += '<option value="' + item.id + '">' + item.descricao + '</option>';
            });
            return opcoes;
        }

        function adicionarLinhaItem() {
            const empresaId = empresaSelect ? empresaSelect.value : '';
            const linha = document.createElement('tr');
            linha.innerHTML =
                '<td><select class="item-catalogo">' + montarOpcoesCatalogo(empresaId) + '</select></td>' +
                '<td><input type="text" name="item_descricao[]" class="item-descricao" required></td>' +
                '<td><input type="text" name="item_ncm[]" class="item-ncm"></td>' +
                '<td><input type="text" name="item_cfop[]" class="item-cfop"></td>' +
                '<td><input type="text" name="item_cst[]" class="item-cst"></td>' +
                '<td><input type="text" name="item_unidade[]" class="item-unidade" value="UN"></td>' +
                '<td><input type="text" name="item_quantidade[]" class="item-quantidade" value="1"></td>' +
                '<td><input type="text" name="item_valor_unitario[]" class="item-valor" value="0,00"></td>' +
                '<td><input type="hidden" name="item_produto_id[]" class="item-produto-id" value="0">' +
                '<button type="button" class="btn btn-danger btn-small btn-remover-item"><i class="fa-solid fa-trash"></i></button></td>';

            corpoItens.appendChild(linha);

            const selectCatalogo = linha.querySelector('.item-catalogo');
            selectCatalogo.addEventListener('change', function () {
                const item = catalogo.find(function (candidato) {
                    return String(candidato.id) === selectCatalogo.value;
                });
                if (item) {
                    linha.querySelector('.item-descricao').value = item.descricao || '';
                    linha.querySelector('.item-ncm').value = item.ncm || '';
                    linha.querySelector('.item-cfop').value = item.cfop || '';
                    linha.querySelector('.item-cst').value = item.cst_csosn || '';
                    linha.querySelector('.item-unidade').value = item.unidade || 'UN';
                    linha.querySelector('.item-valor').value = Number(item.valor_unitario_padrao || 0).toFixed(2).replace('.', ',');
                    linha.querySelector('.item-produto-id').value = item.id;
                } else {
                    linha.querySelector('.item-produto-id').value = 0;
                }
                recalcularTotal();
            });

            linha.querySelector('.item-quantidade').addEventListener('input', recalcularTotal);
            linha.querySelector('.item-valor').addEventListener('input', recalcularTotal);
            linha.querySelector('.btn-remover-item').addEventListener('click', function () {
                linha.remove();
                recalcularTotal();
            });

            recalcularTotal();
        }

        const btnAddItem = document.getElementById('btnAddItem');
        if (btnAddItem) {
            btnAddItem.addEventListener('click', adicionarLinhaItem);
            adicionarLinhaItem();
        }

        if (empresaSelect) {
            empresaSelect.addEventListener('change', function () {
                corpoItens.querySelectorAll('.item-catalogo').forEach(function (select) {
                    select.innerHTML = montarOpcoesCatalogo(empresaSelect.value);
                });
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
