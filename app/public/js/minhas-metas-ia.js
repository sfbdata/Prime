/*
 * Minhas Metas: botão e painel "BlueJus IA" (desenho 04 VS 29.10.26).
 *
 * Abrir e fechar o painel (botão, fundo, X e Esc). As perguntas sugeridas e a caixa de pergunta não respondem aqui:
 * disparam `bj-central:perguntar-ia` com {texto}, e a Central abre o assistente BlueJus IA com a pergunta preenchida.
 */
(function () {
    'use strict';

    var botao = document.querySelector('[data-mm-ia-abrir]');
    var painel = document.getElementById('mmIaPainel');
    if (!botao || !painel) { return; }
    var fundo = document.querySelector('.mm-ia-fundo');
    var campo = painel.querySelector('[data-mm-ia-campo]');

    function definir(aberto) {
        painel.hidden = !aberto;
        fundo.hidden = !aberto;
        botao.dataset.mini = aberto ? '1' : '0';
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        if (aberto) { campo.focus(); } else { botao.focus(); }
    }

    function perguntar(texto) {
        texto = String(texto || '').trim();
        if (texto === '') { return; }
        definir(false);
        document.dispatchEvent(new CustomEvent('bj-central:perguntar-ia', { detail: { texto: texto } }));
        campo.value = '';
    }

    botao.addEventListener('click', function () { definir(painel.hidden); });
    document.querySelectorAll('[data-mm-ia-fechar]').forEach(function (el) {
        el.addEventListener('click', function () { definir(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !painel.hidden) { definir(false); }
    });
    painel.querySelectorAll('[data-mm-ia-perguntar]').forEach(function (el) {
        el.addEventListener('click', function () { perguntar(el.dataset.mmIaPerguntar); });
    });
    painel.querySelector('[data-mm-ia-enviar]').addEventListener('click', function () { perguntar(campo.value); });
    campo.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); perguntar(campo.value); }
    });
})();
