/* BlueJus Voto: faixa "Acompanhar" do portal do eleitor. Sem link da sala, "Abrir sala" mostra o aviso na própria página
   (nunca alert nativo). Com link, "Abrir sala" é um <a> que abre em nova aba e este script não participa. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-vc-sala-sem-link]');
        if (!botao) { return; }
        var aviso = document.querySelector('[data-vc-sala-aviso]');
        if (!aviso) { return; }
        aviso.hidden = false;
        botao.setAttribute('aria-expanded', 'true');
    });
}());
