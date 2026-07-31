<?php
/**
 * Cadastro de série/numeração e CSC/CSCid de NFC-e por empresa emissora. O token CSC é
 * cifrado com o mesmo padrão de certificado_senha_cifrada (criptografarSegredo()/
 * descriptografarSegredo() de config_app_key.php).
 */

require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/config_db_notas.php';
require_once __DIR__ . '/includes/notas-nfce-schema.php';
require_once __DIR__ . '/config_app_key.php';

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function campoInfo(string $textoHtml): string
{
    return '<span class="info-tooltip-wrap">'
        . '<button type="button" class="info-btn" aria-expanded="false" aria-label="Mais informações sobre este campo"><i class="fa-solid fa-info"></i></button>'
        . '<span class="info-tooltip-box" role="tooltip">' . $textoHtml . '</span>'
        . '</span>';
}

$funcionarioId = (int) $_SESSION['funcionario_id'];
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;
$erro = '';
$sucesso = '';

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararSchemaNfce($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;
    $usuarioRaw = (string) ($dadosFuncionario['usuario'] ?? 'Funcionário');

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_nfce_config'])) {
        $_SESSION['csrf_notas_nfce_config'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfce_config'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (!$podeAdministrar) {
            $erro = 'Somente administradores podem alterar a configuração de NFC-e.';
        } else {
            $empresaId = (int) ($_POST['empresa_emissora_id'] ?? 0);
            $serie = trim((string) ($_POST['nfce_serie'] ?? '1'));
            $numeroBase = (int) ($_POST['nfce_numero_base'] ?? 0);
            $cscId = trim((string) ($_POST['nfce_csc_id'] ?? ''));
            $csc = trim((string) ($_POST['nfce_csc'] ?? ''));

            if ($empresaId <= 0) {
                $erro = 'Selecione uma empresa emissora válida.';
            } elseif ($serie === '' || !preg_match('/^\d{1,3}$/', $serie)) {
                $erro = 'A série deve conter de 1 a 3 dígitos numéricos.';
            } elseif ($cscId !== '' && !preg_match('/^\d{1,6}$/', $cscId)) {
                $erro = 'O CSCid deve conter até 6 dígitos numéricos.';
            } else {
                try {
                    if ($csc !== '') {
                        $stmt = $dbNotas->prepare(
                            'UPDATE empresas_emissoras SET nfce_serie = :serie, nfce_numero_base = :numero_base,
                                nfce_csc_id = :csc_id, nfce_csc_cifrado = :csc_cifrado, nfce_csc_atualizado_em = NOW()
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'serie' => $serie,
                            'numero_base' => $numeroBase,
                            'csc_id' => $cscId !== '' ? $cscId : null,
                            'csc_cifrado' => criptografarSegredo($csc),
                            'id' => $empresaId,
                        ]);
                    } else {
                        $stmt = $dbNotas->prepare(
                            'UPDATE empresas_emissoras SET nfce_serie = :serie, nfce_numero_base = :numero_base, nfce_csc_id = :csc_id
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'serie' => $serie,
                            'numero_base' => $numeroBase,
                            'csc_id' => $cscId !== '' ? $cscId : null,
                            'id' => $empresaId,
                        ]);
                    }
                    $sucesso = 'Configuração de NFC-e salva com sucesso.';
                } catch (PDOException $e) {
                    $erro = 'Não foi possível salvar: ' . $e->getMessage();
                }
            }
        }
    }

    $stmt = $dbNotas->query(
        'SELECT id, razao_social, ambiente_emissao, nfce_serie, nfce_numero_base, nfce_csc_id, nfce_csc_atualizado_em
         FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
    );
    $empresasConfig = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = 'Erro ao carregar a configuração de NFC-e: ' . $e->getMessage();
    $empresasConfig = [];
    $usuarioRaw = 'Funcionário';
}

