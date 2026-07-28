<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db_notas.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);

if ($nivelAcesso < 3) {
    header('Location: painel');
    exit;
}

$erro = '';
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function prepararTabelaEmpresasEmissoras(PDO $db): void
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
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_empresas_emissoras_razao_social (razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function colunaExisteEmpresasEmissoras(PDO $db, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'empresas_emissoras\'
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute(['coluna' => $coluna]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararColunasCertificadoEmpresaEmissoras(PDO $db): void
{
    $campos = [
        'certificado_arquivo' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao",
        'certificado_senha_cifrada' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo",
        'certificado_atualizado_em' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada",
        'certificado_atualizado_por' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em",
    ];

    foreach ($campos as $coluna => $sql) {
        if (!colunaExisteEmpresasEmissoras($db, $coluna)) {
            $db->exec($sql);
        }
    }
}

function semearEmpresasEmissoras(PDO $db): void
{
    $stmt = $db->prepare(
        'INSERT INTO empresas_emissoras (razao_social, ambiente_emissao, ativo)
         VALUES (:razao_social, \'homologacao\', 1)
         ON DUPLICATE KEY UPDATE razao_social = razao_social'
    );

    foreach (['Account', 'Art Designer', 'Consplatol', 'MC', 'MC2', 'Smarky', 'Tarsos Pizzaria'] as $nome) {
        $stmt->execute(['razao_social' => $nome]);
    }
}

try {
    $db = obterConexaoNotas();
    prepararTabelaEmpresasEmissoras($db);
    prepararColunasCertificadoEmpresaEmissoras($db);
    semearEmpresasEmissoras($db);

    if (empty($_SESSION['csrf_notas_empresas_emissoras'])) {
        $_SESSION['csrf_notas_empresas_emissoras'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_empresas_emissoras'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'adicionar') {
            $empresaIdEdicao = (int) ($_POST['empresa_id'] ?? 0);
            $razaoSocial = trim($_POST['razao_social'] ?? '');
            $nomeFantasia = trim($_POST['nome_fantasia'] ?? '');
            $cnpj = trim($_POST['cnpj'] ?? '');
            $inscricaoEstadual = trim($_POST['inscricao_estadual'] ?? '');
            $inscricaoMunicipal = trim($_POST['inscricao_municipal'] ?? '');
            $logradouro = trim($_POST['logradouro'] ?? '');
            $numero = trim($_POST['numero'] ?? '');
            $complemento = trim($_POST['complemento'] ?? '');
            $bairro = trim($_POST['bairro'] ?? '');
            $cep = trim($_POST['cep'] ?? '');
            $municipio = trim($_POST['municipio'] ?? '');
            $codigoIbge = trim($_POST['codigo_ibge_municipio'] ?? '');
            $uf = strtoupper(trim($_POST['uf'] ?? ''));
            $crt = $_POST['crt'] !== '' ? (int) $_POST['crt'] : null;
            $ambiente = ($_POST['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';

            if ($razaoSocial === '') {
                $erro = 'Informe a razão social da empresa.';
            } elseif ($empresaIdEdicao > 0) {
                $stmt = $db->prepare(
                    'UPDATE empresas_emissoras SET
                        razao_social = :razao_social,
                        nome_fantasia = :nome_fantasia,
                        cnpj = :cnpj,
                        inscricao_estadual = :inscricao_estadual,
                        inscricao_municipal = :inscricao_municipal,
                        logradouro = :logradouro,
                        numero = :numero,
                        complemento = :complemento,
                        bairro = :bairro,
                        cep = :cep,
                        municipio = :municipio,
                        codigo_ibge_municipio = :codigo_ibge_municipio,
                        uf = :uf,
                        crt = :crt,
                        ambiente_emissao = :ambiente_emissao
                     WHERE id = :id'
                );
                try {
                    $stmt->execute([
                        'razao_social' => $razaoSocial,
                        'nome_fantasia' => $nomeFantasia !== '' ? $nomeFantasia : null,
                        'cnpj' => $cnpj !== '' ? $cnpj : null,
                        'inscricao_estadual' => $inscricaoEstadual !== '' ? $inscricaoEstadual : null,
                        'inscricao_municipal' => $inscricaoMunicipal !== '' ? $inscricaoMunicipal : null,
                        'logradouro' => $logradouro !== '' ? $logradouro : null,
                        'numero' => $numero !== '' ? $numero : null,
                        'complemento' => $complemento !== '' ? $complemento : null,
                        'bairro' => $bairro !== '' ? $bairro : null,
                        'cep' => $cep !== '' ? $cep : null,
                        'municipio' => $municipio !== '' ? $municipio : null,
                        'codigo_ibge_municipio' => $codigoIbge !== '' ? $codigoIbge : null,
                        'uf' => $uf !== '' ? $uf : null,
                        'crt' => $crt,
                        'ambiente_emissao' => $ambiente,
                        'id' => $empresaIdEdicao,
                    ]);
                    $sucesso = 'Empresa emissora atualizada com sucesso.';
                } catch (PDOException $e) {
                    $erro = str_contains($e->getMessage(), 'uq_empresas_emissoras_razao_social')
                        ? 'Já existe outra empresa cadastrada com essa razão social.'
                        : ('Erro ao atualizar empresa: ' . $e->getMessage());
                }
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO empresas_emissoras (
                        razao_social, nome_fantasia, cnpj, inscricao_estadual, inscricao_municipal,
                        logradouro, numero, complemento, bairro, cep, municipio, codigo_ibge_municipio, uf,
                        crt, ambiente_emissao, ativo
                     ) VALUES (
                        :razao_social, :nome_fantasia, :cnpj, :inscricao_estadual, :inscricao_municipal,
                        :logradouro, :numero, :complemento, :bairro, :cep, :municipio, :codigo_ibge_municipio, :uf,
                        :crt, :ambiente_emissao, 1
                     )
                     ON DUPLICATE KEY UPDATE
                        nome_fantasia = VALUES(nome_fantasia),
                        cnpj = VALUES(cnpj),
                        inscricao_estadual = VALUES(inscricao_estadual),
                        inscricao_municipal = VALUES(inscricao_municipal),
                        logradouro = VALUES(logradouro),
                        numero = VALUES(numero),
                        complemento = VALUES(complemento),
                        bairro = VALUES(bairro),
                        cep = VALUES(cep),
                        municipio = VALUES(municipio),
                        codigo_ibge_municipio = VALUES(codigo_ibge_municipio),
                        uf = VALUES(uf),
                        crt = VALUES(crt),
                        ambiente_emissao = VALUES(ambiente_emissao),
                        ativo = 1'
                );
                $stmt->execute([
                    'razao_social' => $razaoSocial,
                    'nome_fantasia' => $nomeFantasia !== '' ? $nomeFantasia : null,
                    'cnpj' => $cnpj !== '' ? $cnpj : null,
                    'inscricao_estadual' => $inscricaoEstadual !== '' ? $inscricaoEstadual : null,
                    'inscricao_municipal' => $inscricaoMunicipal !== '' ? $inscricaoMunicipal : null,
                    'logradouro' => $logradouro !== '' ? $logradouro : null,
                    'numero' => $numero !== '' ? $numero : null,
                    'complemento' => $complemento !== '' ? $complemento : null,
                    'bairro' => $bairro !== '' ? $bairro : null,
                    'cep' => $cep !== '' ? $cep : null,
                    'municipio' => $municipio !== '' ? $municipio : null,
                    'codigo_ibge_municipio' => $codigoIbge !== '' ? $codigoIbge : null,
                    'uf' => $uf !== '' ? $uf : null,
                    'crt' => $crt,
                    'ambiente_emissao' => $ambiente,
                ]);

                $sucesso = 'Empresa emissora salva com sucesso.';
            }
        } elseif (($_POST['acao'] ?? '') === 'desativar') {
            $id = (int) ($_POST['empresa_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE empresas_emissoras SET ativo = 0 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Empresa desativada. Ela deixa de aparecer para escolha em novas notas.';
            }
        } elseif (($_POST['acao'] ?? '') === 'reativar') {
            $id = (int) ($_POST['empresa_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE empresas_emissoras SET ativo = 1 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Empresa reativada com sucesso.';
            }
        } elseif (($_POST['acao'] ?? '') === 'excluir') {
            $id = (int) ($_POST['empresa_id'] ?? 0);
            $confirmado = ($_POST['confirmar_exclusao'] ?? '') === 'sim';

            if ($id <= 0) {
                $erro = 'Empresa inválida.';
            } elseif (!$confirmado) {
                $erro = 'Marque SIM para confirmar a exclusão definitiva.';
            } else {
                $stmt = $db->prepare('SELECT ativo, certificado_arquivo FROM empresas_emissoras WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $empresaExcluir = $stmt->fetch();

                if (!$empresaExcluir) {
                    $erro = 'Empresa não encontrada.';
                } elseif ((int) $empresaExcluir['ativo'] === 1) {
                    $erro = 'Desative a empresa antes de excluir definitivamente.';
                } else {
                    try {
                        $stmt = $db->prepare('DELETE FROM empresas_emissoras WHERE id = :id AND ativo = 0');
                        $stmt->execute(['id' => $id]);

                        if (!empty($empresaExcluir['certificado_arquivo'])) {
                            $arquivoCertificado = __DIR__ . '/certificados-nfse/' . basename($empresaExcluir['certificado_arquivo']);
                            if (is_file($arquivoCertificado)) {
                                unlink($arquivoCertificado);
                            }
                        }

                        $sucesso = 'Empresa excluída definitivamente.';
                    } catch (PDOException $e) {
                        $erro = str_contains($e->getMessage(), 'a foreign key constraint fails')
                            ? 'Não é possível excluir: existem notas fiscais emitidas vinculadas a esta empresa. O histórico precisa ser mantido.'
                            : ('Erro ao excluir empresa: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    $stmt = $db->query(
        'SELECT id, razao_social, nome_fantasia, cnpj, inscricao_estadual, inscricao_municipal,
                logradouro, numero, complemento, bairro, cep, municipio, codigo_ibge_municipio, uf,
                crt, ambiente_emissao, ativo
         FROM empresas_emissoras
         ORDER BY ativo DESC, razao_social ASC'
    );
    $empresas = $stmt->fetchAll();

    $empresaEmEdicao = null;
    $idEdicao = (int) ($_GET['editar'] ?? 0);
    if ($idEdicao > 0) {
        foreach ($empresas as $empresaLista) {
            if ((int) $empresaLista['id'] === $idEdicao) {
                $empresaEmEdicao = $empresaLista;
                break;
            }
        }
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar empresas emissoras: ' . $e->getMessage();
    $empresas = [];
    $empresaEmEdicao = null;
}

$csrf = h($_SESSION['csrf_notas_empresas_emissoras'] ?? '');

function rotuloCrt(?int $crt): string
{
    return [
        1 => '1 - Simples Nacional',
        2 => '2 - Simples Nacional (excesso)',
        3 => '3 - Regime Normal',
    ][$crt] ?? '—';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empresas Emissoras | ACCOUNT Contabilidade</title>
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

        h1, h2 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
        }

        h1 { font-size: clamp(2rem, 5vw, 3.2rem); margin-bottom: 0.8rem; }
        h2 { font-size: 1.25rem; margin-bottom: 1rem; }
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

        .btn-small { padding: 0.55rem 0.75rem; font-size: 0.72rem; white-space: nowrap; }

        .delete-confirm {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--danger);
            font-family: var(--font-titles);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            background: transparent;
            white-space: nowrap;
        }

        .delete-question {
            color: var(--text-muted);
            font-size: 0.78rem;
            white-space: nowrap;
        }

        .delete-confirm input {
            width: 16px;
            height: 16px;
            accent-color: var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .field { display: grid; gap: 0.4rem; }
        .field label { color: var(--text-muted); font-size: 0.85rem; font-weight: 700; }

        .field input,
        .field select {
            width: 100%;
            padding: 0.85rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: #0A0A0A;
            color: var(--text-white);
            font-family: var(--font-body);
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

        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.8rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { color: var(--text-white); font-family: var(--font-titles); font-size: 0.75rem; text-transform: uppercase; }
        .status-active { color: var(--primary); font-weight: 700; }
        .status-inactive { color: var(--danger); font-weight: 700; }
        .row-actions { display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap; }

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
                <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
                <a class="btn btn-outline" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
                <a class="btn btn-outline" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Empresas emissoras</h1>
            <p class="muted">Cadastro das empresas do grupo que poderão emitir notas fiscais (NF-e/NFS-e). Confira CNPJ, Inscrição Estadual, Inscrição Municipal e endereço com o documento oficial de inscrição antes de usar em produção.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <div class="notice warning">
            <strong>Fase 1:</strong> este cadastro ainda não emite notas de verdade. Certificado digital, transmissão para a SEFAZ (NF-e) e para o Portal Nacional da NFS-e ficam para a próxima etapa.
        </div>

        <section class="panel">
            <h2><?php echo $empresaEmEdicao ? 'Editando: ' . h($empresaEmEdicao['razao_social']) : 'Adicionar empresa'; ?></h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="acao" value="adicionar">
                <input type="hidden" name="empresa_id" value="<?php echo h((string) ($empresaEmEdicao['id'] ?? 0)); ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="razao_social">Razão social</label>
                        <input id="razao_social" name="razao_social" type="text" value="<?php echo h($empresaEmEdicao['razao_social'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="nome_fantasia">Nome fantasia</label>
                        <input id="nome_fantasia" name="nome_fantasia" type="text" value="<?php echo h($empresaEmEdicao['nome_fantasia'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cnpj">CNPJ</label>
                        <div class="row-actions">
                            <input id="cnpj" name="cnpj" type="text" placeholder="00.000.000/0000-00" value="<?php echo h($empresaEmEdicao['cnpj'] ?? ''); ?>" style="flex: 1;">
                            <button class="btn btn-outline btn-small" type="button" id="btnBuscarCnpj"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                        </div>
                        <span class="muted" id="statusBuscaCnpj" style="font-size: 0.78rem;"></span>
                    </div>
                    <div class="field">
                        <label for="inscricao_estadual">Inscrição Estadual <a href="https://www.sintegra.gov.br" target="_blank" rel="noopener" class="muted" style="font-weight:400; text-decoration:underline;">(consultar no Sintegra)</a></label>
                        <input id="inscricao_estadual" name="inscricao_estadual" type="text" value="<?php echo h($empresaEmEdicao['inscricao_estadual'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="inscricao_municipal">Inscrição Municipal</label>
                        <input id="inscricao_municipal" name="inscricao_municipal" type="text" value="<?php echo h($empresaEmEdicao['inscricao_municipal'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="crt">Regime tributário (CRT)</label>
                        <select id="crt" name="crt">
                            <option value="">Selecione</option>
                            <?php $crtAtual = $empresaEmEdicao !== null && $empresaEmEdicao['crt'] !== null ? (int) $empresaEmEdicao['crt'] : null; ?>
                            <option value="1" <?php echo $crtAtual === 1 ? 'selected' : ''; ?>>1 - Simples Nacional</option>
                            <option value="2" <?php echo $crtAtual === 2 ? 'selected' : ''; ?>>2 - Simples Nacional (excesso)</option>
                            <option value="3" <?php echo $crtAtual === 3 ? 'selected' : ''; ?>>3 - Regime Normal</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="logradouro">Logradouro</label>
                        <input id="logradouro" name="logradouro" type="text" value="<?php echo h($empresaEmEdicao['logradouro'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="numero">Número</label>
                        <input id="numero" name="numero" type="text" value="<?php echo h($empresaEmEdicao['numero'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="complemento">Complemento</label>
                        <input id="complemento" name="complemento" type="text" value="<?php echo h($empresaEmEdicao['complemento'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="bairro">Bairro</label>
                        <input id="bairro" name="bairro" type="text" value="<?php echo h($empresaEmEdicao['bairro'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cep">CEP</label>
                        <input id="cep" name="cep" type="text" placeholder="00000-000" value="<?php echo h($empresaEmEdicao['cep'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="municipio">Município</label>
                        <input id="municipio" name="municipio" type="text" value="<?php echo h($empresaEmEdicao['municipio'] ?? 'Belo Horizonte'); ?>">
                    </div>
                    <div class="field">
                        <label for="codigo_ibge_municipio">Código IBGE do município</label>
                        <input id="codigo_ibge_municipio" name="codigo_ibge_municipio" type="text" placeholder="3106200" value="<?php echo h($empresaEmEdicao['codigo_ibge_municipio'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="uf">UF</label>
                        <input id="uf" name="uf" type="text" maxlength="2" value="<?php echo h($empresaEmEdicao['uf'] ?? 'MG'); ?>">
                    </div>
                    <div class="field">
                        <label for="ambiente_emissao">Ambiente de emissão</label>
                        <select id="ambiente_emissao" name="ambiente_emissao">
                            <option value="homologacao" <?php echo ($empresaEmEdicao['ambiente_emissao'] ?? 'homologacao') === 'homologacao' ? 'selected' : ''; ?>>Homologação (testes)</option>
                            <option value="producao" <?php echo ($empresaEmEdicao['ambiente_emissao'] ?? '') === 'producao' ? 'selected' : ''; ?>>Produção</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <div class="row-actions">
                            <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                            <?php if ($empresaEmEdicao): ?>
                                <a class="btn btn-outline" href="notas-empresas-emissoras">Cancelar edição</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Empresas cadastradas</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Razão social</th>
                            <th>CNPJ</th>
                            <th>IE / IM</th>
                            <th>Município/UF</th>
                            <th>CRT</th>
                            <th>Ambiente</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $empresa): ?>
                            <tr>
                                <td><?php echo h($empresa['razao_social']); ?><?php if (($empresa['nome_fantasia'] ?? '') !== ''): ?><br><span class="muted"><?php echo h($empresa['nome_fantasia']); ?></span><?php endif; ?></td>
                                <td><?php echo h($empresa['cnpj'] ?? '—'); ?></td>
                                <td><?php echo h(($empresa['inscricao_estadual'] ?? '—') . ' / ' . ($empresa['inscricao_municipal'] ?? '—')); ?></td>
                                <td><?php echo h(($empresa['municipio'] ?? '—') . '/' . ($empresa['uf'] ?? '')); ?></td>
                                <td><?php echo h(rotuloCrt($empresa['crt'] !== null ? (int) $empresa['crt'] : null)); ?></td>
                                <td><?php echo h($empresa['ambiente_emissao'] === 'producao' ? 'Produção' : 'Homologação'); ?></td>
                                <td>
                                    <?php if ((int) $empresa['ativo'] === 1): ?>
                                        <span class="status-active">Ativa</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-outline" href="notas-empresas-emissoras?editar=<?php echo h((string) $empresa['id']); ?>#razao_social"><i class="fa-solid fa-pen"></i> Editar</a>
                                        <?php if ((int) $empresa['ativo'] === 1): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="empresa_id" value="<?php echo h((string) $empresa['id']); ?>">
                                                <input type="hidden" name="acao" value="desativar">
                                                <button class="btn btn-danger" type="submit"><i class="fa-solid fa-ban"></i> Desativar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="empresa_id" value="<?php echo h((string) $empresa['id']); ?>">
                                                <input type="hidden" name="acao" value="reativar">
                                                <button class="btn btn-outline" type="submit"><i class="fa-solid fa-rotate-left"></i> Reativar</button>
                                            </form>
                                            <form method="post" class="row-actions">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="empresa_id" value="<?php echo h((string) $empresa['id']); ?>">
                                                <input type="hidden" name="acao" value="excluir">
                                                <span class="delete-question">Excluir de vez?</span>
                                                <label class="delete-confirm" title="Confirmar exclusão definitiva (o catálogo de produtos/serviços dessa empresa também é apagado; notas fiscais já emitidas impedem a exclusão)">
                                                    <input type="checkbox" name="confirmar_exclusao" value="sim">
                                                    SIM
                                                </label>
                                                <button class="btn btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Excluir</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        function formatarCnpj(valor) {
            const digitos = (valor || '').replace(/\D/g, '').slice(0, 14);
            let resultado = digitos;
            if (digitos.length > 12) {
                resultado = digitos.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})$/, '$1.$2.$3/$4-$5').replace(/-$/, '');
            } else if (digitos.length > 8) {
                resultado = digitos.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})$/, '$1.$2.$3/$4');
            } else if (digitos.length > 5) {
                resultado = digitos.replace(/^(\d{2})(\d{3})(\d{0,3})$/, '$1.$2.$3');
            } else if (digitos.length > 2) {
                resultado = digitos.replace(/^(\d{2})(\d{0,3})$/, '$1.$2');
            }
            return resultado;
        }

        function formatarCep(valor) {
            const digitos = (valor || '').replace(/\D/g, '').slice(0, 8);
            if (digitos.length > 5) {
                return digitos.replace(/^(\d{5})(\d{0,3})$/, '$1-$2');
            }
            return digitos;
        }

        function aplicarMascara(idCampo, funcaoFormatar) {
            const campo = document.getElementById(idCampo);
            if (!campo) {
                return;
            }
            campo.value = funcaoFormatar(campo.value);
            campo.addEventListener('input', function () {
                const posicaoOriginal = campo.selectionStart;
                const tamanhoAntes = campo.value.length;
                campo.value = funcaoFormatar(campo.value);
                const diferenca = campo.value.length - tamanhoAntes;
                if (posicaoOriginal !== null) {
                    campo.setSelectionRange(posicaoOriginal + diferenca, posicaoOriginal + diferenca);
                }
            });
        }

        aplicarMascara('cnpj', formatarCnpj);
        aplicarMascara('cep', formatarCep);

        const btnBuscarCnpj = document.getElementById('btnBuscarCnpj');
        if (btnBuscarCnpj) {
            btnBuscarCnpj.addEventListener('click', function () {
                const campoCnpj = document.getElementById('cnpj');
                const statusEl = document.getElementById('statusBuscaCnpj');
                const digitos = (campoCnpj.value || '').replace(/\D/g, '');

                if (digitos.length !== 14) {
                    statusEl.textContent = 'Informe um CNPJ com 14 dígitos antes de buscar.';
                    statusEl.style.color = '#FFD1CE';
                    return;
                }

                statusEl.textContent = 'Buscando...';
                statusEl.style.color = '';
                btnBuscarCnpj.disabled = true;

                fetch('buscar-cnpj?cnpj=' + digitos)
                    .then(function (resposta) { return resposta.json().then(function (dados) { return { ok: resposta.ok, dados: dados }; }); })
                    .then(function (resultado) {
                        if (!resultado.ok) {
                            statusEl.textContent = resultado.dados.erro || 'Não foi possível buscar o CNPJ.';
                            statusEl.style.color = '#FFD1CE';
                            return;
                        }

                        const dados = resultado.dados;
                        document.getElementById('razao_social').value = dados.razao_social || '';
                        document.getElementById('nome_fantasia').value = dados.nome_fantasia || '';
                        document.getElementById('logradouro').value = dados.logradouro || '';
                        document.getElementById('numero').value = dados.numero || '';
                        document.getElementById('complemento').value = dados.complemento || '';
                        document.getElementById('bairro').value = dados.bairro || '';
                        document.getElementById('cep').value = formatarCep(dados.cep || '');
                        document.getElementById('municipio').value = dados.municipio || '';
                        document.getElementById('codigo_ibge_municipio').value = dados.codigo_ibge_municipio || '';
                        document.getElementById('uf').value = dados.uf || '';
                        if (dados.crt_sugerido) {
                            document.getElementById('crt').value = String(dados.crt_sugerido);
                        }

                        statusEl.style.color = 'var(--primary)';
                        statusEl.textContent = 'Dados preenchidos (' + (dados.situacao_cadastral || 'situação não informada') + '). Confira antes de salvar.';
                    })
                    .catch(function () {
                        statusEl.textContent = 'Erro ao buscar o CNPJ. Tente novamente.';
                        statusEl.style.color = '#FFD1CE';
                    })
                    .finally(function () {
                        btnBuscarCnpj.disabled = false;
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
