/**
 * Filtro de tabela reutilizável (padrão Expediente).
 *
 * Liga-se a qualquer `[data-filtro-root][data-filtro-endpoint]` que contenha um
 * `[data-filtro-form]` e um `[data-filtro-resultado]`. A busca aplica com debounce;
 * facetas (selects/datas) aplicam na hora; chips e paginação são delegados no root
 * persistente (o fragmento de resultado é substituído a cada recarga, então nunca
 * religamos handlers dentro dele).
 *
 * Contrato do endpoint: no XHR devolve SÓ o innerHTML do `[data-filtro-resultado]`.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 350;

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatarData(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);

        return m ? m[3] + '/' + m[2] + '/' + m[1] : v;
    }

    // innerHTML não executa <script>; recria-os para preservar comportamento inline do fragmento.
    function injetarHtml(alvo, html) {
        alvo.innerHTML = html;
        alvo.querySelectorAll('script').forEach(function (velho) {
            var novo = document.createElement('script');
            if (velho.src) {
                novo.src = velho.src;
            } else {
                novo.textContent = velho.textContent;
            }
            velho.parentNode.replaceChild(novo, velho);
        });
    }

    function iniciar(root) {
        var endpoint = root.getAttribute('data-filtro-endpoint');
        var form = root.querySelector('[data-filtro-form]');
        var resultado = root.querySelector('[data-filtro-resultado]');
        if (!endpoint || !form || !resultado) {
            return;
        }

        var timer = null;

        function setHidden(name, value) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) {
                el.value = value;
            }
        }

        function construirChips() {
            var box = form.querySelector('[data-filtro-chips]');
            if (!box) {
                return;
            }

            var chips = [];

            var busca = form.querySelector('.js-filtro-busca');
            if (busca && busca.value.trim() !== '') {
                chips.push({ campo: busca.name, rotulo: busca.dataset.rotulo || 'Busca', valor: busca.value.trim() });
            }

            form.querySelectorAll('.js-filtro-campo').forEach(function (el) {
                if (el.value === '') {
                    return;
                }

                var valor;
                if (el.tagName === 'SELECT') {
                    var opt = el.options[el.selectedIndex];
                    valor = opt ? opt.textContent.trim() : el.value;
                } else if (el.type === 'date') {
                    valor = formatarData(el.value);
                } else {
                    valor = el.value;
                }

                chips.push({ campo: el.name, rotulo: el.dataset.rotulo || '', valor: valor });
            });

            if (chips.length === 0) {
                box.innerHTML = '';

                return;
            }

            var html = chips.map(function (c) {
                return '<span class="filtro-chip">'
                    + (c.rotulo ? '<span class="filtro-chip-rotulo">' + escHtml(c.rotulo) + ':</span> ' : '')
                    + '<span class="filtro-chip-valor">' + escHtml(c.valor) + '</span>'
                    + '<button type="button" class="filtro-chip-remover js-filtro-chip-remover" data-campo="'
                    + escHtml(c.campo) + '" aria-label="Remover"><i class="bi bi-x"></i></button></span>';
            }).join('');
            html += '<button type="button" class="filtro-limpar js-filtro-limpar">Limpar tudo</button>';
            box.innerHTML = html;
        }

        function recarregar(extra) {
            var params = new URLSearchParams(new FormData(form));
            if (extra) {
                Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
            }

            resultado.classList.add('filtro-carregando');
            fetch(endpoint + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) {
                        throw new Error('http');
                    }

                    return r.text();
                })
                .then(function (html) {
                    injetarHtml(resultado, html);
                    resultado.classList.remove('filtro-carregando');
                })
                .catch(function () {
                    resultado.classList.remove('filtro-carregando');
                });
        }

        function aplicar(extra) {
            construirChips();
            recarregar(extra || { page: 1 });
        }

        // Enter no campo de busca dispara o form GET; interceptamos para aplicar via AJAX
        // (sem recarregar a página) e mantemos o fallback GET quando o JS não roda.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            aplicar({ page: 1 });
        });

        root.addEventListener('input', function (e) {
            if (!e.target.classList.contains('js-filtro-busca')) {
                return;
            }

            construirChips();
            clearTimeout(timer);
            timer = setTimeout(function () { recarregar({ page: 1 }); }, DEBOUNCE_MS);
        });

        root.addEventListener('change', function (e) {
            if (e.target.classList.contains('js-filtro-ordenar')) {
                var p = (e.target.value || '').split('|');
                if (!p[0]) {
                    return;
                }

                setHidden('ordenar', p[0]);
                setHidden('direcao', p[1] || 'asc');
                recarregar({ page: 1 });

                return;
            }

            if (e.target.classList.contains('js-filtro-campo')) {
                aplicar({ page: 1 });
            }
        });

        root.addEventListener('click', function (e) {
            var th = e.target.closest('th[data-ordenar]');
            if (th && resultado.contains(th)) {
                var atual = form.querySelector('[name="ordenar"]');
                var dir = form.querySelector('[name="direcao"]');
                var col = th.getAttribute('data-ordenar');
                var mesma = atual && atual.value === col;
                setHidden('ordenar', col);
                setHidden('direcao', (mesma && dir && dir.value === 'asc') ? 'desc' : 'asc');
                recarregar({ page: 1 });

                return;
            }

            var cal = e.target.closest('.js-filtro-calendario');
            if (cal) {
                var d = cal.parentNode.querySelector('input[type="date"]');
                if (d && d.showPicker) {
                    d.showPicker();
                } else if (d) {
                    d.focus();
                }

                return;
            }

            var rem = e.target.closest('.js-filtro-chip-remover');
            if (rem) {
                var alvo = form.querySelector('[name="' + rem.getAttribute('data-campo') + '"]');
                if (alvo) {
                    alvo.value = '';
                }
                aplicar({ page: 1 });

                return;
            }

            if (e.target.closest('.js-filtro-limpar')) {
                form.querySelectorAll('.js-filtro-busca, .js-filtro-campo').forEach(function (el) { el.value = ''; });
                aplicar({ page: 1 });

                return;
            }

            var pag = e.target.closest('.js-filtro-pagina[data-page]');
            if (pag) {
                recarregar({ page: pag.getAttribute('data-page') });
            }
        });

        construirChips();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-filtro-root]').forEach(iniciar);
    });
})();
