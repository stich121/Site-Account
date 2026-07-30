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
$nivelAcesso = atualizarNivelAcessoSessao(obterConexao(), $funcionarioId);
$podeAdministrar = $nivelAcesso >= 3;

// Cadastrar/editar empresa emissora agora é liberado pra qualquer funcionário com acesso à área
// fiscal (mesma checagem de permite_notas_fiscais usada em notas-fiscais.php/notas-clientes.php),
// não só administradores. Desativar/reativar/excluir continuam exigindo nível de administrador
// (checado mais abaixo, dentro de cada ação) por serem ações mais delicadas.
$dbFuncionarios = obterConexao();
if (!schemaJaPreparada('funcionarios_permite_notas_fiscais')) {
    prepararColunaPermiteNotasFiscais($dbFuncionarios);
    marcarSchemaPreparada('funcionarios_permite_notas_fiscais');
}
$stmtPermissao = $dbFuncionarios->prepare('SELECT permite_notas_fiscais FROM funcionarios WHERE id = :id LIMIT 1');
$stmtPermissao->execute(['id' => $funcionarioId]);
$permiteNotas = (int) ($stmtPermissao->fetchColumn() ?: 0) === 1;

if (!$permiteNotas) {
    header('Location: painel');
    exit;
}

$erro = '';
$sucesso = '';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function prepararColunaPermiteNotasFiscais(PDO $db): void
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'permite_notas_fiscais'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE funcionarios ADD COLUMN permite_notas_fiscais TINYINT(1) NOT NULL DEFAULT 1 AFTER permite_ponto');
    }
}

function cnpjEmpresaValido(string $documento): bool
{
    $numero = preg_replace('/\D+/', '', $documento);
    if (strlen($numero) !== 14 || preg_match('/^(\d)\1+$/', $numero)) return false;
    foreach ([12, 13] as $posicao) {
        $soma = 0; $peso = $posicao === 12 ? 5 : 6;
        for ($i = 0; $i < $posicao; $i++) { $soma += (int) $numero[$i] * $peso; $peso = $peso === 2 ? 9 : $peso - 1; }
        $digito = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
        if ((int) $numero[$posicao] !== $digito) return false;
    }
    return true;
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
            nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1,
            nfse_regime_apuracao_sn TINYINT UNSIGNED NULL,
            nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel',
            nfse_regime_especial_tributacao VARCHAR(60) NULL,
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
        'nfse_opcao_simples_nacional' => "ALTER TABLE empresas_emissoras ADD COLUMN nfse_opcao_simples_nacional TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER ambiente_emissao",
        'nfse_regime_apuracao_sn' => "ALTER TABLE empresas_emissoras ADD COLUMN nfse_regime_apuracao_sn TINYINT UNSIGNED NULL AFTER nfse_opcao_simples_nacional",
        'nfse_tributacao_issqn' => "ALTER TABLE empresas_emissoras ADD COLUMN nfse_tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel' AFTER nfse_regime_apuracao_sn",
        'nfse_regime_especial_tributacao' => "ALTER TABLE empresas_emissoras ADD COLUMN nfse_regime_especial_tributacao VARCHAR(60) NULL AFTER nfse_tributacao_issqn",
        'certificado_arquivo' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER nfse_regime_apuracao_sn",
        'certificado_senha_cifrada' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo",
        'certificado_atualizado_em' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada",
        'certificado_atualizado_por' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em",
        'certificado_validade' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_validade DATE NULL AFTER certificado_atualizado_por",
        'nfe_serie' => "ALTER TABLE empresas_emissoras ADD COLUMN nfe_serie VARCHAR(3) NOT NULL DEFAULT '1' AFTER crt",
        'nfe_numero_base' => "ALTER TABLE empresas_emissoras ADD COLUMN nfe_numero_base INT UNSIGNED NOT NULL DEFAULT 0 AFTER nfe_serie",
    ];

    foreach ($campos as $coluna => $sql) {
        if (!colunaExisteEmpresasEmissoras($db, $coluna)) {
            $db->exec($sql);
        }
    }
}

