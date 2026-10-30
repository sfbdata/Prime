/* Mini chat da BlueJus IA dentro da janela da Central (spec docs/specs/chat-ia-mini-central.md).
   É só a TELA: fala com as mesmas rotas e o mesmo SSE de /chat-ia. Montado por central.js quando a conversa aberta é a da IA.
   Nenhum texto do servidor entra como HTML: createElement + textContent. */
(function () {
    'use strict';

    if (window.BJMiniIaChat) { return; }

    var ROTAS = null;
    var TEXTOS = {};
    var csrf = '';

    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined) { e.textContent = texto; }
        return e;
    }

    function T(chave) { return typeof TEXTOS[chave] === 'string' ? TEXTOS[chave] : ''; }

    function api(metodo, url, corpo) {
        var cab = { 'Accept': 'application/json', 'X-CSRF-Token': csrf };
        var opc = { method: metodo, credentials: 'same-origin', headers: cab };
        if (corpo !== undefined) {
            cab['Content-Type'] = 'application/json';
            opc.body = JSON.stringify(corpo);
        }
        return fetch(url, opc).then(function (r) {
            return r.json().then(function (d) { return d; }, function () { return {}; }).then(function (d) {
                return { ok: r.ok, status: r.status, dados: d };
            });
        }, function () { return { ok: false, status: 0, dados: { codigo: 'rede' } }; });
    }

    function textoDoErro(d) {
        var c = d && d.codigo;
        if (c === 'rede') { return T('erro_rede'); }
        if (c === 'sem_chave') { return T('erro_sem_chave'); }
        if (c === 'sem_aceite') { return T('erro_sem_aceite'); }
        if (d && typeof d.mensagem === 'string' && d.mensagem) { return d.mensagem; }
        return T('erro_generico');
    }

    /* A resposta da IA é markdown: o mesmo renderizador seguro da página inteira (chat-ia-markdown.js). */
    var md = null;
    function renderizarMarkdown(texto, destino) {
        if (!md && window.BJMarkdown) {
            md = window.BJMarkdown.criar({
                T: T,
                icone: function () { return document.createElement('span'); },
                copiar: function (t) {
                    return navigator.clipboard && navigator.clipboard.writeText
                        ? navigator.clipboard.writeText(t).then(function () { return true; }, function () { return false; })
                        : Promise.resolve(false);
                }
            });
        }
        if (md) { md.renderizar(texto, destino); } else { destino.textContent = texto; }
    }

    function comId(base, id) { return base.replace('/0', '/' + id); }

    /* Lê o corpo SSE (`event:` + `data:` JSON) e chama aoEvento(nome, dados). */
    function lerFluxo(resp, aoEvento) {
        var leitor = resp.body.getReader();
        var decoder = new TextDecoder();
        var resto = '';
        function processar(bloco) {
            var nome = 'message';
            var dados = '';
            bloco.split('\n').forEach(function (linha) {
                if (linha.indexOf('event:') === 0) { nome = linha.slice(6).trim(); }
                else if (linha.indexOf('data:') === 0) { dados += linha.slice(5).trim(); }
            });
            if (dados === '') { return; }
            try { aoEvento(nome, JSON.parse(dados)); } catch (e) { /* bloco torto: ignora */ }
        }
        function passo() {
            return leitor.read().then(function (r) {
                if (r.done) {
                    if (resto.trim() !== '') { processar(resto); }
                    return;
                }
                resto += decoder.decode(r.value, { stream: true });
                var partes = resto.split('\n\n');
                resto = partes.pop();
                partes.forEach(processar);
                return passo();
            });
        }
        return passo();
    }

    function montar(contentor, cfg) {
        ROTAS = cfg.rotas;
        TEXTOS = cfg.textos || {};
        csrf = cfg.csrf || '';

        var estado = { id: null, conversas: [], enviando: false, abort: null, vivo: true, gen: 0 };
        var raiz = el('div', 'bj-ciq');
        var barra = el('div', 'bj-ciq-barra');
        var seletor = el('select', 'bj-ciq-seletor');
        seletor.setAttribute('aria-label', T('lista_rotulo'));
        var nova = el('button', 'bj-ciq-botao', T('nova'));
        nova.type = 'button';
        var pagina = el('a', 'bj-ciq-botao bj-ciq-pagina', T('pagina_inteira'));
        pagina.target = '_blank';
        pagina.rel = 'noopener';
        barra.appendChild(seletor);
        barra.appendChild(nova);
        barra.appendChild(pagina);
        /* Modo compacto (painel de Minhas Metas): sem barra nem rodapé; só a resposta e o link discreto "Abrir em página inteira". */
        var compacto = cfg.compacto === true;
        if (compacto) { pagina.className = 'bj-ciq-pagina-discreta'; raiz.classList.add('bj-ciq--compacto'); pagina.hidden = true; }
        var pastaLinha = el('div', 'bj-ciq-pasta');
        pastaLinha.hidden = true;
        var aviso = el('div', 'bj-ciq-aviso');
        aviso.setAttribute('role', 'status');
        aviso.hidden = true;
        var corpo = el('div', 'bj-ciq-corpo');
        corpo.setAttribute('aria-live', 'polite');
        var rodape = el('div', 'bj-ciq-rodape');
        var campo = el('textarea', 'bj-ciq-campo');
        campo.rows = 2;
        campo.maxLength = 8000;
        campo.placeholder = T('placeholder');
        campo.setAttribute('aria-label', T('placeholder'));
        var enviar = el('button', 'bj-ciq-botao bj-ciq-enviar', T('enviar'));
        enviar.type = 'button';
        rodape.appendChild(campo);
        rodape.appendChild(enviar);
        if (!compacto) { raiz.appendChild(barra); raiz.appendChild(pastaLinha); }
        raiz.appendChild(aviso);
        raiz.appendChild(corpo);
        if (compacto) { raiz.appendChild(pagina); } else { raiz.appendChild(rodape); }
        contentor.appendChild(raiz);

        /* A pasta aberta na tela (dica de UI; o servidor revalida) vai junto da pergunta, e a pessoa vê qual é. */
        function pastaDaTela() { return typeof cfg.pasta === 'function' ? cfg.pasta() : null; }
        function atualizarPasta() {
            var p = pastaDaTela();
            pastaLinha.hidden = !p;
            pastaLinha.textContent = p ? T('pasta') + ' ' + (p.nup || '') + (p.cliente ? ' · ' + p.cliente : '') : '';
        }

        function mostrarAviso(texto) {
            aviso.textContent = texto;
            aviso.hidden = texto === '';
        }

        function rolar() { corpo.scrollTop = corpo.scrollHeight; }

        function balao(papel, texto) {
            var b = el('div', 'bj-ciq-msg bj-ciq-msg--' + (papel === 'usuario' ? 'minha' : 'ia'));
            var t = el('div', papel === 'usuario' ? 'bj-ciq-texto' : 'bj-ciq-texto bj-ciq-md');
            if (papel === 'usuario') { t.textContent = texto; } else { renderizarMarkdown(texto, t); }
            b.appendChild(t);
            corpo.appendChild(b);
            return t;
        }

        function vazio() {
            corpo.replaceChildren(el('p', 'bj-ciq-vazio', T('vazio')));
        }

        function atualizarPagina() {
            if (estado.id) { pagina.href = comId(ROTAS.pagina, estado.id); pagina.hidden = false; } else { pagina.removeAttribute('href'); pagina.hidden = true; }
        }

        function desenharSeletor() {
            seletor.replaceChildren();
            var opNova = el('option', '', T('nova_opcao'));
            opNova.value = '';
            seletor.appendChild(opNova);
            estado.conversas.forEach(function (c) {
                var o = el('option', '', c.titulo || T('sem_titulo'));
                o.value = String(c.id);
                seletor.appendChild(o);
            });
            seletor.value = estado.id ? String(estado.id) : '';
        }

        function carregarLista() {
            return api('GET', ROTAS.conversas).then(function (r) {
                if (r.ok && Array.isArray(r.dados)) { estado.conversas = r.dados; }
                desenharSeletor();
            });
        }

        function abrirConversa(id) {
            estado.id = id;
            estado.gen++;
            atualizarPagina();
            mostrarAviso('');
            if (!id) { vazio(); desenharSeletor(); return Promise.resolve(); }
            var gen = estado.gen;
            return api('GET', comId(ROTAS.conversa, id)).then(function (r) {
                if (gen !== estado.gen) { return; }
                if (!r.ok) { mostrarAviso(textoDoErro(r.dados)); return; }
                corpo.replaceChildren();
                var msgs = Array.isArray(r.dados.mensagens) ? r.dados.mensagens : [];
                if (msgs.length === 0) { vazio(); }
                msgs.forEach(function (m) {
                    balao(m.papel, String(m.conteudo || ''));
                    if (Array.isArray(m.propostas) && m.propostas.length) { corpo.appendChild(el('p', 'bj-ciq-nota', T('proposta'))); }
                });
                desenharSeletor();
                rolar();
            });
        }

        function garantirConversa() {
            if (estado.id) { return Promise.resolve(estado.id); }
            return api('POST', ROTAS.conversas, { origem: 'central' }).then(function (r) {
                if (!r.ok || !r.dados.id) { mostrarAviso(textoDoErro(r.dados)); return null; }
                estado.id = r.dados.id;
                atualizarPagina();
                return estado.id;
            });
        }

        function terminar() {
            estado.enviando = false;
            estado.abort = null;
            enviar.disabled = false;
            campo.readOnly = false;
            carregarLista();
        }

        function enviarPergunta() {
            var texto = campo.value.trim();
            if (texto === '' || estado.enviando) { return; }
            estado.enviando = true;
            enviar.disabled = true;
            campo.readOnly = true;
            mostrarAviso('');
            garantirConversa().then(function (id) {
                if (!id) { terminar(); return null; }
                var corpoEnvio = { texto: texto };
                var pasta = pastaDaTela();
                if (pasta) { corpoEnvio.pastaId = pasta.pastaId; }
                if (cfg.contexto) { corpoEnvio.contexto = cfg.contexto; }
                estado.abort = typeof AbortController === 'function' ? new AbortController() : null;
                return fetch(comId(ROTAS.mensagens, id), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(corpoEnvio),
                    signal: estado.abort ? estado.abort.signal : undefined
                }).then(function (resp) {
                    if (!resp.ok) {
                        return resp.json().then(function (d) { return d; }, function () { return {}; }).then(function (d) { mostrarAviso(textoDoErro(d)); });
                    }
                    if (corpo.querySelector('.bj-ciq-vazio')) { corpo.replaceChildren(); }
                    balao('usuario', texto);
                    campo.value = '';
                    var alvo = balao('ia', T('pensando'));
                    alvo.parentNode.classList.add('bj-ciq-msg--gerando');
                    var recebido = '';
                    rolar();
                    return lerFluxo(resp, function (evento, d) {
                        if (evento === 'delta') {
                            recebido += String(d.t || '');
                            renderizarMarkdown(recebido, alvo);
                            rolar();
                        } else if (evento === 'proposta') {
                            if (!corpo.querySelector('.bj-ciq-nota')) { corpo.appendChild(el('p', 'bj-ciq-nota', T('proposta'))); }
                        } else if (evento === 'erro') {
                            mostrarAviso(textoDoErro(d));
                            if (recebido === '') { alvo.parentNode.remove(); }
                        }
                    }).then(function () {
                        alvo.parentNode.classList.remove('bj-ciq-msg--gerando');
                        if (recebido === '' && alvo.textContent === T('pensando')) { alvo.parentNode.remove(); }
                    });
                });
            }).catch(function (err) {
                if (!(err && err.name === 'AbortError')) { mostrarAviso(T('erro_rede')); }
            }).then(terminar);
        }

        enviar.addEventListener('click', enviarPergunta);
        campo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); enviarPergunta(); }
        });
        nova.addEventListener('click', function () { abrirConversa(null); campo.focus(); });
        seletor.addEventListener('change', function () { abrirConversa(seletor.value ? parseInt(seletor.value, 10) : null); });

        /* Abre o mini: traz o histórico antigo da Central uma vez (idempotente), lista, e abre a última conversa ou a vazia. */
        function iniciar(textoPreenchido) {
            return api('POST', ROTAS.importar, {}).then(function () { return carregarLista(); }).then(function () {
                if (textoPreenchido) { return abrirConversa(null); }
                var ultima = estado.conversas[0];
                return abrirConversa(ultima ? ultima.id : null);
            }).then(function () {
                if (textoPreenchido) { campo.value = String(textoPreenchido).slice(0, 8000); }
                campo.focus();
            });
        }

        /* Faz uma pergunta já escrita (painel de Minhas Metas). Continua a conversa aberta; a primeira cria a conversa. */
        function perguntar(texto) {
            if (estado.enviando) { return false; }
            campo.value = String(texto || '').slice(0, 8000);
            enviarPergunta();
            return true;
        }

        return {
            perguntar: perguntar,
            iniciar: iniciar,
            atualizarPasta: atualizarPasta,
            preencher: function (texto) {
                abrirConversa(null);
                campo.value = String(texto || '').slice(0, 8000);
                campo.focus();
            },
            destruir: function () {
                estado.vivo = false;
                if (estado.abort) { estado.abort.abort(); }
                raiz.remove();
            }
        };
    }

    window.BJMiniIaChat = { montar: montar };
}());
