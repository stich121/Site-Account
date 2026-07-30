<?php
/**
 * Cabeçalho e navegação compartilhados das telas do emissor fiscal.
 *
 * A página que inclui este arquivo deve, antes do include, opcionalmente definir:
 * - $paginaAtivaNotas (string): 'notas' | 'emitir' | 'clientes' | 'certificados' | 'empresas' | 'produtos'
 * - $podeAdministrar (bool): controla a exibição dos itens administrativos
 *
 * Requer que exista, na página, a função JS sair() e o CSS de
 * assets/css/notas-fiscais.css já carregado.
 */

$paginaAtivaNotas = $paginaAtivaNotas ?? '';
$podeAdministrar = $podeAdministrar ?? false;

$abaClasse = static function (string $chave) use ($paginaAtivaNotas): string {
    return $chave === $paginaAtivaNotas ? 'ativo' : '';
};

$empresasEmissorasNav = [];
$dbNotasNav = null;
if (isset($dbNotas) && $dbNotas instanceof PDO) {
    $dbNotasNav = $dbNotas;
} elseif (isset($db) && $db instanceof PDO) {
    $dbNotasNav = $db;
}
if ($dbNotasNav !== null) {
    try {
        $empresasEmissorasNav = $dbNotasNav->query(
            'SELECT id, razao_social, ambiente_emissao FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        $empresasEmissorasNav = [];
    }
}

if (empty($_SESSION['csrf_notas_empresa_ativa'])) {
    $_SESSION['csrf_notas_empresa_ativa'] = bin2hex(random_bytes(32));
}
$csrfEmpresaAtiva = htmlspecialchars($_SESSION['csrf_notas_empresa_ativa'], ENT_QUOTES, 'UTF-8');

$idsEmpresasNav = array_map(static fn(array $empresa): int => (int) $empresa['id'], $empresasEmissorasNav);
if (
    empty($_SESSION['nfse_empresa_emissora_ativa_id'])
    || !in_array((int) $_SESSION['nfse_empresa_emissora_ativa_id'], $idsEmpresasNav, true)
) {
    $_SESSION['nfse_empresa_emissora_ativa_id'] = $idsEmpresasNav[0] ?? 0;
}
$empresaEmissoraAtivaId = (int) $_SESSION['nfse_empresa_emissora_ativa_id'];
$redirecionarEmpresaAtiva = htmlspecialchars(basename($_SERVER['REQUEST_URI'] ?? 'notas-fiscais'), ENT_QUOTES, 'UTF-8');
?>
<header class="topbar">
    <a class="brand" href="painel" aria-label="Voltar para o painel">
        <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
    </a>
    <?php if (!empty($empresasEmissorasNav)): ?>
        <form class="empresa-ativa-form" method="post" action="notas-selecionar-empresa" aria-label="Selecionar empresa prestadora para emissão">
            <input type="hidden" name="csrf" value="<?php echo $csrfEmpresaAtiva; ?>">
            <input type="hidden" name="redirecionar_para" value="<?php echo $redirecionarEmpresaAtiva; ?>">
            <label for="empresaEmissoraAtiva" class="empresa-ativa-label"><i class="fa-solid fa-building"></i> Emitindo por</label>
            <select id="empresaEmissoraAtiva" name="empresa_emissora_id" onchange="this.form.submit()">
                <?php foreach ($empresasEmissorasNav as $empresaNav): ?>
                    <option value="<?php echo (int) $empresaNav['id']; ?>" <?php echo $empresaEmissoraAtivaId === (int) $empresaNav['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($empresaNav['razao_social'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo ($empresaNav['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
    <div class="menu-hamburguer">
        <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
            <i class="fa-solid fa-bars"></i> Menu
        </button>
        <div class="menu-dropdown" id="menuDropdown">
            <?php if ($podeAdministrar): ?>
                <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Processar fila NFS-e</a>
                <a class="btn btn-outline" href="processar-nfse-adn-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (ADN)</a>
                <a class="btn btn-outline" href="processar-fila-nfe"><i class="fa-solid fa-paper-plane"></i> Processar fila NF-e</a>
                <a class="btn btn-outline" href="processar-nfe-dfe-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (NF-e)</a>
                <a class="btn btn-outline" href="nfe-diagnostico"><i class="fa-solid fa-stethoscope"></i> Diagnóstico NF-e</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
            <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
            <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
        </div>
    </div>
</header>

<nav class="notas-nav" aria-label="Navegação da área fiscal">
    <a class="<?php echo $abaClasse('notas'); ?>" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
    <a class="<?php echo $abaClasse('emitir_produto'); ?>" href="notas-emitir-produto"><i class="fa-solid fa-box"></i> Emitir NF-e</a>
    <a class="<?php echo $abaClasse('emitir_servico'); ?>" href="notas-emitir-servico"><i class="fa-solid fa-file-circle-plus"></i> Emitir NFS-e</a>
    <a class="<?php echo $abaClasse('clientes'); ?>" href="notas-clientes"><i class="fa-solid fa-users"></i> Clientes</a>
    <a class="<?php echo $abaClasse('certificados'); ?>" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
    <?php if ($podeAdministrar): ?>
        <a class="<?php echo $abaClasse('empresas'); ?>" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
        <a class="<?php echo $abaClasse('produtos'); ?>" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
    <?php endif; ?>
</nav>
