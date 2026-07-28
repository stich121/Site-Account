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

function prepararTabelaNotasFiscaisNfse(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_fiscais_nfse (
            nota_id BIGINT UNSIGNED NOT NULL,
            data_competencia DATE NOT NULL,
            serie_dps VARCHAR(5) NULL,
            numero_dps VARCHAR(15) NULL,
            tomador_local ENUM('nao_informado','brasil','exterior') NOT NULL DEFAULT 'nao_informado',
            tomador_indicador_municipal VARCHAR(20) NULL,
            tomador_telefone VARCHAR(20) NULL,
            intermediario_incluido TINYINT(1) NOT NULL DEFAULT 0,
            intermediario_local ENUM('nao_informado','brasil') NOT NULL DEFAULT 'nao_informado',
            intermediario_cpf_cnpj VARCHAR(20) NULL,
            intermediario_nome VARCHAR(180) NULL,
            pais_prestacao VARCHAR(60) NOT NULL DEFAULT 'Brasil',
            municipio_prestacao VARCHAR(120) NULL,
            codigo_tributacao_nacional VARCHAR(20) NULL,
            codigo_tributacao_municipal VARCHAR(20) NULL,
            imune_exportacao_nao_incidencia ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            item_nbs VARCHAR(20) NULL,
            descricao_servico TEXT NULL,
            documento_responsabilidade_tecnica VARCHAR(60) NULL,
            documento_referencia VARCHAR(255) NULL,
            informacoes_complementares TEXT NULL,
            numero_pedido_b2b VARCHAR(120) NULL,
            valor_recebido_intermediario DECIMAL(12,2) NULL,
            desconto_incondicionado DECIMAL(12,2) NULL,
            desconto_condicionado DECIMAL(12,2) NULL,
            tributacao_issqn VARCHAR(40) NOT NULL DEFAULT 'operacao_tributavel',
            regime_especial_tributacao VARCHAR(60) NULL,
            exigibilidade_issqn_suspensa ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            issqn_retido ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            issqn_retido_por ENUM('tomador','intermediario') NULL,
            beneficio_municipal ENUM('nao','sim') NOT NULL DEFAULT 'nao',
            deducao_reducao_base_calculo DECIMAL(12,2) NULL,
            situacao_tributaria_pis_cofins VARCHAR(10) NULL,
            tipo_retencao_pis_cofins_csll VARCHAR(10) NULL,
            irrf DECIMAL(12,2) NULL,
            contribuicoes_sociais_retidas DECIMAL(12,2) NULL,
            contribuicao_previdenciaria_retida DECIMAL(12,2) NULL,
            tributos_modo ENUM('valores','percentuais') NOT NULL DEFAULT 'percentuais',
            tributos_federal_percentual DECIMAL(6,3) NULL,
            tributos_estadual_percentual DECIMAL(6,3) NULL,
            tributos_municipal_percentual DECIMAL(6,3) NULL,
            tributos_federal_valor DECIMAL(12,2) NULL,
            tributos_estadual_valor DECIMAL(12,2) NULL,
            tributos_municipal_valor DECIMAL(12,2) NULL,
            PRIMARY KEY (nota_id),
            CONSTRAINT fk_notas_fiscais_nfse_nota
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

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararTabelaEmpresasEmissorasNotas($dbNotas);
    semearEmpresasEmissorasNotas($dbNotas);
    prepararTabelaNotasProdutosServicosNotas($dbNotas);
    prepararTabelaNotasClientes($dbNotas);
    prepararTabelaNotasFiscais($dbNotas);
    prepararTabelaNotasFiscaisItens($dbNotas);
    prepararTabelaNotasFiscaisNfse($dbNotas);
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

    if (empty($_SESSION['csrf_notas_emitir'])) {
        $_SESSION['csrf_notas_emitir'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_emitir'], $csrf)) {
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

            $itensValidos = [];
            $valorTotalNota = 0.0;
            $dadosNfse = null;

            if ($tipoNota === 'nfse') {
                $numerico = static function (string $campo): ?float {
                    $valor = trim((string) ($_POST[$campo] ?? ''));
                    return $valor !== '' ? (float) str_replace(',', '.', $valor) : null;
                };

                $dataCompetencia = trim($_POST['nfse_data_competencia'] ?? '') !== '' ? trim($_POST['nfse_data_competencia']) : $dataEmissao;
                $informarDps = isset($_POST['nfse_informar_dps']);
                $serieDps = $informarDps ? trim($_POST['nfse_serie_dps'] ?? '') : '';
                $numeroDps = $informarDps ? trim($_POST['nfse_numero_dps'] ?? '') : '';
                $tomadorLocal = in_array($_POST['nfse_tomador_local'] ?? '', ['brasil', 'exterior'], true) ? $_POST['nfse_tomador_local'] : 'nao_informado';
                $tomadorIndicadorMunicipal = trim($_POST['nfse_tomador_indicador_municipal'] ?? '');
                $tomadorTelefone = trim($_POST['nfse_tomador_telefone'] ?? '');
                $intermediarioIncluido = isset($_POST['nfse_intermediario_incluido']);
                $intermediarioLocal = $intermediarioIncluido && ($_POST['nfse_intermediario_local'] ?? '') === 'brasil' ? 'brasil' : 'nao_informado';
                $intermediarioCpfCnpj = $intermediarioIncluido ? trim($_POST['nfse_intermediario_cpf_cnpj'] ?? '') : '';
                $intermediarioNome = $intermediarioIncluido ? trim($_POST['nfse_intermediario_nome'] ?? '') : '';
                $paisPrestacao = trim($_POST['nfse_pais_prestacao'] ?? '') !== '' ? trim($_POST['nfse_pais_prestacao']) : 'Brasil';
                $municipioPrestacao = trim($_POST['nfse_municipio_prestacao'] ?? '');
                $codigoTributacaoNacional = trim($_POST['nfse_codigo_tributacao_nacional'] ?? '');
                $codigoTributacaoMunicipal = trim($_POST['nfse_codigo_tributacao_municipal'] ?? '');
                $imuneExportacao = ($_POST['nfse_imune_exportacao'] ?? '') === 'sim' ? 'sim' : 'nao';
                $itemNbs = trim($_POST['nfse_item_nbs'] ?? '');
                $descricaoServico = trim($_POST['nfse_descricao_servico'] ?? '');
                $documentoResponsabilidadeTecnica = trim($_POST['nfse_documento_responsabilidade_tecnica'] ?? '');
                $documentoReferencia = trim($_POST['nfse_documento_referencia'] ?? '');
                $informacoesComplementaresNfse = trim($_POST['nfse_informacoes_complementares'] ?? '');
                $numeroPedidoB2b = trim($_POST['nfse_numero_pedido_b2b'] ?? '');

                $valorServico = $numerico('nfse_valor_servico') ?? 0.0;
                $valorRecebidoIntermediario = $numerico('nfse_valor_recebido_intermediario');
                $descontoIncondicionado = $numerico('nfse_desconto_incondicionado');
                $descontoCondicionado = $numerico('nfse_desconto_condicionado');

                $tributacaoIssqn = trim($_POST['nfse_tributacao_issqn'] ?? '') !== '' ? trim($_POST['nfse_tributacao_issqn']) : 'operacao_tributavel';
                $regimeEspecialTributacao = trim($_POST['nfse_regime_especial_tributacao'] ?? '');
                $exigibilidadeSuspensa = ($_POST['nfse_exigibilidade_suspensa'] ?? '') === 'sim' ? 'sim' : 'nao';
                $issqnRetido = ($_POST['nfse_issqn_retido'] ?? '') === 'sim' ? 'sim' : 'nao';
                $issqnRetidoPor = $issqnRetido === 'sim' ? (($_POST['nfse_issqn_retido_por'] ?? '') === 'intermediario' ? 'intermediario' : 'tomador') : null;
                $beneficioMunicipal = ($_POST['nfse_beneficio_municipal'] ?? '') === 'sim' ? 'sim' : 'nao';
                $deducaoReducaoBase = $numerico('nfse_deducao_reducao_base');

                $situacaoTributariaPisCofins = trim($_POST['nfse_situacao_pis_cofins'] ?? '');
                $tipoRetencaoPisCofinsCsll = trim($_POST['nfse_tipo_retencao_pis_cofins_csll'] ?? '');
                $irrf = $numerico('nfse_irrf');
                $contribuicoesSociaisRetidas = $numerico('nfse_contribuicoes_sociais_retidas');
                $contribuicaoPrevidenciariaRetida = $numerico('nfse_contribuicao_previdenciaria_retida');

                $tributosModo = ($_POST['nfse_tributos_modo'] ?? '') === 'valores' ? 'valores' : 'percentuais';
                $tributosFederalPercentual = $numerico('nfse_tributos_federal_percentual');
                $tributosEstadualPercentual = $numerico('nfse_tributos_estadual_percentual');
                $tributosMunicipalPercentual = $numerico('nfse_tributos_municipal_percentual');
                $tributosFederalValor = $numerico('nfse_tributos_federal_valor');
                $tributosEstadualValor = $numerico('nfse_tributos_estadual_valor');
                $tributosMunicipalValor = $numerico('nfse_tributos_municipal_valor');

                if ($descricaoServico !== '' && $valorServico > 0) {
                    $itensValidos[] = [
                        'produto_servico_id' => null,
                        'descricao' => $descricaoServico,
                        'ncm' => null,
                        'cfop' => null,
                        'cst_csosn' => null,
                        'codigo_servico_municipal' => $codigoTributacaoMunicipal !== '' ? $codigoTributacaoMunicipal : ($codigoTributacaoNacional !== '' ? $codigoTributacaoNacional : null),
                        'unidade' => 'UN',
                        'quantidade' => 1,
                        'valor_unitario' => $valorServico,
                        'valor_total' => round($valorServico, 2),
                    ];
                    $valorTotalNota = round($valorServico - (float) $descontoIncondicionado - (float) $descontoCondicionado, 2);
                }

                $dadosNfse = [
                    'data_competencia' => $dataCompetencia,
                    'serie_dps' => $serieDps !== '' ? $serieDps : null,
                    'numero_dps' => $numeroDps !== '' ? $numeroDps : null,
                    'tomador_local' => $tomadorLocal,
                    'tomador_indicador_municipal' => $tomadorIndicadorMunicipal !== '' ? $tomadorIndicadorMunicipal : null,
                    'tomador_telefone' => $tomadorTelefone !== '' ? $tomadorTelefone : null,
                    'intermediario_incluido' => $intermediarioIncluido ? 1 : 0,
                    'intermediario_local' => $intermediarioLocal,
                    'intermediario_cpf_cnpj' => $intermediarioCpfCnpj !== '' ? $intermediarioCpfCnpj : null,
                    'intermediario_nome' => $intermediarioNome !== '' ? $intermediarioNome : null,
                    'pais_prestacao' => $paisPrestacao,
                    'municipio_prestacao' => $municipioPrestacao !== '' ? $municipioPrestacao : null,
                    'codigo_tributacao_nacional' => $codigoTributacaoNacional !== '' ? $codigoTributacaoNacional : null,
                    'codigo_tributacao_municipal' => $codigoTributacaoMunicipal !== '' ? $codigoTributacaoMunicipal : null,
                    'imune_exportacao_nao_incidencia' => $imuneExportacao,
                    'item_nbs' => $itemNbs !== '' ? $itemNbs : null,
                    'descricao_servico' => $descricaoServico !== '' ? $descricaoServico : null,
                    'documento_responsabilidade_tecnica' => $documentoResponsabilidadeTecnica !== '' ? $documentoResponsabilidadeTecnica : null,
                    'documento_referencia' => $documentoReferencia !== '' ? $documentoReferencia : null,
                    'informacoes_complementares' => $informacoesComplementaresNfse !== '' ? $informacoesComplementaresNfse : null,
                    'numero_pedido_b2b' => $numeroPedidoB2b !== '' ? $numeroPedidoB2b : null,
                    'valor_recebido_intermediario' => $valorRecebidoIntermediario,
                    'desconto_incondicionado' => $descontoIncondicionado,
                    'desconto_condicionado' => $descontoCondicionado,
                    'tributacao_issqn' => $tributacaoIssqn,
                    'regime_especial_tributacao' => $regimeEspecialTributacao !== '' ? $regimeEspecialTributacao : null,
                    'exigibilidade_issqn_suspensa' => $exigibilidadeSuspensa,
                    'issqn_retido' => $issqnRetido,
                    'issqn_retido_por' => $issqnRetidoPor,
                    'beneficio_municipal' => $beneficioMunicipal,
                    'deducao_reducao_base_calculo' => $deducaoReducaoBase,
                    'situacao_tributaria_pis_cofins' => $situacaoTributariaPisCofins !== '' ? $situacaoTributariaPisCofins : null,
                    'tipo_retencao_pis_cofins_csll' => $tipoRetencaoPisCofinsCsll !== '' ? $tipoRetencaoPisCofinsCsll : null,
                    'irrf' => $irrf,
                    'contribuicoes_sociais_retidas' => $contribuicoesSociaisRetidas,
                    'contribuicao_previdenciaria_retida' => $contribuicaoPrevidenciariaRetida,
                    'tributos_modo' => $tributosModo,
                    'tributos_federal_percentual' => $tributosFederalPercentual,
                    'tributos_estadual_percentual' => $tributosEstadualPercentual,
                    'tributos_municipal_percentual' => $tributosMunicipalPercentual,
                    'tributos_federal_valor' => $tributosFederalValor,
                    'tributos_estadual_valor' => $tributosEstadualValor,
                    'tributos_municipal_valor' => $tributosMunicipalValor,
                ];
            } else {
                $descricoes = $_POST['item_descricao'] ?? [];
                $ncms = $_POST['item_ncm'] ?? [];
                $cfops = $_POST['item_cfop'] ?? [];
                $csts = $_POST['item_cst'] ?? [];
                $unidades = $_POST['item_unidade'] ?? [];
                $quantidades = $_POST['item_quantidade'] ?? [];
                $valoresUnitarios = $_POST['item_valor_unitario'] ?? [];
                $produtoIds = $_POST['item_produto_id'] ?? [];

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
                        'codigo_servico_municipal' => null,
                        'unidade' => trim((string) ($unidades[$indice] ?? '')) ?: 'UN',
                        'quantidade' => $quantidade,
                        'valor_unitario' => $valorUnitario,
                        'valor_total' => $valorTotalItem,
                    ];
                }
            }

            if ($empresaId <= 0) {
                $erro = 'Selecione a empresa emissora.';
            } elseif ($clienteId <= 0) {
                $erro = 'Selecione o cliente destinatário.';
            } elseif ($naturezaOperacao === '') {
                $erro = 'Informe a natureza da operação.';
            } elseif ($tipoNota === 'nfse' && ($dadosNfse === null || $dadosNfse['municipio_prestacao'] === null || $dadosNfse['codigo_tributacao_nacional'] === null)) {
                $erro = 'Informe o município da prestação e o código de tributação nacional do serviço.';
            } elseif (empty($itensValidos)) {
                $erro = $tipoNota === 'nfse'
                    ? 'Informe a descrição do serviço e um valor do serviço maior que zero.'
                    : 'Adicione ao menos um item com descrição e quantidade maior que zero.';
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
                            nota_id, produto_servico_id, descricao, ncm, cfop, cst_csosn, codigo_servico_municipal, unidade,
                            quantidade, valor_unitario, valor_total
                         ) VALUES (
                            :nota_id, :produto_servico_id, :descricao, :ncm, :cfop, :cst_csosn, :codigo_servico_municipal, :unidade,
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
                            'codigo_servico_municipal' => $item['codigo_servico_municipal'],
                            'unidade' => $item['unidade'],
                            'quantidade' => $item['quantidade'],
                            'valor_unitario' => $item['valor_unitario'],
                            'valor_total' => $item['valor_total'],
                        ]);
                    }

                    if ($tipoNota === 'nfse' && $dadosNfse !== null) {
                        $stmtNfse = $dbNotas->prepare(
                            'INSERT INTO notas_fiscais_nfse (
                                nota_id, data_competencia, serie_dps, numero_dps, tomador_local, tomador_indicador_municipal, tomador_telefone,
                                intermediario_incluido, intermediario_local, intermediario_cpf_cnpj, intermediario_nome,
                                pais_prestacao, municipio_prestacao, codigo_tributacao_nacional, codigo_tributacao_municipal,
                                imune_exportacao_nao_incidencia, item_nbs, descricao_servico, documento_responsabilidade_tecnica,
                                documento_referencia, informacoes_complementares, numero_pedido_b2b, valor_recebido_intermediario,
                                desconto_incondicionado, desconto_condicionado, tributacao_issqn, regime_especial_tributacao,
                                exigibilidade_issqn_suspensa, issqn_retido, issqn_retido_por, beneficio_municipal, deducao_reducao_base_calculo,
                                situacao_tributaria_pis_cofins, tipo_retencao_pis_cofins_csll, irrf, contribuicoes_sociais_retidas,
                                contribuicao_previdenciaria_retida, tributos_modo, tributos_federal_percentual, tributos_estadual_percentual,
                                tributos_municipal_percentual, tributos_federal_valor, tributos_estadual_valor, tributos_municipal_valor
                             ) VALUES (
                                :nota_id, :data_competencia, :serie_dps, :numero_dps, :tomador_local, :tomador_indicador_municipal, :tomador_telefone,
                                :intermediario_incluido, :intermediario_local, :intermediario_cpf_cnpj, :intermediario_nome,
                                :pais_prestacao, :municipio_prestacao, :codigo_tributacao_nacional, :codigo_tributacao_municipal,
                                :imune_exportacao_nao_incidencia, :item_nbs, :descricao_servico, :documento_responsabilidade_tecnica,
                                :documento_referencia, :informacoes_complementares, :numero_pedido_b2b, :valor_recebido_intermediario,
                                :desconto_incondicionado, :desconto_condicionado, :tributacao_issqn, :regime_especial_tributacao,
                                :exigibilidade_issqn_suspensa, :issqn_retido, :issqn_retido_por, :beneficio_municipal, :deducao_reducao_base_calculo,
                                :situacao_tributaria_pis_cofins, :tipo_retencao_pis_cofins_csll, :irrf, :contribuicoes_sociais_retidas,
                                :contribuicao_previdenciaria_retida, :tributos_modo, :tributos_federal_percentual, :tributos_estadual_percentual,
                                :tributos_municipal_percentual, :tributos_federal_valor, :tributos_estadual_valor, :tributos_municipal_valor
                             )'
                        );
                        $stmtNfse->execute(array_merge(['nota_id' => $notaId], $dadosNfse));
                    }

                    registrarLogNota($dbNotas, $notaId, $funcionarioId, 'criada', 'Rascunho criado com ' . count($itensValidos) . ' item(ns).');

                    $dbNotas->commit();
                    $sucesso = 'Nota salva como rascunho (nº interno ' . $numeroInterno . '). Veja em "Notas fiscais" para gerar o PDF ou marcar como pronta para envio.';
                } catch (Throwable $e) {
                    $dbNotas->rollBack();
                    $erro = 'Não foi possível salvar a nota: ' . $e->getMessage();
                }
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
} catch (PDOException $e) {
    $erro = 'Erro ao carregar dados para emissão: ' . $e->getMessage();
    $empresasAtivas = [];
    $clientes = [];
    $catalogo = [];
}

