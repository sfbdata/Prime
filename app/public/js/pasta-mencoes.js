/**
 * @menção no Registro da pasta (item 20b), nas observações de Detalhes e no Relatório financeiro
 * (EDT-010r) e nas notas técnicas (PMC-019).
 *
 * Todo compositor dentro de um `[data-mencionaveis-url]` é ligado na carga, cada um com a SUA URL
 * (`textarea._mencoesUrl`); quem monta editor depois (edição, nota técnica) chama `ligar` com a
 * URL posta na textarea.
 *
 * Digitar "@" no editor do Registro abre a lista de colegas (desenho `bluejus-central.js`
 * `menAbrir`/`menEscolher`): ↑/↓ escolhem, Enter/Tab inserem, Esc fecha. A lista vem do servidor
 * (`GET /pasta/{id}/mencionaveis?q=`) — só colegas deste escritório que VEEM a pasta, sem e-mail.
 *
 * No editor a menção é só o texto "@Nome Completo" (nada de marcação nova — D-EDITOR). Ao ENVIAR,
 * cada "@Nome" escolhido na lista vira o token `@[Nome](user:ID)` (`paraEnvio`); o servidor
 * confere o id, troca o rótulo pelo nome do banco e notifica. Ao EDITAR, o caminho inverso
 * (`paraEdicao`): o token volta a "@Nome" no editor e a menção fica lembrada para o envio.
 * Se a pessoa apagar ou alterar o nome depois de escolher, ele deixa de casar e vai como texto.
 *
 * API:
 *   PastaMencoes.ligar(textarea, mencoesIniciais?) → liga o "@" ao editor Quill da textarea
 *   PastaMencoes.paraEnvio(textarea, html)         → html com os tokens das menções escolhidas
 *   PastaMencoes.paraEdicao(conteudo)              → { html, mencoes } para carregar no editor
 *   PastaMencoes.esquecer(textarea)                → zera as menções (depois de enviar)
 *   PastaMencoes.listaAberta(quill?)               → a lista de @ está aberta (neste editor)?
 *
 * Campo simples (despacho da meta, C3): a textarea marcada com `data-mencoes-simples` não tem editor Quill; o
 * `campoSimples` imita, sobre a própria textarea, só o pedaço do Quill que esta lista usa (seleção, texto, troca do
 * trecho e eventos), e o resto do mecanismo é o mesmo. A lista abre logo abaixo do campo.
 *
 * Prioridade das teclas: com a lista aberta, ↑/↓, Enter, Tab e Esc são DELA — inclusive sobre a
 * sugestão de palavras do editor (EDT-015), que também usa Tab. A garantia é dupla: esta lista
 * ouve em captura no contêiner do Quill e para a propagação (o editor ouve na raiz, mais abaixo),
 * e o editor consulta `listaAberta` e se recolhe quando a vê aberta.
 */
