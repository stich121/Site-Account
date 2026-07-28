<?php
/**
 * Cabeçalho e navegação compartilhados das telas do emissor fiscal.
 *
 * A página que inclui este arquivo deve, antes do include, opcionalmente definir:
 * - $paginaAtivaNotas (string): 'notas' | 'emitir' | 'certificados' | 'empresas' | 'produtos'
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
?>
<header class="topbar">
    <a class="brand" href="painel" aria-label="Voltar para o painel">
        <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
    </a>
    <div class="menu-hamburguer">
        <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
            <i class="fa-solid fa-bars"></i> Menu
        </button>
        <div class="menu-dropdown" id="menuDropdown">
            <a class="btn btn-outline" href="notas-emitir#cadastroCliente"><i class="fa-solid fa-user-plus"></i> Cadastrar clientes</a>
            <?php if ($podeAdministrar): ?>
                <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Processar fila NFS-e</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
            <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
            <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
        </div>
    </div>
</header>

<nav class="notas-nav" aria-label="Navegação da área fiscal">
    <a class="<?php echo $abaClasse('notas'); ?>" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
    <a class="<?php echo $abaClasse('emitir'); ?>" href="notas-emitir"><i class="fa-solid fa-file-circle-plus"></i> Emitir nota</a>
    <a class="<?php echo $abaClasse('certificados'); ?>" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
    <?php if ($podeAdministrar): ?>
        <a class="<?php echo $abaClasse('empresas'); ?>" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
        <a class="<?php echo $abaClasse('produtos'); ?>" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
    <?php endif; ?>
</nav>
