/* BlueJus Voto: aviso "Ainda não liberado". Todo botão ou link com data-voto-indisponivel="motivo" abre, em vez de
   não fazer nada ou dar erro, a janela de _janela_indisponivel.html.twig com o motivo de uma linha. Fecha pelo
   voto-janela.js (botão, fundo e Esc). Uso: <button type="button" class="vB" data-voto-indisponivel="Motivo.">. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var gatilho = ev.target.closest('[data-voto-indisponivel]');
        if (!gatilho) { return; }
        var janela = document.querySelector('[data-voto-indisponivel-janela]');
        if (!janela) { return; }
        ev.preventDefault();
        janela.querySelector('[data-voto-indisponivel-motivo]').textContent = gatilho.getAttribute('data-voto-indisponivel');
        janela.hidden = false;
        var fechar = janela.querySelector('[data-voto-janela-fechar]');
        if (fechar) { fechar.focus(); }
    });
})();