$csrf = h($_SESSION['csrf_notas_emitir'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
$catalogoJson = h(json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir Nota Fiscal | ACCOUNT Contabilidade</title>
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
            <h1>Emitir Nota Fiscal</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de produto (NF-e) ou serviço (NFS-e) para outras empresas. As notas ficam como rascunho até a integração com a SEFAZ e o Portal Nacional da NFS-e ser habilitada — acompanhe tudo em <a href="notas-fiscais" style="text-decoration:underline;">Notas fiscais</a>.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

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
                            <div class="row-actions">
                                <input id="cnpj_cpf" name="cnpj_cpf" type="text" style="flex: 1;">
                                <button class="btn btn-outline btn-small" type="button" id="btnBuscarCnpjCliente"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                            </div>
                            <span class="muted" id="statusBuscaCnpjCliente" style="font-size: 0.78rem;"></span>
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

                    <div id="secaoNfe">
                        <h3 style="margin-top: 1.5rem;">Itens (produtos)</h3>
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
                    </div>

                    <div id="secaoNfse">
                        <div class="form-grid" style="margin-top: 1.5rem;">
                            <div class="field">
                                <label for="nfse_data_competencia">Data de competência</label>
                                <input id="nfse_data_competencia" name="nfse_data_competencia" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                            </div>
                            <label class="check-row">
                                <input type="checkbox" id="nfse_informar_dps" name="nfse_informar_dps">
                                Informar série e número da DPS
                            </label>
                        </div>
                        <div class="form-grid" id="camposDpsManual" style="display:none;">
                            <div class="field">
                                <label for="nfse_serie_dps">Série da DPS</label>
                                <input id="nfse_serie_dps" name="nfse_serie_dps" type="text" maxlength="5">
                            </div>
                            <div class="field">
                                <label for="nfse_numero_dps">Número da DPS</label>
                                <input id="nfse_numero_dps" name="nfse_numero_dps" type="text" maxlength="15">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Tomador do serviço</h3>
                        <p class="muted" style="margin-bottom: 0.75rem;">Documento, nome e e-mail do tomador vêm do cliente destinatário selecionado acima.</p>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_tomador_local">Onde está localizado o estabelecimento/domicílio?</label>
                                <select id="nfse_tomador_local" name="nfse_tomador_local">
                                    <option value="nao_informado">Tomador não informado</option>
                                    <option value="brasil" selected>Brasil</option>
                                    <option value="exterior">Exterior</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_tomador_indicador_municipal">Indicador municipal (opcional)</label>
                                <input id="nfse_tomador_indicador_municipal" name="nfse_tomador_indicador_municipal" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tomador_telefone">Telefone (opcional)</label>
                                <input id="nfse_tomador_telefone" name="nfse_tomador_telefone" type="text">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Intermediário do serviço</h3>
                        <label class="check-row" style="margin-top: 0;">
                            <input type="checkbox" id="nfse_intermediario_incluido" name="nfse_intermediario_incluido">
                            Esta NFS-e tem intermediário
                        </label>
                        <div class="form-grid" id="camposIntermediario" style="display:none; margin-top: 1rem;">
                            <div class="field">
                                <label for="nfse_intermediario_local">Onde está localizado o estabelecimento/domicílio?</label>
                                <select id="nfse_intermediario_local" name="nfse_intermediario_local">
                                    <option value="nao_informado">Intermediário não informado</option>
                                    <option value="brasil">Brasil</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_intermediario_cpf_cnpj">CPF/CNPJ do intermediário</label>
                                <input id="nfse_intermediario_cpf_cnpj" name="nfse_intermediario_cpf_cnpj" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_intermediario_nome">Nome/Razão social do intermediário</label>
                                <input id="nfse_intermediario_nome" name="nfse_intermediario_nome" type="text">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Local da prestação do serviço</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_pais_prestacao">País</label>
                                <input id="nfse_pais_prestacao" name="nfse_pais_prestacao" type="text" value="Brasil">
                            </div>
                            <div class="field">
                                <label for="nfse_municipio_prestacao">Município</label>
                                <input id="nfse_municipio_prestacao" name="nfse_municipio_prestacao" type="text" placeholder="Ex.: Belo Horizonte/MG">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Serviço prestado</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_codigo_tributacao_nacional">Código de Tributação Nacional (LC 116)</label>
                                <input id="nfse_codigo_tributacao_nacional" name="nfse_codigo_tributacao_nacional" type="text" placeholder="Ex.: 17.19.01">
                            </div>
                            <div class="field">
                                <label for="nfse_codigo_tributacao_municipal">Código Complementar Municipal</label>
                                <input id="nfse_codigo_tributacao_municipal" name="nfse_codigo_tributacao_municipal" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_imune_exportacao">Imunidade, exportação ou não incidência do ISSQN?</label>
                                <select id="nfse_imune_exportacao" name="nfse_imune_exportacao">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_item_nbs">Item da NBS correspondente (opcional)</label>
                                <input id="nfse_item_nbs" name="nfse_item_nbs" type="text">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_descricao_servico">Descrição do serviço</label>
                                <textarea id="nfse_descricao_servico" name="nfse_descricao_servico" maxlength="2000" placeholder="Descreva o serviço prestado"></textarea>
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Informações complementares</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_documento_responsabilidade_tecnica">Nº do documento de responsabilidade técnica</label>
                                <input id="nfse_documento_responsabilidade_tecnica" name="nfse_documento_responsabilidade_tecnica" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_numero_pedido_b2b">Nº do Pedido/OC/OS/Projeto (B2B)</label>
                                <input id="nfse_numero_pedido_b2b" name="nfse_numero_pedido_b2b" type="text">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_documento_referencia">Documento de referência</label>
                                <textarea id="nfse_documento_referencia" name="nfse_documento_referencia"></textarea>
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label for="nfse_informacoes_complementares">Informações complementares</label>
                                <textarea id="nfse_informacoes_complementares" name="nfse_informacoes_complementares" maxlength="2000"></textarea>
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Valores do serviço prestado</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_valor_servico">Valor do serviço prestado</label>
                                <input id="nfse_valor_servico" name="nfse_valor_servico" type="text" value="0,00">
                            </div>
                            <div class="field">
                                <label for="nfse_valor_recebido_intermediario">Valor recebido pelo intermediário</label>
                                <input id="nfse_valor_recebido_intermediario" name="nfse_valor_recebido_intermediario" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_desconto_incondicionado">Desconto incondicionado</label>
                                <input id="nfse_desconto_incondicionado" name="nfse_desconto_incondicionado" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_desconto_condicionado">Desconto condicionado</label>
                                <input id="nfse_desconto_condicionado" name="nfse_desconto_condicionado" type="text">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Tributação municipal</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_tributacao_issqn">Tributação do ISSQN sobre o serviço prestado</label>
                                <select id="nfse_tributacao_issqn" name="nfse_tributacao_issqn">
                                    <option value="operacao_tributavel" selected>Operação tributável</option>
                                    <option value="isenta">Isenta</option>
                                    <option value="imune">Imune</option>
                                    <option value="exportacao">Exportação de serviço</option>
                                    <option value="nao_incidencia">Não incidência</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_regime_especial_tributacao">Regime Especial de Tributação</label>
                                <select id="nfse_regime_especial_tributacao" name="nfse_regime_especial_tributacao">
                                    <option value="">Nenhum</option>
                                    <option value="me_epp_simples_nacional">ME/EPP Simples Nacional</option>
                                    <option value="mei">Microempreendedor Individual (MEI)</option>
                                    <option value="cooperativa">Cooperativa</option>
                                    <option value="estimativa">Estimativa</option>
                                    <option value="sociedade_profissionais">Sociedade de profissionais</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_exigibilidade_suspensa">A exigibilidade do ISSQN está suspensa?</label>
                                <select id="nfse_exigibilidade_suspensa" name="nfse_exigibilidade_suspensa">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_issqn_retido">Há retenção do ISSQN pelo Tomador ou Intermediário?</label>
                                <select id="nfse_issqn_retido" name="nfse_issqn_retido">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field" id="campoIssqnRetidoPor" style="display:none;">
                                <label for="nfse_issqn_retido_por">Retido por</label>
                                <select id="nfse_issqn_retido_por" name="nfse_issqn_retido_por">
                                    <option value="tomador">Tomador</option>
                                    <option value="intermediario">Intermediário</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_beneficio_municipal">Este serviço está amparado por algum benefício municipal?</label>
                                <select id="nfse_beneficio_municipal" name="nfse_beneficio_municipal">
                                    <option value="nao" selected>Não</option>
                                    <option value="sim">Sim</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_deducao_reducao_base">Dedução/redução da base de cálculo do ISSQN (opcional)</label>
                                <input id="nfse_deducao_reducao_base" name="nfse_deducao_reducao_base" type="text">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Tributação federal</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_situacao_pis_cofins">Situação Tributária do PIS/COFINS</label>
                                <select id="nfse_situacao_pis_cofins" name="nfse_situacao_pis_cofins">
                                    <option value="">Selecione...</option>
                                    <option value="01">01 - Tributável (alíquota básica)</option>
                                    <option value="04">04 - Tributável (alíquota zero)</option>
                                    <option value="06">06 - Não tributável</option>
                                    <option value="07">07 - Isenta</option>
                                    <option value="08">08 - Sem incidência</option>
                                    <option value="09">09 - Com suspensão</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_tipo_retencao_pis_cofins_csll">Tipo de retenção do PIS/COFINS/CSLL</label>
                                <select id="nfse_tipo_retencao_pis_cofins_csll" name="nfse_tipo_retencao_pis_cofins_csll">
                                    <option value="">Selecione...</option>
                                    <option value="nenhuma">Sem retenção</option>
                                    <option value="lei_10833">Retenção conforme Lei 10.833/2003</option>
                                    <option value="outras">Outras retenções</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="nfse_irrf">IRRF (opcional)</label>
                                <input id="nfse_irrf" name="nfse_irrf" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_contribuicoes_sociais_retidas">Contribuições Sociais - Retidas (opcional)</label>
                                <input id="nfse_contribuicoes_sociais_retidas" name="nfse_contribuicoes_sociais_retidas" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_contribuicao_previdenciaria_retida">Contribuição Previdenciária - Retida (opcional)</label>
                                <input id="nfse_contribuicao_previdenciaria_retida" name="nfse_contribuicao_previdenciaria_retida" type="text">
                            </div>
                        </div>

                        <h3 style="margin-top: 1.5rem;">Valor aproximado dos tributos</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label for="nfse_tributos_modo">Como informar</label>
                                <select id="nfse_tributos_modo" name="nfse_tributos_modo">
                                    <option value="percentuais" selected>Configurar os valores percentuais</option>
                                    <option value="valores">Preencher os valores monetários</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid" id="tributosPercentuais">
                            <div class="field">
                                <label for="nfse_tributos_federal_percentual">Federal (%)</label>
                                <input id="nfse_tributos_federal_percentual" name="nfse_tributos_federal_percentual" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_estadual_percentual">Estadual (%)</label>
                                <input id="nfse_tributos_estadual_percentual" name="nfse_tributos_estadual_percentual" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_municipal_percentual">Municipal (%)</label>
                                <input id="nfse_tributos_municipal_percentual" name="nfse_tributos_municipal_percentual" type="text">
                            </div>
                        </div>
                        <div class="form-grid" id="tributosValores" style="display:none;">
                            <div class="field">
                                <label for="nfse_tributos_federal_valor">Federal (R$)</label>
                                <input id="nfse_tributos_federal_valor" name="nfse_tributos_federal_valor" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_estadual_valor">Estadual (R$)</label>
                                <input id="nfse_tributos_estadual_valor" name="nfse_tributos_estadual_valor" type="text">
                            </div>
                            <div class="field">
                                <label for="nfse_tributos_municipal_valor">Municipal (R$)</label>
                                <input id="nfse_tributos_municipal_valor" name="nfse_tributos_municipal_valor" type="text">
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar rascunho</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        function formatarCnpjOuCpf(valor, tipoPessoa) {
            const digitos = (valor || '').replace(/\D/g, '');
            if (tipoPessoa === 'PF') {
                const d = digitos.slice(0, 11);
                if (d.length > 9) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2})$/, '$1.$2.$3-$4').replace(/-$/, '');
                if (d.length > 6) return d.replace(/^(\d{3})(\d{3})(\d{0,3})$/, '$1.$2.$3');
                if (d.length > 3) return d.replace(/^(\d{3})(\d{0,3})$/, '$1.$2');
                return d;
            }
            const d = digitos.slice(0, 14);
            if (d.length > 12) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})$/, '$1.$2.$3/$4-$5').replace(/-$/, '');
            if (d.length > 8) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})$/, '$1.$2.$3/$4');
            if (d.length > 5) return d.replace(/^(\d{2})(\d{3})(\d{0,3})$/, '$1.$2.$3');
            if (d.length > 2) return d.replace(/^(\d{2})(\d{0,3})$/, '$1.$2');
            return d;
        }

        function formatarCepCliente(valor) {
            const d = (valor || '').replace(/\D/g, '').slice(0, 8);
            return d.length > 5 ? d.replace(/^(\d{5})(\d{0,3})$/, '$1-$2') : d;
        }

        const campoCnpjCpf = document.getElementById('cnpj_cpf');
        const campoTipoPessoa = document.getElementById('tipo_pessoa');
        const campoCepCliente = document.getElementById('cliente_cep');
        const btnBuscarCnpjCliente = document.getElementById('btnBuscarCnpjCliente');

        if (campoCnpjCpf && campoTipoPessoa) {
            campoCnpjCpf.addEventListener('input', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
            });
            campoTipoPessoa.addEventListener('change', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
                if (btnBuscarCnpjCliente) {
                    btnBuscarCnpjCliente.style.display = campoTipoPessoa.value === 'PF' ? 'none' : '';
                }
            });
        }

        if (campoCepCliente) {
            campoCepCliente.addEventListener('input', function () {
                campoCepCliente.value = formatarCepCliente(campoCepCliente.value);
            });
        }

        if (btnBuscarCnpjCliente) {
            btnBuscarCnpjCliente.addEventListener('click', function () {
                const statusEl = document.getElementById('statusBuscaCnpjCliente');
                const digitos = (campoCnpjCpf.value || '').replace(/\D/g, '');

                if (digitos.length !== 14) {
                    statusEl.textContent = 'Informe um CNPJ com 14 dígitos antes de buscar.';
                    statusEl.style.color = '#FFD1CE';
                    return;
                }

                statusEl.textContent = 'Buscando...';
                statusEl.style.color = '';
                btnBuscarCnpjCliente.disabled = true;

                fetch('buscar-cnpj?cnpj=' + digitos)
                    .then(function (resposta) { return resposta.json().then(function (dados) { return { ok: resposta.ok, dados: dados }; }); })
                    .then(function (resultado) {
                        if (!resultado.ok) {
                            statusEl.textContent = resultado.dados.erro || 'Não foi possível buscar o CNPJ.';
                            statusEl.style.color = '#FFD1CE';
                            return;
                        }

                        const dados = resultado.dados;
                        document.getElementById('nome_razao_social').value = dados.razao_social || '';
                        document.getElementById('cliente_logradouro').value = dados.logradouro || '';
                        document.getElementById('cliente_numero').value = dados.numero || '';
                        document.getElementById('cliente_complemento').value = dados.complemento || '';
                        document.getElementById('cliente_bairro').value = dados.bairro || '';
                        document.getElementById('cliente_cep').value = formatarCepCliente(dados.cep || '');
                        document.getElementById('cliente_municipio').value = dados.municipio || '';
                        document.getElementById('cliente_uf').value = dados.uf || '';

                        statusEl.style.color = 'var(--primary)';
                        statusEl.textContent = 'Dados preenchidos (' + (dados.situacao_cadastral || 'situação não informada') + '). Confira antes de cadastrar.';
                    })
                    .catch(function () {
                        statusEl.textContent = 'Erro ao buscar o CNPJ. Tente novamente.';
                        statusEl.style.color = '#FFD1CE';
                    })
                    .finally(function () {
                        btnBuscarCnpjCliente.disabled = false;
                    });
            });
        }

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

        if (window.location.hash === '#cadastroCliente') {
            const detalhesCliente = document.getElementById('cadastroCliente');
            if (detalhesCliente) {
                detalhesCliente.open = true;
                detalhesCliente.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

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

        const tipoNotaSelect = document.getElementById('tipo_nota');
        const secaoNfe = document.getElementById('secaoNfe');
        const secaoNfse = document.getElementById('secaoNfse');

        function alternarTipoNota() {
            if (!tipoNotaSelect) return;
            const ehNfse = tipoNotaSelect.value === 'nfse';

            if (secaoNfe) secaoNfe.style.display = ehNfse ? 'none' : '';
            if (secaoNfse) secaoNfse.style.display = ehNfse ? '' : 'none';

            document.querySelectorAll('.item-descricao').forEach(function (campo) {
                campo.required = !ehNfse;
            });

            const descricaoServico = document.getElementById('nfse_descricao_servico');
            const valorServico = document.getElementById('nfse_valor_servico');
            const municipioPrestacao = document.getElementById('nfse_municipio_prestacao');
            const codigoTributacaoNacional = document.getElementById('nfse_codigo_tributacao_nacional');
            [descricaoServico, valorServico, municipioPrestacao, codigoTributacaoNacional].forEach(function (campo) {
                if (campo) campo.required = ehNfse;
            });
        }

        if (tipoNotaSelect) {
            tipoNotaSelect.addEventListener('change', alternarTipoNota);
            alternarTipoNota();
        }

        const checkboxInformarDps = document.getElementById('nfse_informar_dps');
        const camposDpsManual = document.getElementById('camposDpsManual');
        if (checkboxInformarDps && camposDpsManual) {
            checkboxInformarDps.addEventListener('change', function () {
                camposDpsManual.style.display = checkboxInformarDps.checked ? '' : 'none';
            });
        }

        const checkboxIntermediario = document.getElementById('nfse_intermediario_incluido');
        const camposIntermediario = document.getElementById('camposIntermediario');
        if (checkboxIntermediario && camposIntermediario) {
            checkboxIntermediario.addEventListener('change', function () {
                camposIntermediario.style.display = checkboxIntermediario.checked ? '' : 'none';
            });
        }

        const selectIssqnRetido = document.getElementById('nfse_issqn_retido');
        const campoIssqnRetidoPor = document.getElementById('campoIssqnRetidoPor');
        if (selectIssqnRetido && campoIssqnRetidoPor) {
            selectIssqnRetido.addEventListener('change', function () {
                campoIssqnRetidoPor.style.display = selectIssqnRetido.value === 'sim' ? '' : 'none';
            });
        }

        const selectTributosModo = document.getElementById('nfse_tributos_modo');
        const blocoTributosPercentuais = document.getElementById('tributosPercentuais');
        const blocoTributosValores = document.getElementById('tributosValores');
        if (selectTributosModo && blocoTributosPercentuais && blocoTributosValores) {
            selectTributosModo.addEventListener('change', function () {
                const ehValores = selectTributosModo.value === 'valores';
                blocoTributosPercentuais.style.display = ehValores ? 'none' : '';
                blocoTributosValores.style.display = ehValores ? '' : 'none';
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
