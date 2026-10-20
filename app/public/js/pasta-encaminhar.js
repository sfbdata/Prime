/**
 * "Encaminhar via Chat I.A" na tela da pasta (desenho 02, D:1405/1936/2463/6305/6869/6958; spec
 * `docs/specs/encaminhar-via-chat-ia.md` §4.1).
 *
 * Um ouvinte só, por delegação no documento: o botão ↪ do cartão (Dados, Detalhes, Financeiro) e o
 * item do ⋮ do arquivo do Financeiro levam `.js-encaminhar` com três `data-*` renderizados pelo
 * Twig (ou, no cartão montado em JS, com o id numérico que o servidor devolveu):
 * `data-encaminhar-pasta` (id da pasta), `data-encaminhar-tipo` (um valor de
 * `TipoDeItemEncaminhado`) e `data-encaminhar-id` (id do item). Os itens "Encaminhar via Chat I.A"
 * dos menus de registro clicam o ↪ do próprio cartão, como o Responder: o de Dados e o do
 * Financeiro no script de cada menu; o do ⋮ da observação de Detalhes (`.js-obs-encaminhar`) aqui,
 * para não mexer no script de Detalhes (o menu fecha pelo `ps:fechar-popovers`).
 *
 * O clique dispara `bj-central:despachar` com `{pastaId, tipo, itemId}`: o evento, e não
 * `window.BJCentral`, para não depender da ordem de carga do lançador flutuante. Só
 * identificadores saem da tela; o título e o resumo do cartão do despacho vêm do servidor.
 * Sem a Central na página (`central_disponivel()` falso) o Twig nem monta os botões.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        const doMenu = e.target && e.target.closest ? e.target.closest('.js-obs-encaminhar') : null;
        if (doMenu) {
            const artigo = doMenu.closest('.ps-anotacao');
            const botao  = artigo ? artigo.querySelector(':scope > .ps-anotacao-topo > .js-encaminhar') : null;
            document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
            if (botao) { botao.click(); }
            return;
        }

        const alvo = e.target && e.target.closest ? e.target.closest('.js-encaminhar[data-encaminhar-tipo]') : null;
        if (!alvo) { return; }

        const pastaId = Number(alvo.getAttribute('data-encaminhar-pasta'));
        const itemId  = Number(alvo.getAttribute('data-encaminhar-id'));
        const tipo    = String(alvo.getAttribute('data-encaminhar-tipo') || '');
        if (!pastaId || !itemId || !/^[a-z_]+$/.test(tipo)) { return; }

        e.preventDefault();
        document.dispatchEvent(new CustomEvent('bj-central:despachar', {
            detail: { pastaId: pastaId, tipo: tipo, itemId: itemId },
        }));
    });
})();
