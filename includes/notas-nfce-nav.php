<?php
/**
 * Cabeçalho e navegação da área de NFC-e (venda no balcão) — separada fisicamente da
 * navegação de NF-e/NFS-e (includes/notas-nav.php, não alterado). Mesmo padrão visual/JS
 * (topbar, seletor de empresa ativa, alternador de tema, menu hambúrguer).
 *
 * A página que inclui este arquivo deve, antes do include, opcionalmente definir:
 * - $paginaAtivaNotasNfce (string): 'vendas' | 'emitir' | 'config'
 *
 * Requer $dbNotas (PDO) já aberto e assets/css/notas-fiscais.css já carregado.
 */

$paginaAtivaNotasNfce = $paginaAtivaNotasNfce ?? '';

$abaClasseNfce = static function (string $chave) use ($paginaAtivaNotasNfce): string {
    return $chave === $paginaAtivaNotasNfce ? 'ativo' : '';
};

$empresasEmissorasNavNfce = [];
$dbNotasNavNfce = null;
if (isset($dbNotas) && $dbNotas instanceof PDO) {
    $dbNotasNavNfce = $dbNotas;
} elseif (isset($db) && $db instanceof PDO) {
    $dbNotasNavNfce = $db;
}
if ($dbNotasNavNfce !== null) {
    try {
        $empresasEmissorasNavNfce = $dbNotasNavNfce->query(
            'SELECT id, razao_social, ambiente_emissao FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        $empresasEmissorasNavNfce = [];
    }
}

if (empty($_SESSION['csrf_notas_empresa_ativa'])) {
    $_SESSION['csrf_notas_empresa_ativa'] = bin2hex(random_bytes(32));
}
$csrfEmpresaAtivaNfce = htmlspecialchars($_SESSION['csrf_notas_empresa_ativa'], ENT_QUOTES, 'UTF-8');

$idsEmpresasNavNfce = array_map(static fn(array $empresa): int => (int) $empresa['id'], $empresasEmissorasNavNfce);
if (
    empty($_SESSION['nfse_empresa_emissora_ativa_id'])
    || !in_array((int) $_SESSION['nfse_empresa_emissora_ativa_id'], $idsEmpresasNavNfce, true)
) {
    $_SESSION['nfse_empresa_emissora_ativa_id'] = $idsEmpresasNavNfce[0] ?? 0;
}
$empresaEmissoraAtivaIdNfce = (int) $_SESSION['nfse_empresa_emissora_ativa_id'];
$redirecionarEmpresaAtivaNfce = htmlspecialchars(basename($_SERVER['REQUEST_URI'] ?? 'notas-nfce-vendas'), ENT_QUOTES, 'UTF-8');
?>
<script>
(function () {
    var temaSalvo = localStorage.getItem('notas_tema');
    if (temaSalvo === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<header class="topbar">
    <a class="brand" href="painel" aria-label="Voltar para o painel">
        <img src="logo-branca.png" alt="ACCOUNT Contabilidade" id="logoTopo">
    </a>
    <?php if (!empty($empresasEmissorasNavNfce)): ?>
        <form class="empresa-ativa-form" method="post" action="notas-selecionar-empresa" aria-label="Selecionar empresa emissora para a venda">
            <input type="hidden" name="csrf" value="<?php echo $csrfEmpresaAtivaNfce; ?>">
            <input type="hidden" name="redirecionar_para" value="<?php echo $redirecionarEmpresaAtivaNfce; ?>">
            <label for="empresaEmissoraAtivaNfce" class="empresa-ativa-label"><i class="fa-solid fa-building"></i> Vendendo por</label>
            <select id="empresaEmissoraAtivaNfce" name="empresa_emissora_id" onchange="this.form.submit()">
                <?php foreach ($empresasEmissorasNavNfce as $empresaNav): ?>
                    <option value="<?php echo (int) $empresaNav['id']; ?>" <?php echo $empresaEmissoraAtivaIdNfce === (int) $empresaNav['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($empresaNav['razao_social'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo ($empresaNav['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
    <button class="theme-toggle" type="button" id="btnAlternarTema" aria-label="Alternar tema claro/escuro" title="Alternar tema claro/escuro">
        <span class="theme-toggle-track">
            <span class="theme-toggle-thumb">
                <i class="fa-solid fa-sun theme-icon-sun"></i>
                <i class="fa-solid fa-moon theme-icon-moon"></i>
            </span>
        </span>
    </button>
    <?php include __DIR__ . '/menu-completo.php'; ?>
</header>

<script>
(function () {
    var logo = document.getElementById('logoTopo');
    var botao = document.getElementById('btnAlternarTema');

    function aplicarLogo(tema) {
        if (!logo) return;
        logo.src = tema === 'light' ? 'logo Preta.png' : 'logo-branca.png';
    }

    aplicarLogo(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

    botao.addEventListener('click', function () {
        var atual = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        var novo = atual === 'light' ? 'dark' : 'light';

        botao.classList.add('girando');
        setTimeout(function () { botao.classList.remove('girando'); }, 500);

        document.documentElement.setAttribute('data-theme', novo);
        localStorage.setItem('notas_tema', novo);
        aplicarLogo(novo);
    });
})();
</script>

<nav class="notas-nav" aria-label="Navegação da área de NFC-e">
    <a class="<?php echo $abaClasseNfce('vendas'); ?>" href="notas-nfce-vendas"><i class="fa-solid fa-cash-register"></i> Vendas (NFC-e)</a>
    <a class="<?php echo $abaClasseNfce('emitir'); ?>" href="notas-nfce-emitir"><i class="fa-solid fa-plus"></i> Emitir NFC-e</a>
    <a class="<?php echo $abaClasseNfce('config'); ?>" href="notas-nfce-config"><i class="fa-solid fa-gear"></i> Configuração NFC-e</a>
    <a href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas (NF-e/NFS-e)</a>
</nav>
