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
 *    branco desliza até ele;
 *  - legendas dos cards em toque (`.db-card-legenda--hover`): com mouse elas
 *    aparecem no hover (só CSS); no toque, tocar o card liga `is-tocado` nele
 *    (e desliga nos outros) — tocar fora apaga;
 *  - Exportar PDF (`.js-db-exportar-pdf`): window.print() com o document.title
 *    trocado pelo nome do arquivo do desenho. No `beforeprint` (botão ou Ctrl+P)
 *    o cabeçalho só-impressão é reescrito com o período/filtros do momento e o
 *    tema escuro é trocado pelo claro até o `afterprint` (o papel é branco).
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

    // ── legendas em toque ─────────────────────────────────────────────────

    function ligarLegendasEmToque(root) {
        root.addEventListener('pointerup', function (e) {
            if (e.pointerType !== 'touch' && e.pointerType !== 'pen') {
                return;
            }
            var card = e.target.closest('.db-stat-card');
            root.querySelectorAll('.db-stat-card.is-tocado').forEach(function (c) {
                if (c !== card) {
                    c.classList.remove('is-tocado');
                }
            });
            if (card) {
                card.classList.toggle('is-tocado');
            }
        });
    }

    // ── Exportar PDF ──────────────────────────────────────────────────────

    function p2(n) {
        return String(n).padStart(2, '0');
    }

    function dataBr(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');

        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }

    function textoDoSelect(form, nome, vazio) {
        var el = form ? form.querySelector('select[name="' + nome + '"]') : null;
        if (!el || el.value === '') {
            return vazio;
        }
        var opt = el.options[el.selectedIndex];

        return opt ? opt.textContent.trim() : vazio;
    }

    function atualizarCabecalhoImpressao(root) {
        var form   = root.querySelector('[data-filtro-form]');
        var campos = camposDeData(root);
        var periodo = root.querySelector('.db-print-periodo');
        var facetas = root.querySelector('.db-print-facetas');
        var quando  = root.querySelector('.db-print-quando');

        if (periodo && campos) {
            periodo.textContent = (dataBr(campos.de.value) || 'início') + ' a ' + (dataBr(campos.ate.value) || 'hoje');
        }
        if (facetas) {
            facetas.textContent = textoDoSelect(form, 'responsavel', 'Todos os responsáveis')
                + ' · ' + textoDoSelect(form, 'cargo', 'Todos os cargos');
        }
        if (quando) {
            var d = new Date();
            quando.textContent = p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + '/' + d.getFullYear()
                + ' às ' + p2(d.getHours()) + ':' + p2(d.getMinutes());
        }
    }

    function ligarExportarPdf(root) {
        var html = document.documentElement;
        var temaAntes = null;

        window.addEventListener('beforeprint', function () {
            atualizarCabecalhoImpressao(root);
            if (html.getAttribute('data-bs-theme') === 'dark') {
                temaAntes = 'dark';
                html.setAttribute('data-bs-theme', 'light');
            }
        });
        window.addEventListener('afterprint', function () {
            if (temaAntes !== null) {
                html.setAttribute('data-bs-theme', temaAntes);
                temaAntes = null;
            }
        });

        root.addEventListener('click', function (e) {
            if (!e.target.closest('.js-db-exportar-pdf')) {
                return;
            }
            var tituloAntes = document.title;
            var d = new Date();
            document.title = 'BlueJus Relatorio de desempenho ' + p2(d.getDate()) + '.' + p2(d.getMonth() + 1) + '.' + d.getFullYear();
            window.setTimeout(function () {
                window.print();
                window.setTimeout(function () { document.title = tituloAntes; }, 400);
            }, 60);
        });
    }

    function iniciar(root) {
        ligarSegmentado(root);
        ligarLegendasEmToque(root);
        ligarExportarPdf(root);

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