$csrf = h($_SESSION['csrf_notas_nfce_config'] ?? '');
$usuario = h(trim(str_replace('.', ' ', $usuarioRaw ?? '')));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuração NFC-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotasNfce = 'config'; include __DIR__ . '/includes/notas-nfce-nav.php'; ?>

        <section class="panel">
            <h1>Configuração NFC-e</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Série, numeração inicial e o CSC (Código de Segurança do Contribuinte) + CSCid de cada empresa — obtidos no portal da SEFAZ do estado da empresa. Sem isso a NFC-e não pode ser emitida (o QR Code exige CSC/CSCid válidos).</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (!$podeAdministrar): ?>
            <div class="notice warning">Você pode visualizar, mas somente administradores podem alterar esta configuração.</div>
        <?php endif; ?>

        <?php foreach ($empresasConfig as $empresaConfig): ?>
            <section class="panel">
                <h2><i class="fa-solid fa-building"></i> <?php echo h($empresaConfig['razao_social']); ?> (<?php echo ($empresaConfig['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'; ?>)</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="empresa_emissora_id" value="<?php echo (int) $empresaConfig['id']; ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="serie_<?php echo (int) $empresaConfig['id']; ?>">Série da NFC-e
                                <?php echo campoInfo('Série numérica usada na numeração das NFC-e desta empresa — independente da série de NF-e.'); ?>
                            </label>
                            <input type="text" id="serie_<?php echo (int) $empresaConfig['id']; ?>" name="nfce_serie" value="<?php echo h((string) $empresaConfig['nfce_serie']); ?>" maxlength="3" <?php echo $podeAdministrar ? '' : 'disabled'; ?>>
                        </div>
                        <div class="field">
                            <label for="numero_base_<?php echo (int) $empresaConfig['id']; ?>">Número base
                                <?php echo campoInfo('Piso manual da numeração — útil para "avançar" a numeração quando já existem NFC-e emitidas fora deste sistema. Nunca reduz o próximo número.'); ?>
                            </label>
                            <input type="number" id="numero_base_<?php echo (int) $empresaConfig['id']; ?>" name="nfce_numero_base" value="<?php echo (int) $empresaConfig['nfce_numero_base']; ?>" min="0" <?php echo $podeAdministrar ? '' : 'disabled'; ?>>
                        </div>
                        <div class="field">
                            <label for="csc_id_<?php echo (int) $empresaConfig['id']; ?>">CSCid
                                <?php echo campoInfo('Identificador do CSC, obtido junto com o token no portal da SEFAZ do estado da empresa.'); ?>
                            </label>
                            <input type="text" id="csc_id_<?php echo (int) $empresaConfig['id']; ?>" name="nfce_csc_id" value="<?php echo h((string) ($empresaConfig['nfce_csc_id'] ?? '')); ?>" maxlength="6" <?php echo $podeAdministrar ? '' : 'disabled'; ?>>
                        </div>
                        <div class="field">
                            <label for="csc_<?php echo (int) $empresaConfig['id']; ?>">CSC (token)
                                <?php echo campoInfo('Token secreto do CSC. Fica cifrado no banco (mesmo padrão da senha do certificado) — deixe em branco para manter o valor já salvo.'); ?>
                            </label>
                            <input type="password" id="csc_<?php echo (int) $empresaConfig['id']; ?>" name="nfce_csc" placeholder="<?php echo $empresaConfig['nfce_csc_atualizado_em'] ? 'Já configurado — deixe em branco para manter' : 'Cole o token CSC aqui'; ?>" autocomplete="off" <?php echo $podeAdministrar ? '' : 'disabled'; ?>>
                        </div>
                    </div>
                    <?php if ($podeAdministrar): ?>
                        <div style="margin-top: 1rem;">
                            <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                        </div>
                    <?php endif; ?>
                </form>
            </section>
        <?php endforeach; ?>

        <?php if (empty($empresasConfig)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. Cadastre em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a>.</div>
        <?php endif; ?>
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
