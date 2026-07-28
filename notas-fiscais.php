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

function colunaExisteFuncionarios(PDO $db, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'funcionarios\'
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunaPermiteNotasFiscais(PDO $db): void
{
    if (!colunaExisteFuncionarios($db, 'permite_notas_fiscais')) {
        $db->exec('ALTER TABLE funcionarios ADD COLUMN permite_notas_fiscais TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto');
    }
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
            certificado_arquivo VARCHAR(255) NULL,
            certificado_senha_cifrada VARCHAR(512) NULL,
            certificado_atualizado_em TIMESTAMP NULL,
            certificado_atualizado_por INT UNSIGNED NULL,
            certificado_validade DATE NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function semearEmpresasEmissorasNotas(PDO $db): void
{
    // So semeia se a tabela estiver vazia (ver comentario equivalente em notas-empresas-emissoras.php).
    if ((int) $db->query('SELECT COUNT(*) FROM empresas_emissoras')->fetchColumn() > 0) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO empresas_emissoras (razao_social, ambiente_emissao, ativo)
         VALUES (:razao_social, \'homologacao\', 1)
         ON DUPLICATE KEY UPDATE razao_social = razao_social'
    );

    foreach (['Account', 'Art Designer', 'Consplatol', 'MC', 'MC2', 'Smarky', 'Tarsos Pizzaria'] as $nome) {
        $stmt->execute(['razao_social' => $nome]);
    }
}

function prepararTabelaNotasProdutosServicosNotas(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_produtos_servicos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_emissora_id INT UNSIGNED NOT NULL,
            tipo ENUM('produto','servico') NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            codigo_interno VARCHAR(60) NULL,
            ncm VARCHAR(10) NULL,
            cfop VARCHAR(6) NULL,
            cst_csosn VARCHAR(6) NULL,
            codigo_servico_municipal VARCHAR(20) NULL,
            unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
            valor_unitario_padrao DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            aliquota_icms DECIMAL(5,2) NULL,
            aliquota_pis DECIMAL(5,2) NULL,
            aliquota_cofins DECIMAL(5,2) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_produtos_servicos_empresa (empresa_emissora_id, ativo),
            CONSTRAINT fk_produtos_servicos_empresa_notas
                FOREIGN KEY (empresa_emissora_id) REFERENCES empresas_emissoras(id)
                ON DELETE CASCADE
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
            chave_acesso VARCHAR(60) NULL,
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
            codigo_servico_municipal VARCHAR(20) NULL,
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

function colunaExisteNotas(PDO $db, string $tabela, string $coluna): bool
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

function prepararColunasFase2Notas(PDO $db): void
{
    if (!colunaExisteNotas($db, 'notas_fiscais', 'chave_acesso')) {
        $db->exec('ALTER TABLE notas_fiscais ADD COLUMN chave_acesso VARCHAR(60) NULL AFTER informacoes_frete');
    }

    if (!colunaExisteNotas($db, 'notas_fiscais_itens', 'codigo_servico_municipal')) {
        $db->exec('ALTER TABLE notas_fiscais_itens ADD COLUMN codigo_servico_municipal VARCHAR(20) NULL AFTER cst_csosn');
    }
}

function prepararColunasCertificadoEmpresa(PDO $db): void
{
    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_arquivo')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_senha_cifrada')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_atualizado_em')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_atualizado_por')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em');
    }

    if (!colunaExisteNotas($db, 'empresas_emissoras', 'certificado_validade')) {
        $db->exec('ALTER TABLE empresas_emissoras ADD COLUMN certificado_validade DATE NULL AFTER certificado_atualizado_por');
    }
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
    semearEmpresasEmissorasNotas($dbNotas);
    prepararTabelaNotasProdutosServicosNotas($dbNotas);
    prepararTabelaNotasClientes($dbNotas);
    prepararTabelaNotasFiscais($dbNotas);
    prepararTabelaNotasFiscaisItens($dbNotas);
    prepararTabelaNotasFiscaisLog($dbNotas);
    prepararColunasFase2Notas($dbNotas);
    prepararColunasCertificadoEmpresa($dbNotas);

    prepararColunaPermiteNotasFiscais($db);

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
                n.chave_acesso, n.motivo_rejeicao,
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
    $notas = [];
}

$csrf = h($_SESSION['csrf_notas_fiscais'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
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

        .menu-hamburguer { position: relative; }

        .menu-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            display: none;
            flex-direction: column;
            gap: 0.4rem;
            min-width: 240px;
            padding: 0.6rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
            z-index: 60;
        }

        .menu-dropdown.aberto { display: flex; }
        .menu-dropdown .btn { justify-content: flex-start; width: 100%; }

        .btn-danger {
            background: transparent;
            color: #FFD1CE;
            border: 1px solid rgba(255, 69, 58, 0.35);
        }

        .btn-small { padding: 0.55rem 0.75rem; font-size: 0.72rem; }

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

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
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
            <div class="menu-hamburguer">
                <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
                    <i class="fa-solid fa-bars"></i> Menu
                </button>
                <div class="menu-dropdown" id="menuDropdown">
                    <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
                    <a class="btn btn-outline" href="notas-emitir"><i class="fa-solid fa-file-circle-plus"></i> Emitir nota fiscal</a>
                    <a class="btn btn-outline" href="notas-emitir#cadastroCliente"><i class="fa-solid fa-user-plus"></i> Cadastrar clientes</a>
                    <a class="btn btn-outline" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
                    <?php if ($podeAdministrar): ?>
                        <a class="btn btn-outline" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
                        <a class="btn btn-outline" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
                        <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Processar fila NFS-e</a>
                    <?php endif; ?>
                    <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                    <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                    <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
                </div>
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

        <section class="panel">
            <a class="btn" href="notas-emitir"><i class="fa-solid fa-file-circle-plus"></i> Nova nota (emitir)</a>
        </section>

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
                                <td>
                                    <span class="status-pill status-<?php echo h($nota['status']); ?>"><?php echo h(rotuloStatusNota($nota['status'])); ?></span>
                                    <?php if (($nota['chave_acesso'] ?? '') !== ''): ?>
                                        <div class="muted" style="font-size: 0.7rem; margin-top: 0.3rem;">Chave: <?php echo h($nota['chave_acesso']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($nota['status'] === 'rejeitada' && ($nota['motivo_rejeicao'] ?? '') !== ''): ?>
                                        <div class="muted" style="font-size: 0.7rem; margin-top: 0.3rem; color: #FFD1CE;"><?php echo h($nota['motivo_rejeicao']); ?></div>
                                    <?php endif; ?>
                                </td>
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
