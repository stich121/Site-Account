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
require_once __DIR__ . '/config_app_key.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$podeAdministrar = atualizarNivelAcessoSessao(obterConexao(), $funcionarioId) >= 3;

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicaoCertificados(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function rotuloValidadeCertificado(?string $dataValidade): string
{
    if ($dataValidade === null || $dataValidade === '') {
        return '<span class="muted">—</span>';
    }

    $hoje = new DateTimeImmutable('today');
    $validade = new DateTimeImmutable($dataValidade);
    $diasRestantes = (int) $hoje->diff($validade)->format('%r%a');
    $dataFormatada = htmlspecialchars($validade->format('d/m/Y'), ENT_QUOTES, 'UTF-8');

    if ($diasRestantes < 0) {
        return '<span class="status-inactive">' . $dataFormatada . ' (vencido)</span>';
    }

    if ($diasRestantes <= 30) {
        return '<span style="color: #FFE8A3; font-weight: 700;">' . $dataFormatada . ' (vence em ' . $diasRestantes . ' dia' . ($diasRestantes === 1 ? '' : 's') . ')</span>';
    }

    return '<span class="status-active">' . $dataFormatada . '</span>';
}

function colunaExisteEmpresasCert(PDO $db, string $coluna): bool
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

function prepararColunasCertificadoPagina(PDO $db): void
{
    $campos = [
        'certificado_arquivo' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_arquivo VARCHAR(255) NULL AFTER ambiente_emissao",
        'certificado_senha_cifrada' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_senha_cifrada VARCHAR(512) NULL AFTER certificado_arquivo",
        'certificado_atualizado_em' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_em TIMESTAMP NULL AFTER certificado_senha_cifrada",
        'certificado_atualizado_por' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_atualizado_por INT UNSIGNED NULL AFTER certificado_atualizado_em",
        'certificado_validade' => "ALTER TABLE empresas_emissoras ADD COLUMN certificado_validade DATE NULL AFTER certificado_atualizado_por",
    ];

    foreach ($campos as $coluna => $sql) {
        if (!colunaExisteEmpresasCert($db, $coluna)) {
            $db->exec($sql);
        }
    }
}

function pastaCertificados(): string
{
    return __DIR__ . '/certificados-nfse';
}

function salvarArquivoCertificado(array $arquivo): string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Selecione o arquivo do certificado (.pfx ou .p12).');
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível carregar o arquivo do certificado.');
    }

    if (($arquivo['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('O certificado deve ter no máximo 8 MB.');
    }

    $extensao = strtolower(pathinfo((string) ($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extensao, ['pfx', 'p12'], true)) {
        throw new RuntimeException('Envie um arquivo .pfx ou .p12.');
    }

    $diretorio = pastaCertificados();
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de certificados no servidor.');
    }

    $htaccess = $diretorio . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensao;
    $destino = $diretorio . '/' . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o certificado no servidor.');
    }

    return $nomeArquivo;
}

function ultimosErrosOpenssl(): string
{
    $erros = [];
    while (($msg = openssl_error_string()) !== false) {
        $erros[] = $msg;
    }

    return implode(' | ', $erros);
}

function erroIndicaCriptografiaLegada(string $detalhe): bool
{
    return $detalhe !== '' && (
        stripos($detalhe, 'digital envelope') !== false
        || stripos($detalhe, 'unsupported') !== false
        || stripos($detalhe, 'algorithm') !== false
    );
}

