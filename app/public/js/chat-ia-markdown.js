/*
     * Renderizador de markdown seguro, compartilhado pela página do Chat I.A (chat-ia.js) e pelo mini chat da Central
     * (central-ia-chat.js). A resposta da IA é dado não confiável: só createElement/textContent, nunca HTML em string.
     *
     * window.BJMarkdown.criar({T, icone, copiar}) devolve {renderizar(texto, destino)}:
     *   T(chave): rótulos "copiar"/"copiado" do bloco de código; icone(nome): nó SVG do ícone; copiar(texto): Promise.
     */
    (function () {
        'use strict';

        function criar(cfg) {
            var T = cfg.T;
            var icone = cfg.icone;
            var copiarTexto = cfg.copiar;

            function el(tag, classe, texto) {
                var n = document.createElement(tag);
                if (classe) { n.className = classe; }
                if (texto != null && texto !== '') { n.textContent = texto; }
                return n;
            }

            function limpar(no) {
                while (no.firstChild) { no.removeChild(no.firstChild); }
            }

        var RE_CERCA = /^\s*(`{3,}|~{3,})\s*([\w#+.\-]*)[^`]*$/;
        var RE_TITULO = /^\s{0,3}(#{1,4})\s+(.+?)\s*#*\s*$/;
        var RE_HR = /^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/;
        var RE_ITEM = /^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/;
        var RE_CITACAO = /^\s{0,3}>\s?(.*)$/;
        var RE_SEP_TABELA = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/;
        var RE_INLINE = /(`+)([\s\S]*?[^`])\1(?!`)|\\([\\`*_{}\[\]()#+\-.!~|>])|\[([^\]\n]+)\]\(([^)\s]+)(?:\s+"[^"\n]*")?\)|(https?:\/\/[^\s<>]*[^\s<>.,;:!?'")\]])|\*\*([^\s*](?:[\s\S]*?[^\s*])?)\*\*|__([^\s_](?:[\s\S]*?[^\s_])?)__|~~([^\s~](?:[\s\S]*?[^\s~])?)~~|\*([^\s*](?:[^*\n]*?[^\s*])?)\*|_([^\s_](?:[^_\n]*?[^\s_])?)_|(\n)/g;

        /* Só http, https e mailto viram link. Qualquer outro esquema vira texto. */
        function urlSegura(u) {
            var s = String(u || '').trim();
            if (/^(https?:\/\/|mailto:)/i.test(s)) { return s; }
            return null;
        }

        function ehPalavra(c) { return c !== '' && /\w/.test(c); }

        /* Linha inline maior que isto vira texto simples: as alternâncias lazy do RE_INLINE são quadráticas no pior caso. */
        var MAX_INLINE = 2000;

        function inline(texto, pai) {
            if (texto.length > MAX_INLINE) {
                texto.split('\n').forEach(function (linha, i) {
                    if (i > 0) { pai.appendChild(el('br')); }
                    if (linha.length > MAX_INLINE) { pai.appendChild(document.createTextNode(linha)); } else { inline(linha, pai); }
                });
                return;
            }
            var re = new RegExp(RE_INLINE.source, 'g');
            var ultimo = 0;
            var m;
            function soltar(ate) {
                if (ate > ultimo) { pai.appendChild(document.createTextNode(texto.slice(ultimo, ate))); }
            }
            while ((m = re.exec(texto)) !== null) {
                var ini = m.index;
                var fim = ini + m[0].length;
                if ((m[8] !== undefined || m[11] !== undefined) && (ehPalavra(texto.charAt(ini - 1)) || ehPalavra(texto.charAt(fim)))) {
                    re.lastIndex = ini + 1;
                    continue;
                }
                soltar(ini);
                ultimo = fim;
                if (m[2] !== undefined) {
                    pai.appendChild(el('code', '', m[2].replace(/^ (.*) $/, '$1')));
                } else if (m[3] !== undefined) {
                    pai.appendChild(document.createTextNode(m[3]));
                } else if (m[4] !== undefined) {
                    var alvo = urlSegura(m[5]);
                    if (alvo) {
                        var a = el('a');
                        a.href = alvo;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        inline(m[4], a);
                        pai.appendChild(a);
                    } else {
                        pai.appendChild(document.createTextNode(m[4]));
                    }
                } else if (m[6] !== undefined) {
                    var auto = el('a', '', m[6]);
                    auto.href = m[6];
                    auto.target = '_blank';
                    auto.rel = 'noopener noreferrer';
                    pai.appendChild(auto);
                } else if (m[7] !== undefined || m[8] !== undefined) {
                    var forte = el('strong');
                    inline(m[7] !== undefined ? m[7] : m[8], forte);
                    pai.appendChild(forte);
                } else if (m[9] !== undefined) {
                    var risc = el('del');
                    inline(m[9], risc);
                    pai.appendChild(risc);
                } else if (m[10] !== undefined || m[11] !== undefined) {
                    var ital = el('em');
                    inline(m[10] !== undefined ? m[10] : m[11], ital);
                    pai.appendChild(ital);
                } else if (m[12] !== undefined) {
                    pai.appendChild(el('br'));
                }
            }
            soltar(texto.length);
        }

        function recuo(s) { return s.replace(/\t/g, '    ').length; }
        function emBranco(l) { return /^\s*$/.test(l); }

        function celulas(linha) {
            var s = linha.trim();
            if (s.charAt(0) === '|') { s = s.slice(1); }
            var lista = [];
            var atual = '';
            for (var i = 0; i < s.length; i++) {
                var c = s.charAt(i);
                if (c === '\\' && s.charAt(i + 1) === '|') { atual += '|'; i++; } else if (c === '|') { lista.push(atual.trim()); atual = ''; } else { atual += c; }
            }
            if (atual.trim() !== '' || lista.length === 0) { lista.push(atual.trim()); }
            return lista;
        }

        function iniciaTabela(linhas, i) {
            return linhas[i].indexOf('|') !== -1 && i + 1 < linhas.length && linhas[i + 1].indexOf('|') !== -1 &&
                linhas[i + 1].indexOf('-') !== -1 && RE_SEP_TABELA.test(linhas[i + 1]);
        }

        function iniciaOutroBloco(linhas, i) {
            var l = linhas[i];
            return RE_CERCA.test(l) || RE_TITULO.test(l) || RE_HR.test(l) || RE_CITACAO.test(l) || RE_ITEM.test(l) || iniciaTabela(linhas, i);
        }

        function blocoDeCodigo(linhas, i, m, pai) {
            var marca = m[1];
            var fechar = new RegExp('^\\s*' + (marca.charAt(0) === '`' ? '`' : '~') + '{' + marca.length + ',}\\s*$');
            var corpo = [];
            i++;
            while (i < linhas.length && !fechar.test(linhas[i])) { corpo.push(linhas[i]); i++; }
            var codigo = corpo.join('\n');
            var caixa = el('div', 'ci-bloco');
            var topo = el('div', 'ci-bloco-topo');
            topo.appendChild(el('span', '', m[2] || ''));
            var copiar = el('button', 'ci-botao-texto');
            copiar.type = 'button';
            copiar.appendChild(icone('copiar'));
            copiar.appendChild(el('span', '', T('copiar')));
            copiar.addEventListener('click', function () {
                copiarTexto(codigo).then(function () {
                    copiar.lastChild.textContent = T('copiado');
                    window.setTimeout(function () { copiar.lastChild.textContent = T('copiar'); }, 1600);
                });
            });
            topo.appendChild(copiar);
            var pre = el('pre');
            pre.appendChild(el('code', '', codigo));
            caixa.appendChild(topo);
            caixa.appendChild(pre);
            pai.appendChild(caixa);
            return i + 1;
        }

        function tabela(linhas, i, pai) {
            var cab = celulas(linhas[i]);
            var alinha = celulas(linhas[i + 1]).map(function (c) {
                if (/^:-+:$/.test(c)) { return 'center'; }
                if (/-+:$/.test(c)) { return 'right'; }
                return 'left';
            });
            var caixa = el('div', 'ci-tabela');
            var t = el('table');
            var thead = el('thead');
            var trh = el('tr');
            cab.forEach(function (c, k) {
                var th = el('th');
                th.style.textAlign = alinha[k] || 'left';
                inline(c, th);
                trh.appendChild(th);
            });
            thead.appendChild(trh);
            t.appendChild(thead);
            var tbody = el('tbody');
            i += 2;
            while (i < linhas.length && !emBranco(linhas[i]) && linhas[i].indexOf('|') !== -1) {
                var cel = celulas(linhas[i]);
                var tr = el('tr');
                for (var k = 0; k < cab.length; k++) {
                    var td = el('td');
                    td.style.textAlign = alinha[k] || 'left';
                    inline(cel[k] || '', td);
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
                i++;
            }
            t.appendChild(tbody);
            caixa.appendChild(t);
            pai.appendChild(caixa);
            return i;
        }

        function lista(linhas, i, pai) {
            var itens = [];
            while (i < linhas.length) {
                var l = linhas[i];
                var m = RE_ITEM.exec(l);
                if (m && !RE_HR.test(l)) {
                    itens.push({ ind: recuo(m[1]), ord: /\d/.test(m[2]), num: parseInt(m[2], 10), texto: m[3] });
                    i++;
                    continue;
                }
                if (emBranco(l)) {
                    var j = i + 1;
                    while (j < linhas.length && emBranco(linhas[j])) { j++; }
                    if (j < linhas.length && RE_ITEM.test(linhas[j]) && !RE_HR.test(linhas[j])) { i = j; continue; }
                    break;
                }
                if (itens.length && /^\s{2,}\S/.test(l) && !RE_CERCA.test(l)) {
                    itens[itens.length - 1].texto += '\n' + l.trim();
                    i++;
                    continue;
                }
                break;
            }
            var pilha = [];
            itens.forEach(function (it) {
                while (pilha.length && (pilha[pilha.length - 1].ind > it.ind || (pilha[pilha.length - 1].ind === it.ind && pilha[pilha.length - 1].ord !== it.ord))) { pilha.pop(); }
                if (!pilha.length || pilha[pilha.length - 1].ind < it.ind) {
                    var nova = el(it.ord ? 'ol' : 'ul');
                    if (it.ord && it.num !== 1) { nova.setAttribute('start', String(it.num)); }
                    var topo = pilha.length ? pilha[pilha.length - 1].el : null;
                    var ultimoLi = topo && topo.lastChild;
                    (ultimoLi || pai).appendChild(nova);
                    pilha.push({ ind: it.ind, ord: it.ord, el: nova });
                }
                var li = el('li');
                inline(it.texto, li);
                pilha[pilha.length - 1].el.appendChild(li);
            });
            return i;
        }

        function paragrafo(linhas, i, pai) {
            var partes = [];
            while (i < linhas.length && !emBranco(linhas[i]) && (partes.length === 0 || !iniciaOutroBloco(linhas, i))) {
                partes.push(linhas[i]);
                i++;
            }
            var p = el('p');
            inline(partes.join('\n'), p);
            pai.appendChild(p);
            return i;
        }

        function blocos(linhas, pai) {
            var i = 0;
            while (i < linhas.length) {
                var l = linhas[i];
                var m;
                if (emBranco(l)) { i++; continue; }
                if ((m = RE_CERCA.exec(l)) !== null) { i = blocoDeCodigo(linhas, i, m, pai); continue; }
                if ((m = RE_TITULO.exec(l)) !== null) {
                    var h = el('h' + m[1].length);
                    inline(m[2], h);
                    pai.appendChild(h);
                    i++;
                    continue;
                }
                if (RE_HR.test(l)) { pai.appendChild(el('hr')); i++; continue; }
                if (RE_CITACAO.test(l)) {
                    var dentro = [];
                    while (i < linhas.length && RE_CITACAO.test(linhas[i])) { dentro.push(RE_CITACAO.exec(linhas[i])[1]); i++; }
                    var bq = el('blockquote');
                    blocos(dentro, bq);
                    pai.appendChild(bq);
                    continue;
                }
                if (RE_ITEM.test(l)) { i = lista(linhas, i, pai); continue; }
                if (iniciaTabela(linhas, i)) { i = tabela(linhas, i, pai); continue; }
                i = paragrafo(linhas, i, pai);
            }
        }

        /* Troca o conteúdo de `destino` pelo markdown renderizado. Monta num fragmento e troca de uma vez (sem piscar). */
        function renderizarMarkdown(texto, destino) {
            var frag = document.createDocumentFragment();
            blocos(String(texto).replace(/\r\n?/g, '\n').split('\n'), frag);
            limpar(destino);
            destino.appendChild(frag);
        }

        return { renderizar: renderizarMarkdown };
    }

    window.BJMarkdown = { criar: criar };
})();
