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
 *  - brilho que segue o cursor nos cards (`--mx`/`--my`, só com mouse);
 *  - segmentado de período (`.db-seg-btn[data-de][data-ate]`): preenche os
 *    campos data_de/data_ate do parcial de filtro e dispara UM `change`, que o
 *    filtro-tabela.js trata como qualquer faceta (chips + XHR). As datas vêm
 *    prontas do servidor nos atributos; aqui ninguém calcula calendário. O
 *    botão ativo é o que tem as duas datas iguais às dos campos — recalculado
 *    a cada mudança (digitação, chip removido, "Limpar tudo") — e o indicador
 *    branco desliza até ele.
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

    // ── segmentado de período ─────────────────────────────────────────────

    function camposDeData(root) {
        var form = root.querySelector('[data-filtro-form]');
        if (!form) {
            return null;
        }
        var de  = form.querySelector('[name="data_de"]');
        var ate = form.querySelector('[name="data_ate"]');

        return (de && ate) ? { de: de, ate: ate } : null;
    }

    function medirIndicador(seg) {
        var ind   = seg.querySelector('.db-seg-ind');
        var ativo = seg.querySelector('.db-seg-btn.is-ativo');
        if (!ind) {
            return;
        }
        if (!ativo) {
            seg.classList.remove('has-ativo');

            return;
        }
        ind.style.setProperty('--db-ind-x', ativo.offsetLeft + 'px');
        ind.style.setProperty('--db-ind-w', ativo.offsetWidth + 'px');
        seg.classList.add('has-ativo');
    }

    function marcarPeriodoAtivo(root) {
        var campos = camposDeData(root);
        var vDe  = campos ? campos.de.value : '';
        var vAte = campos ? campos.ate.value : '';

        root.querySelectorAll('.db-seg').forEach(function (seg) {
            seg.querySelectorAll('.db-seg-btn').forEach(function (btn) {
                var on = vDe !== '' && btn.getAttribute('data-de') === vDe && btn.getAttribute('data-ate') === vAte;
                btn.classList.toggle('is-ativo', on);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            medirIndicador(seg);
        });
    }

    function ligarSegmentado(root) {
        root.querySelectorAll('.db-seg').forEach(function (seg) { seg.classList.remove('db-seg--sem-js'); });

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('.db-seg-btn');
            if (btn && root.contains(btn)) {
                var campos = camposDeData(root);
                if (!campos) {
                    return;
                }
                campos.de.value  = btn.getAttribute('data-de') || '';
                campos.ate.value = btn.getAttribute('data-ate') || '';
                marcarPeriodoAtivo(root);
                // um único change: o filtro-tabela.js lê o form inteiro (chips + XHR)
                campos.ate.dispatchEvent(new Event('change', { bubbles: true }));

                return;
            }

            // chip removido ou "Limpar tudo": o filtro-tabela.js (registrado antes) já
            // zerou os campos quando este handler roda — basta reler.
            if (e.target.closest('.js-filtro-chip-remover, .js-filtro-limpar')) {
                marcarPeriodoAtivo(root);
            }
        });

        // datas digitadas/escolhidas no calendário
        root.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('js-filtro-campo')) {
                marcarPeriodoAtivo(root);
            }
        });

        var remedir = function () { root.querySelectorAll('.db-seg').forEach(medirIndicador); };
        window.addEventListener('resize', remedir);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(remedir);
        }

        marcarPeriodoAtivo(root);
    }

    function iniciar(root) {
        ligarSegmentado(root);

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
            new MutationObserver(function () {
                // Primeiro resultado novo: as animacoes de ENTRADA ja rodaram uma
                // vez; a partir daqui so os numeros contam (ver CSS .db-pronto).
                root.classList.add('db-pronto');
                contarCards(resultado, false);
            }).observe(resultado, { childList: true });
        }

        contarCards(resultado, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.db-page [data-filtro-root]').forEach(iniciar);
    });
})();
