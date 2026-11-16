/* BlueJus Voto: "Lembrar quem não votou" (desenho 18). O item do menu do cartão é um link para a janela montada pelo
   servidor (voto_assembleia_lembrete); aqui o clique busca o fragmento e o abre sobre a tela. Fechar, fundo e Esc são do
   voto-janela.js. Sem JS, o link abre o fragmento puro. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var link = ev.target.closest && ev.target.closest('a[data-voto-lembrete], .vKm a[href$="/lembrete"]');
        if (!link || ev.metaKey || ev.ctrlKey || ev.shiftKey) { return; }
        ev.preventDefault();
        fetch(link.getAttribute('href'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('falhou'); } return r.text(); })
            .then(function (html) {
                var anterior = document.querySelector('.voto-lembrar');
                if (anterior) { anterior.remove(); }
                var pai = document.querySelector('.voto-tela') || document.body;
                var caixa = document.createElement('div');
                caixa.innerHTML = html.trim();
                var janela = caixa.firstElementChild;
                if (!janela) { return; }
                pai.appendChild(janela);
                janela.hidden = false;
                var primeiro = janela.querySelector('button, a[href]');
                if (primeiro) { primeiro.focus(); }
            })
            .catch(function () { window.location.href = link.getAttribute('href'); });
    });
})();
