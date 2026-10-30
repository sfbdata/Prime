/*
 * Minhas Metas: botão e painel "BlueJus IA" (desenho 04 VS 29.10.26).
 *
 * Abrir e fechar o painel (botão, fundo, X e Esc). As perguntas sugeridas e a caixa de pergunta respondem AQUI, no
 * próprio painel: monta o mini chat da IA (central-ia-chat.js) na área de resposta, com `contexto: 'metas'` (o servidor
 * monta as metas da pessoa; o cliente nunca manda o texto). Sem o mini chat carregado, cai no evento antigo
 * `bj-central:perguntar-ia` com {texto}, que abre o assistente na Central.
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

    var area = painel.querySelector('[data-mm-ia-resposta]');
    var chat = null;

    function lerJson(texto) {
        try { return JSON.parse(texto || '{}'); } catch (e) { return {}; }
    }

    function montarChat() {
        if (chat || !window.BJMiniIaChat || !area || !painel.dataset.chatIaRotas) { return chat; }
        chat = window.BJMiniIaChat.montar(area, {
            rotas: lerJson(painel.dataset.chatIaRotas),
            textos: lerJson(painel.dataset.chatIaTextos),
            csrf: painel.dataset.chatIaCsrf || '',
            compacto: true,
            contexto: 'metas'
        });
        return chat;
    }

    function perguntar(texto) {
        texto = String(texto || '').trim();
        if (texto === '') { return; }
        if (!montarChat()) {
            definir(false);
            document.dispatchEvent(new CustomEvent('bj-central:perguntar-ia', { detail: { texto: texto } }));
            campo.value = '';
            return;
        }
        if (chat.perguntar(texto)) {
            campo.value = '';
            area.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
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
