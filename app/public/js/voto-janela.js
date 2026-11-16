/* BlueJus Voto: janelas do desenho 18 (.vModAbrir). Botão com data-voto-janela="#id" abre; data-voto-janela-fechar
   e o clique no fundo fecham, e Esc também. Na janela "Abrir votação": "Por tempo definido" mostra o campo Minutos
   (160px); "Sem prazo" o esconde. */
(function () {
    'use strict';

    var origem = null;

    function aberta() { return document.querySelector('[data-voto-janela-raiz]:not([hidden])'); }

    function sincronizarDuracao(janela) {
        var duracao = janela.querySelector('[data-voto-duracao]');
        var campo = janela.querySelector('[data-voto-minutos]');
        if (!duracao || !campo) { return; }
        var porTempo = duracao.value === 'min';
        campo.hidden = !porTempo;
    }

    function abrir(janela, botao) {
        origem = botao;
        janela.hidden = false;
        sincronizarDuracao(janela);
        var primeiro = janela.querySelector('select, input:not([type=hidden]):not(:disabled), button');
        if (primeiro) { primeiro.focus(); }
    }

    function fechar(janela) {
        janela.hidden = true;
        if (origem) { origem.focus(); origem = null; }
    }

    document.addEventListener('click', function (ev) {
        var gatilho = ev.target.closest('[data-voto-janela]');
        if (gatilho) {
            var alvo = document.querySelector(gatilho.getAttribute('data-voto-janela'));
            if (alvo) { abrir(alvo, gatilho); }
            return;
        }
        if (ev.target.closest('[data-voto-janela-fechar]')) {
            fechar(ev.target.closest('[data-voto-janela-raiz]'));
            return;
        }
        if (ev.target.hasAttribute && ev.target.hasAttribute('data-voto-janela-raiz')) {
            fechar(ev.target);
        }
    });

    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-voto-duracao]')) {
            sincronizarDuracao(ev.target.closest('[data-voto-janela-raiz]'));
        }
    });

    document.addEventListener('keydown', function (ev) {
        var janela = aberta();
        if (!janela) { return; }
        if (ev.key === 'Escape') { fechar(janela); return; }
        if (ev.key !== 'Tab') { return; }
        /* Prende o Tab dentro da janela aberta. */
        var foco = Array.prototype.slice.call(janela.querySelectorAll('select, input:not([type=hidden]):not(:disabled), button:not(:disabled), a[href]'))
            .filter(function (e) { return e.offsetParent !== null; });
        if (!foco.length) { return; }
        var primeiro = foco[0], ultimo = foco[foco.length - 1];
        if (!janela.contains(document.activeElement)) { ev.preventDefault(); primeiro.focus(); }
        else if (ev.shiftKey && document.activeElement === primeiro) { ev.preventDefault(); ultimo.focus(); }
        else if (!ev.shiftKey && document.activeElement === ultimo) { ev.preventDefault(); primeiro.focus(); }
    });
})();