function executarOpenssl(array $argumentos, array $variaveisAmbiente): void
{
    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processo = @proc_open(array_merge(['openssl'], $argumentos), $descritores, $pipes, null, $variaveisAmbiente);

    if (!is_resource($processo)) {
        throw new RuntimeException('Não foi possível executar o openssl no servidor (proc_open indisponível).');
    }

    fclose($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $codigo = proc_close($processo);

    if ($codigo !== 0) {
        throw new RuntimeException(trim($erro) !== '' ? trim($erro) : "openssl retornou código {$codigo}.");
    }
}

// Certificados ICP-Brasil antigos costumam usar RC2/3DES para cifrar o .pfx, algo que o
// OpenSSL 3 (usado pelo PHP 8.3 do servidor) recusa por padrão, mesmo com a senha certa.
// Em vez de obrigar a conversão manual pra cada certificado de cliente, tentamos converter
// automaticamente aqui: extrai com "-legacy" (que ainda entende o formato antigo) e
// reempacota num .pfx novo, reaproveitando a mesma senha, sem o funcionário precisar fazer nada.
function converterCertificadoLegadoParaModerno(string $caminhoOriginal, string $senha): string
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('A função proc_open está desabilitada neste servidor; não é possível converter automaticamente.');
    }

    $pemTemp = tempnam(sys_get_temp_dir(), 'cert_') . '.pem';
    $pfxTemp = tempnam(sys_get_temp_dir(), 'cert_') . '.pfx';
    // getenv() preserva o ambiente do processo (PATH, fontes de entropia do sistema etc.);
    // se passássemos só a senha, o openssl perderia acesso a isso e falharia de formas estranhas.
    $env = getenv() + ['CERT_SENHA' => $senha];

    try {
        executarOpenssl(['pkcs12', '-in', $caminhoOriginal, '-out', $pemTemp, '-nodes', '-legacy', '-passin', 'env:CERT_SENHA'], $env);
        executarOpenssl(['pkcs12', '-export', '-in', $pemTemp, '-out', $pfxTemp, '-passout', 'env:CERT_SENHA'], $env);

        $novoConteudo = file_get_contents($pfxTemp);
        if ($novoConteudo === false) {
            throw new RuntimeException('Não foi possível ler o certificado convertido.');
        }

        return $novoConteudo;
    } finally {
        @unlink($pemTemp);
        @unlink($pfxTemp);
    }
}

