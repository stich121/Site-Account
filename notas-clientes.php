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
$nivelAcesso = atualizarNivelAcessoSessao(obterConexao(), $funcionarioId);
$podeAdministrar = $nivelAcesso >= 3;

$erro = '';
$sucesso = '';
$clienteEmEdicao = null;

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function documentoNfseValido(string $documento): bool
{
    $numero = preg_replace('/\D+/', '', $documento);
    if (!in_array(strlen($numero), [11, 14], true) || preg_match('/^(\d)\1+$/', $numero)) return false;
    if (strlen($numero) === 11) {
        for ($digitoPos = 9; $digitoPos <= 10; $digitoPos++) {
            $soma = 0; $peso = $digitoPos + 1;
            for ($i = 0; $i < $digitoPos; $i++) $soma += (int) $numero[$i] * ($peso--);
            $digito = 11 - ($soma % 11); if ($digito >= 10) $digito = 0;
            if ((int) $numero[$digitoPos] !== $digito) return false;
        }
        return true;
    }
    foreach ([12, 13] as $posicao) {
        $soma = 0; $peso = $posicao === 12 ? 5 : 6;
        for ($i = 0; $i < $posicao; $i++) { $soma += (int) $numero[$i] * $peso; $peso = $peso === 2 ? 9 : $peso - 1; }
        $digito = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
        if ((int) $numero[$posicao] !== $digito) return false;
    }
    return true;
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

function prepararTabelaNotasClientes(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notas_clientes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo_pessoa ENUM('PJ','PF') NOT NULL,
            nome_razao_social VARCHAR(180) NOT NULL,
            cnpj_cpf VARCHAR(20) NULL,
            inscricao_estadual VARCHAR(30) NULL,
            inscricao_municipal VARCHAR(20) NULL,
            email VARCHAR(180) NULL,
            logradouro VARCHAR(180) NULL,
            numero VARCHAR(20) NULL,
            complemento VARCHAR(120) NULL,
            bairro VARCHAR(120) NULL,
            cep VARCHAR(12) NULL,
            municipio VARCHAR(120) NULL,
            codigo_ibge_municipio VARCHAR(7) NULL,
            codigo_pais VARCHAR(4) NULL,
            nif VARCHAR(40) NULL,
            motivo_nao_nif VARCHAR(2) NULL,
            uf CHAR(2) NULL,
            indicador_consumidor_final TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT UNSIGNED NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notas_clientes_nome (nome_razao_social)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararColunaInscricaoMunicipalClientes(PDO $db): void
{
    if (!colunaExisteNotas($db, 'notas_clientes', 'inscricao_municipal')) {
        $db->exec('ALTER TABLE notas_clientes ADD COLUMN inscricao_municipal VARCHAR(20) NULL AFTER inscricao_estadual');
    }
    $camposEnderecoFiscal = [
        'codigo_ibge_municipio' => 'ALTER TABLE notas_clientes ADD COLUMN codigo_ibge_municipio VARCHAR(7) NULL AFTER municipio',
        'codigo_pais' => 'ALTER TABLE notas_clientes ADD COLUMN codigo_pais VARCHAR(4) NULL AFTER codigo_ibge_municipio',
        'nif' => 'ALTER TABLE notas_clientes ADD COLUMN nif VARCHAR(40) NULL AFTER codigo_pais',
        'motivo_nao_nif' => 'ALTER TABLE notas_clientes ADD COLUMN motivo_nao_nif VARCHAR(2) NULL AFTER nif',
    ];
    foreach ($camposEnderecoFiscal as $coluna => $sql) {
        if (!colunaExisteNotas($db, 'notas_clientes', $coluna)) $db->exec($sql);
    }
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();

    prepararColunaPermiteNotasFiscais($db);
    prepararTabelaNotasClientes($dbNotas);
    prepararColunaInscricaoMunicipalClientes($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_clientes'])) {
        $_SESSION['csrf_notas_clientes'] = bin2hex(random_bytes(32));
    }

    $clienteEdicaoId = (int) ($_GET['editar'] ?? 0);
    if ($clienteEdicaoId > 0) {
        $stmtEdicao = $dbNotas->prepare('SELECT * FROM notas_clientes WHERE id = :id LIMIT 1');
        $stmtEdicao->execute(['id' => $clienteEdicaoId]);
        $clienteEmEdicao = $stmtEdicao->fetch() ?: null;
        if (!$clienteEmEdicao) {
            $erro = 'Cliente não encontrado.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_clientes'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (in_array(($_POST['acao'] ?? ''), ['cadastrar_cliente', 'editar_cliente'], true)) {
            $editando = ($_POST['acao'] ?? '') === 'editar_cliente';
            $clienteId = (int) ($_POST['cliente_id'] ?? 0);
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
            $codigoIbgeCliente = trim($_POST['cliente_codigo_ibge_municipio'] ?? '');
            $codigoPaisCliente = trim($_POST['cliente_codigo_pais'] ?? '1058');
            $nifCliente = trim($_POST['cliente_nif'] ?? '');
            $motivoNaoNifCliente = trim($_POST['cliente_motivo_nao_nif'] ?? '');
            $uf = strtoupper(trim($_POST['cliente_uf'] ?? ''));
            $consumidorFinal = isset($_POST['indicador_consumidor_final']) ? 1 : 0;

            $documentoClienteCadastro = preg_replace('/\D+/', '', $cnpjCpf);
            $tamanhoEsperado = $tipoPessoa === 'PF' ? 11 : 14;
            $clienteExterior = $codigoPaisCliente !== '' && $codigoPaisCliente !== '1058';

            if ($editando && $clienteId <= 0) {
                $erro = 'Cliente inválido para edição.';
            } elseif ($nomeRazaoSocial === '') {
                $erro = 'Informe o nome/razão social do cliente.';
            } elseif (!$clienteExterior && (strlen($documentoClienteCadastro) !== $tamanhoEsperado || !documentoNfseValido($documentoClienteCadastro))) {
                $erro = $tipoPessoa === 'PF' ? 'Informe um CPF válido.' : 'Informe um CNPJ válido.';
            } elseif ($clienteExterior && $nifCliente === '' && $motivoNaoNifCliente === '') {
                $erro = 'Cliente exterior exige NIF ou motivo oficial da ausência de NIF.';
            } elseif ($logradouro === '' || $numero === '' || $bairro === '' || $municipio === '') {
                $erro = 'Preencha o endereço do cliente.';
            } elseif (!$clienteExterior && (!preg_match('/^[A-Z]{2}$/', $uf) || !preg_match('/^\d{7}$/', $codigoIbgeCliente))) {
                $erro = 'Cliente nacional exige UF válida e código IBGE do município com 7 dígitos.';
            } elseif ($cep !== '' && strlen(preg_replace('/\D+/', '', $cep)) !== 8) {
                $erro = 'Informe um CEP válido, com 8 dígitos.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido para o cliente ou deixe em branco.';
            } else {
                $parametros = [
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
                    'codigo_ibge_municipio' => $codigoIbgeCliente !== '' ? $codigoIbgeCliente : null,
                    'codigo_pais' => $codigoPaisCliente !== '' ? $codigoPaisCliente : null,
                    'nif' => $nifCliente !== '' ? $nifCliente : null,
                    'motivo_nao_nif' => $motivoNaoNifCliente !== '' ? $motivoNaoNifCliente : null,
                    'uf' => $uf !== '' ? $uf : null,
                    'indicador_consumidor_final' => $consumidorFinal,
                ];

                if ($editando) {
                    $parametros['id'] = $clienteId;
                    $stmt = $dbNotas->prepare(
                        'UPDATE notas_clientes SET
                            tipo_pessoa = :tipo_pessoa, nome_razao_social = :nome_razao_social, cnpj_cpf = :cnpj_cpf,
                            inscricao_estadual = :inscricao_estadual, email = :email, logradouro = :logradouro,
                            numero = :numero, complemento = :complemento, bairro = :bairro, cep = :cep,
                            municipio = :municipio, codigo_ibge_municipio = :codigo_ibge_municipio, codigo_pais = :codigo_pais,
                            nif = :nif, motivo_nao_nif = :motivo_nao_nif, uf = :uf,
                            indicador_consumidor_final = :indicador_consumidor_final
                         WHERE id = :id'
                    );
                    $stmt->execute($parametros);
                    $sucesso = 'Cliente atualizado.';
                    $clienteEmEdicao = null;
                } else {
                    $parametros['criado_por'] = $funcionarioId;
                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_clientes (
                            tipo_pessoa, nome_razao_social, cnpj_cpf, inscricao_estadual, email,
                            logradouro, numero, complemento, bairro, cep, municipio, codigo_ibge_municipio, codigo_pais, nif, motivo_nao_nif, uf,
                            indicador_consumidor_final, criado_por
                         ) VALUES (
                            :tipo_pessoa, :nome_razao_social, :cnpj_cpf, :inscricao_estadual, :email,
                            :logradouro, :numero, :complemento, :bairro, :cep, :municipio, :codigo_ibge_municipio, :codigo_pais, :nif, :motivo_nao_nif, :uf,
                            :indicador_consumidor_final, :criado_por
                         )'
                    );
                    $stmt->execute($parametros);
                    $sucesso = 'Cliente cadastrado. Já pode ser selecionado ao criar uma nota.';
                }
            }
        }
    }

    $stmt = $dbNotas->query(
        'SELECT id, tipo_pessoa, nome_razao_social, cnpj_cpf, inscricao_estadual, inscricao_municipal, email,
                logradouro, numero, complemento, bairro, cep, municipio, codigo_ibge_municipio, codigo_pais,
                nif, motivo_nao_nif, uf, indicador_consumidor_final
         FROM notas_clientes ORDER BY nome_razao_social ASC'
    );
    $clientes = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar clientes: ' . $e->getMessage();
    $clientes = [];
}

$csrf = h($_SESSION['csrf_notas_clientes'] ?? '');
$usuario = h(nomeExibicao($usuarioRaw));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'clientes'; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1>Clientes</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Cadastro dos destinatários (tomadores) usados ao emitir notas fiscais. Qualquer funcionário com acesso ao emissor pode cadastrar e usar esses clientes.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2><?php echo $clienteEmEdicao ? 'Editando: ' . h($clienteEmEdicao['nome_razao_social']) : 'Cadastrar novo cliente'; ?></h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="acao" value="<?php echo $clienteEmEdicao ? 'editar_cliente' : 'cadastrar_cliente'; ?>">
                <input type="hidden" name="cliente_id" value="<?php echo h((string) ($clienteEmEdicao['id'] ?? 0)); ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="tipo_pessoa">Tipo de pessoa</label>
                        <select id="tipo_pessoa" name="tipo_pessoa">
                            <option value="PJ" <?php echo ($clienteEmEdicao['tipo_pessoa'] ?? 'PJ') === 'PJ' ? 'selected' : ''; ?>>Pessoa jurídica</option>
                            <option value="PF" <?php echo ($clienteEmEdicao['tipo_pessoa'] ?? '') === 'PF' ? 'selected' : ''; ?>>Pessoa física</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="nome_razao_social">Nome / Razão social</label>
                        <input id="nome_razao_social" name="nome_razao_social" type="text" value="<?php echo h($clienteEmEdicao['nome_razao_social'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="cnpj_cpf">CNPJ / CPF</label>
                        <div class="row-actions">
                            <input id="cnpj_cpf" name="cnpj_cpf" type="text" maxlength="18" style="flex: 1;" value="<?php echo h($clienteEmEdicao['cnpj_cpf'] ?? ''); ?>">
                            <button class="btn btn-outline btn-small" type="button" id="btnBuscarCnpjCliente"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                        </div>
                        <span class="muted" id="statusBuscaCnpjCliente" style="font-size: 0.78rem;"></span>
                    </div>
                    <div class="field">
                        <label for="cliente_inscricao_estadual">Inscrição Estadual (ou "ISENTO")</label>
                        <input id="cliente_inscricao_estadual" name="cliente_inscricao_estadual" type="text" value="<?php echo h($clienteEmEdicao['inscricao_estadual'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_email">E-mail</label>
                        <input id="cliente_email" name="cliente_email" type="email" value="<?php echo h($clienteEmEdicao['email'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_logradouro">Logradouro</label>
                        <input id="cliente_logradouro" name="cliente_logradouro" type="text" value="<?php echo h($clienteEmEdicao['logradouro'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_numero">Número</label>
                        <input id="cliente_numero" name="cliente_numero" type="text" value="<?php echo h($clienteEmEdicao['numero'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_complemento">Complemento</label>
                        <input id="cliente_complemento" name="cliente_complemento" type="text" value="<?php echo h($clienteEmEdicao['complemento'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_bairro">Bairro</label>
                        <input id="cliente_bairro" name="cliente_bairro" type="text" value="<?php echo h($clienteEmEdicao['bairro'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_cep">CEP</label>
                        <input id="cliente_cep" name="cliente_cep" type="text" value="<?php echo h($clienteEmEdicao['cep'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_municipio">Município</label>
                        <input id="cliente_municipio" name="cliente_municipio" type="text" value="<?php echo h($clienteEmEdicao['municipio'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_codigo_ibge_municipio">Código IBGE do município</label>
                        <input id="cliente_codigo_ibge_municipio" name="cliente_codigo_ibge_municipio" type="text" inputmode="numeric" pattern="\d{7}" maxlength="7" value="<?php echo h($clienteEmEdicao['codigo_ibge_municipio'] ?? ''); ?>">
                        <span class="muted" id="statusCodigoIbgeCliente" style="font-size: 0.78rem;"></span>
                    </div>
                    <div class="field">
                        <label for="cliente_codigo_pais">Código do país</label>
                        <input id="cliente_codigo_pais" name="cliente_codigo_pais" type="text" inputmode="numeric" maxlength="4" value="<?php echo h($clienteEmEdicao['codigo_pais'] ?? '1058'); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_nif">NIF (destinatário exterior)</label>
                        <input id="cliente_nif" name="cliente_nif" type="text" maxlength="40" value="<?php echo h($clienteEmEdicao['nif'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_motivo_nao_nif">Motivo da ausência de NIF</label>
                        <input id="cliente_motivo_nao_nif" name="cliente_motivo_nao_nif" type="text" maxlength="2" placeholder="Código oficial" value="<?php echo h($clienteEmEdicao['motivo_nao_nif'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="cliente_uf">UF</label>
                        <input id="cliente_uf" name="cliente_uf" type="text" maxlength="2" value="<?php echo h($clienteEmEdicao['uf'] ?? ''); ?>">
                    </div>
                    <label class="check-row">
                        <input type="checkbox" name="indicador_consumidor_final" <?php echo (int) ($clienteEmEdicao['indicador_consumidor_final'] ?? 1) === 1 ? 'checked' : ''; ?>>
                        Consumidor final
                    </label>
                    <div class="field">
                        <label>&nbsp;</label>
                        <div class="row-actions">
                            <button class="btn" type="submit"><i class="fa-solid fa-user-plus"></i> <?php echo $clienteEmEdicao ? 'Salvar alterações' : 'Cadastrar cliente'; ?></button>
                            <?php if ($clienteEmEdicao): ?>
                                <a class="btn btn-outline" href="notas-clientes">Cancelar edição</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Clientes cadastrados</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nome / Razão social</th>
                            <th>Tipo</th>
                            <th>CNPJ/CPF</th>
                            <th>Município/UF</th>
                            <th>E-mail</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td><?php echo h($cliente['nome_razao_social']); ?></td>
                                <td><?php echo $cliente['tipo_pessoa'] === 'PF' ? 'Pessoa física' : 'Pessoa jurídica'; ?></td>
                                <td><?php echo h($cliente['cnpj_cpf'] ?? '—'); ?></td>
                                <td><?php echo h(($cliente['municipio'] ?? '—') . ($cliente['uf'] ? '/' . $cliente['uf'] : '')); ?></td>
                                <td><?php echo h($cliente['email'] ?? '—'); ?></td>
                                <td>
                                    <a class="btn btn-outline btn-small" href="notas-clientes?editar=<?php echo h((string) $cliente['id']); ?>#nome_razao_social"><i class="fa-solid fa-pen"></i> Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientes)): ?>
                            <tr><td colspan="6" class="muted">Nenhum cliente cadastrado.</td></tr>
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
            campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
            campoCnpjCpf.addEventListener('input', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
            });
            campoTipoPessoa.addEventListener('change', function () {
                campoCnpjCpf.value = formatarCnpjOuCpf(campoCnpjCpf.value, campoTipoPessoa.value);
                if (btnBuscarCnpjCliente) {
                    btnBuscarCnpjCliente.style.display = campoTipoPessoa.value === 'PF' ? 'none' : '';
                }
            });
            if (btnBuscarCnpjCliente) {
                btnBuscarCnpjCliente.style.display = campoTipoPessoa.value === 'PF' ? 'none' : '';
            }
        }

        if (campoCepCliente) {
            campoCepCliente.value = formatarCepCliente(campoCepCliente.value);
            campoCepCliente.addEventListener('input', function () {
                campoCepCliente.value = formatarCepCliente(campoCepCliente.value);
            });
        }

        let municipiosIbge = [];
        function normalizarTexto(valor) {
            return String(valor || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase('pt-BR').trim();
        }
        function buscarCodigoIbgePorNomeUf(nome, uf) {
            const nomeNormalizado = normalizarTexto(nome);
            const ufNormalizada = String(uf || '').toUpperCase().trim();
            if (nomeNormalizado === '' || ufNormalizada === '') return '';
            const municipio = municipiosIbge.find(function (item) {
                return normalizarTexto(item.nome) === nomeNormalizado && String(item.uf).toUpperCase() === ufNormalizada;
            });
            return municipio ? String(municipio.codigo) : '';
        }
        function tentarPreencherCodigoIbge() {
            const campoMunicipio = document.getElementById('cliente_municipio');
            const campoUf = document.getElementById('cliente_uf');
            const campoCodigo = document.getElementById('cliente_codigo_ibge_municipio');
            const statusCodigo = document.getElementById('statusCodigoIbgeCliente');
            if (!campoMunicipio || !campoUf || !campoCodigo) return;
            if (campoCodigo.value.trim() !== '') return;
            const codigo = buscarCodigoIbgePorNomeUf(campoMunicipio.value, campoUf.value);
            if (codigo) {
                campoCodigo.value = codigo;
                if (statusCodigo) {
                    statusCodigo.style.color = 'var(--primary)';
                    statusCodigo.textContent = 'Preenchido automaticamente a partir do município/UF.';
                }
            } else if (statusCodigo && campoMunicipio.value.trim() !== '' && campoUf.value.trim() !== '') {
                statusCodigo.style.color = '#FFD1CE';
                statusCodigo.textContent = 'Não foi possível localizar automaticamente; confira o código oficial.';
            }
        }
        fetch('ibge-municipios.json', { cache: 'force-cache' })
            .then(function (resposta) { return resposta.ok ? resposta.json() : []; })
            .then(function (municipios) {
                municipiosIbge = Array.isArray(municipios) ? municipios : [];
                tentarPreencherCodigoIbge();
            })
            .catch(function () { municipiosIbge = []; });

        const campoMunicipioCliente = document.getElementById('cliente_municipio');
        const campoUfCliente = document.getElementById('cliente_uf');
        if (campoMunicipioCliente) campoMunicipioCliente.addEventListener('blur', tentarPreencherCodigoIbge);
        if (campoUfCliente) campoUfCliente.addEventListener('blur', tentarPreencherCodigoIbge);

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
                        document.getElementById('cliente_codigo_ibge_municipio').value = dados.codigo_ibge_municipio || '';
                        document.getElementById('cliente_uf').value = dados.uf || '';
                        tentarPreencherCodigoIbge();

                        statusEl.style.color = 'var(--primary)';
                        statusEl.textContent = 'Dados preenchidos (' + (dados.situacao_cadastral || 'situação não informada') + '). Confira antes de salvar.';
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
