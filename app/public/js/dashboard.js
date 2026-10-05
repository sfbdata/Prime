/**
 * Dashboard — comportamento de tela do visual aprovado.
 *
 * Tudo é delegado no `[data-filtro-root]` persistente: o fragmento de cards e
 * tabela é trocado por innerHTML a cada filtro (public/js/filtro-tabela.js),
 * então nada aqui segura referência a elemento de dentro dele.
 *
 *  - contagem dos números dos cards (`.db-stat-num[data-alvo]`): sobem de 0 até
 *    o valor ao carregar (1,1s, easeOutQuart, começando 0,38s depois, quando o
 *    card já entrou) e, ao trocar filtro, do valor atual até o novo em 0,65s.
 *    O valor final já vem no HTML, então sem JS (ou com movimento reduzido)
 *    a tela mostra o resultado direto;
 *  - brilho que segue o cursor nos cards (`--mx`/`--my`, só com mouse).
 */
(function () {
    'use strict';

    var reduz = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // último valor exibido por card (data-card) — a próxima contagem parte dele
    var exibidos = {};

    function easeOutQuart(t) {
        return 1 - Math.pow(1 - t, 4);
    }

    function contar(el, de, ate, duracao, atraso) {
        var inicio = null;

        function passo(ts) {
            if (!el.isConnected) {
                return; // o fragmento foi trocado no meio da contagem
            }
            if (inicio === null) {
                inicio = ts;
            }
            var t = Math.min(1, (ts - inicio) / duracao);
            el.textContent = String(Math.round(de + (ate - de) * easeOutQuart(t)));
            if (t < 1) {
                window.requestAnimationFrame(passo);
            }
        }

        el.textContent = String(de);
        window.setTimeout(function () { window.requestAnimationFrame(passo); }, atraso);
    }

    function contarCards(area, primeiraCarga) {
        area.querySelectorAll('.db-stat-num[data-alvo]').forEach(function (el) {
            var alvo = parseInt(el.getAttribute('data-alvo'), 10);
            var chave = el.getAttribute('data-card') || '';
            if (isNaN(alvo)) {
                return;
            }

            var de = primeiraCarga ? 0 : (exibidos[chave] !== undefined ? exibidos[chave] : 0);
            exibidos[chave] = alvo;

            if (reduz || de === alvo) {
                el.textContent = String(alvo);

                return;
            }

            contar(el, de, alvo, primeiraCarga ? 1100 : 650, primeiraCarga ? 380 : 0);
        });
    }

    function iniciar(root) {
        var resultado = root.querySelector('[data-filtro-resultado]');
        if (!resultado) {
            return;
        }

        // Brilho e grade do hover seguem o cursor (só mouse; em toque nada acontece).
        root.addEventListener('pointermove', function (e) {
            if (e.pointerType !== 'mouse') {
                return;
            }
            var card = e.target.closest('.db-stat-card');
            if (!card) {
                return;
            }
            var r = card.getBoundingClientRect();
            card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            card.style.setProperty('--my', (e.clientY - r.top) + 'px');
        });

        // O filtro-tabela.js troca o fragmento por innerHTML sem avisar: observar
        // os filhos diretos da região é o jeito de saber que chegou resultado novo.
        if (window.MutationObserver) {
            new MutationObserver(function () { contarCards(resultado, false); })
                .observe(resultado, { childList: true });
        }

        contarCards(resultado, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.db-page [data-filtro-root]').forEach(iniciar);
    });
})();