(function () {
    'use strict';

    /** Mesmo padrão do servidor (`MencoesDoRegistro::PADRAO`). */
    var PADRAO = /(?:@|&#64;)\[([^\[\]<>]{1,120})\]\(user:(\d{1,10})\)/g;
    /** O que o desenho reconhece como "@termo" sendo digitado antes do cursor. */
    var DIGITANDO = /(?:^|[\s( ])@([A-Za-zÀ-ÿ]{0,20})$/;

    var pop = null;
    var estado = { textarea: null, quill: null, inicio: 0, termo: '', lista: [], sel: 0, seq: 0, timer: 0 };

    function urlPadrao() {
        var caixa = document.querySelector('[data-mencionaveis-url]');

        return caixa ? caixa.getAttribute('data-mencionaveis-url') : null;
    }

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escRegex(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function decodificar(s) {
        var t = document.createElement('textarea');
        t.innerHTML = s;

        return t.value;
    }

    /** Rótulo do token — o mesmo corte do servidor (`MencoesDoRegistro::rotulo`). */
    function rotulo(nome) {
        var limpo = String(nome).replace(/[\[\]()<>]+/g, ' ').replace(/\s+/g, ' ').trim();

        return limpo === '' ? 'colega' : limpo.slice(0, 120);
    }

    function mencoesDe(textarea) {
        if (!textarea._mencoes) { textarea._mencoes = []; }

        return textarea._mencoes;
    }

    function lembrar(textarea, pessoa) {
        var lista = mencoesDe(textarea);
        if (!lista.some(function (m) { return m.id === pessoa.id; })) {
            lista.push({ id: pessoa.id, nome: pessoa.nome });
        }
    }

    /**
     * Troca cada "@Nome" escolhido pelo token. No editor rico, só nos trechos de TEXTO do HTML, onde o nome está
     * escapado ("&" é "&amp;"). No campo simples (`data-mencoes-simples`) o valor é texto puro: nada de corte por
     * tag nem de escape, senão um nome com "&" ou "<" não casaria (o servidor escapa o texto inteiro depois).
     */
    function paraEnvio(textarea, html) {
        var lista = mencoesDe(textarea).slice().sort(function (a, b) { return b.nome.length - a.nome.length; });
        if (!html || lista.length === 0) { return html; }
        if (textarea.hasAttribute && textarea.hasAttribute('data-mencoes-simples')) {
            return trocarMencoes(String(html), lista, function (s) { return String(s); });
        }

        return String(html).split(/(<[^>]*>)/).map(function (parte) {
            if (parte === '' || parte.charAt(0) === '<') { return parte; }

            return trocarMencoes(parte, lista, escHtml);
        }).join('');
    }

    /** `esc` é o escape do meio: `escHtml` no HTML do editor, identidade no texto puro do campo simples. */
    function trocarMencoes(parte, lista, esc) {
        lista.forEach(function (m) {
            var re = new RegExp('@' + escRegex(esc(m.nome)) + '(?![A-Za-zÀ-ÿ0-9\\]])', 'g');
            parte = parte.replace(re, function () {
                return '@[' + esc(rotulo(m.nome)) + '](user:' + m.id + ')';
            });
        });

        return parte;
    }

    function paraEdicao(conteudo) {
        var mencoes = [];
        var html = String(conteudo || '').replace(PADRAO, function (_, rot, id) {
            var nome = decodificar(rot);
            if (!mencoes.some(function (m) { return m.id === Number(id); })) {
                mencoes.push({ id: Number(id), nome: nome });
            }

            return '@' + rot;
        });

        return { html: html, mencoes: mencoes };
    }

    function esquecer(textarea) {
        if (textarea) { textarea._mencoes = []; }
        fechar();
    }

    // ── Lista ────────────────────────────────────────────────────────────

    function fechar() {
        clearTimeout(estado.timer);
        estado.seq++;
        if (pop) { pop.remove(); pop = null; }
        estado.textarea = null;
        estado.quill = null;
        estado.lista = [];
    }

    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined) { e.textContent = texto; }

        return e;
    }

    function desenhar() {
        var L = estado.lista;
        if (!estado.quill || L.length === 0) {
            if (pop) { pop.remove(); pop = null; }

            return;
        }
        if (estado.sel >= L.length) { estado.sel = 0; }

        if (!pop) {
            pop = el('div', 'ps-mencao-lista');
            pop.setAttribute('role', 'listbox');
            pop.setAttribute('aria-label', 'Mencionar pessoa');
            // Clicar na lista não pode tirar o foco do editor (perderia a posição do cursor).
            pop.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            document.body.appendChild(pop);
        }
        pop.innerHTML = '';
        pop.appendChild(el('div', 'ps-mencao-lista-cab', 'Mencionar · notifica a pessoa'));

        L.forEach(function (u, i) {
            var b = el('button', 'ps-mencao-opcao');
            b.type = 'button';
            b.setAttribute('role', 'option');
            b.setAttribute('aria-selected', i === estado.sel ? 'true' : 'false');
            b.addEventListener('click', function () { estado.sel = i; escolher(); });
            b.addEventListener('mouseenter', function () {
                estado.sel = i;
                pop.querySelectorAll('[role=option]').forEach(function (x, j) {
                    x.setAttribute('aria-selected', j === i ? 'true' : 'false');
                });
            });

            var av = el('span', 'ps-mencao-avatar', u.foto ? '' : (u.iniciais || '?'));
            if (u.foto) { av.style.backgroundImage = 'url(' + JSON.stringify(String(u.foto)) + ')'; }
            var textos = el('span', 'ps-mencao-textos');
            textos.appendChild(el('span', 'ps-mencao-nome', u.nome));
            if (u.cargo) { textos.appendChild(el('span', 'ps-mencao-cargo', u.cargo)); }
            b.appendChild(av);
            b.appendChild(textos);
            pop.appendChild(b);
        });

        posicionar();
    }

    function posicionar() {
        if (!pop || !estado.quill) { return; }
        var caixa = estado.quill.container.getBoundingClientRect();
        var b = estado.quill.getBounds(estado.inicio) || { left: 12, top: 0, bottom: 20 };
        var r = { left: caixa.left + b.left, top: caixa.top + b.top, bottom: caixa.top + b.top + (b.height || 20) };
        var H = Math.min(300, 30 + estado.lista.length * 42);
        var top = r.bottom + 6 + H > window.innerHeight ? Math.max(8, r.top - H - 6) : r.bottom + 6;
        pop.style.left = Math.max(8, Math.min(window.innerWidth - 288, r.left - 6)) + 'px';
        pop.style.top = top + 'px';
    }

    function buscar(textarea, quill, inicio, termo) {
        var url = textarea._mencoesUrl || urlPadrao();
        if (!url) { return; }

        estado.textarea = textarea;
        estado.quill = quill;
        estado.inicio = inicio;
        if (estado.termo !== termo) { estado.sel = 0; }
        estado.termo = termo;

        clearTimeout(estado.timer);
        var seq = ++estado.seq;
        estado.timer = setTimeout(function () {
            fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(termo), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (dados) {
                    if (seq !== estado.seq) { return; } // resposta velha: o usuário já digitou mais
                    estado.lista = Array.isArray(dados) ? dados : [];
                    desenhar();
                })
                .catch(function () { if (seq === estado.seq) { estado.lista = []; desenhar(); } });
        }, 150);
    }

    function escolher() {
        var quill = estado.quill;
        var textarea = estado.textarea;
        var u = estado.lista[estado.sel];
        if (!quill || !textarea || !u) { fechar(); return; }

        var sel = quill.getSelection(true);
        var fim = sel ? sel.index : estado.inicio + estado.termo.length + 1;
        var ins = '@' + u.nome + ' ';
        // 'api': a autocorreção do editor só age sobre o que a pessoa DIGITOU — o nome escolhido
        // não pode ser "corrigido". A textarea é sincronizada do mesmo jeito.
        quill.deleteText(estado.inicio, fim - estado.inicio, 'api');
        quill.insertText(estado.inicio, ins, 'api');
        quill.setSelection(estado.inicio + ins.length, 0, 'api');
        lembrar(textarea, u);
        fechar();
    }

    function verificar(textarea, quill) {
        var sel = quill.getSelection();
        if (!sel || sel.length > 0) { if (estado.quill === quill) { fechar(); } return; }

        var antes = quill.getText(Math.max(0, sel.index - 40), Math.min(40, sel.index));
        var m = antes.match(DIGITANDO);
        if (!m) { if (estado.quill === quill) { fechar(); } return; }

        buscar(textarea, quill, sel.index - m[1].length - 1, m[1]);
    }

    /** O pedaço da API do Quill que esta lista usa, sobre uma textarea sem editor (campo simples). */
    function campoSimples(textarea) {
        function trocar(valor, cursor) {
            textarea.value = valor;
            textarea.setSelectionRange(cursor, cursor);
            textarea.dispatchEvent(new Event('input'));
        }

        return {
            container: textarea,
            getSelection: function (focar) {
                if (!focar && document.activeElement !== textarea) { return null; }

                return { index: textarea.selectionStart, length: textarea.selectionEnd - textarea.selectionStart };
            },
            getBounds: function () { return { left: 12, top: textarea.offsetHeight - 20, height: 20 }; },
            getText: function (inicio, tamanho) { return textarea.value.substr(inicio, tamanho); },
            deleteText: function (inicio, tamanho) {
                trocar(textarea.value.slice(0, inicio) + textarea.value.slice(inicio + tamanho), inicio);
            },
            insertText: function (inicio, texto) {
                trocar(textarea.value.slice(0, inicio) + texto + textarea.value.slice(inicio), inicio + texto.length);
            },
            setSelection: function (indice) { textarea.focus(); textarea.setSelectionRange(indice, indice); },
            on: function (evento, fn) {
                if (evento === 'text-change') {
                    textarea.addEventListener('input', function (ev) { if (ev.isTrusted) { fn(null, null, 'user'); } });
                } else if (evento === 'selection-change') {
                    textarea.addEventListener('blur', function () { fn(null); });
                }
            },
        };
    }

    function ligar(textarea, mencoesIniciais) {
        if (!textarea || textarea._mencoesLigado) { return; }
        var quill = window.EditorRico && EditorRico.instancia ? EditorRico.instancia(textarea) : null;
        if (!quill && textarea.hasAttribute('data-mencoes-simples')) { quill = campoSimples(textarea); }
        if (!quill) { return; }

        textarea._mencoesLigado = true;
        textarea._mencoes = (mencoesIniciais || []).slice();

        quill.on('text-change', function (delta, anterior, origem) {
            if (origem === 'user') { setTimeout(function () { verificar(textarea, quill); }, 0); }
        });
        quill.on('selection-change', function (faixa) {
            if (!faixa && estado.quill === quill) { fechar(); }
        });

        // Captura no contêiner: chega ANTES do teclado do Quill (Enter não pode quebrar a linha
        // enquanto a lista está aberta).
        quill.container.addEventListener('keydown', function (ev) {
            if (!pop || estado.quill !== quill) { return; }
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                var n = estado.lista.length;
                estado.sel = (estado.sel + (ev.key === 'ArrowDown' ? 1 : -1) + n) % n;
                desenhar();
            } else if ((ev.key === 'Enter' && !ev.ctrlKey && !ev.metaKey) || ev.key === 'Tab') {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                escolher();
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                fechar();
            }
        }, true);
    }

    document.addEventListener('click', function (ev) {
        if (pop && !pop.contains(ev.target)) { fechar(); }
    }, true);
    window.addEventListener('scroll', function () { if (pop) { posicionar(); } }, true);

    /** Liga cada compositor marcado (Registro, Detalhes, Financeiro), com a URL da própria caixa. */
    function ligarCompositor(tentativas) {
        var faltam = 0;
        document.querySelectorAll('[data-mencionaveis-url]').forEach(function (caixa) {
            var textarea = caixa.querySelector('textarea');
            if (!textarea || textarea._mencoesLigado) { return; }
            if (textarea.hasAttribute('data-mencoes-simples') || (window.EditorRico && EditorRico.instancia(textarea))) {
                textarea._mencoesUrl = caixa.getAttribute('data-mencionaveis-url');
                ligar(textarea);

                return;
            }
            faltam++;
        });
        // O editor é montado por outro script `defer`: espera um pouco se ainda não montou.
        if (faltam > 0 && tentativas > 0) { setTimeout(function () { ligarCompositor(tentativas - 1); }, 100); }
    }

    /** A lista está aberta — no editor dado, se houver (a sugestão de palavras do editor cede a ela). */
    function listaAberta(quill) {
        return !!pop && (!quill || estado.quill === quill);
    }

    window.PastaMencoes = { ligar: ligar, paraEnvio: paraEnvio, paraEdicao: paraEdicao, esquecer: esquecer, listaAberta: listaAberta };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ligarCompositor(20); });
    } else {
        ligarCompositor(20);
    }
})();
