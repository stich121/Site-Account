<?php
/**
 * Botão de alternar tema claro/escuro, reutilizado nas páginas internas que não usam
 * notas-fiscais.css/notas-nav.php. Inclua dentro do <header class="topbar">, depois do
 * .brand, e garanta que assets/css/theme-toggle.css esteja linkado no <head>.
 *
 * A página que inclui isso deve ter, no <img> do logo, id="logoTopo" - o script troca
 * entre logo-branca.png (escuro) e "logo Preta.png" (claro) automaticamente.
 */
?>
<script>
(function () {
    var temaSalvo = localStorage.getItem('notas_tema');
    if (temaSalvo === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<button class="theme-toggle" type="button" id="btnAlternarTema" aria-label="Alternar tema claro/escuro" title="Alternar tema claro/escuro">
    <span class="theme-toggle-track">
        <span class="theme-toggle-thumb">
            <i class="fa-solid fa-sun theme-icon-sun"></i>
            <i class="fa-solid fa-moon theme-icon-moon"></i>
        </span>
    </span>
</button>
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
