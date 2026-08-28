/**
 * Filtro de Estado da carteira: troca o <select> pelo botão com funil e menu do desenho 1B.
 *
 * MELHORIA PROGRESSIVA, de propósito. O <select> nasce visível e funcionando; só depois que este
 * script roda ele é escondido e o botão aparece. Sem JS a tela continua filtrando — e, mais
 * importante, o <select> segue sendo o campo REAL do formulário: é ele que entra no FormData do
 * XHR e é dele que o `filtro-tabela.js` tira o rótulo do chip ("Estado: Só com atraso"). O botão
 * apenas o pilota.
 */
(function () {
    'use strict';

    function ligar(caixa) {
        var select = caixa.querySelector('select[name="estado"]');
        var rotulo = caixa.querySelector('[data-estado-rotulo]');
        if (!select || !rotulo) {
            return;
        }

        var padrao = caixa.getAttribute('data-estado-padrao') || 'Estado';
        var opcoes = caixa.querySelectorAll('[data-estado-valor]');

        // Só agora o <select> sai de cena. Fora da ordem de tabulação e fora da árvore de
        // acessibilidade para não ser anunciado duas vezes — quem responde por ele é o botão.
        caixa.classList.add('is-melhorado');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        function sincronizar() {
            var atual = select.value;
            var escolhida = select.options[select.selectedIndex];
            rotulo.textContent = atual === '' ? padrao : escolhida.textContent.trim();
            caixa.classList.toggle('is-ativo', atual !== '');
            opcoes.forEach(function (op) {
                var minha = op.getAttribute('data-estado-valor') === atual;
                op.classList.toggle('is-atual', minha);
                op.setAttribute('aria-current', minha ? 'true' : 'false');
            });
        }

        caixa.addEventListener('click', function (e) {
            var opcao = e.target.closest('[data-estado-valor]');
            if (!opcao) {
                return;
            }

            var valor = opcao.getAttribute('data-estado-valor');
            if (select.value === valor) {
                return;  // nada mudou: não vale uma recarga
            }

            select.value = valor;
            sincronizar();
            // Atribuir `.value` por script NÃO dispara `change`, e é no `change` delegado no root
            // que o `filtro-tabela.js` escuta `.js-filtro-campo`. Sem este disparo o botão mudaria
            // de rótulo e a lista ficaria como estava — a pior falha possível num filtro.
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // "Limpar tudo" e o ✕ do chip zeram o campo direto, sem `change`: o rótulo do botão passaria
        // a mentir. O motor mexe no valor dentro do próprio clique, então re-sincronizamos depois.
        var root = caixa.closest('[data-filtro-root]');
        if (root) {
            root.addEventListener('click', function (e) {
                if (e.target.closest('.js-filtro-limpar, .js-filtro-chip-remover')) {
                    window.setTimeout(sincronizar, 0);
                }
            });
        }

        sincronizar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-estado-menu]').forEach(ligar);
    });
})();
