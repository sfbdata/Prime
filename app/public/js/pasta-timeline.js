/* =============================================================================
   Timeline inteligente da pasta (L18 da Trilha B; desenho `bluejus-central.js`
   `tlRender`/`tlCard`). POR REGRAS, sem IA: o servidor (`pasta_timeline`) junta as
   fontes reais, filtra e aplica as regras; aqui só se desenha.

   Segurança: TODO texto entra por textContent. Nenhum innerHTML com dado.
   A cor de categoria/prioridade vem de constante do servidor e só é aplicada se
   for um hex de 6 dígitos.

   "Enquanto você estava fora" (PAS-043): ao abrir, o painel registra a visita no
   servidor (`data-visita-url`, POST com CSRF) e SÓ DEPOIS carrega a lista — o
   servidor conta o que é novo a partir da visita anterior. Nada fica no navegador.
   ============================================================================= */
(function () {
    'use strict';

    var painel  = document.getElementById('psTimeline');
    var overlay = document.getElementById('psTimelineOverlay');
    var abrir   = document.getElementById('psTimelineAbrir');
    if (!painel || !overlay) { return; }

    var url      = painel.getAttribute('data-url');
    var urlVisita   = painel.getAttribute('data-visita-url');
    var tokenVisita = painel.getAttribute('data-visita-token');
    var busca    = painel.querySelector('[data-tl-busca]');
    var chips    = painel.querySelector('[data-tl-chips]');
    var periodo  = painel.querySelector('[data-tl-periodo]');
    var pessoa   = painel.querySelector('[data-tl-pessoa]');
    var resumir  = painel.querySelector('[data-tl-resumir]');
    var lista    = painel.querySelector('[data-tl-lista]');
    var mais     = painel.querySelector('[data-tl-mais]');
    var fechar   = painel.querySelector('.ps-tl-fechar');

    var LIMITE_INICIAL = 200;
    var LIMITE_MAXIMO  = 600;
    var TRES_HORAS     = 3 * 3600 * 1000;
    // `notificacao` agrupa como no desenho (`['mensagem', 'documento', 'observacao', 'notificacao']`).
    var AGRUPAVEIS     = { registro: 1, documento: 1, cadastro: 1, notificacao: 1 };

    var estado = {
        categoria: 'tudo', periodo: 'tudo', pessoa: '', q: '', limite: LIMITE_INICIAL,
        resumo: false, resumoDesde: false, abertos: {}, dados: null
    };
    var focoAnterior = null;
    var controle     = null;
    var espera       = null;

    /* ── utilidades ─────────────────────────────────────────────────────── */
    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }
        return n;
    }
    function icone(nome) {
        var i = el('i', 'bi ' + (/^bi-[a-z0-9-]+$/.test(nome) ? nome : 'bi-dot'));
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function cor(no, valor) {
        if (typeof valor === 'string' && /^#[0-9a-f]{6}$/i.test(valor)) { no.style.setProperty('--tl-cor', valor); }
    }
    function dataLocal(iso) { return new Date(iso); }
    function rotuloDoDia(diaIso) {
        var p = diaIso.split('-');
        var d = new Date(+p[0], +p[1] - 1, +p[2]);
        var h = new Date(); h.setHours(0, 0, 0, 0);
        var k = Math.round((h - d) / 864e5);
        if (k === 0) { return 'Hoje'; }
        if (k === 1) { return 'Ontem'; }
        if (k === -1) { return 'Amanhã'; }
        if (k < 0) { return d.toLocaleDateString('pt-BR') + ' (futuro)'; }
        return d.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    /* ── abrir / fechar ─────────────────────────────────────────────────── */
    function abrirPainel() {
        focoAnterior = document.activeElement;
        painel.classList.add('is-aberto');
        overlay.classList.add('is-aberto');
        painel.removeAttribute('aria-hidden');
        if (abrir) { abrir.setAttribute('aria-expanded', 'true'); }
        if (busca) { busca.focus(); }
        registrarVisita().then(carregar);
    }

    // A visita vai ANTES da carga: é ela que desloca a "anterior" no servidor. Falha (lápide,
    // rede) não impede a timeline de abrir — só fica sem o aviso novo.
    function registrarVisita() {
        if (!urlVisita || !window.fetch) { return Promise.resolve(); }
        var corpo = new URLSearchParams();
        corpo.set('_token', tokenVisita || '');

        return fetch(urlVisita, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: corpo
        }).then(function () {}, function () {});
    }
    function fecharPainel() {
        if (!painel.classList.contains('is-aberto')) { return; }
        painel.classList.remove('is-aberto');
        overlay.classList.remove('is-aberto');
        painel.setAttribute('aria-hidden', 'true');
        if (abrir) { abrir.setAttribute('aria-expanded', 'false'); }
        if (focoAnterior && typeof focoAnterior.focus === 'function') { focoAnterior.focus(); }
    }

    if (abrir) { abrir.addEventListener('click', abrirPainel); }
    if (fechar) { fechar.addEventListener('click', fecharPainel); }
    overlay.addEventListener('click', fecharPainel);
    painel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fecharPainel(); } });

    // Atalho T com o menu ⋮ aberto (o mesmo contrato do E e do H).
    var menu = document.getElementById('psMenuAcoes');
    if (menu && abrir) {
        document.addEventListener('keydown', function (e) {
            if (!menu.classList.contains('is-aberto') || e.ctrlKey || e.metaKey || e.altKey) { return; }
            var alvo = e.target;
            if (alvo && (/INPUT|TEXTAREA|SELECT/.test(alvo.tagName) || alvo.isContentEditable)) { return; }
            if ((e.key || '').toLowerCase() !== 't') { return; }
            e.preventDefault();
            abrir.click();
        });
    }

    /* ── filtros ────────────────────────────────────────────────────────── */
    if (busca) {
        busca.addEventListener('input', function () {
            estado.q = busca.value;
            window.clearTimeout(espera);
            espera = window.setTimeout(carregar, 250);
        });
    }
    if (periodo) { periodo.addEventListener('change', function () { estado.periodo = periodo.value; carregar(); }); }
    if (pessoa) { pessoa.addEventListener('change', function () { estado.pessoa = pessoa.value; carregar(); }); }
    if (resumir) {
        resumir.addEventListener('click', function () {
            estado.resumo = !estado.resumo;
            estado.resumoDesde = false;
            resumir.setAttribute('aria-pressed', estado.resumo ? 'true' : 'false');
            desenhar();
        });
    }
    if (mais) {
        mais.addEventListener('click', function () {
            estado.limite = Math.min(LIMITE_MAXIMO, estado.limite + LIMITE_INICIAL);
            carregar();
        });
    }

    /* ── carga ──────────────────────────────────────────────────────────── */
    function carregar() {
        if (controle) { controle.abort(); }
        controle = window.AbortController ? new AbortController() : null;

        var p = new URLSearchParams();
        p.set('categoria', estado.categoria);
        p.set('periodo', estado.periodo);
        if (estado.pessoa) { p.set('pessoa', estado.pessoa); }
        if (estado.q.trim()) { p.set('q', estado.q.trim()); }
        p.set('limite', String(estado.limite));

        lista.setAttribute('aria-busy', 'true');
        fetch(url + '?' + p.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controle ? controle.signal : undefined
        })
            .then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.json();
            })
            .then(function (dados) {
                estado.dados = dados;
                lista.setAttribute('aria-busy', 'false');
                desenhar();
            })
            .catch(function (erro) {
                if (erro && erro.name === 'AbortError') { return; }
                lista.setAttribute('aria-busy', 'false');
                lista.textContent = '';
                lista.appendChild(el('div', 'ps-tl-vazio', 'Não foi possível carregar a timeline. Tente de novo.'));
            });
    }

    /* ── desenho ────────────────────────────────────────────────────────── */
    function desenhar() {
        var d = estado.dados;
        if (!d) { return; }
        var topo = lista.scrollTop;

        desenharChips(d);
        desenharPessoas(d);

        lista.textContent = '';
        var semFiltro = estado.categoria === 'tudo' && !estado.q.trim();

        if (d.novos > 0 && semFiltro) { lista.appendChild(caixaForaDesde(d)); }
        if (estado.resumo) { lista.appendChild(caixaResumo(d)); }
        if (d.atencao && d.atencao.length && semFiltro) { lista.appendChild(caixaAtencao(d)); }

        if (!d.eventos.length) {
            var vazio = d.contagens && d.contagens.tudo === 0
                ? 'Nenhum acontecimento registrado nesta pasta.'
                : (estado.q.trim() ? 'Nada encontrado para "' + estado.q.trim() + '".' : 'Nenhum evento com esses filtros.');
            lista.appendChild(el('div', 'ps-tl-vazio', vazio));
        } else {
            desenharEventos(d);
        }

        if (d.truncado) {
            lista.appendChild(el('div', 'ps-tl-aviso', 'Mostrando os ' + d.eventos.length + ' acontecimentos mais recentes'
                + (d.limite >= LIMITE_MAXIMO ? ' (o máximo desta tela).' : '.')));
        }
        if (mais) { mais.hidden = !(d.truncado && d.limite < LIMITE_MAXIMO); }

        lista.scrollTop = topo;
    }

    function desenharChips(d) {
        chips.textContent = '';
        var c = d.contagens || {};
        var itens = [['tudo', 'Tudo', null], ['pendente', '⚠ Pendências', c.pendente || 0]];
        Object.keys(d.categorias || {}).forEach(function (k) {
            if (c[k]) { itens.push([k, d.categorias[k].rotulo, c[k]]); }
        });
        if (c.marco) { itens.push(['marco', 'Marcos', c.marco]); }

        itens.forEach(function (it) {
            var b = el('button', 'ps-tl-chip' + (estado.categoria === it[0] ? ' is-ativo' : ''), it[1] + (it[2] ? ' ' + it[2] : ''));
            b.type = 'button';
            b.setAttribute('aria-pressed', estado.categoria === it[0] ? 'true' : 'false');
            b.setAttribute('data-tl-chip', it[0]);
            b.addEventListener('click', function () { estado.categoria = it[0]; carregar(); });
            chips.appendChild(b);
        });
    }

    function desenharPessoas(d) {
        if (!pessoa) { return; }
        var atual = estado.pessoa;
        while (pessoa.options.length > 1) { pessoa.remove(1); }
        (d.pessoas || []).forEach(function (nome) {
            var o = el('option', '', nome);
            o.value = nome;
            pessoa.appendChild(o);
        });
        pessoa.value = atual;
    }

    // Desenho `tlRender`: "Enquanto você estava fora: N acontecimento(s)", a contagem por tipo
    // ("2 registros · 1 documentos") e "Ver resumo", que abre o resumo a partir da visita.
    function caixaForaDesde(d) {
        var caixa = el('div', 'ps-tl-fora');
        caixa.appendChild(el('div', 'ps-tl-fora-tit', 'Enquanto você estava fora: ' + d.novos + ' acontecimento(s)'));
        var partes = [];
        Object.keys(d.novosPorCategoria || {}).forEach(function (k) {
            var cat = d.categorias[k];
            partes.push(d.novosPorCategoria[k] + ' ' + (cat ? cat.rotulo : k).toLowerCase());
        });
        caixa.appendChild(el('div', '', partes.join(' · ')));
        var bt = el('button', 'ps-tl-fora-bt', 'Ver resumo');
        bt.type = 'button';
        bt.addEventListener('click', function () {
            estado.resumo = true;
            estado.resumoDesde = true;
            if (resumir) { resumir.setAttribute('aria-pressed', 'true'); }
            desenhar();
        });
        caixa.appendChild(bt);
        return caixa;
    }

    function caixaResumo(d) {
        var desde = estado.resumoDesde && d.foraDesde && d.resumoDesde !== null;
        var caixa = el('div', 'ps-tl-resumo');
        caixa.appendChild(el('div', 'ps-tl-resumo-tit', desde
            ? 'O que aconteceu desde ' + new Date(d.foraDesde).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
            : 'Resumo da atividade'));
        caixa.appendChild(el('div', '', desde ? d.resumoDesde : d.resumo));
        caixa.appendChild(el('div', 'ps-tl-resumo-nota', 'Contagem por regras fixas, sobre os acontecimentos filtrados.'));
        return caixa;
    }

    function caixaAtencao(d) {
        var caixa = el('div', 'ps-tl-atencao');
        caixa.appendChild(el('div', 'ps-tl-atencao-tit', '⚠ Precisa de atenção'));
        d.atencao.forEach(function (e) {
            var linha = el('div', 'ps-tl-atencao-item');
            var pip = el('span', 'ps-tl-pip');
            cor(pip, (d.prioridades[e.prioridade] || d.prioridades.info).cor);
            linha.appendChild(pip);
            linha.appendChild(el('span', '', e.titulo + (e.texto ? ': ' + String(e.texto).slice(0, 80) : '')));
            caixa.appendChild(linha);
        });
        return caixa;
    }

    function desenharEventos(d) {
        var ev = d.eventos;
        var diaAtual = null;
        var buscaAtiva = !!estado.q.trim();

        for (var i = 0; i < ev.length; i++) {
            var e = ev[i];
            if (e.dia !== diaAtual) {
                diaAtual = e.dia;
                lista.appendChild(el('div', 'ps-tl-dia', rotuloDoDia(e.dia)));
            }

            // Grupo do desenho: 3+ da mesma categoria no mesmo dia, até 3h do primeiro.
            var chaveGrupo = e.dia + ':' + e.categoria + ':' + e.id;
            var fim = i + 1;
            if (!buscaAtiva && AGRUPAVEIS[e.categoria]) {
                var t0 = dataLocal(e.quando).getTime();
                while (fim < ev.length && ev[fim].dia === e.dia && ev[fim].categoria === e.categoria
                    && Math.abs(dataLocal(ev[fim].quando).getTime() - t0) < TRES_HORAS) { fim++; }
            }
            if (fim - i >= 3 && !estado.abertos[chaveGrupo]) {
                lista.appendChild(botaoGrupo(d, e, fim - i, chaveGrupo));
                i = fim - 1;
                continue;
            }
            lista.appendChild(cartao(d, e));
        }
    }

    function botaoGrupo(d, e, n, chave) {
        var cat = d.categorias[e.categoria] || { rotulo: e.categoria, icone: 'bi-dot', cor: '#455c6b' };
        var b = el('button', 'ps-tl-grupo');
        b.type = 'button';
        cor(b, cat.cor);
        var bola = el('span', 'ps-tl-bola');
        bola.appendChild(icone(cat.icone));
        b.appendChild(bola);
        var corpo = el('span', 'ps-tl-grupo-corpo');
        corpo.appendChild(el('span', 'ps-tl-grupo-tit', (e.hora ? e.hora + ' · ' : '') + cat.rotulo));
        corpo.appendChild(el('span', 'ps-tl-grupo-n', n + ' ' + cat.rotulo.toLowerCase() + ' · toque para ver'));
        b.appendChild(corpo);
        b.appendChild(icone('bi-chevron-down'));
        b.addEventListener('click', function () { estado.abertos[chave] = 1; desenhar(); });
        return b;
    }

    function cartao(d, e) {
        var cat = d.categorias[e.categoria] || { rotulo: e.categoria, icone: 'bi-dot', cor: '#455c6b' };
        var prio = e.prioridade ? d.prioridades[e.prioridade] : null;

        var c = el('div', 'ps-tl-cartao' + (e.marco ? ' ps-tl-cartao--marco' : ''));
        c.setAttribute('data-tl-evento', e.id);
        c.setAttribute('data-tl-categoria', e.categoria);
        cor(c, cat.cor);
        if (prio) { c.style.setProperty('--tl-borda', /^#[0-9a-f]{6}$/i.test(prio.cor) ? prio.cor : ''); }

        var bola = el('span', 'ps-tl-bola');
        bola.appendChild(icone(cat.icone));
        c.appendChild(bola);

        var corpo = el('div', 'ps-tl-corpo');
        var topo = el('div', 'ps-tl-topo');
        if (e.hora) { topo.appendChild(el('span', 'ps-tl-hora', e.hora)); }
        topo.appendChild(el('span', 'ps-tl-titulo', e.titulo));
        if (prio && e.prioridade !== 'info') {
            var selo = el('span', 'ps-tl-prio', prio.rotulo);
            cor(selo, prio.cor);
            topo.appendChild(selo);
        }
        if (e.marco) { topo.appendChild(el('span', 'ps-tl-marco', 'Marco · ' + e.marco)); }
        if (e.editado) { topo.appendChild(el('span', 'ps-tl-editado', 'editado')); }
        corpo.appendChild(topo);

        var texto = e.texto ? String(e.texto) : '';
        var longo = texto.length > 160;
        // Como no desenho (`TL.abertos`): 1 = texto inteiro; 'h' = Antes e Depois.
        var aberto = estado.abertos[e.id] === 1;
        var alteracao = estado.abertos[e.id] === 'h' && !!e.antes;
        if (texto) { corpo.appendChild(el('div', 'ps-tl-texto', longo && !aberto ? texto.slice(0, 160) + '…' : texto)); }
        if (alteracao) { corpo.appendChild(caixaAlteracao(e, texto)); }

        var rodape = [e.fonte, e.autor].filter(function (x) { return !!x; }).join(' · ');
        if (rodape) { corpo.appendChild(el('div', 'ps-tl-fonte', rodape)); }

        var acoes = el('div', 'ps-tl-acoes');
        if (e.link) {
            var a = el('a', 'ps-tl-acao', e.rotuloLink || 'Abrir');
            a.href = e.link;
            a.addEventListener('click', function (ev) { irPara(ev, e.link); });
            acoes.appendChild(a);
        }
        if (e.antes) {
            var btAlt = el('button', 'ps-tl-acao', alteracao ? 'Ocultar alteração' : 'Ver alteração');
            btAlt.type = 'button';
            btAlt.addEventListener('click', function () { estado.abertos[e.id] = alteracao ? 0 : 'h'; desenhar(); });
            acoes.appendChild(btAlt);
        }
        if (longo) {
            var bt = el('button', 'ps-tl-acao', aberto ? 'Mostrar menos' : 'Ver tudo');
            bt.type = 'button';
            bt.addEventListener('click', function () { estado.abertos[e.id] = aberto ? 0 : 1; desenhar(); });
            acoes.appendChild(bt);
        }
        if (acoes.childNodes.length) { corpo.appendChild(acoes); }

        c.appendChild(corpo);
        return c;
    }

    // "Ver alteração" (desenho `tlCard`): Antes, Depois (o texto inteiro) e "Alterado em".
    function caixaAlteracao(e, depois) {
        var caixa = el('div', 'ps-tl-alteracao');
        [['Antes:', String(e.antes)], ['Depois:', depois]].forEach(function (par) {
            var linha = el('div', '');
            linha.appendChild(el('b', '', par[0]));
            linha.appendChild(document.createTextNode(' ' + par[1]));
            caixa.appendChild(linha);
        });
        if (e.alteradoEm) {
            caixa.appendChild(el('div', 'ps-tl-alteracao-em', 'Alterado em ' + new Date(e.alteradoEm).toLocaleString('pt-BR')));
        }
        return caixa;
    }

    // Link para uma aba DESTA pasta (`#push`): troca a aba aqui, sem recarregar — o
    // pasta-show.js só lê o fragmento na carga da página.
    function irPara(ev, link) {
        var alvo;
        try { alvo = new URL(link, window.location.href); } catch (x) { return; }
        if (alvo.pathname !== window.location.pathname || !alvo.hash) { return; }
        var gatilho = document.getElementById(alvo.hash.slice(1) + '-tab');
        if (!gatilho || !window.bootstrap) { return; }
        ev.preventDefault();
        fecharPainel();
        window.bootstrap.Tab.getOrCreateInstance(gatilho).show();
    }
})();