function semearEmpresasEmissoras(PDO $db): void
{
    // So semeia se a tabela estiver vazia: rodar isso em toda carga de pagina, casando por
    // razao_social, recriava uma empresa "padrao" em branco sempre que alguem renomeava
    // (ex.: editar "Smarky" para o nome fantasia completo fazia surgir um "Smarky" novo vazio).
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

try {
    $db = obterConexaoNotas();
    if (!schemaJaPreparada('notas_empresas_emissoras')) {
        prepararTabelaEmpresasEmissoras($db);
        prepararColunasCertificadoEmpresaEmissoras($db);
        marcarSchemaPreparada('notas_empresas_emissoras');
    }
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
            $nfeSerie = trim((string) ($_POST['nfe_serie'] ?? '')) !== '' ? trim((string) $_POST['nfe_serie']) : '1';
            $nfeNumeroBase = (int) ($_POST['nfe_numero_base'] ?? 0);
            $ambiente = ($_POST['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';
            $opcaoSimplesNacional = (int) ($_POST['nfse_opcao_simples_nacional'] ?? 0);
            $regimeApuracaoSn = trim((string) ($_POST['nfse_regime_apuracao_sn'] ?? '')) !== '' ? (int) $_POST['nfse_regime_apuracao_sn'] : null;
            $tributacaoIssqn = trim((string) ($_POST['nfse_tributacao_issqn'] ?? ''));
            $regimeEspecialTributacao = trim((string) ($_POST['nfse_regime_especial_tributacao'] ?? ''));

            $cnpjNumeros = preg_replace('/\D+/', '', $cnpj);
            $cepNumeros = preg_replace('/\D+/', '', $cep);
            if ($razaoSocial === '') {
                $erro = 'Informe a razão social da empresa.';
            } elseif (!cnpjEmpresaValido($cnpjNumeros)) {
                $erro = 'Informe um CNPJ válido para a empresa emissora.';
            } elseif ($inscricaoMunicipal === '') {
                $erro = 'Informe a Inscrição Municipal da empresa emissora.';
            } elseif ($logradouro === '' || $numero === '' || $bairro === '' || $municipio === '') {
                $erro = 'Preencha o endereço fiscal completo da empresa.';
            } elseif (strlen($cepNumeros) !== 8 || !preg_match('/^\d{7}$/', $codigoIbge) || !preg_match('/^[A-Z]{2}$/', $uf)) {
                $erro = 'Confira CEP (8 dígitos), código IBGE (7 dígitos) e UF.';
            } elseif (!in_array($crt, [1, 2, 3, 4], true)) {
                $erro = 'Selecione o CRT da empresa.';
            } elseif (!preg_match('/^[0-9]{1,3}$/D', $nfeSerie)) {
                $erro = 'A série da NF-e deve conter de 1 a 3 dígitos.';
            } elseif ($nfeNumeroBase < 0) {
                $erro = 'O número da última NF-e emitida não pode ser negativo.';
            } elseif (!in_array($opcaoSimplesNacional, [1, 2, 3], true)) {
                $erro = 'Informe a opção da empresa pelo Simples Nacional para a NFS-e.';
            } elseif ($opcaoSimplesNacional === 3 && !in_array($regimeApuracaoSn, [1, 2, 3], true)) {
                $erro = 'Para ME/EPP do Simples, informe o regime de apuração da NFS-e.';
            } elseif ($opcaoSimplesNacional !== 3 && $regimeApuracaoSn !== null) {
                $erro = 'O regime de apuração do Simples só se aplica à opção ME/EPP.';
            } elseif (!in_array($tributacaoIssqn, ['operacao_tributavel', 'imune', 'exportacao', 'nao_incidencia'], true)) {
                $erro = 'Selecione a tributação municipal padrão da NFS-e.';
            } elseif (!in_array($regimeEspecialTributacao, ['', 'cooperativa', 'estimativa', 'microempresa_municipal', 'notario_registrador', 'profissional_autonomo', 'sociedade_profissionais'], true)) {
                $erro = 'Selecione um regime especial de tributação válido.';
            } elseif (in_array($opcaoSimplesNacional, [2, 3], true) && $regimeEspecialTributacao !== '') {
                $erro = 'MEI e ME/EPP do Simples não podem acumular outro regime especial na NFS-e.';
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
                        nfe_serie = :nfe_serie,
                        nfe_numero_base = :nfe_numero_base,
                        ambiente_emissao = :ambiente_emissao,
                        nfse_opcao_simples_nacional = :nfse_opcao_simples_nacional,
                        nfse_regime_apuracao_sn = :nfse_regime_apuracao_sn,
                        nfse_tributacao_issqn = :nfse_tributacao_issqn,
                        nfse_regime_especial_tributacao = :nfse_regime_especial_tributacao
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
                        'nfe_serie' => $nfeSerie,
                        'nfe_numero_base' => $nfeNumeroBase,
                        'ambiente_emissao' => $ambiente,
                        'nfse_opcao_simples_nacional' => $opcaoSimplesNacional,
                        'nfse_regime_apuracao_sn' => $regimeApuracaoSn,
                        'nfse_tributacao_issqn' => $tributacaoIssqn,
                        'nfse_regime_especial_tributacao' => $regimeEspecialTributacao !== '' ? $regimeEspecialTributacao : null,
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
                        crt, nfe_serie, nfe_numero_base, ambiente_emissao, nfse_opcao_simples_nacional, nfse_regime_apuracao_sn, nfse_tributacao_issqn, nfse_regime_especial_tributacao, ativo
                     ) VALUES (
                        :razao_social, :nome_fantasia, :cnpj, :inscricao_estadual, :inscricao_municipal,
                        :logradouro, :numero, :complemento, :bairro, :cep, :municipio, :codigo_ibge_municipio, :uf,
                        :crt, :nfe_serie, :nfe_numero_base, :ambiente_emissao, :nfse_opcao_simples_nacional, :nfse_regime_apuracao_sn, :nfse_tributacao_issqn, :nfse_regime_especial_tributacao, 1
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
                        nfe_serie = VALUES(nfe_serie),
                        nfe_numero_base = VALUES(nfe_numero_base),
                        ambiente_emissao = VALUES(ambiente_emissao),
                        nfse_opcao_simples_nacional = VALUES(nfse_opcao_simples_nacional),
                        nfse_regime_apuracao_sn = VALUES(nfse_regime_apuracao_sn),
                        nfse_tributacao_issqn = VALUES(nfse_tributacao_issqn),
                        nfse_regime_especial_tributacao = VALUES(nfse_regime_especial_tributacao),
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
                    'nfe_serie' => $nfeSerie,
                    'nfe_numero_base' => $nfeNumeroBase,
                    'ambiente_emissao' => $ambiente,
                    'nfse_opcao_simples_nacional' => $opcaoSimplesNacional,
                    'nfse_regime_apuracao_sn' => $regimeApuracaoSn,
                    'nfse_tributacao_issqn' => $tributacaoIssqn,
                    'nfse_regime_especial_tributacao' => $regimeEspecialTributacao !== '' ? $regimeEspecialTributacao : null,
                ]);

                $sucesso = 'Empresa emissora salva com sucesso.';
            }
        } elseif (($_POST['acao'] ?? '') === 'desativar' && !$podeAdministrar) {
            $erro = 'Só administradores podem desativar uma empresa emissora.';
        } elseif (($_POST['acao'] ?? '') === 'desativar') {
            $id = (int) ($_POST['empresa_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE empresas_emissoras SET ativo = 0 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Empresa desativada. Ela deixa de aparecer para escolha em novas notas.';
            }
        } elseif (($_POST['acao'] ?? '') === 'reativar' && !$podeAdministrar) {
            $erro = 'Só administradores podem reativar uma empresa emissora.';
        } elseif (($_POST['acao'] ?? '') === 'reativar') {
            $id = (int) ($_POST['empresa_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE empresas_emissoras SET ativo = 1 WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $sucesso = 'Empresa reativada com sucesso.';
            }
        } elseif (($_POST['acao'] ?? '') === 'excluir' && !$podeAdministrar) {
            $erro = 'Só administradores podem excluir uma empresa emissora.';
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
                crt, nfe_serie, nfe_numero_base, ambiente_emissao, nfse_opcao_simples_nacional, nfse_regime_apuracao_sn, nfse_tributacao_issqn, nfse_regime_especial_tributacao, ativo
         FROM empresas_emissoras
         ORDER BY ativo DESC, razao_social ASC'
    );
    $empresas = $stmt->fetchAll();

    // Ultima NF-e lancada por empresa (na serie atual dela). "ultima_nfe_sistema" e o que
    // ja foi emitido por aqui; "ultima_nfe_efetiva" tambem considera o ajuste manual
    // (nfe_numero_base), que serve de piso quando ha numeracao emitida fora do sistema.
    // A proxima nota usa sempre o maior dos dois + 1, entao ela acompanha automaticamente
    // tanto as emissoes pelo sistema quanto um novo ajuste manual.
    try {
        $stmtUltimaNfe = $db->prepare(
            "SELECT MAX(numero_interno) FROM notas_fiscais WHERE empresa_emissora_id = :empresa_id AND tipo_nota = 'nfe' AND serie = :serie"
        );
        foreach ($empresas as &$empresaUltimaNfe) {
            $stmtUltimaNfe->execute([
                'empresa_id' => $empresaUltimaNfe['id'],
                'serie' => (string) ($empresaUltimaNfe['nfe_serie'] ?? '1'),
            ]);
            $ultimaSistema = $stmtUltimaNfe->fetchColumn();
            $empresaUltimaNfe['ultima_nfe_sistema'] = $ultimaSistema !== false && $ultimaSistema !== null ? (int) $ultimaSistema : null;
            $empresaUltimaNfe['ultima_nfe_efetiva'] = max((int) ($empresaUltimaNfe['ultima_nfe_sistema'] ?? 0), (int) $empresaUltimaNfe['nfe_numero_base']);
            if ($empresaUltimaNfe['ultima_nfe_efetiva'] === 0) {
                $empresaUltimaNfe['ultima_nfe_efetiva'] = null;
            }
        }
        unset($empresaUltimaNfe);
    } catch (PDOException $e) {
        foreach ($empresas as &$empresaUltimaNfe) {
            $empresaUltimaNfe['ultima_nfe_sistema'] = null;
            $empresaUltimaNfe['ultima_nfe_efetiva'] = (int) $empresaUltimaNfe['nfe_numero_base'] > 0 ? (int) $empresaUltimaNfe['nfe_numero_base'] : null;
        }
        unset($empresaUltimaNfe);
    }

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
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'empresas'; include __DIR__ . '/includes/notas-nav.php'; ?>

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
                <h3><i class="fa-solid fa-id-card"></i> Identificação</h3>
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
                            <input id="cnpj" name="cnpj" type="text" maxlength="18" required placeholder="00.000.000/0000-00" value="<?php echo h($empresaEmEdicao['cnpj'] ?? ''); ?>" style="flex: 1;">
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
                        <input id="inscricao_municipal" name="inscricao_municipal" type="text" maxlength="30" required value="<?php echo h($empresaEmEdicao['inscricao_municipal'] ?? ''); ?>">
                    </div>
                </div>

                <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-scale-balanced"></i> Tributação e regime NFS-e</h3>
                <div class="form-grid">
                    <div class="field">
                        <label for="crt">Regime tributário (CRT)</label>
                        <select id="crt" name="crt">
                            <option value="">Selecione</option>
                            <?php $crtAtual = $empresaEmEdicao !== null && $empresaEmEdicao['crt'] !== null ? (int) $empresaEmEdicao['crt'] : null; ?>
                            <option value="1" <?php echo $crtAtual === 1 ? 'selected' : ''; ?>>1 - Simples Nacional</option>
                            <option value="2" <?php echo $crtAtual === 2 ? 'selected' : ''; ?>>2 - Simples Nacional (excesso)</option>
                            <option value="3" <?php echo $crtAtual === 3 ? 'selected' : ''; ?>>3 - Regime Normal</option>
                            <option value="4" <?php echo $crtAtual === 4 ? 'selected' : ''; ?>>4 - MEI</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nfe_serie">Série da NF-e</label>
                        <input id="nfe_serie" name="nfe_serie" type="text" maxlength="3" value="<?php echo h((string) ($empresaEmEdicao['nfe_serie'] ?? '1')); ?>">
                    </div>
                    <div class="field">
                        <label for="nfe_numero_base">
                            Última NF-e emitida (ajuste manual)
                            <span class="info-tooltip-wrap">
                                <button type="button" class="info-btn" data-info-target="infoNfeNumeroBase" aria-expanded="false" aria-label="Mais informações sobre este campo">
                                    <i class="fa-solid fa-info"></i>
                                </button>
                                <span class="info-tooltip-box" id="infoNfeNumeroBase" role="tooltip">
                                    Preencha aqui se já existir numeração emitida fora do sistema (ou para corrigir a sequência). A próxima NF-e emitida por aqui sempre usa o maior valor entre este ajuste e o que já foi lançado pelo sistema, mais 1 — depois disso a numeração segue sozinha.
                                </span>
                            </span>
                        </label>
                        <input id="nfe_numero_base" name="nfe_numero_base" type="number" min="0" step="1" value="<?php echo h((string) ($empresaEmEdicao['nfe_numero_base'] ?? 0)); ?>">
                        <?php if ($empresaEmEdicao !== null): ?>
                            <span class="muted">
                                Já lançado pelo sistema nesta série: <?php echo $empresaEmEdicao['ultima_nfe_sistema'] !== null ? 'Nº ' . h((string) $empresaEmEdicao['ultima_nfe_sistema']) : 'nenhuma ainda'; ?>.
                                Próxima NF-e será a Nº <?php echo h((string) ((int) ($empresaEmEdicao['ultima_nfe_efetiva'] ?? 0) + 1)); ?>.
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="nfse_opcao_simples_nacional">
                            Opção pelo Simples na NFS-e
                            <span class="info-tooltip-wrap">
                                <button type="button" class="info-btn" data-info-target="infoNfseOpcaoSimples" aria-expanded="false" aria-label="Mais informações sobre este campo">
                                    <i class="fa-solid fa-info"></i>
                                </button>
                                <span class="info-tooltip-box" id="infoNfseOpcaoSimples" role="tooltip">
                                    Classificação específica da NFS-e; não é inferida pelo CRT.
                                </span>
                            </span>
                        </label>
                        <?php $opSnAtual = (int) ($empresaEmEdicao['nfse_opcao_simples_nacional'] ?? 1); ?>
                        <select id="nfse_opcao_simples_nacional" name="nfse_opcao_simples_nacional" required>
                            <option value="1" <?php echo $opSnAtual === 1 ? 'selected' : ''; ?>>1 - Não optante</option>
                            <option value="2" <?php echo $opSnAtual === 2 ? 'selected' : ''; ?>>2 - MEI</option>
                            <option value="3" <?php echo $opSnAtual === 3 ? 'selected' : ''; ?>>3 - ME/EPP optante</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nfse_regime_apuracao_sn">
                            Regime de apuração do Simples
                            <span class="info-tooltip-wrap">
                                <button type="button" class="info-btn" data-info-target="infoNfseRegimeApuracao" aria-expanded="false" aria-label="Mais informações sobre este campo">
                                    <i class="fa-solid fa-info"></i>
                                </button>
                                <span class="info-tooltip-box" id="infoNfseRegimeApuracao" role="tooltip">
                                    Obrigatório somente para ME/EPP optante (opção 3).
                                </span>
                            </span>
                        </label>
                        <?php $regSnAtual = $empresaEmEdicao['nfse_regime_apuracao_sn'] ?? null; ?>
                        <select id="nfse_regime_apuracao_sn" name="nfse_regime_apuracao_sn">
                            <option value="">Não se aplica</option>
                            <option value="1" <?php echo (int) $regSnAtual === 1 ? 'selected' : ''; ?>>1 - Tributos federais e ISSQN pelo Simples</option>
                            <option value="2" <?php echo (int) $regSnAtual === 2 ? 'selected' : ''; ?>>2 - Federais pelo Simples e ISSQN pelo regime normal</option>
                            <option value="3" <?php echo (int) $regSnAtual === 3 ? 'selected' : ''; ?>>3 - Apuração dos tributos pelo Simples (MEI)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nfse_tributacao_issqn">Tributação municipal padrão</label>
                        <?php $tributacaoIssqnAtual = $empresaEmEdicao['nfse_tributacao_issqn'] ?? 'operacao_tributavel'; ?>
                        <select id="nfse_tributacao_issqn" name="nfse_tributacao_issqn" required>
                            <option value="operacao_tributavel" <?php echo $tributacaoIssqnAtual === 'operacao_tributavel' ? 'selected' : ''; ?>>Operação tributável</option>
                            <option value="imune" <?php echo $tributacaoIssqnAtual === 'imune' ? 'selected' : ''; ?>>Imunidade</option>
                            <option value="exportacao" <?php echo $tributacaoIssqnAtual === 'exportacao' ? 'selected' : ''; ?>>Exportação de serviço</option>
                            <option value="nao_incidencia" <?php echo $tributacaoIssqnAtual === 'nao_incidencia' ? 'selected' : ''; ?>>Não incidência</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nfse_regime_especial_tributacao">Regime especial de tributação</label>
                        <?php $regimeEspecialAtual = $empresaEmEdicao['nfse_regime_especial_tributacao'] ?? ''; ?>
                        <select id="nfse_regime_especial_tributacao" name="nfse_regime_especial_tributacao">
                            <option value="">Nenhum</option>
                            <option value="cooperativa" <?php echo $regimeEspecialAtual === 'cooperativa' ? 'selected' : ''; ?>>Cooperativa</option>
                            <option value="estimativa" <?php echo $regimeEspecialAtual === 'estimativa' ? 'selected' : ''; ?>>Estimativa</option>
                            <option value="microempresa_municipal" <?php echo $regimeEspecialAtual === 'microempresa_municipal' ? 'selected' : ''; ?>>Microempresa municipal</option>
                            <option value="notario_registrador" <?php echo $regimeEspecialAtual === 'notario_registrador' ? 'selected' : ''; ?>>Notário ou registrador</option>
                            <option value="profissional_autonomo" <?php echo $regimeEspecialAtual === 'profissional_autonomo' ? 'selected' : ''; ?>>Profissional autônomo</option>
                            <option value="sociedade_profissionais" <?php echo $regimeEspecialAtual === 'sociedade_profissionais' ? 'selected' : ''; ?>>Sociedade de profissionais</option>
                        </select>
                    </div>
                </div>

                <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-location-dot"></i> Endereço</h3>
                <div class="form-grid">
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
                        <input id="codigo_ibge_municipio" name="codigo_ibge_municipio" type="text" inputmode="numeric" pattern="\d{7}" maxlength="7" required placeholder="3106200" value="<?php echo h($empresaEmEdicao['codigo_ibge_municipio'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="uf">UF</label>
                        <input id="uf" name="uf" type="text" maxlength="2" value="<?php echo h($empresaEmEdicao['uf'] ?? 'MG'); ?>">
                    </div>
                </div>

                <h3 style="margin-top: 1.5rem;"><i class="fa-solid fa-server"></i> Emissão</h3>
                <div class="form-grid">
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
                            <th>Série NF-e / última emitida</th>
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
                                <td><?php echo h((string) ($empresa['nfe_serie'] ?? '1')); ?> / <?php echo $empresa['ultima_nfe_efetiva'] !== null ? 'Nº ' . h((string) $empresa['ultima_nfe_efetiva']) : '—'; ?></td>
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
                                        <?php if ($podeAdministrar && (int) $empresa['ativo'] === 1): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="empresa_id" value="<?php echo h((string) $empresa['id']); ?>">
                                                <input type="hidden" name="acao" value="desativar">
                                                <button class="btn btn-danger" type="submit"><i class="fa-solid fa-ban"></i> Desativar</button>
                                            </form>
                                        <?php elseif ($podeAdministrar): ?>
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

        document.querySelectorAll('.info-btn').forEach(function (botaoInfo) {
            const caixaInfo = document.getElementById(botaoInfo.dataset.infoTarget);
            if (!caixaInfo) return;
            botaoInfo.addEventListener('click', function (evento) {
                evento.stopPropagation();
                const jaAberta = caixaInfo.classList.contains('aberto');
                document.querySelectorAll('.info-tooltip-box.aberto').forEach(function (outra) {
                    outra.classList.remove('aberto');
                });
                document.querySelectorAll('.info-btn[aria-expanded="true"]').forEach(function (outroBotao) {
                    outroBotao.setAttribute('aria-expanded', 'false');
                });
                if (!jaAberta) {
                    caixaInfo.classList.add('aberto');
                    botaoInfo.setAttribute('aria-expanded', 'true');
                }
            });
        });
        document.addEventListener('click', function (evento) {
            if (evento.target.closest('.info-tooltip-wrap')) return;
            document.querySelectorAll('.info-tooltip-box.aberto').forEach(function (caixa) {
                caixa.classList.remove('aberto');
            });
            document.querySelectorAll('.info-btn[aria-expanded="true"]').forEach(function (botao) {
                botao.setAttribute('aria-expanded', 'false');
            });
        });

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

        const opcaoSimplesNfse = document.getElementById('nfse_opcao_simples_nacional');
        const regimeEspecialNfse = document.getElementById('nfse_regime_especial_tributacao');
        function atualizarRegimeEspecialNfse() {
            if (!opcaoSimplesNfse || !regimeEspecialNfse) return;
            const simplesPrevalece = opcaoSimplesNfse.value === '2' || opcaoSimplesNfse.value === '3';
            if (simplesPrevalece) regimeEspecialNfse.value = '';
            regimeEspecialNfse.disabled = simplesPrevalece;
        }
        if (opcaoSimplesNfse) {
            opcaoSimplesNfse.addEventListener('change', atualizarRegimeEspecialNfse);
            atualizarRegimeEspecialNfse();
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
