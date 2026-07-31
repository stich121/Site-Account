<?php
/**
 * Menu hamburguer universal - caminhos pras áreas principais do sistema (emissor de notas,
 * buscador de notas, fila de envio e painel de ponto), pra dar pra ir de qualquer tela pra
 * qualquer uma dessas sem precisar voltar pro painel. Navegação DENTRO de cada área já existe na
 * própria tela (ex.: abas de notas-nav.php, botões do painel de ponto) - aqui é só o atalho entre
 * as áreas. Os links de "Enviar fila" ficam visíveis pra todo mundo logado.
 *
 * Requer que o CSS de assets/css/notas-fiscais.css OU assets/css/theme-toggle.css já esteja
 * carregado (ambos têm as classes .menu-hamburguer/.menu-dropdown). Não depende de nenhuma
 * função JS da página - o botão "Sair" já vem com a lógica de logout embutida.
 *
 * Saída: o bloco <div class="menu-hamburguer">...</div> completo, incluindo o botão que abre o
 * menu - a página só precisa incluir isso dentro do <header>, sem precisar declarar o próprio
 * botão de menu.
 */
?>
<div class="menu-hamburguer">
    <button class="btn btn-outline" type="button" id="btnMenuHamburguer" aria-haspopup="true" aria-expanded="false" aria-label="Abrir menu">
        <i class="fa-solid fa-bars"></i> Menu
    </button>
    <div class="menu-dropdown" id="menuDropdown">
        <a class="btn btn-outline" href="notas-fiscais"><i class="fa-solid fa-file-invoice"></i> Emissor de notas</a>
        <a class="btn btn-outline" href="notas-fiscais-nfse-adn"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NFS-e</a>
        <a class="btn btn-outline" href="notas-fiscais-nfe-dfe"><i class="fa-solid fa-magnifying-glass"></i> Buscador de NF-e</a>
        <a class="btn btn-outline" href="processar-fila-nfse"><i class="fa-solid fa-paper-plane"></i> Enviar fila NFS-e</a>
        <a class="btn btn-outline" href="processar-fila-nfe"><i class="fa-solid fa-paper-plane"></i> Enviar fila NF-e</a>
        <a class="btn btn-outline" href="painel"><i class="fa-solid fa-clock"></i> Painel de ponto</a>

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
