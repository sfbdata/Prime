/*
 * Lista /suporte/mensagens: clicar em "Responder por e-mail" abre o e-mail (o link mailto segue normal) e marca a
 * mensagem como respondida no servidor. Quando a marcação volta, a página recarrega para mostrar "Respondida em" e o
 * botão "Desfazer" (e o contador do menu já sem ela).
 */
(function () {
    'use strict';

    document.addEventListener('click', function (evento) {
        var link = evento.target.closest('a[data-suporte-marcar]');
        if (!link) {
            return;
        }

        var url = link.getAttribute('data-suporte-marcar');
        var dados = new FormData();
        dados.append('_token', link.getAttribute('data-suporte-token') || '');
        dados.append('respondida', '1');
        // Um clique só marca uma vez, mesmo que a pessoa clique de novo antes de a página recarregar.
        link.removeAttribute('data-suporte-marcar');

        fetch(url, { method: 'POST', body: dados, credentials: 'same-origin', keepalive: true })
            .then(function (resposta) {
                if (resposta.ok) {
                    window.setTimeout(function () { window.location.reload(); }, 800);
                }
            })
            .catch(function () {});
    });
})();
