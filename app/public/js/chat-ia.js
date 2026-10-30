/* Chat I.A em página inteira (spec docs/specs/chat-pagina-inteira.md).
 * JS puro, sem build. Todo texto de tela vem do servidor (data-textos); nada é montado por HTML em string:
 * só createElement/textContent. O markdown da resposta passa pelo renderizador próprio daqui. */
(function () {
    'use strict';

    var raiz = document.getElementById('ci-app');
    if (!raiz) { return; }

    /* ------------------------------------------------------------------ dados do servidor */
    function lerJson(texto, padrao) {
        try { return JSON.parse(texto || ''); } catch (e) { return padrao; }
    }
    var urls = lerJson(raiz.dataset.urls, {});
    var textos = lerJson(raiz.dataset.textos, {});
    var modelos = lerJson(raiz.dataset.modelos, []);
    var csrf = raiz.dataset.csrf || '';
    var nomePessoa = raiz.dataset.nome || '';
    var tituloOriginal = document.title;

    function T(chave, vars) {
        var s = textos[chave];
        if (typeof s !== 'string') { return ''; }
        if (vars) {
            Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
        }
        return s;
    }

    var estado = {
        conversaId: raiz.dataset.conversaId ? parseInt(raiz.dataset.conversaId, 10) : null,
        modelo: raiz.dataset.modeloPadrao || '',
        semChave: raiz.dataset.semChave === '1',
        titulo: '',
        msgs: [],
        conversas: [],
        anexos: [],
        pasta: null,
        enviando: false,
        abort: null,
        sessao: 0,
        seguindo: true,
        buscaSeq: 0,
        listaPronta: false,
        pastaSeq: 0
    };

    /* ------------------------------------------------------------------ utilidades de DOM */
    function $(id) { return document.getElementById(id); }

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto != null && texto !== '') { n.textContent = texto; }
        return n;
    }

    function limpar(no) {
        while (no.firstChild) { no.removeChild(no.firstChild); }
    }

    var NS_SVG = 'http://www.w3.org/2000/svg';
    function icone(nome) {
        var s = document.createElementNS(NS_SVG, 'svg');
        s.setAttribute('class', 'ci-svg');
        s.setAttribute('aria-hidden', 'true');
        var u = document.createElementNS(NS_SVG, 'use');
        u.setAttribute('href', '#ci-i-' + nome);
        s.appendChild(u);
        return s;
    }

    function trocarIcone(botao, nome) {
        var u = botao.querySelector('use');
        if (u) { u.setAttribute('href', '#ci-i-' + nome); }
    }

    function botaoIcone(nome, rotulo, aoClicar, extra) {
        var b = el('button', 'ci-icone ci-icone-pequeno' + (extra ? ' ' + extra : ''));
        b.type = 'button';
        b.setAttribute('aria-label', rotulo);
        b.title = rotulo;
        b.appendChild(icone(nome));
        b.addEventListener('click', aoClicar);
        return b;
    }

    function guardar(chave, valor) {
        try { window.localStorage.setItem(chave, valor); } catch (e) { /* sem armazenamento */ }
    }
    function ler(chave) {
        try { return window.localStorage.getItem(chave); } catch (e) { return null; }
    }

    function comId(modelo, id) { return String(modelo || '').replace('__ID__', String(id)); }

    function copiarTexto(texto) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(texto).then(function () { return true; }, function () { return copiarPorCampo(texto); });
        }
        return Promise.resolve(copiarPorCampo(texto));
    }
    function copiarPorCampo(texto) {
        var ta = el('textarea');
        ta.value = texto;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }

    /* ------------------------------------------------------------------ rede */
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
        }, function () {
            return { ok: false, status: 0, dados: { codigo: 'rede' } };
        });
    }

    function textoDoErro(dados) {
        var codigo = dados && dados.codigo;
        if (codigo === 'rede') { return T('erro_rede'); }
        if (codigo === 'sem_chave') { return T('erro_sem_chave'); }
        if (codigo === 'sem_aceite') { return T('erro_sem_aceite'); }
        if (dados && typeof dados.mensagem === 'string' && dados.mensagem) { return dados.mensagem; }
        return T('erro_generico');
    }

    /* ------------------------------------------------------------------ avisos */
    var elAviso = $('ci-aviso');
    function mostrarAviso(texto) {
        $('ci-aviso-texto').textContent = texto;
        elAviso.hidden = false;
    }
    function limparAviso() { elAviso.hidden = true; }
    $('ci-aviso-fechar').addEventListener('click', limparAviso);

    /* ------------------------------------------------------------------ markdown seguro (chat-ia-markdown.js) */
    var renderizarMarkdown = window.BJMarkdown.criar({ T: T, icone: icone, copiar: copiarTexto }).renderizar;


    /* ------------------------------------------------------------------ rolagem */
    var elRolagem = $('ci-rolagem');
    var elDescer = $('ci-descer');
    var elMensagens = $('ci-mensagens');
    var elCorpo = $('ci-corpo');

    function noFim() { return elRolagem.scrollHeight - elRolagem.scrollTop - elRolagem.clientHeight < 80; }
    elRolagem.addEventListener('scroll', function () {
        estado.seguindo = noFim();
        elDescer.hidden = estado.seguindo || estado.msgs.length === 0;
    }, { passive: true });
    function rolarFim(forcar) {
        if (forcar) { estado.seguindo = true; }
        if (estado.seguindo) {
            elRolagem.scrollTop = elRolagem.scrollHeight;
            elDescer.hidden = true;
        }
    }
    elDescer.addEventListener('click', function () { rolarFim(true); });

    /* ------------------------------------------------------------------ mensagens na tela */
    function mostrarVazio(vazio) {
        elCorpo.classList.toggle('ci-vazia', vazio);
        elMensagens.hidden = vazio;
        if (vazio) { limpar(elMensagens); }
    }

    function saudacao() {
        var h = new Date().getHours();
        var s = h < 12 ? T('saudacao_bom_dia') : (h < 18 ? T('saudacao_boa_tarde') : T('saudacao_boa_noite'));
        return nomePessoa ? T('saudacao_com_nome', { saudacao: s, nome: nomePessoa }) : s;
    }

    function atualizarTitulo(titulo) {
        estado.titulo = titulo || '';
        $('ci-titulo-conversa').textContent = estado.titulo || T('titulo_pagina');
        document.title = estado.titulo ? estado.titulo + ' | BlueJus' : tituloOriginal;
    }

    function seloDe(nome, nomeIcone) {
        var s = el('span', 'ci-selo');
        s.appendChild(icone(nomeIcone));
        s.appendChild(el('span', '', nome));
        return s;
    }

    function atualizarUltima() {
        var ultimaIa = -1;
        var ultimaUsuario = -1;
        estado.msgs.forEach(function (m, i) {
            m.el.classList.remove('ci-ultima');
            if (m.papel === 'assistente') { ultimaIa = i; } else { ultimaUsuario = i; }
        });
        estado.msgs.forEach(function (m, i) {
            if (m.btnRegenerar) { m.btnRegenerar.hidden = i !== ultimaIa || estado.enviando; }
            if (m.btnEditar) { m.btnEditar.hidden = i !== ultimaUsuario || estado.enviando; }
        });
        if (ultimaIa >= 0) { estado.msgs[ultimaIa].el.classList.add('ci-ultima'); }
    }

    function adicionarMensagemUsuario(dados) {
        var m = { papel: 'usuario', texto: dados.conteudo || '', id: dados.id || null };
        m.el = el('div', 'ci-msg ci-msg-usuario');
        var selos = (dados.anexos || []).map(function (a) { return seloDe(a.nome, 'clipe'); });
        if (dados.pasta) { selos.push(seloDe('NUP ' + dados.pasta.nup, 'pasta')); }
        if (selos.length) {
            var caixa = el('div', 'ci-anexos-msg');
            selos.forEach(function (s) { caixa.appendChild(s); });
            m.el.appendChild(caixa);
        }
        m.balao = el('div', 'ci-balao', m.texto);
        m.el.appendChild(m.balao);
        m.acoes = el('div', 'ci-acoes');
        m.btnEditar = botaoIcone('editar', T('editar'), function () { iniciarEdicao(m); });
        m.acoes.appendChild(botaoIcone('copiar', T('copiar'), function (e) { copiarComAviso(m.texto, e.currentTarget); }));
        m.acoes.appendChild(m.btnEditar);
        m.el.appendChild(m.acoes);
        elMensagens.appendChild(m.el);
        estado.msgs.push(m);
        return m;
    }

    /* ------------------------------------------------------------------ cartão da proposta de cadastro
       A I.A só PROPÕE: o cartão mostra o que o servidor guardou (campo e valor literais, via textContent) e quem grava é o
       clique em Confirmar. O que a pessoa edita aqui é o que vale no clique, e o servidor revalida tudo. */
    function adicionarCartao(m, proposta) {
        var c = { dados: proposta, el: el('div', 'ci-proposta'), valores: null, erros: proposta.erros || {}, aviso: '', ocupado: false, alterou: false };
        m.el.insertBefore(c.el, m.nota);
        desenharCartao(c);
        return c;
    }

    function linkDoCartao(url, id, texto) {
        var a = el('a', 'ci-proposta-link', texto);
        a.href = comId(url, id);
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        return a;
    }

    /* O servidor guarda e valida a data como AAAA-MM-DD; na tela ela aparece dd/mm/aaaa. */
    function dataParaTela(chave, valor) {
        var m = chave === 'nascimento' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor) : null;
        return m ? m[3] + '/' + m[2] + '/' + m[1] : valor;
    }

    function dataParaServidor(chave, valor) {
        var m = chave === 'nascimento' ? /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(valor.trim()) : null;
        return m ? m[3] + '-' + m[2] + '-' + m[1] : valor;
    }

    function colherValores(c) {
        var v = { campos: {}, pasta: {} };
        c.el.querySelectorAll('[data-campo]').forEach(function (i) { var k = i.getAttribute('data-campo'); v.campos[k] = dataParaServidor(k, i.value); });
        c.el.querySelectorAll('[data-pasta]').forEach(function (i) { v.pasta[i.getAttribute('data-pasta')] = i.value; });
        return v;
    }

    function campoDoCartao(c, grupo, chave, rotulo, valor) {
        var linha = el('div', 'ci-proposta-linha');
        var id = 'ci-p' + c.dados.id + '-' + grupo + '-' + chave;
        var rot = el('label', 'ci-proposta-rotulo', rotulo);
        rot.setAttribute('for', id);
        var campo = el('input', 'ci-proposta-campo');
        campo.type = 'text';
        campo.id = id;
        campo.maxLength = 1000;
        campo.autocomplete = 'off';
        campo.value = grupo === 'campos' ? dataParaTela(chave, valor) : valor;
        campo.setAttribute(grupo === 'campos' ? 'data-campo' : 'data-pasta', chave);
        campo.addEventListener('input', function () {
            c.alterou = true;
            if (c.botaoConfirmar && c.dados.duplicada) { c.botaoConfirmar.hidden = false; }
        });
        linha.appendChild(rot);
        linha.appendChild(campo);
        var erro = c.erros[(grupo === 'campos' ? 'campos.' : 'pasta.') + chave];
        if (erro) {
            campo.setAttribute('aria-invalid', 'true');
            var msg = el('div', 'ci-proposta-erro', erro);
            msg.id = id + '-erro';
            campo.setAttribute('aria-describedby', msg.id);
            linha.appendChild(msg);
        }
        return linha;
    }

    function desenharCartao(c) {
        var p = c.dados;
        var antigos = c.valores;
        limpar(c.el);
        c.el.className = 'ci-proposta ci-proposta-' + p.estado;
        c.botaoConfirmar = null;
        var titulo = el('div', 'ci-proposta-titulo', T(p.tipo === 'pasta' ? 'proposta_titulo_pasta' : 'proposta_titulo_cliente'));
        c.el.setAttribute('role', 'group');
        c.el.setAttribute('aria-label', titulo.textContent);
        c.el.appendChild(titulo);

        if (p.estado !== 'pendente') {
            var fim = el('div', 'ci-proposta-situacao');
            if (p.estado === 'confirmada') {
                var res = p.resultado || {};
                fim.appendChild(el('span', '', T(res.tipo === 'pasta' ? 'proposta_criada_pasta' : 'proposta_criada_cliente')));
                if (res.id) { fim.appendChild(linkDoCartao(res.tipo === 'pasta' ? urls.pasta : urls.cliente, res.id, T('proposta_abrir'))); }
            } else {
                fim.textContent = T(p.estado === 'cancelada' ? 'proposta_cancelada' : 'proposta_vencida');
            }
            c.el.appendChild(fim);
            if (c.aviso) { c.el.appendChild(el('div', 'ci-proposta-erro', c.aviso)); }
            return;
        }

        c.el.appendChild(el('div', 'ci-proposta-dica', T('proposta_confira')));
        if (p.pasta) {
            [['nomeCliente', 'proposta_nome_cliente'], ['nomeAcao', 'proposta_nome_acao'], ['nup', 'proposta_numero']].forEach(function (f) {
                if (f[0] === 'nup' && !p.pasta.nup && !(antigos && antigos.pasta.nup)) { return; }
                var v = antigos && antigos.pasta[f[0]] !== undefined ? antigos.pasta[f[0]] : p.pasta[f[0]];
                c.el.appendChild(campoDoCartao(c, 'pasta', f[0], T(f[1]), v));
            });
        }
        if (p.campos && p.campos.length) {
            if (p.pasta) { c.el.appendChild(el('div', 'ci-proposta-subtitulo', T('proposta_cadastro_do_cliente'))); }
            p.campos.forEach(function (f) {
                var v = antigos && antigos.campos[f.chave] !== undefined ? antigos.campos[f.chave] : f.valor;
                c.el.appendChild(campoDoCartao(c, 'campos', f.chave, f.rotulo, v));
            });
        }
        if (p.duplicada) {
            var dup = el('div', 'ci-proposta-alerta');
            dup.setAttribute('role', 'status');
            dup.appendChild(el('span', '', T('proposta_duplicada', { documento: p.duplicada.documento, nome: p.duplicada.nome })));
            dup.appendChild(linkDoCartao(urls.cliente, p.duplicada.id, T('proposta_abrir_cadastro')));
            c.el.appendChild(dup);
        }
        if (p.semelhantes && p.semelhantes.lista && p.semelhantes.lista.length) {
            var sem = el('div', 'ci-proposta-alerta');
            sem.setAttribute('role', 'status');
            sem.appendChild(el('div', '', T('proposta_semelhantes', { total: p.semelhantes.total })));
            var ul = el('ul', 'ci-proposta-lista');
            p.semelhantes.lista.forEach(function (s) {
                var li = el('li');
                li.appendChild(linkDoCartao(urls.pasta, s.id, s.nup));
                li.appendChild(el('span', '', ' ' + s.nomeCliente + (s.nomeAcao ? ', ' + s.nomeAcao : '')));
                ul.appendChild(li);
            });
            sem.appendChild(ul);
            c.el.appendChild(sem);
        }
        var geral = c.aviso || c.erros[''];
        if (geral) {
            var g = el('div', 'ci-proposta-erro', geral);
            g.setAttribute('role', 'alert');
            c.el.appendChild(g);
        }

        var acoes = el('div', 'ci-proposta-acoes');
        var temAviso = !!(p.semelhantes && p.semelhantes.lista && p.semelhantes.lista.length);
        if (p.duplicada && p.duplicada.podeUsarExistente) {
            var usar = el('button', 'ci-botao ci-botao-primario', T('proposta_usar_existente'));
            usar.type = 'button';
            usar.disabled = c.ocupado;
            /* "Abrir nova pasta" já é a decisão de abrir mesmo com pasta parecida: o Confirmar fica escondido e não pode ser o único caminho. */
            usar.addEventListener('click', function () { confirmarProposta(c, temAviso ? { usarExistente: true, criarMesmoAssim: true } : { usarExistente: true }); });
            acoes.appendChild(usar);
        }
        var ok = el('button', 'ci-botao' + (p.duplicada && p.duplicada.podeUsarExistente ? '' : ' ci-botao-primario'), T(temAviso ? 'proposta_criar_mesmo_assim' : 'proposta_confirmar'));
        ok.type = 'button';
        ok.disabled = c.ocupado;
        /* Cadastro que já existe: o Confirmar que criaria o segundo some, e só volta se a pessoa editar os dados. */
        ok.hidden = !!p.duplicada && !c.alterou;
        ok.addEventListener('click', function () { confirmarProposta(c, temAviso ? { criarMesmoAssim: true } : {}); });
        c.botaoConfirmar = ok;
        acoes.appendChild(ok);
        var nao = el('button', 'ci-botao', T('proposta_cancelar'));
        nao.type = 'button';
        nao.disabled = c.ocupado;
        nao.addEventListener('click', function () { cancelarProposta(c); });
        acoes.appendChild(nao);
        c.el.appendChild(acoes);
    }

    /* Resposta do servidor ao clique: sucesso troca o cartão; recusa mostra o motivo no próprio cartão, sem perder o que a pessoa editou. */
    function aoResponderProposta(c, r) {
        c.ocupado = false;
        var d = r.dados || {};
        c.aviso = '';
        c.erros = {};
        if (r.ok) {
            c.dados = d;
            c.valores = null;
        } else if (r.status === 422 && d.erros) {
            c.erros = d.erros;
        } else if (r.status === 409 && d.codigo === 'duplicada' && d.existente) {
            c.dados.duplicada = { id: d.existente.id, nome: d.existente.nome, documento: d.existente.documento || T('proposta_documento'), podeUsarExistente: !!d.podeUsarExistente };
            c.alterou = false;
        } else if (r.status === 409 && d.codigo === 'pasta_semelhante') {
            c.dados.semelhantes = { lista: d.semelhantes || [], total: d.total || 0 };
        } else if (r.status === 409) {
            c.dados = { id: c.dados.id, tipo: c.dados.tipo, estado: d.codigo === 'proposta_vencida' ? 'expirada' : (d.codigo === 'proposta_confirmada' ? 'confirmada' : 'cancelada'), resultado: { tipo: c.dados.tipo } };
            c.aviso = d.mensagem || '';
        } else {
            c.aviso = textoDoErro(d);
        }
        desenharCartao(c);
    }

    function confirmarProposta(c, extras) {
        if (c.ocupado) { return; }
        c.valores = colherValores(c);
        var corpo = {};
        if (c.dados.campos) { corpo.campos = c.valores.campos; }
        if (c.dados.pasta) { corpo.pasta = c.valores.pasta; }
        Object.keys(extras || {}).forEach(function (k) { corpo[k] = extras[k]; });
        c.ocupado = true;
        desenharCartao(c);
        api('POST', comId(urls.propostaConfirmar, c.dados.id), corpo).then(function (r) { aoResponderProposta(c, r); });
    }

    function cancelarProposta(c) {
        if (c.ocupado) { return; }
        c.valores = colherValores(c);
        c.ocupado = true;
        desenharCartao(c);
        api('POST', comId(urls.propostaCancelar, c.dados.id), {}).then(function (r) { aoResponderProposta(c, r); });
    }

    function adicionarMensagemIa(dados) {
        var m = { papel: 'assistente', texto: dados.conteudo || '', id: dados.id || null, interrompida: !!dados.interrompida, gerando: false };
        m.el = el('div', 'ci-msg ci-msg-ia');
        m.md = el('div', 'ci-md');
        m.nota = el('div', 'ci-marca-interrompida');
        m.nota.hidden = true;
        m.acoes = el('div', 'ci-acoes');
        m.acoes.appendChild(botaoIcone('copiar', T('copiar_resposta'), function (e) { copiarComAviso(m.texto, e.currentTarget); }));
        m.btnRegenerar = botaoIcone('refazer', T('regenerar'), function () { enviar({ regenerar: true }); });
        m.acoes.appendChild(m.btnRegenerar);
        m.el.appendChild(m.md);
        m.el.appendChild(m.nota);
        m.el.appendChild(m.acoes);
        elMensagens.appendChild(m.el);
        estado.msgs.push(m);
        if (m.texto) { renderizarMarkdown(m.texto, m.md); }
        marcarInterrompida(m);
        (dados.propostas || []).forEach(function (p) { adicionarCartao(m, p); });
        return m;
    }

    function marcarInterrompida(m) {
        m.nota.hidden = !m.interrompida;
        m.nota.textContent = m.interrompida ? T('interrompida') : '';
    }

    function copiarComAviso(texto, botao) {
        copiarTexto(texto).then(function () {
            trocarIcone(botao, 'ok');
            botao.title = T('copiado');
            window.setTimeout(function () { trocarIcone(botao, 'copiar'); botao.title = botao.getAttribute('aria-label'); }, 1600);
        });
    }

    function removerMensagem(m) {
        if (m.el.parentNode) { m.el.parentNode.removeChild(m.el); }
        var i = estado.msgs.indexOf(m);
        if (i >= 0) { estado.msgs.splice(i, 1); }
    }

    function digitando() {
        var d = el('span', 'ci-digitando');
        d.setAttribute('role', 'img');
        d.setAttribute('aria-label', T('gerando'));
        d.appendChild(el('span', 'ci-digitando-texto', T('pensando')));
        d.appendChild(el('i'));
        d.appendChild(el('i'));
        d.appendChild(el('i'));
        return d;
    }

    function esqueletoDeMensagens() {
        mostrarVazio(false);
        limpar(elMensagens);
        for (var i = 0; i < 3; i++) { elMensagens.appendChild(el('div', 'ci-esqueleto-linha')); }
    }

    /* Edição inline da última pergunta. */
    function iniciarEdicao(m) {
        if (estado.enviando || !m.balao.parentNode) { return; }
        var ed = el('div', 'ci-editor');
        var ta = el('textarea');
        ta.value = m.texto;
        ta.setAttribute('aria-label', T('editar'));
        var acoes = el('div', 'ci-editor-acoes');
        var cancelar = el('button', 'ci-botao', T('cancelar'));
        cancelar.type = 'button';
        var salvar = el('button', 'ci-botao ci-botao-primario', T('enviar'));
        salvar.type = 'button';
        acoes.appendChild(cancelar);
        acoes.appendChild(salvar);
        ed.appendChild(ta);
        ed.appendChild(acoes);
        m.el.replaceChild(ed, m.balao);
        m.acoes.hidden = true;
        function crescer() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 240) + 'px'; }
        function sair() {
            m.el.replaceChild(m.balao, ed);
            m.acoes.hidden = false;
        }
        function confirmar() {
            var novo = ta.value;
            if (!novo.trim()) { return; }
            sair();
            enviar({ texto: novo, editarUltima: true });
        }
        ta.addEventListener('input', crescer);
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); sair(); return; }
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); confirmar(); }
        });
        cancelar.addEventListener('click', function () { sair(); });
        salvar.addEventListener('click', confirmar);
        crescer();
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    /* ------------------------------------------------------------------ envio e fluxo SSE */
    var campo = $('ci-texto');
    var botaoEnviar = $('ci-enviar');

    function atualizarBotaoEnviar() {
        if (estado.enviando) {
            botaoEnviar.disabled = false;
            botaoEnviar.setAttribute('aria-label', T('parar'));
            botaoEnviar.title = T('parar');
            trocarIcone(botaoEnviar, 'parar');
        } else {
            botaoEnviar.disabled = campo.value.trim() === '';
            botaoEnviar.setAttribute('aria-label', T('enviar'));
            botaoEnviar.title = T('enviar');
            trocarIcone(botaoEnviar, 'enviar');
        }
    }

    function crescerCampo() {
        campo.style.height = 'auto';
        campo.style.height = Math.min(campo.scrollHeight, 220) + 'px';
    }

    function processarBloco(bloco, aoEvento) {
        var evento = 'message';
        var dados = [];
        bloco.split(/\r?\n/).forEach(function (linha) {
            if (linha.indexOf('event:') === 0) { evento = linha.slice(6).trim(); } else if (linha.indexOf('data:') === 0) { dados.push(linha.slice(5).replace(/^ /, '')); }
        });
        if (!dados.length) { return; }
        var obj = null;
        try { obj = JSON.parse(dados.join('\n')); } catch (e) { return; }
        aoEvento(evento, obj);
    }

    /* Lê o corpo como stream e entrega cada evento SSE (delta, fim, erro). */
    function lerFluxo(resp, aoEvento) {
        var buf = '';
        function consumir(final) {
            var partes = buf.split(/\r?\n\r?\n/);
            buf = final ? '' : partes.pop();
            partes.forEach(function (p) { processarBloco(p, aoEvento); });
        }
        if (!resp.body || !resp.body.getReader || typeof TextDecoder === 'undefined') {
            return resp.text().then(function (t) { buf = t; consumir(true); });
        }
        var leitor = resp.body.getReader();
        var dec = new TextDecoder('utf-8');
        function passo() {
            return leitor.read().then(function (r) {
                if (r.done) { buf += dec.decode(); consumir(true); return null; }
                buf += dec.decode(r.value, { stream: true });
                consumir(false);
                return passo();
            });
        }
        return passo();
    }

    function garantirConversa() {
        if (estado.conversaId) { return Promise.resolve(estado.conversaId); }
        return api('POST', urls.conversas, {}).then(function (r) {
            if (!r.ok || !r.dados.id) {
                mostrarAviso(textoDoErro(r.dados));
                return null;
            }
            estado.conversaId = r.dados.id;
            try { window.history.replaceState({ id: r.dados.id }, '', comId(urls.paginaDaConversa, r.dados.id)); } catch (e) { /* sem history */ }
            var pendente = modelos.length && $('ci-modelo').value !== (raiz.dataset.modeloPadrao || '') ? $('ci-modelo').value : null;
            if (pendente) { return api('PATCH', comId(urls.conversa, r.dados.id), { modelo: pendente }).then(function () { return r.dados.id; }); }
            return r.dados.id;
        });
    }

    /* "Voltar ao BlueJus": volta à página do sistema de onde a pessoa veio; sem origem do sistema, o link vai ao início. */
    (function () {
        var voltar = $('ci-voltar');
        if (!voltar) { return; }
        voltar.addEventListener('click', function (e) {
            var origem = '';
            try {
                var ref = document.referrer ? new URL(document.referrer) : null;
                if (ref && ref.origin === window.location.origin && ref.pathname.indexOf('/chat-ia') !== 0 && ref.pathname.indexOf('/login') !== 0) { origem = ref.href; }
            } catch (err) { origem = ''; }
            if (origem !== '') { e.preventDefault(); window.location.assign(origem); }
        });
    }());

    function enviar(op) {
        op = op || {};
        if (estado.enviando) { return; }
        var texto = op.regenerar ? '' : (op.texto !== undefined ? op.texto : campo.value);
        if (!op.regenerar && texto.trim() === '') { mostrarAviso(T('erro_texto_vazio')); return; }
        if (estado.semChave) { mostrarAviso(T('erro_sem_chave')); return; }
        if (estado.anexos.some(function (a) { return a.enviando; })) { return; }
        limparAviso();
        var sessao = estado.sessao;
        estado.enviando = true;
        atualizarBotaoEnviar();
        atualizarUltima();
        var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        estado.abort = ctl;
        var m = null;
        var terminou = false;
        var vivo = function () { return sessao === estado.sessao; };
        /* Pergunta nova: ela e os anexos entram na conversa NA HORA, a caixa limpa e o "Pensando" anima até o 1º trecho.
           Se o servidor recusar, a pergunta volta para a caixa (desfazer). Editar e regenerar esperam o "ok" do servidor,
           porque mexem na resposta que já está na tela. */
        var otimista = !op.regenerar && !op.editarUltima;
        var devolver = null;
        var foiAoServidor = false;
        var enviadosAgora = null;
        var pastaAgora = null;
        if (otimista) {
            devolver = { texto: texto, anexos: estado.anexos, pasta: estado.pasta };
            /* prepararTela esvazia estado.anexos e estado.pasta: o que vai ao servidor tem de ser lido ANTES. */
            enviadosAgora = estado.anexos.filter(function (a) { return a.id; });
            pastaAgora = estado.pasta;
            m = prepararTela(op, texto, enviadosAgora, pastaAgora);
        }
        function desfazer() {
            if (!m || !devolver) { return; }
            var ultimaUsuario = estado.msgs[estado.msgs.indexOf(m) - 1];
            removerMensagem(m);
            if (ultimaUsuario && ultimaUsuario.papel === 'usuario') { removerMensagem(ultimaUsuario); }
            m = null;
            campo.value = devolver.texto;
            estado.anexos = devolver.anexos;
            estado.pasta = devolver.pasta;
            crescerCampo();
            renderChips();
            if (!estado.msgs.length) { mostrarVazio(true); }
        }

        function encerrar() {
            if (!vivo()) { return; }
            estado.enviando = false;
            estado.abort = null;
            if (m) { m.gerando = false; m.el.classList.remove('ci-gerando'); }
            elMensagens.setAttribute('aria-busy', 'false');
            atualizarBotaoEnviar();
            atualizarUltima();
            campo.focus();
        }

        garantirConversa().then(function (id) {
            if (!id) { desfazer(); return null; }
            if (!vivo()) { return null; }
            var corpo = { texto: texto };
            if (op.regenerar) { corpo.regenerar = true; }
            if (op.editarUltima) { corpo.editarUltima = true; }
            var enviados = otimista ? enviadosAgora : estado.anexos.filter(function (a) { return a.id; });
            var pasta = otimista ? pastaAgora : estado.pasta;
            /* Editar a última pergunta ou regenerar NÃO manda pastaId nem anexoIds: o servidor mantém a pasta e os anexos
               da pergunta (são os chips que o balão já mostra). Os chips do campo valem só para a próxima pergunta nova. */
            if (!op.regenerar && !op.editarUltima) {
                if (pasta) { corpo.pastaId = pasta.id; }
                if (enviados.length) { corpo.anexoIds = enviados.map(function (a) { return a.id; }); }
            }
            foiAoServidor = true;
            return fetch(comId(urls.mensagens, id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream, application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(corpo),
                signal: ctl ? ctl.signal : undefined
            }).then(function (resp) {
                if (!resp.ok) {
                    return resp.json().then(function (d) { return d; }, function () { return {}; }).then(function (d) {
                        if (vivo()) { desfazer(); mostrarAviso(textoDoErro(d)); }
                    });
                }
                if (!vivo()) { return null; }
                if (!otimista) { m = prepararTela(op, texto, enviados, pasta); }
                return lerFluxo(resp, function (evento, d) {
                    if (!vivo()) { return; }
                    if (evento === 'delta') {
                        m.texto += String(d.t || '');
                        agendarRender(m);
                    } else if (evento === 'proposta') {
                        adicionarCartao(m, d);
                        rolarFim();
                    } else if (evento === 'fim') {
                        terminou = true;
                        m.id = d.mensagemId || null;
                        agendarRender(m, true);
                        if (d.cortada) { m.nota.hidden = false; m.nota.textContent = T('cortada'); }
                        if (d.titulo) { atualizarTitulo(d.titulo); }
                        carregarLista();
                    } else if (evento === 'erro') {
                        terminou = true;
                        mostrarAviso(textoDoErro(d));
                        if (m.texto === '') { removerMensagem(m); m = null; } else { m.interrompida = true; marcarInterrompida(m); agendarRender(m, true); }
                    }
                }).then(function () {
                    if (!terminou && m && vivo()) {
                        m.interrompida = true;
                        marcarInterrompida(m);
                        agendarRender(m, true);
                        mostrarAviso(T('erro_rede'));
                    }
                });
            });
        }).catch(function (err) {
            if (!vivo()) { return; }
            if (otimista && !foiAoServidor) { desfazer(); mostrarAviso(T('erro_rede')); return; }
            if (err && err.name === 'AbortError') {
                if (m) { m.interrompida = true; marcarInterrompida(m); agendarRender(m, true); }
            } else {
                mostrarAviso(T('erro_rede'));
                if (m) { m.interrompida = true; marcarInterrompida(m); agendarRender(m, true); }
            }
        }).then(encerrar);
    }

    /* Depois do "ok" do servidor: mexe na tela (nova pergunta, edição ou regeneração) e abre o espaço da resposta. */
    function prepararTela(op, texto, anexos, pasta) {
        mostrarVazio(false);
        var ultima = estado.msgs[estado.msgs.length - 1];
        if ((op.regenerar || op.editarUltima) && ultima && ultima.papel === 'assistente') { removerMensagem(ultima); }
        if (op.editarUltima) {
            var u = estado.msgs[estado.msgs.length - 1];
            if (u && u.papel === 'usuario') { u.texto = texto; u.balao.textContent = texto; }
        } else if (!op.regenerar) {
            adicionarMensagemUsuario({
                conteudo: texto,
                anexos: anexos.map(function (a) { return { id: a.id, nome: a.nome }; }),
                pasta: pasta ? { id: pasta.id, nup: pasta.nup } : null
            });
            campo.value = '';
            crescerCampo();
            estado.anexos = [];
            estado.pasta = null;
            renderChips();
        }
        var ia = adicionarMensagemIa({});
        ia.gerando = true;
        ia.el.classList.add('ci-gerando');
        ia.md.appendChild(digitando());
        elMensagens.setAttribute('aria-busy', 'true');
        atualizarUltima();
        rolarFim(true);
        return ia;
    }

    var renderAgendado = null;
    function agendarRender(m, agora) {
        function fazer() {
            renderAgendado = null;
            if (m.texto !== '') { renderizarMarkdown(m.texto, m.md); }
            rolarFim(false);
        }
        if (agora) {
            if (renderAgendado !== null && window.cancelAnimationFrame) { window.cancelAnimationFrame(renderAgendado); }
            fazer();
            return;
        }
        if (renderAgendado === null) {
            renderAgendado = window.requestAnimationFrame ? window.requestAnimationFrame(fazer) : window.setTimeout(fazer, 50);
        }
    }

    function parar() {
        if (estado.abort) { estado.abort.abort(); }
    }

    $('ci-caixa').addEventListener('submit', function (e) {
        e.preventDefault();
        if (estado.enviando) { parar(); return; }
        enviar({});
    });
    campo.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            if (!estado.enviando) { enviar({}); }
        }
    });
    campo.addEventListener('input', function (e) {
        crescerCampo();
        atualizarBotaoEnviar();
        if (e.inputType === 'insertText' && e.data === '#' && !e.isComposing) {
            var pos = campo.selectionStart;
            var antes = campo.value.charAt(pos - 2);
            if (pos === 1 || /\s/.test(antes)) {
                campo.value = campo.value.slice(0, pos - 1) + campo.value.slice(pos);
                campo.setSelectionRange(pos - 1, pos - 1);
                crescerCampo();
                atualizarBotaoEnviar();
                abrirPastas();
            }
        }
    });
    campo.addEventListener('paste', function (e) {
        var arquivos = e.clipboardData && e.clipboardData.files;
        if (arquivos && arquivos.length) {
            e.preventDefault();
            anexarArquivos(arquivos);
        }
    });

    /* ------------------------------------------------------------------ anexos e pasta citada */
    var elChips = $('ci-chips');
    var TIPOS = { 'application/pdf': 'pdf', 'image/png': 'img', 'image/jpeg': 'img', 'image/gif': 'img', 'image/webp': 'img', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx' };
    var EXTENSOES = { pdf: 'pdf', png: 'img', jpg: 'img', jpeg: 'img', gif: 'img', webp: 'img', docx: 'docx' };
    var LIMITES = { pdf: 10 * 1024 * 1024, img: 3 * 1024 * 1024, docx: 10 * 1024 * 1024 };
    var MAX_ANEXOS = 3;

    function tipoDoArquivo(f) {
        if (TIPOS[f.type]) { return TIPOS[f.type]; }
        var ext = String(f.name || '').split('.').pop().toLowerCase();
        return f.type === '' || f.type === 'application/octet-stream' ? (EXTENSOES[ext] || null) : null;
    }

    function renderChips() {
        limpar(elChips);
        estado.anexos.forEach(function (a) {
            var chip = el('span', 'ci-chip' + (a.enviando ? ' ci-enviando' : ''));
            chip.appendChild(icone('clipe'));
            chip.appendChild(el('span', '', a.nome));
            chip.appendChild(botaoIcone('fechar', T('remover') + ': ' + a.nome, function () { removerAnexo(a); }));
            elChips.appendChild(chip);
        });
        if (estado.pasta) {
            var cp = el('span', 'ci-chip');
            cp.appendChild(icone('pasta'));
            cp.appendChild(el('span', '', T('citar_pasta_chip', { nup: estado.pasta.nup, cliente: estado.pasta.cliente || '' })));
            cp.appendChild(botaoIcone('fechar', T('remover') + ': ' + estado.pasta.nup, function () { estado.pasta = null; renderChips(); }));
            elChips.appendChild(cp);
        }
        elChips.hidden = !estado.anexos.length && !estado.pasta;
    }

    function removerAnexo(a) {
        var tirar = function () { estado.anexos = estado.anexos.filter(function (x) { return x !== a; }); renderChips(); };
        if (!a.id) { tirar(); return; }
        api('DELETE', comId(urls.anexo, a.id)).then(function (r) {
            if (r.ok || r.status === 404) { tirar(); } else { mostrarAviso(textoDoErro(r.dados)); }
        });
    }

    function anexarArquivos(arquivos) {
        var lote = Array.prototype.slice.call(arquivos);
        if (!lote.length) { return; }
        limparAviso();
        if (estado.anexos.length + lote.length > MAX_ANEXOS) { mostrarAviso(T('erro_anexo_quantidade')); return; }
        for (var i = 0; i < lote.length; i++) {
            var tipo = tipoDoArquivo(lote[i]);
            if (!tipo) { mostrarAviso(T('erro_anexo_tipo', { nome: lote[i].name })); return; }
            if (lote[i].size > LIMITES[tipo]) { mostrarAviso(T('erro_anexo_tamanho', { nome: lote[i].name })); return; }
        }
        garantirConversa().then(function (id) {
            if (!id) { return; }
            lote.forEach(function (f) { subirArquivo(f, id); });
        });
    }

    function subirArquivo(f, conversaId) {
        var item = { nome: f.name, id: null, enviando: true };
        estado.anexos.push(item);
        renderChips();
        var fd = new FormData();
        fd.append('arquivo', f);
        fd.append('conversaId', String(conversaId));
        fetch(urls.anexos, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }, body: fd })
            .then(function (r) { return r.json().then(function (d) { return d; }, function () { return {}; }).then(function (d) { return { ok: r.ok, dados: d }; }); })
            .then(function (r) {
                if (r.ok && r.dados.id) {
                    item.id = r.dados.id;
                    item.enviando = false;
                } else {
                    estado.anexos = estado.anexos.filter(function (x) { return x !== item; });
                    mostrarAviso(textoDoErro(r.dados));
                }
                renderChips();
            }, function () {
                estado.anexos = estado.anexos.filter(function (x) { return x !== item; });
                mostrarAviso(T('erro_rede'));
                renderChips();
            });
    }

    $('ci-anexar').addEventListener('click', function () { $('ci-arquivo').click(); });
    $('ci-arquivo').addEventListener('change', function (e) {
        anexarArquivos(e.target.files);
        e.target.value = '';
    });

    var contadorArraste = 0;
    function temArquivos(e) {
        var t = e.dataTransfer && e.dataTransfer.types;
        return !!t && Array.prototype.indexOf.call(t, 'Files') !== -1;
    }
    $('ci-principal').addEventListener('dragenter', function (e) {
        if (!temArquivos(e)) { return; }
        e.preventDefault();
        contadorArraste++;
        $('ci-soltar').hidden = false;
    });
    $('ci-principal').addEventListener('dragover', function (e) { if (temArquivos(e)) { e.preventDefault(); } });
    $('ci-principal').addEventListener('dragleave', function (e) {
        if (!temArquivos(e)) { return; }
        contadorArraste = Math.max(0, contadorArraste - 1);
        if (contadorArraste === 0) { $('ci-soltar').hidden = true; }
    });
    $('ci-principal').addEventListener('drop', function (e) {
        if (!temArquivos(e)) { return; }
        e.preventDefault();
        contadorArraste = 0;
        $('ci-soltar').hidden = true;
        anexarArquivos(e.dataTransfer.files);
    });

    /* Citar pasta */
    var elPastas = $('ci-pastas');
    var elPastasBusca = $('ci-pastas-busca');
    var elPastasLista = $('ci-pastas-lista');
    var pastasAchadas = [];
    var pastaSel = 0;
    var tempoPasta = null;

    function pintarPastas() {
        limpar(elPastasLista);
        if (!pastasAchadas.length) {
            var v = el('li', 'ci-vazio-lista', T('citar_pasta_vazio'));
            v.setAttribute('role', 'presentation');
            elPastasLista.appendChild(v);
            return;
        }
        pastasAchadas.forEach(function (p, i) {
            var li = el('li');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', i === pastaSel ? 'true' : 'false');
            li.appendChild(el('strong', '', p.nup));
            if (p.cliente) { li.appendChild(el('small', '', p.cliente)); }
            li.addEventListener('mousedown', function (e) { e.preventDefault(); escolherPasta(p); });
            elPastasLista.appendChild(li);
        });
    }

    function buscarPastas() {
        var seq = ++estado.pastaSeq;
        api('GET', urls.pastas + '?q=' + encodeURIComponent(elPastasBusca.value.trim())).then(function (r) {
            if (seq !== estado.pastaSeq) { return; }
            pastasAchadas = r.ok && Array.isArray(r.dados) ? r.dados : [];
            pastaSel = 0;
            pintarPastas();
        });
    }

    function abrirPastas() {
        elPastas.hidden = false;
        $('ci-citar').setAttribute('aria-expanded', 'true');
        elPastasBusca.value = '';
        elPastasBusca.focus();
        pintarPastas();
        buscarPastas();
    }
    function fecharPastas(devolverFoco) {
        if (elPastas.hidden) { return; }
        elPastas.hidden = true;
        $('ci-citar').setAttribute('aria-expanded', 'false');
        if (devolverFoco) { campo.focus(); }
    }
    function escolherPasta(p) {
        estado.pasta = { id: p.id, nup: p.nup, cliente: p.cliente || '' };
        renderChips();
        fecharPastas(true);
    }
    $('ci-citar').addEventListener('click', function () { if (elPastas.hidden) { abrirPastas(); } else { fecharPastas(true); } });
    elPastasBusca.addEventListener('input', function () {
        window.clearTimeout(tempoPasta);
        tempoPasta = window.setTimeout(buscarPastas, 250);
    });
    elPastasBusca.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); fecharPastas(true); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (pastasAchadas.length) { pastaSel = (pastaSel + (e.key === 'ArrowDown' ? 1 : pastasAchadas.length - 1)) % pastasAchadas.length; pintarPastas(); }
            return;
        }
        if (e.key === 'Enter' && !e.isComposing) {
            e.preventDefault();
            if (pastasAchadas[pastaSel]) { escolherPasta(pastasAchadas[pastaSel]); }
        }
    });
    document.addEventListener('mousedown', function (e) {
        if (!elPastas.hidden && !elPastas.contains(e.target) && !$('ci-citar').contains(e.target)) { fecharPastas(false); }
    });

    /* ------------------------------------------------------------------ histórico (barra lateral) */
    var elHistorico = $('ci-historico');
    var elBusca = $('ci-busca');
    var ORDEM_GRUPOS = ['fixadas', 'hoje', 'ultimos_7_dias', 'mais_antigas'];
    var tempoBusca = null;

    function carregarLista() {
        var seq = ++estado.buscaSeq;
        var q = elBusca.value.trim();
        return api('GET', urls.conversas + (q ? '?q=' + encodeURIComponent(q) : '')).then(function (r) {
            if (seq !== estado.buscaSeq) { return; }
            estado.conversas = r.ok && Array.isArray(r.dados) ? r.dados : [];
            estado.listaPronta = true;
            renderLista(q);
        });
    }

    function renderLista(q) {
        if (!estado.listaPronta) { return; }
        limpar(elHistorico);
        if (!estado.conversas.length) {
            elHistorico.appendChild(el('div', 'ci-vazio-lista', q ? T('sem_resultados') : T('sem_conversas')));
            return;
        }
        ORDEM_GRUPOS.forEach(function (g) {
            var doGrupo = estado.conversas.filter(function (c) { return c.grupo === g; });
            if (!doGrupo.length) { return; }
            elHistorico.appendChild(el('div', 'ci-grupo', T('grupo_' + g)));
            doGrupo.forEach(function (c) { elHistorico.appendChild(linhaDaConversa(c)); });
        });
    }

    function linhaDaConversa(c) {
        var linha = el('div', 'ci-conversa' + (c.id === estado.conversaId ? ' ci-atual' : ''));
        linha.dataset.id = String(c.id);
        var link = el('button', 'ci-conversa-link', c.titulo || T('sem_titulo'));
        link.type = 'button';
        link.title = c.titulo || T('sem_titulo');
        if (c.id === estado.conversaId) { link.setAttribute('aria-current', 'page'); }
        link.addEventListener('click', function () {
            fecharGaveta();
            if (c.id !== estado.conversaId) { abrirConversa(c.id, true); }
        });
        var mais = botaoIcone('mais', T('mais_opcoes'), function () { abrirMenu(mais, c, linha, link); }, 'ci-conversa-mais');
        mais.setAttribute('aria-haspopup', 'menu');
        mais.setAttribute('aria-expanded', 'false');
        linha.appendChild(link);
        linha.appendChild(mais);
        return linha;
    }

    elBusca.addEventListener('input', function () {
        window.clearTimeout(tempoBusca);
        tempoBusca = window.setTimeout(carregarLista, 250);
    });
    elHistorico.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
        var links = Array.prototype.slice.call(elHistorico.querySelectorAll('.ci-conversa-link'));
        var i = links.indexOf(document.activeElement);
        if (i === -1) { return; }
        e.preventDefault();
        var prox = links[i + (e.key === 'ArrowDown' ? 1 : -1)];
        if (prox) { prox.focus(); }
    });

    /* Menu da conversa */
    var menuAberto = null;
    function fecharMenu(devolverFoco) {
        if (!menuAberto) { return; }
        var m = menuAberto;
        menuAberto = null;
        if (m.menu.parentNode) { m.menu.parentNode.removeChild(m.menu); }
        m.gatilho.setAttribute('aria-expanded', 'false');
        if (devolverFoco) { m.gatilho.focus(); }
    }

    function abrirMenu(gatilho, c, linha, link) {
        if (menuAberto && menuAberto.gatilho === gatilho) { fecharMenu(true); return; }
        fecharMenu(false);
        var menu = el('div', 'ci-menu');
        menu.setAttribute('role', 'menu');
        var itens = [
            { icone: 'editar', rotulo: T('renomear'), acao: function () { iniciarRenomear(c, linha, link); } },
            { icone: 'fixar', rotulo: c.fixada ? T('desafixar') : T('fixar'), acao: function () { alternarFixada(c); } },
            { icone: 'lixo', rotulo: T('excluir'), perigo: true, acao: function () { pedirExclusao(c); } }
        ];
        var botoes = itens.map(function (it) {
            var b = el('button', it.perigo ? 'ci-perigo' : '');
            b.type = 'button';
            b.setAttribute('role', 'menuitem');
            b.appendChild(icone(it.icone));
            b.appendChild(el('span', '', it.rotulo));
            b.addEventListener('click', function () { fecharMenu(false); it.acao(); });
            menu.appendChild(b);
            return b;
        });
        menu.addEventListener('keydown', function (e) {
            var i = botoes.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); botoes[(i + 1) % botoes.length].focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); botoes[(i + botoes.length - 1) % botoes.length].focus(); } else if (e.key === 'Home') { e.preventDefault(); botoes[0].focus(); } else if (e.key === 'End') { e.preventDefault(); botoes[botoes.length - 1].focus(); } else if (e.key === 'Escape') { e.preventDefault(); fecharMenu(true); } else if (e.key === 'Tab') { fecharMenu(false); }
        });
        document.body.appendChild(menu);
        var r = gatilho.getBoundingClientRect();
        var largura = menu.offsetWidth;
        var altura = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - largura - 8)) + 'px';
        menu.style.top = (r.bottom + altura + 8 > window.innerHeight ? Math.max(8, r.top - altura - 4) : r.bottom + 4) + 'px';
        gatilho.setAttribute('aria-expanded', 'true');
        menuAberto = { menu: menu, gatilho: gatilho };
        botoes[0].focus();
    }
    document.addEventListener('mousedown', function (e) {
        if (menuAberto && !menuAberto.menu.contains(e.target) && !menuAberto.gatilho.contains(e.target)) { fecharMenu(false); }
    });

    function iniciarRenomear(c, linha, link) {
        var campoNome = el('input', 'ci-renomear');
        campoNome.type = 'text';
        campoNome.maxLength = 120;
        campoNome.value = c.titulo || '';
        campoNome.setAttribute('aria-label', T('renomear_rotulo'));
        var feito = false;
        function concluir(salvar) {
            if (feito) { return; }
            feito = true;
            var novo = campoNome.value.trim();
            if (salvar && novo !== '' && novo !== c.titulo) {
                api('PATCH', comId(urls.conversa, c.id), { titulo: novo }).then(function (r) {
                    if (r.ok) {
                        c.titulo = novo;
                        if (c.id === estado.conversaId) { atualizarTitulo(novo); }
                    } else {
                        mostrarAviso(textoDoErro(r.dados));
                    }
                    carregarLista();
                });
                return;
            }
            linha.replaceChild(link, campoNome);
            link.focus();
        }
        campoNome.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); concluir(true); } else if (e.key === 'Escape') { e.preventDefault(); concluir(false); }
        });
        campoNome.addEventListener('blur', function () { concluir(true); });
        linha.replaceChild(campoNome, link);
        campoNome.focus();
        campoNome.select();
    }

    function alternarFixada(c) {
        api('PATCH', comId(urls.conversa, c.id), { fixada: !c.fixada }).then(function (r) {
            if (!r.ok) { mostrarAviso(textoDoErro(r.dados)); }
            carregarLista();
        });
    }

    /* Confirmação de exclusão: diálogo próprio, dentro da página. */
    var elDialogo = $('ci-dialogo');
    var dialogoGatilho = null;
    function fecharDialogo() {
        elDialogo.hidden = true;
        if (dialogoGatilho && dialogoGatilho.focus) { dialogoGatilho.focus(); }
        dialogoGatilho = null;
    }
    function pedirExclusao(c) {
        dialogoGatilho = document.activeElement && document.activeElement !== document.body ? document.activeElement : $('ci-novo');
        $('ci-dialogo-titulo').textContent = T('excluir_titulo');
        $('ci-dialogo-texto').textContent = T('excluir_texto', { titulo: c.titulo || T('sem_titulo') });
        elDialogo.hidden = false;
        $('ci-dialogo-cancelar').focus();
        $('ci-dialogo-confirmar').onclick = function () {
            fecharDialogo();
            api('DELETE', comId(urls.conversa, c.id)).then(function (r) {
                if (!r.ok && r.status !== 404) { mostrarAviso(textoDoErro(r.dados)); return; }
                if (c.id === estado.conversaId) { novoChat(true); }
                carregarLista();
            });
        };
    }
    $('ci-dialogo-cancelar').addEventListener('click', fecharDialogo);
    elDialogo.addEventListener('mousedown', function (e) { if (e.target === elDialogo) { fecharDialogo(); } });
    elDialogo.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); fecharDialogo(); return; }
        if (e.key === 'Tab') {
            var a = $('ci-dialogo-cancelar');
            var b = $('ci-dialogo-confirmar');
            if (e.shiftKey && document.activeElement === a) { e.preventDefault(); b.focus(); } else if (!e.shiftKey && document.activeElement === b) { e.preventDefault(); a.focus(); }
        }
    });

    /* ------------------------------------------------------------------ navegação entre conversas */
    function idDaUrl() {
        var base = String(new URL(urls.pagina, window.location.origin).pathname).replace(/\/+$/, '');
        var m = new RegExp('^' + base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/(\\d{1,9})/?$').exec(window.location.pathname);
        return m ? parseInt(m[1], 10) : null;
    }

    function zerarConversa() {
        estado.sessao++;
        if (estado.abort) { estado.abort.abort(); }
        estado.abort = null;
        estado.enviando = false;
        estado.msgs = [];
        estado.anexos = [];
        estado.pasta = null;
        campo.value = '';
        crescerCampo();
        renderChips();
        limparAviso();
        elMensagens.setAttribute('aria-busy', 'false');
        atualizarBotaoEnviar();
    }

    function novoChat(empurrar) {
        zerarConversa();
        estado.conversaId = null;
        atualizarTitulo('');
        $('ci-modelo').value = estado.modelo = raiz.dataset.modeloPadrao || $('ci-modelo').value;
        mostrarVazio(true);
        $('ci-saudacao').textContent = saudacao();
        elDescer.hidden = true;
        if (empurrar) { try { window.history.pushState({ id: null }, '', urls.pagina); } catch (e) { /* sem history */ } }
        renderLista(elBusca.value.trim());
        campo.focus();
    }

    function abrirConversa(id, empurrar) {
        zerarConversa();
        estado.conversaId = id;
        if (empurrar) { try { window.history.pushState({ id: id }, '', comId(urls.paginaDaConversa, id)); } catch (e) { /* sem history */ } }
        esqueletoDeMensagens();
        renderLista(elBusca.value.trim());
        var sessao = estado.sessao;
        return api('GET', comId(urls.conversa, id)).then(function (r) {
            if (sessao !== estado.sessao) { return; }
            if (!r.ok) {
                mostrarAviso(r.status === 404 ? T('erro_conversa') : textoDoErro(r.dados));
                novoChat(true);
                return;
            }
            var d = r.dados;
            atualizarTitulo(d.titulo || '');
            if (d.modelo) { $('ci-modelo').value = estado.modelo = d.modelo; }
            limpar(elMensagens);
            var lista = d.mensagens || [];
            mostrarVazio(lista.length === 0);
            $('ci-saudacao').textContent = saudacao();
            lista.forEach(function (m) {
                if (m.papel === 'usuario') { adicionarMensagemUsuario(m); } else { adicionarMensagemIa(m); }
            });
            atualizarUltima();
            rolarFim(true);
            campo.focus();
        });
    }

    window.addEventListener('popstate', function () {
        var id = idDaUrl();
        if (id === estado.conversaId) { return; }
        if (id) { abrirConversa(id, false); } else { novoChat(false); }
    });

    $('ci-novo').addEventListener('click', function () {
        fecharGaveta();
        novoChat(true);
    });

    /* ------------------------------------------------------------------ barra lateral, tema, modelo */
    var consultaCelular = window.matchMedia ? window.matchMedia('(max-width: 800px)') : null;
    function ehCelular() { return !!consultaCelular && consultaCelular.matches; }
    var fundoGaveta = $('ci-fundo-gaveta');

    function abrirGaveta() {
        raiz.classList.add('ci-gaveta-aberta');
        fundoGaveta.hidden = false;
        $('ci-novo').focus();
    }
    function fecharGaveta() {
        if (!raiz.classList.contains('ci-gaveta-aberta')) { return; }
        raiz.classList.remove('ci-gaveta-aberta');
        fundoGaveta.hidden = true;
        $('ci-abrir-barra').focus();
    }
    fundoGaveta.addEventListener('click', fecharGaveta);
    $('ci-recolher').addEventListener('click', function () {
        if (ehCelular()) { fecharGaveta(); return; }
        raiz.classList.add('ci-recolhida');
        guardar('chat-ia-barra', '1');
        $('ci-abrir-barra').focus();
    });
    $('ci-abrir-barra').addEventListener('click', function () {
        if (ehCelular()) { abrirGaveta(); return; }
        raiz.classList.remove('ci-recolhida');
        guardar('chat-ia-barra', '0');
        $('ci-novo').focus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && raiz.classList.contains('ci-gaveta-aberta') && !menuAberto && elDialogo.hidden) { fecharGaveta(); }
    });
    if (ler('chat-ia-barra') === '1') { raiz.classList.add('ci-recolhida'); }

    function temaEfetivo() {
        var t = document.documentElement.getAttribute('data-theme');
        if (t === 'light' || t === 'dark') { return t; }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function pintarBotaoTema() {
        var escuro = temaEfetivo() === 'dark';
        var b = $('ci-tema');
        var rotulo = escuro ? T('tema_claro') : T('tema_escuro');
        b.setAttribute('aria-label', rotulo);
        b.title = rotulo;
        trocarIcone(b, escuro ? 'sol' : 'lua');
    }
    $('ci-tema').addEventListener('click', function () {
        var novo = temaEfetivo() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', novo);
        guardar('chat-ia-tema', novo);
        pintarBotaoTema();
    });

    var elModelo = $('ci-modelo');
    modelos.forEach(function (mo) {
        var o = el('option', '', mo.rotulo);
        o.value = mo.valor;
        elModelo.appendChild(o);
    });
    elModelo.value = estado.modelo;
    elModelo.addEventListener('change', function () {
        estado.modelo = elModelo.value;
        if (!estado.conversaId) { return; }
        api('PATCH', comId(urls.conversa, estado.conversaId), { modelo: estado.modelo }).then(function (r) {
            if (!r.ok) { mostrarAviso(textoDoErro(r.dados)); }
        });
    });

    /* ------------------------------------------------------------------ início */
    pintarBotaoTema();
    for (var k = 0; k < 4; k++) { elHistorico.appendChild(el('div', 'ci-esqueleto-linha')); }
    if (estado.semChave) {
        $('ci-banner-texto').textContent = raiz.dataset.aviso || T('erro_sem_chave');
        $('ci-banner').hidden = false;
    } else if (raiz.dataset.aviso) {
        mostrarAviso(raiz.dataset.aviso);
    }
    $('ci-saudacao').textContent = saudacao();
    atualizarBotaoEnviar();
    carregarLista();
    if (estado.conversaId) {
        var inicial = estado.conversaId;
        estado.conversaId = null;
        abrirConversa(inicial, false);
    } else {
        campo.focus();
    }
}());
