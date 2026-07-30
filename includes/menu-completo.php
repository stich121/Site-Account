<?php
/**
 * Menu hamburguer universal - lista TODOS os caminhos do sistema (área fiscal + área de
 * ponto/RH), pra dar pra ir de qualquer tela pra qualquer outra sem precisar voltar pro painel.
 *
 * Uso: a página que inclui isso deve, antes do include, opcionalmente definir:
 * - $podeAdministrar (bool): mostra os itens administrativos (nível de acesso >= 3)
 *
 * Requer que o CSS de assets/css/notas-fiscais.css OU assets/css/theme-toggle.css já esteja
 * carregado (ambos têm as classes .menu-hamburguer/.menu-dropdown). Não depende de nenhuma
 * função JS da página - o botão "Sair" já vem com a lógica de logout embutida.
 *
 * Saída: o bloco <div class="menu-hamburguer">...</div> completo, incluindo o botão que abre o
 * menu - a página só precisa incluir isso dentro do <header>, sem precisar declarar o próprio
 * botão de menu.
 */

$podeAdministrar = $podeAdministrar ?? false;
?>
<div class="menu-hamburguer">
    <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
        <i class="fa-solid fa-bars"></i> Menu
    </button>
    <div class="menu-dropdown" id="menuDropdown">
        <p class="menu-dropdown-titulo">Fiscal</p>
        <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Notas fiscais</a>
        <a class="btn btn-outline" href="notas-emitir-produto"><i class="fa-solid fa-box"></i> Emitir NF-e</a>
        <a class="btn btn-outline" href="notas-emitir-servico"><i class="fa-solid fa-file-circle-plus"></i> Emitir NFS-e</a>
        <a class="btn btn-outline" href="notas-fiscais-nfse-adn"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NFS-e</a>
        <a class="btn btn-outline" href="notas-fiscais-nfe-dfe"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NF-e</a>
        <a class="btn btn-outline" href="notas-clientes"><i class="fa-solid fa-users"></i> Clientes</a>
        <a class="btn btn-outline" href="notas-certificados"><i class="fa-solid fa-key"></i> Certificado digital</a>
        <a class="btn btn-outline" href="notas-empresas-emissoras"><i class="fa-solid fa-building"></i> Empresas emissoras</a>
        <?php if ($podeAdministrar): ?>
            <a class="btn btn-outline" href="notas-produtos-servicos"><i class="fa-solid fa-boxes-stacked"></i> Produtos/Serviços</a>
            <a class="btn btn-outline" href="nfe-diagnostico"><i class="fa-solid fa-stethoscope"></i> Diagnóstico NF-e</a>
            <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Processar fila NFS-e</a>
            <a class="btn btn-outline" href="processar-fila-nfe"><i class="fa-solid fa-paper-plane"></i> Processar fila NF-e</a>
            <a class="btn btn-outline" href="processar-nfse-adn-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (ADN)</a>
            <a class="btn btn-outline" href="processar-nfe-dfe-automatico"><i class="fa-solid fa-rotate"></i> Sincronização automática (NF-e)</a>
        <?php endif; ?>

        <hr class="menu-dropdown-separador">

        <p class="menu-dropdown-titulo">Ponto e RH</p>
        <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>
        <a class="btn btn-outline" href="programas-funcionarios"><i class="fa-solid fa-laptop-code"></i> Programas dos funcionários</a>
        <?php if ($podeAdministrar): ?>
            <a class="btn btn-outline" href="gerenciar-funcionarios"><i class="fa-solid fa-users-gear"></i> Gerenciar funcionários</a>
            <a class="btn btn-outline" href="afastamentos"><i class="fa-solid fa-person-walking-arrow-right"></i> Afastamentos</a>
            <a class="btn btn-outline" href="tipos-afastamentos"><i class="fa-solid fa-sliders"></i> Tipos de afastamento</a>
            <a class="btn btn-outline" href="apuracao-ponto"><i class="fa-solid fa-calculator"></i> Apuração de ponto</a>
            <a class="btn btn-outline" href="banco-horas"><i class="fa-solid fa-hourglass-half"></i> Banco de horas</a>
            <a class="btn btn-outline" href="historico-espelho"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de espelho</a>
            <a class="btn btn-outline" href="historico-download"><i class="fa-solid fa-download"></i> Histórico de download</a>
        <?php endif; ?>

        <hr class="menu-dropdown-separador">

        <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
        <button class="btn btn-outline" type="button" id="btnMenuCompletoSair"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
    </div>
</div>
<script>
(function () {
    var botaoAbrir = document.getElementById('btnMenuHamburguer');
    var dropdown = document.getElementById('menuDropdown');
    var botaoSair = document.getElementById('btnMenuCompletoSair');

    if (botaoAbrir && dropdown) {
        botaoAbrir.addEventListener('click', function (evento) {
            evento.stopPropagation();
            var aberto = dropdown.classList.toggle('aberto');
            botaoAbrir.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        });
        document.addEventListener('click', function (evento) {
            if (!dropdown.contains(evento.target) && evento.target !== botaoAbrir) {
                dropdown.classList.remove('aberto');
                botaoAbrir.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (botaoSair) {
        botaoSair.addEventListener('click', function () {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1').then(function () {
                window.location.href = '/';
            });
        });
    }
})();
</script>
