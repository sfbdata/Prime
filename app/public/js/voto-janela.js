/* BlueJus Voto: janelas do desenho 18 (.vMod/.vModC). Botão com data-voto-janela="#id" abre; data-voto-janela-fechar
   e o clique no fundo fecham, e Esc também. Na janela "Abrir votação": "Por tempo definido" mostra o campo Minutos
   (160px); "Sem prazo" o esconde. */
(function () {
    'use strict';

    var origem = null;

    function aberta() { return document.querySelector('.vMod:not([hidden]):not(.vModConfirma)'); }

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
            fechar(ev.target.closest('.vMod'));
            return;
        }
        if (ev.target.classList && ev.target.classList.contains('vMod') && !ev.target.classList.contains('vModConfirma')) {
            fechar(ev.target);
        }
    });

    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-voto-duracao]')) {
            sincronizarDuracao(ev.target.closest('.vMod'));
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') { return; }
        var janela = aberta();
        if (janela) { fechar(janela); }
    });
})();