function extrairCnpjDoCertificado(array $infoCertificado): ?string
{
    $cn = $infoCertificado['subject']['CN'] ?? '';
    if (is_array($cn)) {
        $cn = $cn[0] ?? '';
    }

    // Certificados e-CNPJ (ICP-Brasil) trazem o CNPJ dentro do Common Name, geralmente no
    // formato "RAZAO SOCIAL:14DIGITOS". Procuramos qualquer sequência de 14 dígitos no CN.
    if (preg_match('/(\d{14})/', (string) $cn, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * @return array{validade: string, cnpj: ?string}
 */
function validarCertificadoPfx(string $caminho, string $senha): array
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Não foi possível ler o arquivo do certificado salvo.');
    }

    $certs = [];
    if (!openssl_pkcs12_read($conteudo, $certs, $senha)) {
        $detalhe = ultimosErrosOpenssl();

        if (erroIndicaCriptografiaLegada($detalhe)) {
            try {
                $conteudoConvertido = converterCertificadoLegadoParaModerno($caminho, $senha);
            } catch (RuntimeException $e) {
                throw new RuntimeException(
                    'Este certificado usa uma criptografia antiga (RC2/3DES) que o servidor não abre diretamente, '
                    . 'e a conversão automática falhou: ' . $e->getMessage()
                );
            }

            if (file_put_contents($caminho, $conteudoConvertido) === false) {
                throw new RuntimeException('Certificado convertido, mas não foi possível salvá-lo no servidor.');
            }

            $certs = [];
            if (!openssl_pkcs12_read($conteudoConvertido, $certs, $senha)) {
                throw new RuntimeException('Certificado convertido automaticamente, mas ainda não abriu. Detalhe: ' . ultimosErrosOpenssl());
            }
        } else {
            throw new RuntimeException(
                'Certificado inválido ou senha incorreta. Confira o arquivo e a senha e tente novamente.'
                . ($detalhe !== '' ? (' Detalhe técnico: ' . $detalhe) : '')
            );
        }
    }

    $infoCertificado = openssl_x509_parse($certs['cert'] ?? '');
    if ($infoCertificado === false || empty($infoCertificado['validTo_time_t'])) {
        throw new RuntimeException('Não foi possível ler a data de validade do certificado.');
    }

    return [
        'validade' => (new DateTimeImmutable('@' . $infoCertificado['validTo_time_t']))->format('Y-m-d'),
        'cnpj' => extrairCnpjDoCertificado($infoCertificado),
    ];
}

$erro = '';
$sucesso = '';

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararColunasCertificadoPagina($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    if ((int) ($stmt->fetchColumn() ?: 0) !== 1) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_certificados'])) {
        $_SESSION['csrf_notas_certificados'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_certificados'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'salvar') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $senha = (string) ($_POST['senha_certificado'] ?? '');

            $stmt = $dbNotas->prepare('SELECT razao_social, cnpj, certificado_arquivo FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $empresaId]);
            $empresaAtual = $stmt->fetch();

            if (!$empresaAtual) {
                $erro = 'Empresa emissora não encontrada.';
            } elseif ($senha === '') {
                $erro = 'Informe a senha do certificado.';
            } else {
                $nomeArquivo = null;

                try {
                    $nomeArquivo = salvarArquivoCertificado($_FILES['certificado'] ?? []);
                    $resultado = validarCertificadoPfx(pastaCertificados() . '/' . $nomeArquivo, $senha);

                    $cnpjEmpresa = preg_replace('/\D/', '', (string) ($empresaAtual['cnpj'] ?? ''));
                    if ($resultado['cnpj'] !== null && $cnpjEmpresa !== '' && $resultado['cnpj'] !== $cnpjEmpresa) {
                        throw new RuntimeException(
                            'Este certificado é de outro CNPJ (' . $resultado['cnpj'] . ') e não bate com o CNPJ cadastrado '
                            . 'para "' . $empresaAtual['razao_social'] . '" (' . $cnpjEmpresa . '). '
                            . 'Confira se selecionou a empresa certa ou se o CNPJ cadastrado da empresa está correto.'
                        );
                    }

                    $stmt = $dbNotas->prepare(
                        'UPDATE empresas_emissoras
                         SET certificado_arquivo = :certificado_arquivo,
                             certificado_senha_cifrada = :certificado_senha_cifrada,
                             certificado_atualizado_em = NOW(),
                             certificado_atualizado_por = :funcionario_id,
                             certificado_validade = :certificado_validade
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'certificado_arquivo' => $nomeArquivo,
                        'certificado_senha_cifrada' => criptografarSegredo($senha),
                        'funcionario_id' => $funcionarioId,
                        'certificado_validade' => $resultado['validade'],
                        'id' => $empresaId,
                    ]);

                    if (!empty($empresaAtual['certificado_arquivo'])) {
                        $arquivoAntigo = pastaCertificados() . '/' . basename($empresaAtual['certificado_arquivo']);
                        if (is_file($arquivoAntigo)) {
                            unlink($arquivoAntigo);
                        }
                    }

                    $sucesso = 'Certificado cadastrado com sucesso para a empresa.';
                } catch (RuntimeException $e) {
                    $erro = $e->getMessage();

                    if ($nomeArquivo !== null) {
                        $arquivoRecemSalvo = pastaCertificados() . '/' . $nomeArquivo;
                        if (is_file($arquivoRecemSalvo)) {
                            unlink($arquivoRecemSalvo);
                        }
                    }
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'remover') {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);

            $stmt = $dbNotas->prepare('SELECT certificado_arquivo FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $empresaId]);
            $empresaAtual = $stmt->fetch();

            if ($empresaAtual) {
                if (!empty($empresaAtual['certificado_arquivo'])) {
                    $arquivoAntigo = pastaCertificados() . '/' . basename($empresaAtual['certificado_arquivo']);
                    if (is_file($arquivoAntigo)) {
                        unlink($arquivoAntigo);
                    }
                }

                $stmt = $dbNotas->prepare(
                    'UPDATE empresas_emissoras
                     SET certificado_arquivo = NULL, certificado_senha_cifrada = NULL,
                         certificado_atualizado_em = NULL, certificado_atualizado_por = NULL,
                         certificado_validade = NULL
                     WHERE id = :id'
                );
                $stmt->execute(['id' => $empresaId]);
                $sucesso = 'Certificado removido.';
            }
        }
    }

    $stmt = $dbNotas->query(
        'SELECT id, razao_social, cnpj, certificado_arquivo, certificado_atualizado_em, certificado_atualizado_por, certificado_validade
         FROM empresas_emissoras
         WHERE ativo = 1
         ORDER BY razao_social ASC'
    );
    $empresas = $stmt->fetchAll();

    $funcionarioIds = array_values(array_unique(array_filter(array_map(
        static fn (array $e): int => (int) ($e['certificado_atualizado_por'] ?? 0),
        $empresas
    ))));
    $usuariosPorId = [];
    if (!empty($funcionarioIds)) {
        $placeholders = implode(',', array_fill(0, count($funcionarioIds), '?'));
        $stmt = $db->prepare("SELECT id, usuario FROM funcionarios WHERE id IN ({$placeholders})");
        $stmt->execute($funcionarioIds);
        $usuariosPorId = array_column($stmt->fetchAll(), 'usuario', 'id');
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar certificados: ' . $e->getMessage();
    $empresas = [];
    $usuariosPorId = [];
}

$csrf = h($_SESSION['csrf_notas_certificados'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado Digital | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'certificados'; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1>Certificado digital</h1>
            <p class="muted">Cadastre o certificado A1 (.pfx/.p12) de cada empresa emissora. O arquivo fica numa pasta bloqueada por senha de servidor (não acessível por URL) e a senha do certificado é guardada cifrada — nunca em texto puro.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (empty($empresas)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. Cadastre uma em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a> primeiro.</div>
        <?php else: ?>
            <section class="panel">
                <h2>Cadastrar / substituir certificado</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="salvar">
                    <div class="form-grid">
                        <div class="field">
                            <label for="empresa_emissora_id">Empresa emissora</label>
                            <select id="empresa_emissora_id" name="empresa_emissora_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo h((string) $empresa['id']); ?>"><?php echo h($empresa['razao_social']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="certificado">Arquivo do certificado (.pfx/.p12)</label>
                            <input id="certificado" name="certificado" type="file" accept=".pfx,.p12" required>
                        </div>
                        <div class="field">
                            <label for="senha_certificado">Senha do certificado</label>
                            <input id="senha_certificado" name="senha_certificado" type="password" required autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="btn" type="submit"><i class="fa-solid fa-upload"></i> Salvar certificado</button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Certificados cadastrados</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>CNPJ</th>
                            <th>Status</th>
                            <th>Validade</th>
                            <th>Atualizado em</th>
                            <th>Atualizado por</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $empresa): ?>
                            <tr>
                                <td><?php echo h($empresa['razao_social']); ?></td>
                                <td><?php echo h($empresa['cnpj'] ?? '—'); ?></td>
                                <td>
                                    <?php if (!empty($empresa['certificado_arquivo'])): ?>
                                        <span class="status-active">Configurado</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Não configurado</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo rotuloValidadeCertificado($empresa['certificado_validade'] ?? null); ?></td>
                                <td><?php echo h($empresa['certificado_atualizado_em'] ? (new DateTimeImmutable($empresa['certificado_atualizado_em']))->format('d/m/Y H:i') : '—'); ?></td>
                                <td><?php echo h(nomeExibicaoCertificados($usuariosPorId[$empresa['certificado_atualizado_por']] ?? null) ?: '—'); ?></td>
                                <td>
                                    <?php if (!empty($empresa['certificado_arquivo'])): ?>
                                        <form method="post" class="row-actions">
                                            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="empresa_emissora_id" value="<?php echo h((string) $empresa['id']); ?>">
                                            <input type="hidden" name="acao" value="remover">
                                            <button class="btn btn-danger btn-small" type="submit"><i class="fa-solid fa-trash"></i> Remover</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
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
