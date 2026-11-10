/**
 * Aba "Auditoria" da tela do Ponto (docs/specs/ponto-desenho-0811.md, E9, P64). O painel é buscado pelo
 * js/ponto-paineis.js (`data-carregar` = rota `ponto_auditoria`); aqui só a troca de página, por delegação no
 * documento, porque o HTML do painel chega por `innerHTML` e os scripts dele não rodam.
 *
 * O botão de página traz o endereço da página em `data-auditoria-pagina`: ele passa a ser o `data-carregar` do painel
 * (o "Tentar de novo" de uma falha repete a mesma página) e o painel é buscado de novo.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (evento) {
        const botao = evento.target instanceof Element ? evento.target.closest('[data-auditoria-pagina]') : null;
        if (!botao || botao.disabled || !window.PontoPaineis) {
            return;
        }
        const painel = botao.closest('[data-carregar]');
        if (!painel) {
            return;
        }
        evento.preventDefault();
        painel.dataset.carregar = botao.dataset.auditoriaPagina;
        window.PontoPaineis.recarregar(painel).then(function () {
            const topo = painel.querySelector('.pe-audit-rolagem');
            if (topo) {
                topo.scrollTop = 0;
            }
        });
    });
})();
