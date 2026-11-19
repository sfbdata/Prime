/* =============================================================================
   pasta-recebida.js: as escritas da página reduzida da pasta recebida de outra conta (spec acesso-restrito-e-compartilhar
   §4.5.1, fatia 9b; `pasta/recebida.html.twig`). Os formulários `[data-pr-form]` (anotação, troca de fase e envio de
   arquivo) vão ao servidor por fetch, com o próprio FormData (o token CSRF vai junto). Sucesso: a página recarrega e
   mostra a anotação, a fase ou o documento novo. Recusa: a mensagem do servidor (`{erro}`) aparece no `[data-pr-erro]`
   do formulário; sem ela (404, falha de rede), o texto de `data-erro` da página.
   ============================================================================= */
(function () {
    'use strict';

    var pagina = document.querySelector('.pr-page');
    if (!pagina) { return; }
    var erroPadrao = pagina.getAttribute('data-erro') || '';

    function mostrarErro(form, texto) {
        var alvo = form.querySelector('[data-pr-erro]');
        if (!alvo) { return; }
        alvo.textContent = texto || erroPadrao;
        alvo.hidden = false;
    }

    function enviar(form) {
        var botao = form.querySelector('[type="submit"]');
        var alvo = form.querySelector('[data-pr-erro]');
        if (alvo) { alvo.hidden = true; }
        if (botao) { botao.disabled = true; }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (r) {
            if (r.ok) {
                window.location.reload();
                return;
            }
            mostrarErro(form, r.j && r.j.erro);
            if (botao) { botao.disabled = false; }
        }, function () {
            mostrarErro(form, '');
            if (botao) { botao.disabled = false; }
        });
    }

    pagina.querySelectorAll('[data-pr-form]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            enviar(form);
        });
    });
}());
