/* =============================================================================
   assinatura.js: tela Assinatura de documentos (lista, modelos prontos, assistente com rascunho e detalhe).
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (classe Component e renderVals).
   Contratos: docs/specs/assinatura-documentos-etapa-1.md (seções 2, 3.3, 4.1 e 4.2) e
   docs/specs/assinatura-documentos-etapa-2.md (seções 5.1 e 7: envio, links, lembrete, prévia e Cancelar envio) e
   docs/specs/assinatura-documentos-etapa-4.md (modelos do escritório: aba Modelos, editor e passo 1).

   O Twig monta só o esqueleto da lista e os atributos data-* da raiz; o resto é desenhado aqui a partir de
   GET /assinatura/documentos e GET /assinatura/documentos/{id}. Os blocos do documento são montados no navegador,
   como no desenho (modelos, [[campo]], cláusulas, condições), e o servidor valida e guarda.

   O botão só aparece quando a URL dele existe na raiz (data-url-*). Etapa 2: "Gerar links e enviar", Copiar link,
   E-mail (mailto), WhatsApp, Tela do signatário (prévia em nova aba), Enviar lembrete e Cancelar envio. Etapa 4:
   Criar novo modelo, Editar, Excluir e Duplicar e adaptar; o editor vira blocos aqui (lerEd do desenho) e nenhum HTML
   vai ao servidor. Ficam para depois: código BJA, Baixar PDF com selo, Vincular à pasta e aba Validar.
   ============================================================================= */
(function () {
    'use strict';

    const raiz = document.getElementById('bjaPagina');
    if (!raiz || !window.BJAFolha) { return; }

    const F = window.BJAFolha;
    const T = (chave, p) => BJ_T.t(['assinatura_eletronica_tela', 'js', chave].join('.'), p);
    const lerJson = (texto, padrao) => { try { const v = JSON.parse(texto || ''); return v == null ? padrao : v; } catch (e) { return padrao; } };
    const eu = lerJson(raiz.dataset.eu, {});
    const padraoServ = lerJson(raiz.dataset.padrao, {});
    const logos = lerJson(raiz.dataset.logos, {});
    const ds = raiz.dataset;
    const url = {
        documentos: ds.urlDocumentos || '',
        documento: ds.urlDocumento || '',
        criar: ds.urlCriar || '',
        salvar: ds.urlSalvar || '',
        duplicar: ds.urlDuplicar || '',
        lixeira: ds.urlLixeira || '',
        restaurar: ds.urlRestaurar || '',
        excluir: ds.urlExcluir || '',
        cor: ds.urlCor || '',
        logo: ds.urlLogo || '',
        enviar: ds.urlEnviar || '',
        lembrete: ds.urlLembrete || '',
        cancelar: ds.urlCancelar || '',
        previa: ds.urlPrevia || '',
        modelos: ds.urlModelos || '',
        modeloCriar: ds.urlModeloCriar || '',
        modeloSalvar: ds.urlModeloSalvar || '',
        modeloExcluir: ds.urlModeloExcluir || '',
    };
    /* Troca o marcador 0 (último trecho "0" do caminho) pelo id real, como a Agenda 1A. */
    const comId = (modelo, id) => {
        const p = String(modelo).split('/');
        for (let i = p.length - 1; i >= 0; i--) { if (p[i] === '0') { p[i] = encodeURIComponent(String(id)); break; } }
        return p.join('/');
    };
    /* Prévia do signatário: /assinatura/documentos/0/previa/0, o último 0 é a ordem e o anterior é o id. */
    const comIdOrdem = (modelo, id, ordem) => {
        const p = String(modelo).split('/');
        let falta = 2;
        for (let i = p.length - 1; i >= 0 && falta; i--) { if (p[i] === '0') { p[i] = encodeURIComponent(String(falta === 2 ? ordem : id)); falta--; } }
        return p.join('/');
    };

    /* ── Utilidades ─────────────────────────────────────────────────────── */
    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' }[c]));
    const idioma = () => (window.BJFormato ? window.BJFormato.idioma() : 'pt-BR');
    const data = v => { if (!v) { return ''; } const d = new Date(v); return isNaN(d.getTime()) ? '' : d.toLocaleDateString(idioma(), { day: '2-digit', month: '2-digit', year: 'numeric' }); };
    const dataHora = v => { if (!v) { return ''; } const d = new Date(v); return isNaN(d.getTime()) ? '' : d.toLocaleString(idioma()); };
    const $ = id => document.getElementById(id);

    /* ── Tabelas do desenho (static ST, CORES, NIV) ─────────────────────── */
    const FILTROS = ['todos', 'aguardando', 'concluido', 'rascunho', 'recusado', 'expirado', 'lixeira'];
    const ST_ICONE = { rascunho: 'bi-pencil', aguardando: 'bi-hourglass-split', concluido: 'bi-check-circle', recusado: 'bi-x-circle', expirado: 'bi-clock', cancelado: 'bi-slash-circle' };
    const CORES = [['', ''], ['azul', '#dcecf9'], ['verde', '#dff3e5'], ['amarelo', '#fff1c4'], ['laranja', '#fde3cc'], ['vermelho', '#fbdfe2'], ['roxo', '#ebe1fa'], ['cinza', '#e3e8ec']];
    const NIVEIS = ['simples', 'reforcada', 'govbr', 'icp'];
    const PASSOS = [['documento', 'bi-file-earmark'], ['dados', 'bi-input-cursor-text'], ['signatarios', 'bi-people'], ['enviar', 'bi-send']];
    const corHex = k => (CORES.find(c => c[0] === k && k) || [])[1] || '';
    const nomeCor = k => T('cor.' + (k || 'sem'));
    const nivelRotulo = n => (NIVEIS.indexOf(n) >= 0 ? T('nivel.' + n) : '');

    const el = {
        telaLista: $('bjaTelaLista'),
        telaNovo: $('bjaTelaNovo'),
        telaDet: $('bjaTelaDet'),
        telaMod: $('bjaTelaMod'),
        abaDocs: $('bjaAbaDocs'),
        abaMods: $('bjaAbaMods'),
        chips: $('bjaChips'),
        busca: $('bjaBusca'),
        pasta: $('bjaPasta'),
        coresF: $('bjaCoresF'),
        selBar: $('bjaSelBar'),
        todos: $('bjaTodos'),
        linhas: $('bjaLinhas'),
        vazio: $('bjaVazio'),
        ctx: $('bjaCamCtx'),
        modal: $('bjaCamModal'),
        toast: $('bjaCamToast'),
    };

    /* ── Estado ─────────────────────────────────────────────────────────── */
    const s = {
        tela: 'lista',
        aba: 'docs',
        docs: [],
        pastas: [],
        carregado: false,
        filtro: 'todos',
        busca: '',
        pasta: '',
        corF: '',
        sel: {},
        ctx: null,
        exc: null,
        w: null,
        wErro: '',
        arqSt: '',
        mBusca: '',
        det: null,
        canc: false,
        meus: [],
        m: null,
        mdErro: '',
    };
    let seqArquivo = 0;
    /* Editor de modelo: a área editável desenhada agora e o último cursor dentro dela (_rng do desenho). */
    let edAtual = null, edRng = null;

    /* ── Servidor ───────────────────────────────────────────────────────── */
    async function pedir(endereco, opcoes) {
        const o = opcoes || {};
        const r = await fetch(endereco, {
            method: o.method || 'GET',
            body: o.body,
            credentials: 'same-origin',
            headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, o.headers || {}),
        });
        let j = null;
        try { j = await r.json(); } catch (e) { j = null; }
        if (!r.ok) { const erro = new Error('HTTP ' + r.status); erro.texto = (j && j.erro) || ''; erro.status = r.status; throw erro; }
        return j || {};
    }
    const postJson = (endereco, corpo) => pedir(endereco, { method: 'POST', body: JSON.stringify(corpo), headers: { 'Content-Type': 'application/json' } });
    const falha = e => { if (e && !e.texto) { console.error(e); } avisar((e && e.texto) || T('erro_geral'), 'erro'); };

    const doc = id => s.docs.find(d => String(d.id) === String(id));
    const idsReais = lista => lista.map(k => (doc(k) || {}).id).filter(v => v != null);
    const stAtual = d => (d.situacao === 'aguardando' && d.venceEm && new Date(d.venceEm) < new Date() ? 'expirado' : d.situacao);
    /* p1 do desenho: o primeiro pendente da lista, alvo do Copiar link e do Enviar lembrete (só com o documento Aguardando). */
    const primeiroPendente = d => (d.signatarios || []).find(x => x.situacao === 'pendente') || null;
    const temLink = d => !!d && !d.naLixeira && stAtual(d) === 'aguardando' && !!primeiroPendente(d);

    async function carregar() {
        if (!url.documentos) { return; }
        try {
            const r = await pedir(url.documentos);
            s.docs = (r.documentos || []).map(d => Object.assign(d, { stA: stAtual(d) }));
            s.pastas = (r.pastas || []).filter(Boolean);
            s.carregado = true;
            Object.keys(s.sel).forEach(id => { if (!doc(id)) { delete s.sel[id]; } });
            if (s.pasta && s.pastas.indexOf(s.pasta) < 0) { s.pasta = ''; }
        } catch (e) { falha(e); }
        if (s.tela === 'lista') { desenharLista(); }
    }

    /* ── Avisos (toast do desenho: verde, vermelho, amarelo, azul; some em 3,4 s) ── */
    let tToast = null;
    function avisar(texto, tipo) {
        el.toast.innerHTML = '<div class="bjaToast' + (tipo ? ' is-' + tipo : '') + '" role="status">' + esc(texto) + '</div>';
        clearTimeout(tToast);
        tToast = setTimeout(() => { el.toast.innerHTML = ''; }, 3400);
    }

    /* ── Balão das dicas (data-tip): o do bj-dica.js do desenho, no lugar da dica do navegador. Aparece 280 ms depois de
       o mouse chegar, abaixo do alvo e centralizado, sem sair da tela (margem de 8 px) nem cobrir o alvo; some ao sair,
       clicar, teclar, rolar e no botão direito. ── */
    let balao = null, balaoAlvo = null, balaoTempo = null;
    function esconderBalao() {
        clearTimeout(balaoTempo); balaoTempo = null; balaoAlvo = null;
        if (balao) { balao.classList.remove('is-vis'); }
    }
    function posicionarBalao() {
        if (!balaoAlvo || !balao) { return; }
        const r = balaoAlvo.getBoundingClientRect(), w = balao.offsetWidth, h = balao.offsetHeight, m = 8;
        let x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
        if (y + h > innerHeight - m) { y = r.top - h - 8; }
        if (y < m) { y = Math.min(innerHeight - h - m, r.bottom + 8); }
        x = Math.max(m, Math.min(innerWidth - w - m, x));
        balao.style.left = x + 'px'; balao.style.top = y + 'px';
    }
    function mostrarBalao(alvo) {
        const t = alvo.getAttribute('data-tip') || '';
        if (!t || !alvo.isConnected) { return; }
        if (!balao) { balao = document.createElement('div'); balao.className = 'bjaBalao'; balao.setAttribute('role', 'tooltip'); raiz.appendChild(balao); }
        balaoAlvo = alvo; balao.textContent = t; balao.classList.remove('is-vis');
        requestAnimationFrame(() => { posicionarBalao(); if (balaoAlvo === alvo) { balao.classList.add('is-vis'); } });
    }
    raiz.addEventListener('mouseover', ev => {
        const a = ev.target.closest && ev.target.closest('[data-tip]');
        if (!a || a === balaoAlvo) { return; }
        clearTimeout(balaoTempo);
        balaoTempo = setTimeout(() => mostrarBalao(a), 280);
    });
    raiz.addEventListener('mouseout', ev => {
        if (!balaoAlvo && !balaoTempo) { return; }
        const para = ev.relatedTarget, de = ev.target.closest && ev.target.closest('[data-tip]');
        if (de && para && de.contains(para)) { return; }
        esconderBalao();
    });
    ['mousedown', 'keydown', 'wheel', 'scroll', 'contextmenu'].forEach(nome => window.addEventListener(nome, esconderBalao, { capture: true, passive: true }));

    /* Redesenha mantendo o foco no mesmo controle (data-bja-foco). */
    function comFoco(fazer) {
        const a = document.activeElement;
        const chave = a && raiz.contains(a) ? a.getAttribute('data-bja-foco') : null;
        fazer();
        if (chave) {
            const n = Array.from(raiz.querySelectorAll('[data-bja-foco]')).find(x => x.getAttribute('data-bja-foco') === chave);
            if (n) { n.focus(); }
        }
    }

    /* ── Telas ──────────────────────────────────────────────────────────── */
    function mostrarTela() {
        el.telaLista.hidden = s.tela !== 'lista';
        el.telaNovo.hidden = s.tela !== 'novo';
        el.telaDet.hidden = s.tela !== 'det';
        el.telaMod.hidden = s.tela !== 'modelo';
        if (s.tela !== 'novo') { el.telaNovo.innerHTML = ''; }
        if (s.tela !== 'det') { el.telaDet.innerHTML = ''; }
        if (s.tela !== 'modelo') { el.telaMod.innerHTML = ''; s.m = null; edAtual = null; edRng = null; }
    }

    function irParaLista() {
        s.tela = 'lista'; s.w = null; s.det = null; s.aba = 'docs';
        mostrarTela();
        desenharLista();
    }

    /* Saída do editor (Modelos, salvar, modelo apagado por outra pessoa): sempre a aba Modelos, como sairMod do desenho. */
    function irParaModelos() {
        s.tela = 'lista'; s.w = null; s.det = null; s.aba = 'mods';
        mostrarTela();
        desenharLista();
    }

    /* ── Lista: abas, filtros, cores, seleção e tabela ──────────────────── */
    function visiveis() {
        const qb = s.busca.trim().toLowerCase();
        return s.docs.filter(d => (s.filtro === 'lixeira' ? !!d.naLixeira : !d.naLixeira)
            && (s.filtro === 'todos' || s.filtro === 'lixeira' || d.stA === s.filtro)
            && (!s.corF || d.cor === s.corF)
            && (!s.pasta || d.pasta === s.pasta)
            && (!qb || (d.nome + ' ' + (d.signatarios || []).map(x => x.nome).join(' ')).toLowerCase().indexOf(qb) >= 0));
    }
    const selDe = vis => vis.filter(d => s.sel[String(d.id)]).map(d => String(d.id));

    function desenharLista() {
        raiz.querySelectorAll('[data-bja-aba]').forEach(b => b.setAttribute('aria-selected', String(b.getAttribute('data-bja-aba') === s.aba)));
        el.abaDocs.hidden = s.aba !== 'docs';
        el.abaMods.hidden = s.aba !== 'mods';
        if (s.aba === 'docs') { desenharDocs(); return; }
        el.abaMods.innerHTML = '<div class="bjaModsTopo"><span class="bjaModsTx">' + esc(T('modelos_intro')) + '</span>'
            + (url.modeloCriar ? '<button type="button" class="bjaP" data-bja-acao="mod-criar"><i class="bi bi-plus-lg"></i>' + esc(T('mod.criar')) + '</button>' : '')
            + '</div><div class="bjaModsCorpo">' + gruposHtml('', true) + '</div>';
    }

    function coresHtml(alvo) {
        return CORES.map(([k, hex]) => '<button type="button" class="bjaCor" data-bja-cor="' + k + '" data-bja-cor-alvo="' + alvo + '" aria-label="' + esc(nomeCor(k)) + '" data-tip="' + esc(nomeCor(k)) + '" style="background:' + (hex || '#fff') + '">'
            + (k ? '' : '<i class="bi bi-slash-lg"></i>') + '</button>').join('');
    }

    function desenharDocs() {
        const atv = s.docs.filter(d => !d.naLixeira), lixo = s.docs.filter(d => d.naLixeira);
        el.chips.innerHTML = FILTROS.map(id => {
            const n = id === 'todos' ? atv.length : id === 'lixeira' ? lixo.length : atv.filter(d => d.stA === id).length;
            return '<button type="button" class="bjaChip' + (s.filtro === id ? ' is-on' : '') + '" data-bja-filtro="' + id + '" aria-pressed="' + (s.filtro === id) + '">' + esc(T('chip', { rotulo: T('filtro.' + id), n: n })) + '</button>';
        }).join('');

        el.coresF.innerHTML = '<button type="button" class="bjaCor' + (!s.corF ? ' is-on' : '') + '" data-bja-corf="" aria-label="' + esc(T('cor.todas')) + '" data-tip="' + esc(T('cor.todas')) + '" aria-pressed="' + !s.corF + '" style="background:#fff"><i class="bi bi-asterisk"></i></button>'
            + CORES.slice(1).map(([k, hex]) => '<button type="button" class="bjaCor' + (s.corF === k ? ' is-on' : '') + '" data-bja-corf="' + k + '" aria-label="' + esc(nomeCor(k)) + '" data-tip="' + esc(nomeCor(k)) + '" aria-pressed="' + (s.corF === k) + '" style="background:' + hex + '"></button>').join('');

        const atuais = Array.from(el.pasta.options).slice(1).map(o => o.value);
        if (atuais.join('\n') !== s.pastas.join('\n')) {
            el.pasta.length = 1;
            s.pastas.forEach(p => el.pasta.add(new Option(p, p)));
        }
        el.pasta.value = s.pasta;
        if (el.busca.value !== s.busca) { el.busca.value = s.busca; }

        const vis = visiveis(), ids = selDe(vis);
        el.selBar.hidden = !ids.length;
        el.selBar.innerHTML = !ids.length ? '' : '<b>' + esc(T('selecionados', { n: ids.length })) + '</b>'
            + (url.cor ? '<span class="bjaSelCores">' + esc(T('cor_rotulo')) + ' ' + coresHtml('lote') + '</span>' : '')
            + '<span class="bjaVao"></span>'
            + (s.filtro === 'lixeira'
                ? (url.restaurar ? '<button type="button" class="bjaB" data-bja-acao="restaurar-lote"><i class="bi bi-arrow-counterclockwise"></i>' + esc(T('restaurar')) + '</button>' : '')
                : (url.lixeira ? '<button type="button" class="bjaB bjaR" data-bja-acao="excluir-lote"><i class="bi bi-trash3"></i>' + esc(T('excluir')) + '</button>' : ''))
            + '<button type="button" class="bjaB bjaN" data-bja-acao="limpar-sel">' + esc(T('limpar_selecao')) + '</button>';

        el.todos.checked = vis.length > 0 && ids.length === vis.length;
        el.linhas.innerHTML = vis.map(linhaHtml).join('');
        el.vazio.hidden = !s.carregado || vis.length > 0;
    }

    function seloHtml(st) {
        return '<span class="bjaSelo st-' + esc(st) + '"><i class="bi ' + (ST_ICONE[st] || 'bi-circle') + '"></i>' + esc(T('situacao.' + st)) + '</span>';
    }

    function linhaHtml(d) {
        const id = String(d.id), n = d.assinados || 0, tot = d.total || 0, pct = tot ? Math.round(n / tot * 100) : 0, hex = corHex(d.cor);
        return '<tr class="bjaLin' + (hex ? ' tem-cor' : '') + '" data-bja-id="' + esc(id) + '"' + (hex ? ' style="background:' + hex + '"' : '') + '>'
            + '<td class="bjaTdSel"><input type="checkbox" data-bja-sel="' + esc(id) + '" data-bja-foco="sel-' + esc(id) + '" aria-label="' + esc(T('selecionar_doc', { nome: d.nome })) + '"' + (s.sel[id] ? ' checked' : '') + '></td>'
            + '<td class="bjaTdDoc"><button type="button" class="bjaNomeDoc" data-bja-acao="abrir">' + esc(d.nome) + '</button><div class="bjaSub">' + esc([d.categoria, d.pasta].filter(Boolean).join(' · ')) + '</div></td>'
            + '<td class="bjaTdSig"><div class="bjaQuem">' + esc(T('assinaram', { n: n, total: tot })) + '</div><div class="bjaBarra"><div style="width:' + pct + '%"></div></div></td>'
            + '<td class="bjaNw">' + esc(data(d.criadoEm)) + '</td>'
            + '<td class="bjaNw">' + (d.stA === 'aguardando' ? esc(data(d.venceEm)) : '') + '</td>'
            + '<td>' + seloHtml(d.stA) + '</td>'
            + '<td class="bjaTdAc">' + (temLink(d) ? acoesDaLinha() : '') + '<button type="button" class="bjaB" data-bja-acao="abrir">' + esc(T('abrir')) + '</button> '
            + '<button type="button" class="bjaB bjaI bjaN" data-bja-acao="ctx" aria-label="' + esc(T('mais_opcoes')) + '"><i class="bi bi-three-dots-vertical"></i></button></td></tr>';
    }

    function acoesDaLinha() {
        const bt = (acao, ic, rot) => '<button type="button" class="bjaB bjaI" data-bja-acao="' + acao + '" aria-label="' + esc(rot) + '" data-tip="' + esc(rot) + '"><i class="bi ' + ic + '"></i></button> ';
        return (url.documento ? bt('lin-copiar', 'bi-link-45deg', T('linha.copiar')) : '') + (url.lembrete ? bt('lin-lembrete', 'bi-bell', T('linha.lembrete')) : '');
    }

    /* ── Modelos (aba Modelos e passo 1): os do escritório primeiro (meus() do desenho), depois os prontos ── */
    const modelos = () => s.meus.concat(window.BJAssinaModelos || []);
    const modelo = id => modelos().find(m => m.id === id);

    /* Modelo do escritório no formato dos prontos (contrato da etapa 4, seção 5): o id é a chave esc-<id>, que o
       documento guarda; quem assina vem só com o papel. */
    const doServidor = x => ({
        id: x.chave, idServ: x.id, t: x.nome, cat: x.categoria, layout: x.layout || '', lateral: x.lateral || '', limpa: !!x.folhaBranca,
        campos: x.campos || [], sign: (x.signatarios || []).map(y => ({ papel: y.papel, nome: '', email: '' })), corpo: x.corpo || [], meu: 1,
    });

    async function carregarModelos() {
        if (!url.modelos) { return; }
        try { const r = await pedir(url.modelos); s.meus = (r.modelos || []).map(doServidor); } catch (e) { falha(e); return; }
        if (s.tela === 'lista' && s.aba === 'mods') { desenharLista(); }
        const g = s.tela === 'novo' ? el.telaNovo.querySelector('[data-bja-grupos]') : null;
        if (g) { g.innerHTML = gruposHtml(s.mBusca, false); }
    }

    function gruposHtml(q, comCaixa) {
        const mq = (q || '').trim().toLowerCase(), todos = modelos(), cats = [...new Set(todos.map(m => m.cat))];
        return cats.map(cat => ({ cat: cat, itens: todos.filter(m => m.cat === cat && (!mq || m.t.toLowerCase().indexOf(mq) >= 0)) }))
            .filter(g => g.itens.length)
            .map(g => '<div><b class="bjaGrupoTit">' + esc(g.cat) + '</b><div class="bjaMods">' + g.itens.map(m => {
                const bt = '<button type="button" class="bjaMod" data-bja-modelo="' + esc(m.id) + '"><i class="bi ' + esc(m.ic || 'bi-file-earmark-plus') + '"></i><span class="bjaModTx"><b>' + esc(m.t) + '</b><span class="bjaModD">' + esc(m.d || T('modelo_escritorio')) + '</span></span></button>';
                if (!comCaixa) { return bt; }
                const ac = acoesDoModelo(m);
                return '<div class="bjaModCx">' + bt + (ac ? '<span class="bjaModAc">' + ac + '</span>' : '') + '</div>';
            }).join('') + '</div></div>').join('');
    }

    /* Coluna de ações do cartão (aba Modelos): Editar e Excluir no do escritório, Duplicar e adaptar no pronto. */
    function acoesDoModelo(m) {
        const bt = (acao, ic, rotulo, dica, perigo) => '<button type="button" class="bjaB bjaI' + (perigo ? ' bjaR' : '') + '" data-bja-acao="' + acao + '" data-bja-mod="' + esc(m.id) + '" aria-label="' + esc(rotulo) + '" data-tip="' + esc(dica) + '"><i class="bi ' + ic + '"></i></button>';
        if (!m.meu) { return url.modeloCriar ? bt('mod-duplicar', 'bi-copy', T('mod.duplicar'), T('mod.duplicar'), false) : ''; }
        return (url.modeloSalvar ? bt('mod-editar', 'bi-pencil', T('mod.editar'), T('mod.editar_dica'), false) : '')
            + (url.modeloExcluir ? bt('mod-excluir', 'bi-trash3', T('mod.excluir'), T('mod.excluir_dica'), true) : '');
    }

    /* ── Montagem dos blocos (res, cond, opAtiva, numera e blocos do desenho) ── */
    function res(texto, c, modo) {
        return String(texto).replace(/\[\[(\^?)([^\]]+?)\]\]/g, (_, u, k) => {
            const v = String(c[k] || '').trim();
            if (!v) { return modo === 'vazio' ? '' : modo ? '\u0001____________\u0002' : '____________'; }
            return u ? v.toUpperCase() : v;
        });
    }
    function cond(se, c) {
        return !se || se.split('&').every(t => { const [k, v] = t.split('='); return v != null ? String(c[k] || '') === v : !!String(c[k] || '').trim(); });
    }
    const opAtiva = (b, c) => !b.op || b.op.split('|').some(k => !!(c || {})[k]);
    function numera(lista, pega, poe) {
        const ord = T('numera.ordinais').split(','), rom = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
        const nome = n => (n <= 10 ? ord[n - 1] : n < 20 ? T('numera.decima', { ordinal: ord[n - 11] }) : T('numera.vigesima'));
        let cl = 0, se = 0, su = 0;
        lista.forEach(b => {
            const t = pega(b);
            if (t == null) { return; }
            const x = String(t).replace(/Cláusula @:/, () => T('numera.clausula', { ordinal: nome(++cl) })).replace(/^@ - /, () => rom[se++] + ' - ').replace(/^1\.@\)/, () => '1.' + (++su) + ')');
            if (x !== t) { poe(b, x); }
        });
        return lista;
    }
    function blocos(m, c, marca) {
        const R0 = m.corpo.filter(b => cond(b.se, c) && opAtiva(b, c)).map(b => {
            for (const k of ['n', 't', 's', 'p', 'h', 'l']) { if (b[k] != null) { return { k: k, x: res(b[k], c, marca), full: b.full ? 1 : 0 }; } }
            if (b.d != null) { return { k: 'd', x: res(b.d, c, marca), full: 0 }; }
            return { k: 'a', i: b.a, r: b.r ? res(b.r, c, marca) : '' };
        });
        numera(R0, b => b.x, (b, x) => { b.x = x; });
        return R0;
    }

    /* Valores iniciais dos campos (padrao do desenho): dados do escritório e do usuário vêm de data-padrao. */
    function padrao() {
        const n = new Date(), esc0 = (window.BJAssinaCampos || {}).Escritorio || [];
        const trat = ((esc0.find(c => c[0] === 'adv_trat') || [])[4] || [])[0] || '';
        const base = { adv_trat: trat, adv_nome: '', adv_seccional: '', adv_oab: '', adv_email: '', esc_tel: '', esc_end: '', esc_cep: '', esc_razao: '', esc_reg: '', esc_cnpj: '', cidade: '' };
        Object.keys(base).forEach(k => { const v = padraoServ && padraoServ[k]; if (v != null && String(v).trim() !== '') { base[k] = String(v); } });
        base.data_ext = T('data_extenso', { dia: n.getDate(), mes: n.toLocaleDateString(idioma(), { month: 'long' }), ano: n.getFullYear() });
        base.reserva = 'com';
        base.contrato_num = n.toLocaleDateString('sv-SE') + '.P000';
        return base;
    }

    /* ── Logo do documento ──────────────────────────────────────────────── */
    function logoInicial() {
        const p = logos.padrao || {};
        if (p.tipo === 'propria' && p.url) { return { tipo: 'propria', url: p.url, arquivo: null, origem: 'padrao' }; }
        if (p.tipo === 'escritorio' && logos.escritorio) { return { tipo: 'escritorio', url: logos.escritorio }; }
        return { tipo: 'bluejus', url: logos.bluejus || '' };
    }
    const logoFixInicial = () => { const t = (logos.padrao || {}).tipo; return (t === 'propria' && !!(logos.padrao || {}).url) || (t === 'escritorio' && !!logos.escritorio); };
    function logoDoDocumento(lg, id) {
        const tipo = (lg && lg.tipo) || 'bluejus';
        if (tipo === 'propria') { return { tipo: 'propria', url: (lg && lg.url) || (url.logo && id != null ? comId(url.logo, id) : ''), arquivo: null, origem: 'documento' }; }
        // "Meu Escritório" sem logo cadastrada não é oferecida: o servidor já grava a da BlueJus no lugar.
        if (tipo === 'escritorio' && logos.escritorio) { return { tipo: 'escritorio', url: (lg && lg.url) || logos.escritorio }; }
        return { tipo: 'bluejus', url: (tipo === 'bluejus' && lg && lg.url) || logos.bluejus || '' };
    }
    /* A logo própria vai junto do rascunho: a enviada agora, ou a padrão do escritório num documento novo. */
    async function arquivoDaLogo(lg) {
        if (!lg || lg.tipo !== 'propria') { return null; }
        if (lg.arquivo) { return lg.arquivo; }
        if (lg.origem !== 'padrao' || !lg.url) { return null; }
        const r = await fetch(lg.url, { credentials: 'same-origin' });
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        const b = await r.blob();
        return new File([b], b.type === 'image/jpeg' ? 'logo.jpg' : 'logo.png', { type: b.type || 'image/png' });
    }

    /* ── Assistente "Novo documento" ────────────────────────────────────── */
    const sigVazio = () => ({ nome: '', email: '', cpf: '', papel: T('p3.papel_padrao'), nivel: 'simples' });

    function novoW(o) {
        return Object.assign({
            passo: 1, mod: '', up: null, nome: '', pasta: '', c: padrao(), sign: [], seq: false,
            msg: T('p4.mensagem_padrao'), dias: 7, logo: logoInicial(), logoFix: logoFixInicial(), logoVista: false, limpa: false,
            layout: '', lateral: '', categoria: '', modelo: null, arquivo: null, arquivoId: 0, arqServ: null, rasc: null,
        }, o || {});
    }

    function abrirNovo() {
        s.w = novoW(); s.tela = 'novo'; s.wErro = ''; s.arqSt = ''; s.mBusca = '';
        mostrarTela();
        desenharNovo();
    }

    function usar(m) {
        if (!m) { return; }
        const rasc = s.tela === 'novo' && s.w ? s.w.rasc : null;
        s.w = novoW({ passo: 2, mod: m.id, nome: m.t, c: Object.assign(padrao(), m.def), limpa: !!m.limpa, layout: m.layout || '', lateral: m.lateral || '', categoria: m.cat, modelo: m.id, rasc: rasc });
        s.tela = 'novo'; s.wErro = '';
        mostrarTela();
        desenharNovo();
    }

    /* "Continuar e enviar" de um rascunho: volta ao passo 3 com os blocos guardados (retomar do desenho). */
    function retomar(d) {
        s.w = novoW({
            passo: 3, mod: '', up: d.blocos || [], nome: d.nome || '', pasta: d.pasta || '', c: {},
            sign: (d.signatarios || []).map(x => ({ nome: x.nome || '', email: x.email || '', cpf: x.cpf || '', papel: x.papel || '', nivel: NIVEIS.indexOf(x.nivel) >= 0 ? x.nivel : 'simples' })),
            seq: !!d.emOrdem, msg: d.mensagem || '', dias: d.prazoDias || 7, rasc: d.id, logo: logoDoDocumento(d.logo, d.id), limpa: !!d.folhaBranca,
            layout: d.layout || '', lateral: d.lateral || '', categoria: d.categoria || '', modelo: d.modelo || null, arqServ: d.arquivo || null,
        });
        s.tela = 'novo'; s.wErro = ''; s.ctx = null; s.det = null;
        desenharCamadas();
        mostrarTela();
        desenharNovo();
    }

    function wBlocos(marca) {
        const w = s.w;
        if (w.up) { return w.up; }
        const m = modelo(w.mod);
        return m ? blocos(m, w.c, marca) : [];
    }

    function camposVisiveis(m) {
        const w = s.w, ocultos = {};
        (m.opcoes || []).forEach(g => g.itens.forEach(o => { if (!w.c[o.k]) { (o.f || []).forEach(x => { ocultos[x] = 1; }); } }));
        return m.campos.filter(c0 => !ocultos[c0[0]]);
    }

    function fonteW() {
        const w = s.w;
        if (w.arquivo) { const f = w.arquivo; return { chave: 'f' + w.arquivoId, obter: () => f.arrayBuffer() }; }
        if (w.arqServ && w.arqServ.url) { return F.fonteDaUrl(w.arqServ.url); }
        return null;
    }

    function desenharNovo() {
        const w = s.w, pa = w.passo;
        el.telaNovo.innerHTML = '<div class="bjaTopoNovo"><button type="button" class="bjaB bjaN" data-bja-acao="voltar-lista"><i class="bi bi-arrow-left"></i>' + esc(T('voltar_documentos')) + '</button><h1 class="bjaH1Novo">' + esc(T('novo_titulo')) + '</h1></div>'
            + '<section class="bjaCard bjaOculto"><div class="bjaPass">' + PASSOS.map(([k, ic], i) => {
                const c = pa === i + 1 ? ' is-atual' : pa > i + 1 ? ' is-feito' : '';
                return '<div class="bjaPasso' + c + '"' + (pa === i + 1 ? ' aria-current="step"' : '') + '><i class="bi ' + (pa > i + 1 ? 'bi-check-circle-fill' : ic) + '"></i><span class="t">' + esc(T('passo.' + k)) + '</span></div>';
            }).join('') + '</div>'
            + '<div class="bjaPassoCorpo" data-bja-corpo>' + corpoPasso() + '</div>'
            + '<div class="bjaErro" data-bja-erro' + (s.wErro ? '' : ' hidden') + '>' + esc(s.wErro) + '</div>'
            + (pa > 1 ? navHtml() : '') + '</section>';
        pintarPrevias();
    }

    function redesenharCorpo() {
        const corpo = el.telaNovo.querySelector('[data-bja-corpo]');
        if (!corpo) { return; }
        comFoco(() => { corpo.innerHTML = corpoPasso(); });
        pintarPrevias();
    }

    function erroW(texto) {
        s.wErro = texto || '';
        const e = el.telaNovo.querySelector('[data-bja-erro]');
        if (e) { e.textContent = s.wErro; e.hidden = !s.wErro; }
    }

    function corpoPasso() {
        const pa = s.w.passo;
        return pa === 1 ? passo1() : pa === 2 ? passo2() : pa === 3 ? passo3() : passo4();
    }

    function passo1() {
        return '<div class="bjaP1"><label class="bjaUp"><i class="bi bi-cloud-arrow-up"></i><span class="bjaUpTx"><b>' + esc(T('p1.enviar_avulso')) + '</b><span>' + esc(T('p1.tipos')) + ' <span data-bja-arqst>' + esc(s.arqSt) + '</span></span></span>'
            + '<span class="bjaB">' + esc(T('p1.escolher')) + '</span><input type="file" accept=".pdf,.docx" data-bja-subir hidden></label>'
            + '<div class="bjaLinBusca"><input class="bjaF" type="text" data-bja-mbusca data-bja-foco="mbusca" aria-label="' + esc(T('buscar_modelo')) + '" placeholder="' + esc(T('buscar_modelo')) + '" value="' + esc(s.mBusca) + '" autocomplete="off">'
            + (url.modeloCriar ? '<button type="button" class="bjaB" data-bja-acao="mod-criar"><i class="bi bi-plus-lg"></i>' + esc(T('mod.criar')) + '</button>' : '') + '</div>'
            + '<div class="bjaGrupos" data-bja-grupos>' + gruposHtml(s.mBusca, false) + '</div></div>';
    }

    function campoHtml(c0) {
        const k = c0[0], v = s.w.c[k] == null ? '' : String(s.w.c[k]);
        const ctl = c0[4]
            ? '<select class="bjaF" data-bja-c="' + esc(k) + '" data-bja-foco="c-' + esc(k) + '">' + c0[4].map(o => '<option value="' + esc(o) + '"' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'
            : '<input class="bjaF" type="text" data-bja-c="' + esc(k) + '" data-bja-foco="c-' + esc(k) + '" value="' + esc(v) + '" placeholder="' + esc(c0[3] || '') + '">';
        return '<div' + (c0[2] ? ' class="is-largo"' : '') + '><label class="bjaL">' + esc(c0[1]) + ctl + '</label></div>';
    }

    function passo2() {
        const w = s.w, m = modelo(w.mod);
        w.logoVista = true; // a caixa "Usar esta logo nos próximos documentos" está à vista: o salvar manda a escolha dela
        const logoBts = [['bluejus', T('p2.logo_bluejus')]].concat(logos.escritorio ? [['escritorio', T('p2.logo_escritorio')]] : []);
        let h = '<div class="bjaGrid bjaCorpo"><div class="bjaLat">'
            + '<div class="bjaQuadro"><div class="bjaQuadroTit">' + esc(T('p2.logo')) + '</div><div class="bjaLogoLin"><span class="bjaLogoMini"><img alt="' + esc(T('p2.logo_atual')) + '" src="' + esc(w.logo.url || '') + '"></span>'
            + '<div class="bjaLogoBts"><label class="bjaB"><i class="bi bi-upload"></i>' + esc(T('p2.alterar_logo')) + '<input type="file" accept="image/png,image/jpeg" data-bja-logo hidden></label>'
            + logoBts.map(([k, r]) => '<button type="button" class="bjaB bjaN" data-bja-logo-tipo="' + k + '" data-bja-foco="logo-' + k + '">' + esc(r) + '</button>').join('') + '</div></div>'
            + '<label class="bjaLogoFix"><input type="checkbox" data-bja-wchk="logoFix" data-bja-foco="logoFix"' + (w.logoFix ? ' checked' : '') + '>' + esc(T('p2.usar_logo')) + '</label></div>'
            + '<label class="bjaL">' + esc(T('p2.nome')) + '<input class="bjaF" type="text" data-bja-w="nome" data-bja-foco="w-nome" value="' + esc(w.nome) + '"></label>'
            + '<label class="bjaL">' + esc(T('p2.pasta')) + '<input class="bjaF" type="text" list="bjaPs" data-bja-w="pasta" data-bja-foco="w-pasta" value="' + esc(w.pasta) + '"><datalist id="bjaPs">' + s.pastas.map(p => '<option value="' + esc(p) + '"></option>').join('') + '</datalist></label>'
            + '<div class="bjaQuadro"><label class="bjaChk"><input type="checkbox" data-bja-wchk="limpa" data-bja-foco="limpa"' + (w.limpa ? ' checked' : '') + '><span><b>' + esc(T('p2.folha_branca')) + '</b> ' + esc(T('p2.folha_branca_det')) + '</span></label>'
            + (w.limpa ? '<div class="bjaNota">' + esc(T('p2.folha_branca_nota')) + '</div>' : '') + '</div>';
        if (m && m.opcoes && m.opcoes.length) {
            h += '<div class="bjaOpcoes">' + m.opcoes.map(g => '<div><b class="bjaOpcTit">' + esc(g.r) + '</b><div class="bjaChks">' + g.itens.map(o => '<label class="bjaChk"><input type="checkbox" data-bja-cl="' + esc(o.k) + '" data-bja-foco="cl-' + esc(o.k) + '"' + (w.c[o.k] ? ' checked' : '') + '><span>' + esc(o.r) + '</span></label>').join('') + '</div></div>').join('') + '</div>';
        }
        h += '<div class="bjaCampos">' + (m ? camposVisiveis(m).map(campoHtml).join('') : '') + '</div>';
        return h + '</div><div class="bjaCent"><div class="bjaPv" data-folha="w"></div></div></div>';
    }

    function passo3() {
        const w = s.w;
        return '<div class="bjaP3"><div class="bjaP3Cab"><b>' + esc(T('p3.quem')) + '</b>'
            + '<label class="bjaOrdem"><input type="checkbox" data-bja-wchk="seq"' + (w.seq ? ' checked' : '') + '>' + esc(T('p3.em_ordem')) + '</label>'
            + '<button type="button" class="bjaB" data-bja-acao="add-eu"><i class="bi bi-person-check"></i>' + esc(T('p3.incluir_me')) + '</button>'
            + '<button type="button" class="bjaB" data-bja-acao="add-sig"><i class="bi bi-plus-lg"></i>' + esc(T('p3.adicionar')) + '</button></div>'
            + w.sign.map((x, i) => '<div class="bjaSigCx" data-bja-i="' + i + '">'
                + ['nome', 'email', 'cpf', 'papel'].map(k => '<label class="bjaL">' + esc(T('p3.' + k)) + '<input class="bjaF" type="' + (k === 'email' ? 'email' : 'text') + '" data-bja-s="' + k + '" value="' + esc(x[k] || '') + '"></label>').join('')
                + '<label class="bjaL">' + esc(T('p3.forma')) + '<select class="bjaF" data-bja-s="nivel">' + NIVEIS.map(n => '<option value="' + n + '"' + (x.nivel === n ? ' selected' : '') + '>' + esc(T('nivel.' + n)) + '</option>').join('') + '</select></label>'
                + '<button type="button" class="bjaB bjaR bjaI" data-bja-acao="tira-sig" aria-label="' + esc(T('p3.remover')) + '"><i class="bi bi-trash3"></i></button></div>').join('')
            + '</div>';
    }

    function resumo() {
        const w = s.w, m = modelo(w.mod);
        let r = T('p4.resumo_sig', { n: w.sign.length }) + (w.seq ? T('p4.resumo_ordem') : '') + T('p4.resumo_prazo', { n: w.dias });
        if (m) {
            const vazios = camposVisiveis(m).filter(c0 => !String(w.c[c0[0]] == null ? '' : w.c[c0[0]]).trim()).length;
            if (vazios) { r += T('p4.resumo_brancos', { n: vazios }); }
        }
        return r;
    }

    function passo4() {
        const w = s.w;
        return '<div class="bjaGrid bjaCorpo"><div class="bjaLat">'
            + '<label class="bjaL">' + esc(T('p4.mensagem')) + '<textarea class="bjaF" rows="4" data-bja-w="msg">' + esc(w.msg) + '</textarea></label>'
            + '<label class="bjaL">' + esc(T('p4.prazo')) + '<input class="bjaF" type="number" min="1" max="90" data-bja-w="dias" value="' + esc(w.dias) + '"></label>'
            + '<div class="bjaResumo"><b data-bja-rnome>' + esc(w.nome) + '</b><br><span data-bja-resumo>' + esc(resumo()) + '</span></div>'
            + '</div><div class="bjaCent"><div class="bjaPv" data-folha="w"></div></div></div>';
    }

    function navHtml() {
        const pa = s.w.passo, podeSalvar = s.w.rasc ? !!url.salvar : !!url.criar;
        return '<div class="bjaNav"><button type="button" class="bjaB bjaN" data-bja-acao="w-voltar">' + esc(T('nav.voltar')) + '</button>'
            + (podeSalvar ? '<button type="button" class="bjaB" data-bja-acao="w-salvar">' + esc(T('nav.salvar')) + '</button>' : '')
            + (pa < 4 ? '<button type="button" class="bjaP" data-bja-acao="w-avancar">' + esc(T('nav.avancar')) + '</button>'
                : url.enviar ? '<button type="button" class="bjaP" data-bja-acao="w-enviar">' + esc(T('nav.enviar')) + '</button>' : '')
            + '</div>';
    }

    function entrarSign() {
        const w = s.w, m = modelo(w.mod);
        if (w.sign.length) { return w.sign; }
        // Campo vazio vira texto vazio (e não a linha "____________" do desenho): o e-mail é conferido no servidor.
        const sign = m ? m.sign.filter(x => cond(x.se, w.c)).map(x => ({ nome: res(x.nome, w.c, 'vazio').trim(), email: res(x.email, w.c, 'vazio').trim(), cpf: '', papel: x.papel, nivel: 'simples' })) : [sigVazio()];
        if (m && w.c.cpf && sign[0]) { sign[0].cpf = w.c.cpf; }
        return sign;
    }

    function irParaPasso(n) {
        s.w.passo = n; s.wErro = '';
        desenharNovo();
    }

    function avancar() {
        const w = s.w, pa = w.passo;
        if (pa === 2) {
            const mA = w.up ? null : modelo(w.mod), gF = ((mA && mA.opcoes) || []).find(g => g.req && !g.itens.some(o => w.c[o.k]));
            if (gF) { erroW(T('p2.marque_opcao', { grupo: gF.r })); return; }
            w.sign = entrarSign();
            irParaPasso(3);
        } else if (pa === 3) {
            if (!w.sign.length) { erroW(T('p3.inclua')); return; }
            irParaPasso(4);
        }
    }

    function voltar() {
        const w = s.w;
        irParaPasso(w.passo === 3 && w.up ? 1 : w.passo - 1);
    }

    function tgCl(k) {
        const w = s.w, m = modelo(w.mod);
        if (!m || !m.opcoes) { return; }
        let o = null;
        m.opcoes.forEach(g => g.itens.forEach(x => { if (x.k === k) { o = x; } }));
        if (!o) { return; }
        const c = Object.assign({}, w.c), on = !c[o.k];
        c[o.k] = on ? '1' : '';
        if (on) { (o.off || []).forEach(x => { c[x] = ''; }); } else if (o.rest) { (o.off || []).forEach(x => { c[x] = (m.def || {})[x] || ''; }); }
        w.c = c;
        erroW('');
        redesenharCorpo();
    }

    function cpfOk(cpf) {
        const n = String(cpf).replace(/\D/g, '');
        if (!/^\d{11}$/.test(n) || /^(\d)\1+$/.test(n)) { return false; }
        const dv = t => { let soma = 0; for (let i = 0; i < t; i++) { soma += +n[i] * (t + 1 - i); } const r = soma * 10 % 11; return r === 10 ? 0 : r; };
        return dv(9) === +n[9] && dv(10) === +n[10];
    }

    async function subir(fl) {
        if (!fl || !s.w) { return; }
        const ext = (fl.name.split('.').pop() || '').toLowerCase(), nome = fl.name.replace(/\.[^.]+$/, '');
        arqSt(T('p1.lendo', { arquivo: fl.name }));
        try {
            let up = [], arquivo = null, id = 0;
            if (ext === 'pdf') {
                id = ++seqArquivo;
                const pdf = await F.abrirPdf({ chave: 'f' + id, obter: () => fl.arrayBuffer() });
                for (let i = 1; i <= pdf.numPages; i++) { up.push({ k: 'pg', n: i }); }
                arquivo = fl;
            } else if (ext === 'docx') {
                if (!window.mammoth) { throw new Error('mammoth'); }
                const r = await window.mammoth.extractRawText({ arrayBuffer: await fl.arrayBuffer() });
                up = r.value.split(/\n+/).map(x => x.trim()).filter(Boolean).map(x => ({ k: 'p', x: x, full: 0 }));
            } else {
                throw new Error('tipo');
            }
            up.push({ k: 'a', i: 0, r: '' });
            s.arqSt = '';
            Object.assign(s.w, { passo: 3, up: up, nome: nome, mod: '', modelo: null, categoria: T('documento_avulso'), layout: '', lateral: '', arquivo: arquivo, arquivoId: id, arqServ: null, sign: [sigVazio()] });
            s.wErro = '';
            desenharNovo();
        } catch (e) {
            console.error(e);
            arqSt(T('p1.erro'));
        }
    }

    function arqSt(texto) {
        s.arqSt = texto;
        const n = el.telaNovo.querySelector('[data-bja-arqst]');
        if (n) { n.textContent = texto; }
    }

    function subirLogo(f) {
        if (!f || !s.w) { return; }
        const leitor = new FileReader();
        leitor.onload = () => {
            const img = new Image();
            img.onload = () => {
                const k = Math.min(1, 1000 / Math.max(img.width, img.height)), c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(img.width * k)); c.height = Math.max(1, Math.round(img.height * k));
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                const local = c.toDataURL('image/png');
                c.toBlob(b => {
                    if (!b || !s.w) { return; }
                    s.w.logo = { tipo: 'propria', url: local, arquivo: new File([b], 'logo.png', { type: 'image/png' }), origem: 'local' };
                    redesenharCorpo();
                }, 'image/png');
            };
            img.src = leitor.result;
        };
        leitor.readAsDataURL(f);
    }

    function dadosDoRascunho() {
        const w = s.w, dias = Math.min(90, Math.max(1, parseInt(w.dias, 10) || 7));
        return {
            nome: String(w.nome || '').trim(),
            pasta: String(w.pasta || '').trim(),
            categoria: w.categoria || '',
            modelo: w.modelo || null,
            blocos: wBlocos(false),
            layout: w.layout || '',
            lateral: w.lateral || '',
            folhaBranca: !!w.limpa,
            emOrdem: !!w.seq,
            mensagem: w.msg || '',
            prazoDias: dias,
            logo: { tipo: w.logo.tipo },
            // Marcada grava a logo padrão do escritório, desmarcada limpa (tgLogoFix do desenho). Sem a caixa à vista
            // (rascunho retomado ou arquivo enviado, que pulam o passo 2) o campo não vai e o padrão fica como está.
            usarLogoComoPadrao: w.logoVista ? !!w.logoFix : undefined,
            signatarios: w.sign.map(x => ({ nome: String(x.nome || '').trim(), email: String(x.email || '').trim(), cpf: String(x.cpf || '').replace(/\D/g, ''), papel: String(x.papel || '').trim(), nivel: x.nivel })),
        };
    }

    async function formDoRascunho() {
        const w = s.w, dados = dadosDoRascunho(), fd = new FormData();
        fd.append('dados', JSON.stringify(dados));
        if (w.arquivo && dados.blocos.some(b => b.k === 'pg')) { fd.append('arquivo', w.arquivo, w.arquivo.name); }
        const lg = await arquivoDaLogo(w.logo);
        if (lg) { fd.append('logo', lg, lg.name); }
        return fd;
    }

    async function gravar(destino, botao, depois) {
        const w = s.w;
        if (w.sign.some(x => { const n = String(x.cpf || '').replace(/\D/g, ''); return n !== '' && n.length !== 11; })) { erroW(T('p3.cpf_invalido')); return; }
        if (botao) { botao.disabled = true; }
        try {
            const r = await pedir(destino, { method: 'POST', body: await formDoRascunho() });
            await depois(r);
        } catch (e) {
            if (e && !e.texto) { console.error(e); }
            erroW((e && e.texto) || T('erro_geral'));
        } finally {
            if (botao && botao.isConnected) { botao.disabled = false; }
        }
    }

    function salvarRascunho(botao) {
        const w = s.w, destino = w.rasc ? comId(url.salvar, w.rasc) : url.criar;
        if (!destino) { return; }
        gravar(destino, botao, async () => {
            irParaLista();
            avisar(T('toast.rascunho_salvo'));
            await carregar();
        });
    }

    /* "Gerar links e enviar" (passo 4 e "Continuar e enviar"): as mesmas conferências do desenho, depois salva o rascunho
       pela rota da etapa 1 e só então envia. O servidor confere o resto (e-mail do Simples, nível, CPF) e a recusa
       aparece na linha de erro do assistente. Se o envio for recusado, o rascunho já salvo continua sendo o mesmo. */
    function enviar(botao) {
        const w = s.w, destino = w.rasc ? comId(url.salvar, w.rasc) : url.criar;
        if (w.sign.some(x => !String(x.nome || '').trim())) { erroW(T('p3.nomes')); return; }
        if (w.sign.some(x => x.cpf && !cpfOk(x.cpf))) { erroW(T('p3.cpf_invalido')); return; }
        if (!destino || !url.enviar) { return; }
        gravar(destino, botao, async r => {
            if (r && r.id != null) { w.rasc = r.id; }
            const e = await pedir(comId(url.enviar, w.rasc), { method: 'POST' });
            s.w = null;
            mostrarDetalhe(e.documento);
            avisar(T('toast.pronto'));
            await carregar();
        });
    }

    /* ── Detalhe do documento ───────────────────────────────────────────── */
    async function abrirDetalhe(id) {
        if (!url.documento) { return; }
        let d = null;
        try { d = await pedir(comId(url.documento, id)); } catch (e) { falha(e); return; }
        mostrarDetalhe(d);
    }

    function mostrarDetalhe(d) {
        if (!d) { irParaLista(); return; }
        s.det = d; s.tela = 'det'; s.w = null; s.ctx = null; s.canc = false;
        desenharCamadas();
        mostrarTela();
        desenharDetalhe();
    }

    async function carregarDetalheERetomar(id) {
        if (!url.documento) { return; }
        try { retomar(await pedir(comId(url.documento, id))); } catch (e) { falha(e); }
    }

    /* Método gravado pelo servidor ('simples') no rótulo do desenho ('E-mail e código'); valor desconhecido sai como veio. */
    const metodoRotulo = m => (m === 'simples' ? T('metodo.simples') : m || '');

    /* Barra de ações do signatário pendente (C9 a C13): só com o documento Aguardando e dentro do prazo, para todos os
       pendentes (mesmo com ordem). E-mail (mailto) e WhatsApp (sem número) levam o link do próprio signatário. */
    function acoesDoSignatario(d, x, i) {
        const link = x.link || '';
        const assunto = T('det.assunto', { nome: d.nome });
        const mail = 'mailto:' + (x.email || '') + '?subject=' + encodeURIComponent(assunto) + '&body=' + encodeURIComponent((d.mensagem ? d.mensagem + '\n\n' : '') + link);
        const wa = 'https://wa.me/?text=' + encodeURIComponent(T('det.whatsapp_msg', { nome: x.nome, documento: d.nome, link: link }));
        // Sem link (servidor sem a chave da assinatura), E-mail e WhatsApp avisam em vez de abrir uma mensagem sem endereço.
        const ancora = (href, extra) => (link ? 'href="' + esc(href) + '"' + extra : 'href="#" data-bja-acao="sem-link"');
        return '<div class="bjaSigBts" data-bja-sig="' + i + '">'
            + '<button type="button" class="bjaB" data-bja-acao="sig-copiar"><i class="bi bi-link-45deg"></i>' + esc(T('det.copiar')) + '</button>'
            + '<a class="bjaB" ' + ancora(mail, '') + '><i class="bi bi-envelope"></i>' + esc(T('det.email')) + '</a>'
            + '<a class="bjaB" ' + ancora(wa, ' target="_blank" rel="noopener"') + '><i class="bi bi-whatsapp"></i>' + esc(T('det.whatsapp')) + '</a>'
            + (url.previa ? '<button type="button" class="bjaB bjaN" data-bja-acao="sig-previa"><i class="bi bi-box-arrow-up-right"></i>' + esc(T('det.tela_signatario')) + '</button>' : '')
            + '</div>';
    }

    /* C5: o nome digitado ao assinar (nomeDigitado, do Detalhe) quando vier; senão o cadastrado (desenho: nomeAss || nome). */
    function nomeDoSignatario(x) { return x.nomeDigitado || x.nome || ''; }

    function desenharDetalhe() {
        const d = s.det, st = stAtual(d);
        const sigs = (d.signatarios || []).map((x, i) => {
            const sg = x.situacao === 'assinado' ? 'assinado' : x.situacao === 'recusado' ? 'recusado' : 'pendente';
            const metodo = metodoRotulo(x.metodo);
            const info = sg === 'assinado' ? dataHora(x.assinadoEm) + (metodo ? ' · ' + metodo : '') : (x.email || T('det.sem_email'));
            return '<div class="bjaSigLin"><div class="bjaSigTop"><b>' + esc(nomeDoSignatario(x)) + '</b><span class="bjaSeloSig sg-' + sg + '">' + esc(T('sig.' + sg)) + '</span></div>'
                + '<div class="bjaSigPapel">' + esc(x.papel) + ' · ' + esc(nivelRotulo(x.nivel)) + '</div><div class="bjaSigInfo">' + esc(info) + '</div>'
                + (sg === 'pendente' && st === 'aguardando' ? acoesDoSignatario(d, x, i) : '') + '</div>';
        }).join('');
        const bts = (st === 'rascunho' && !d.naLixeira ? '<button type="button" class="bjaP" data-bja-acao="det-retomar"><i class="bi bi-pencil"></i>' + esc(T('det.continuar')) + '</button>' : '')
            + (st === 'aguardando' && !d.naLixeira && url.cancelar ? '<button type="button" class="bjaB bjaR" data-bja-acao="det-cancelar">' + esc(T('det.cancelar')) + '</button>' : '');
        const evs = (d.eventos || []).map(e => '<div class="bjaEv"><b>' + esc(e.texto) + '</b><div>' + esc(dataHora(e.em)) + '</div></div>').join('');
        el.telaDet.innerHTML = '<div class="bjaTopoDet"><button type="button" class="bjaB bjaN" data-bja-acao="voltar-lista"><i class="bi bi-arrow-left"></i>' + esc(T('voltar_documentos')) + '</button>'
            + '<h1 class="bjaH1Det">' + esc(d.nome) + '</h1><span class="bjaSeloDet st-' + esc(st) + '">' + esc(T('situacao.' + st)) + '</span></div>'
            + '<div class="bjaGrid"><div class="bjaLat">'
            + '<section class="bjaCard bjaOculto"><div class="bjaHd"><i class="bi bi-people"></i>' + esc(T('det.signatarios')) + '</div>' + sigs + '</section>'
            + '<section class="bjaCard bjaCodCard"><div class="bjaCodRot">' + esc(T('det.codigo')) + '</div><div class="bjaCodVal">' + esc(d.codigo || T('det.gerado_no_envio')) + '</div>'
            + (bts ? '<div class="bjaCodBts">' + bts + '</div>' : '') + '</section>'
            + '<section class="bjaCard bjaOculto"><div class="bjaHd"><i class="bi bi-clock-history"></i>' + esc(T('det.trilha')) + '</div><div class="bjaTrilha">' + evs + '</div></section>'
            + '</div><div class="bjaCent"><div class="bjaPv" data-folha="d"></div></div></div>';
        pintarPrevias();
    }

    /* ── Prévia A4 (assistente e detalhe) ───────────────────────────────── */
    let rafPrevia = 0;
    function pintarPrevias() {
        cancelAnimationFrame(rafPrevia);
        rafPrevia = requestAnimationFrame(() => {
            raiz.querySelectorAll('[data-folha]').forEach(pv => {
                const t = pv.getAttribute('data-folha');
                if (t === 'w' && s.w) {
                    const w = s.w;
                    F.pintar(pv, { blocos: wBlocos(true), sign: null, logo: w.logo.url, layout: w.layout, lateral: w.lateral, limpa: w.limpa, cod: '', hash: '', fonte: fonteW() });
                } else if (t === 'd' && s.det) {
                    const d = s.det;
                    F.pintar(pv, {
                        blocos: d.blocos || [], sign: (d.signatarios || []).map(x => Object.assign({}, x, { nome: nomeDoSignatario(x) })), logo: logoDoDocumento(d.logo, d.id).url, layout: d.layout || '', lateral: d.lateral || '',
                        limpa: !!d.folhaBranca, cod: d.codigo || '', hash: d.hashEnviado || '', fonte: d.arquivo && d.arquivo.url ? F.fonteDaUrl(d.arquivo.url) : null,
                    });
                } else if (t === 'm' && s.m) {
                    /* Editor de modelo: escritório preenchido, campo do cliente marcado e a logo da BlueJus (html() do desenho). */
                    const m = s.m;
                    F.pintar(pv, { blocos: blocos(lerEd(), padrao(), true), sign: null, logo: logos.bluejus || '', layout: m.layout, lateral: m.lateral, limpa: !!m.limpa, cod: '', hash: '', fonte: null });
                }
            });
        });
    }

    /* ── Editor de modelo (contrato da etapa 4, seção 8): abrirMod, lerEd, htmlDe, noCursor, insChip, bloco,
       alinhaDir, impWord e salvaMod do desenho. O texto mora numa área editável; ao salvar, lerEd a converte em
       campos, signatários e blocos, e só isso vai ao servidor. htmlDe faz o caminho de volta com cada trecho escapado. ── */
    const camposCon = () => { const C0 = window.BJAssinaCampos || {}; return [].concat(C0.Cliente || [], C0.Escritorio || [], C0.Local || []); };
    const novoHtml = () => '<h1>' + esc(T('mod.inicial_titulo')) + '</h1><p>' + esc(T('mod.inicial_texto')) + '</p>';
    const papelPadrao = () => T('mod.papel_padrao');

    function abrirMod(o) {
        s.w = null; s.det = null; s.ctx = null; s.exc = null;
        s.m = Object.assign({ id: null, t: '', cat: T('mod.cat_padrao'), layout: '', lateral: '', limpa: false, html: novoHtml() }, o || {});
        s.mdErro = ''; s.tela = 'modelo';
        desenharCamadas();
        mostrarTela();
        desenharMod();
    }

    function htmlDe(m) {
        const lab = {};
        (m.campos || []).forEach(c => { lab[c[0]] = c[1]; });
        /* O desenho escapa só & e <; aqui o texto é partido em negrito, campo e resto, e cada pedaço passa pelo esc. */
        const inl = t => String(t).split(/(\{b\}|\{\/b\}|\[\[\^?[^\]]+?\]\])/).map(p => {
            if (p === '{b}') { return '<b>'; }
            if (p === '{/b}') { return '</b>'; }
            const k = /^\[\[\^?([^\]]+?)\]\]$/.exec(p);
            return k ? '<span class="ch" contenteditable="false" data-c="' + esc(k[1]) + '">' + esc(lab[k[1]] || k[1]) + '</span>' : esc(p);
        }).join('');
        const papel = i => ((m.sign || [])[i] || {}).papel || papelPadrao();
        return numera((m.corpo || []).filter(b => opAtiva(b, m.def)).map(b => Object.assign({}, b)), b => (b.p != null ? b.p : b.h), (b, x) => { if (b.p != null) { b.p = x; } else { b.h = x; } })
            .map(b => (b.t != null ? '<h1>' + inl(b.t) + '</h1>' : b.s != null ? '<h2>' + inl(b.s) + '</h2>' : b.h != null ? '<h3>' + inl(b.h) + '</h3>'
                : b.n != null ? '<p data-n="1">' + inl(b.n) + '</p>' : b.d != null ? '<p data-d="1">' + inl(b.d) + '</p>' : b.l != null ? '<p><b>' + inl(b.l) + '</b></p>'
                    : b.p != null ? '<p>' + inl(b.p) + '</p>'
                        : '<div data-ass="1" contenteditable="false" data-p="' + esc(papel(b.a)) + '" data-r="' + esc(b.r || '') + '">' + esc(T('mod.assinatura_de', { papel: papel(b.a) })) + '</div>')).join('');
    }

    /* Elementos que fazem bloco na raiz do editor; o resto (texto, negrito, campo) é trecho de parágrafo. */
    const BLOCO_DO_EDITOR = /^(P|DIV|H[1-6]|UL|OL|LI|BLOCKQUOTE|PRE|TABLE)$/;

    function lerEd() {
        const m = s.m, root = edAtual || new DOMParser().parseFromString('<body>' + m.html + '</body>', 'text/html').body;
        const con = camposCon(), cps = [], visto = new Set();
        const reg = (k, r) => { if (visto.has(k)) { return; } visto.add(k); const c0 = con.find(x => x[0] === k); cps.push(c0 ? c0.slice() : [k, r, r.length > 16 ? 1 : 0]); };
        const pedaco = c => {
            if (c.nodeType === 3) { return c.nodeValue; }
            if (c.nodeType !== 1) { return ''; }
            if (c.dataset && c.dataset.c) { reg(c.dataset.c, c.textContent); return '[[' + c.dataset.c + ']]'; }
            if (/^(B|STRONG)$/.test(c.tagName)) { const t = inl(c); return t.trim() ? '{b}' + t + '{/b}' : t; }
            if (c.tagName === 'BR') { return ' '; }
            return inl(c);
        };
        const trecho = nos => nos.map(pedaco).join('').replace(/\u00a0/g, ' ');
        const inl = n => trecho(Array.from(n.childNodes));
        const corpo = [], sign = [];
        let prim = true, solto = [];
        /* D-j: o que estiver solto na raiz (texto, negrito, campo) vira um parágrafo, com o campo registrado; o desenho
           lia só o texto solto e o campo saía de campos (dc:330). */
        const fecharSolto = () => { const t = trecho(solto).trim(); solto = []; if (t) { corpo.push({ p: t }); } };
        Array.from(root.childNodes).forEach(n => {
            if (n.nodeType === 3 || (n.nodeType === 1 && !(n.dataset && n.dataset.ass) && !BLOCO_DO_EDITOR.test(n.tagName))) { solto.push(n); return; }
            if (n.nodeType !== 1) { return; }
            fecharSolto();
            if (n.dataset && n.dataset.ass) {
                const a = sign.length;
                sign.push({ papel: n.dataset.p || papelPadrao(), nome: '', email: '' });
                corpo.push(Object.assign({ a: a }, n.dataset.r ? { r: n.dataset.r } : {}));
                return;
            }
            const t = inl(n).trim();
            if (!t) { return; }
            if (n.tagName === 'H1') { corpo.push({ t: t }); } else if (n.tagName === 'H2') { corpo.push({ s: t }); } else if (n.tagName === 'H3') { corpo.push({ h: t }); } else if (n.dataset && n.dataset.n) { corpo.push({ n: t }); } else if (n.dataset && n.dataset.d) { corpo.push({ d: t }); } else {
                const o = { p: t };
                if (m.layout === '2col' && prim) { o.full = 1; prim = false; }
                corpo.push(o);
            }
        });
        fecharSolto();
        return { id: m.id || 'prevm', cat: m.cat, t: m.t, layout: m.layout, lateral: m.lateral, limpa: !!m.limpa, campos: cps, sign: sign, corpo: corpo };
    }

    function mdCamposHtml() {
        const m = s.m, pad = T('mod.cat_padrao'), cats = [pad].concat([...new Set(modelos().map(x => x.cat).filter(c => c && c !== pad))]);
        return '<label class="bjaL">' + esc(T('mod.nome')) + '<input class="bjaF" type="text" data-bja-md="t" maxlength="200" value="' + esc(m.t) + '" autocomplete="off"></label>'
            + '<label class="bjaL">' + esc(T('mod.categoria')) + '<input class="bjaF" type="text" data-bja-md="cat" maxlength="80" list="bjaCats" value="' + esc(m.cat) + '" autocomplete="off"><datalist id="bjaCats">' + cats.map(c => '<option value="' + esc(c) + '"></option>').join('') + '</datalist></label>'
            + '<label class="bjaL">' + esc(T('mod.formato')) + '<select class="bjaF" data-bja-md="layout"><option value=""' + (m.layout === '2col' ? '' : ' selected') + '>' + esc(T('mod.formato_inteira')) + '</option><option value="2col"' + (m.layout === '2col' ? ' selected' : '') + '>' + esc(T('mod.formato_2col')) + '</option></select></label>'
            + '<label class="bjaChk bjaMdLimpa"><input type="checkbox" data-bja-md="limpa"' + (m.limpa ? ' checked' : '') + '><span><b>' + esc(T('p2.folha_branca')) + '</b> ' + esc(T('p2.folha_branca_det')) + '</span></label>'
            + '<label class="bjaL" data-bja-md-lat' + (m.layout === '2col' ? '' : ' hidden') + '>' + esc(T('mod.lateral')) + '<input class="bjaF" type="text" data-bja-md="lateral" maxlength="20000" value="' + esc(m.lateral) + '" autocomplete="off"></label>';
    }

    function mdBarraHtml() {
        const ferr = [['bi-type-bold', 'negrito', 'bold'], ['bi-type-h1', 'titulo', 'h1'], ['bi-type-h2', 'subtitulo', 'h2'], ['bi-type-h3', 'secao', 'h3'], ['bi-text-paragraph', 'paragrafo', 'p'], ['bi-text-right', '', 'dir']];
        const C0 = window.BJAssinaCampos || {}, grupos = [['grupo_cliente', C0.Cliente], ['grupo_escritorio', C0.Escritorio], ['grupo_local', C0.Local]];
        return ferr.map(([ic, k, cmd]) => {
            const dica = T('mod.' + (k || 'direita'));
            return '<button type="button" class="bjaB bjaN" data-bja-acao="mod-ed" data-bja-ed="' + cmd + '" aria-label="' + esc(dica) + '" data-tip="' + esc(dica) + '"><i class="bi ' + ic + '"></i>' + (k ? esc(T('mod.' + k)) : '') + '</button>';
        }).join('')
            + '<span class="bjaMdDiv"></span><select class="bjaF bjaMdIns" data-bja-ins aria-label="' + esc(T('mod.inserir')) + '"><option value="">' + esc(T('mod.inserir_op')) + '</option>'
            + grupos.map(([r, lista]) => '<optgroup label="' + esc(T('mod.' + r)) + '">' + (lista || []).map(c => '<option value="' + esc(c[0]) + '">' + esc(c[1]) + '</option>').join('') + '</optgroup>').join('')
            + '</select>';
    }

    function desenharMod() {
        const m = s.m;
        el.telaMod.innerHTML = '<div class="bjaTopoMod"><button type="button" class="bjaB bjaN" data-bja-acao="mod-sair"><i class="bi bi-arrow-left"></i>' + esc(T('mod.voltar')) + '</button>'
            + '<h1 class="bjaH1Mod">' + esc(m.id ? T('mod.titulo_editar') : T('mod.titulo_novo')) + '</h1>'
            + '<label class="bjaB bjaMdImp"><i class="bi bi-file-earmark-word"></i>' + esc(T('mod.importar')) + '<input type="file" accept=".docx" data-bja-impword hidden></label>'
            + '<button type="button" class="bjaP" data-bja-acao="mod-salvar"><i class="bi bi-check2"></i>' + esc(T('mod.salvar')) + '</button></div>'
            + '<div class="bjaGrid"><div class="bjaCent bjaMdEdCol"><section class="bjaCard bjaOculto">'
            + '<div class="bjaMdCampos">' + mdCamposHtml() + '</div>'
            + '<div class="bjaMdBarra">' + mdBarraHtml() + '</div>'
            + '<div class="bjaMdCriar"><div class="bjaMdPar"><input class="bjaF" type="text" data-bja-md-novo maxlength="60" aria-label="' + esc(T('mod.novo_campo')) + '" placeholder="' + esc(T('mod.novo_campo_ex')) + '" autocomplete="off">'
            + '<button type="button" class="bjaB" data-bja-acao="mod-campo">' + esc(T('mod.criar_campo')) + '</button></div>'
            + '<div class="bjaMdPar"><input class="bjaF" type="text" data-bja-md-papel maxlength="120" aria-label="' + esc(T('mod.papel')) + '" placeholder="' + esc(T('mod.papel_ex')) + '" autocomplete="off">'
            + '<button type="button" class="bjaB" data-bja-acao="mod-assinatura"><i class="bi bi-pen"></i>' + esc(T('mod.assinatura')) + '</button></div></div>'
            + '<div class="bjaEd" data-bja-editor role="textbox" aria-multiline="true" aria-label="' + esc(T('mod.texto')) + '"></div>'
            + '</section><div class="bjaMdErro" data-bja-mderro aria-live="polite">' + esc(s.mdErro) + '</div></div>'
            + '<div class="bjaCent bjaMdPvCol"><div class="bjaPv" data-folha="m"></div></div></div>';
        const ed = el.telaMod.querySelector('[data-bja-editor]');
        ed.contentEditable = 'true';
        ed.innerHTML = m.html;
        edAtual = ed; edRng = null;
        ['keyup', 'mouseup'].forEach(nome => ed.addEventListener(nome, guardarCursor));
        ed.addEventListener('input', () => { guardarCursor(); pintarPrevias(); });
        pintarPrevias();
    }

    function erroMd(texto) {
        s.mdErro = texto || '';
        const e = el.telaMod.querySelector('[data-bja-mderro]');
        if (e) { e.textContent = s.mdErro; }
    }

    function guardarCursor() {
        const s0 = window.getSelection();
        if (edAtual && s0 && s0.rangeCount && edAtual.contains(s0.anchorNode)) { edRng = s0.getRangeAt(0).cloneRange(); }
    }

    function noCursor(no) {
        const ed = edAtual;
        if (!ed) { return; }
        ed.focus();
        const s0 = window.getSelection();
        let r = edRng && ed.contains(edRng.startContainer) ? edRng : null;
        if (r) { r.deleteContents(); }
        if (!r || r.startContainer === ed) { r = dentroDeBloco(ed, r); }
        r.insertNode(no); r.setStartAfter(no); r.collapse(true);
        s0.removeAllRanges(); s0.addRange(r);
        edRng = r.cloneRange();
        pintarPrevias();
    }

    /* D-j: o campo sempre termina dentro de um bloco (o lerEd do desenho só o lê dentro de um, dc:330; o noCursor do
       desenho, sem cursor, o punha na raiz, dc:332). Sem cursor: fim do último bloco de texto, ou um parágrafo novo no
       fim. Cursor entre blocos: um parágrafo novo ali. */
    function dentroDeBloco(ed, r) {
        let alvo;
        if (r) {
            alvo = document.createElement('p');
            r.insertNode(alvo);
        } else {
            const fim = ed.lastChild;
            alvo = fim && fim.nodeType === 1 && !fim.dataset.ass && BLOCO_DO_EDITOR.test(fim.tagName) ? fim : ed.appendChild(document.createElement('p'));
        }
        if (alvo.childNodes.length === 1 && alvo.firstChild.nodeName === 'BR') { alvo.removeChild(alvo.firstChild); }
        const n = document.createRange();
        n.selectNodeContents(alvo);
        n.collapse(false);
        return n;
    }

    function insChip(k, r) {
        const c = document.createElement('span');
        c.className = 'ch'; c.setAttribute('contenteditable', 'false'); c.dataset.c = k; c.textContent = r;
        noCursor(c);
        noCursor(document.createTextNode(' '));
    }

    /* Barra de formatação: os botões não tiram o foco do texto (mousedown cancelado); Negrito, bloco e à direita. */
    function formatar(cmd) {
        if (!edAtual) { return; }
        if (cmd === 'dir') { alinhaDir(); return; }
        if (cmd !== 'bold') { edAtual.focus(); }
        try { if (cmd === 'bold') { document.execCommand('bold'); } else { document.execCommand('formatBlock', false, cmd); } } catch (e) { /* navegador sem execCommand: o texto fica como está */ }
        pintarPrevias();
    }

    function alinhaDir() {
        const s0 = window.getSelection();
        let n = s0 && s0.anchorNode;
        while (n && n !== edAtual && n.parentNode !== edAtual) { n = n.parentNode; }
        if (n && n.nodeType === 1 && n !== edAtual) { if (n.dataset.d) { delete n.dataset.d; } else { n.dataset.d = '1'; } pintarPrevias(); }
    }

    function criarCampo() {
        const i = el.telaMod.querySelector('[data-bja-md-novo]'), r = i ? i.value.trim() : '';
        if (!r) { return; }
        if (/[[\]]/.test(r) || r.charAt(0) === '^') { erroMd(T('mod.erro_nome_campo')); return; }
        erroMd('');
        insChip(r, r);
        i.value = '';
    }

    function criarAssinatura() {
        if (!edAtual) { return; }
        const i = el.telaMod.querySelector('[data-bja-md-papel]'), p0 = (i ? i.value.trim() : '') || papelPadrao();
        const d0 = document.createElement('div');
        d0.dataset.ass = '1'; d0.dataset.p = p0; d0.setAttribute('contenteditable', 'false'); d0.textContent = T('mod.assinatura_de', { papel: p0 });
        edAtual.appendChild(d0);
        const p1 = document.createElement('p');
        p1.appendChild(document.createElement('br'));
        edAtual.appendChild(p1);
        if (i) { i.value = ''; }
        pintarPrevias();
    }

    /* Nome, categoria, formato, folha branca e texto lateral do modelo (data-bja-md). */
    function mudarMod(t) {
        const k = t.getAttribute('data-bja-md');
        s.m[k] = k === 'limpa' ? t.checked : t.value;
        if (k === 't') { erroMd(''); }
        if (k === 'layout') { const lat = el.telaMod.querySelector('[data-bja-md-lat]'); if (lat) { lat.hidden = t.value !== '2col'; } }
        pintarPrevias();
    }

    /* Importar Word (impWord do desenho): mammoth converte, o DOMParser lê sem executar nada, e cada bloco vira um
       parágrafo com o negrito; o resto é texto escapado. Substitui o texto do editor. */
    async function importarWord(f) {
        const ed = edAtual;
        if (!f || !ed || !s.m) { return; }
        try {
            if (!window.mammoth) { throw new Error('mammoth'); }
            const r = await window.mammoth.convertToHtml({ arrayBuffer: await f.arrayBuffer() });
            const corpo = new DOMParser().parseFromString(String(r.value || ''), 'text/html').body;
            const inl = n => Array.from(n.childNodes).map(c => (c.nodeType === 3 ? esc(c.nodeValue) : c.nodeType !== 1 ? '' : /^(B|STRONG)$/.test(c.tagName) ? '<b>' + inl(c) + '</b>' : inl(c))).join('');
            const h = Array.from(corpo.children).map(n => { const t = inl(n).trim(); return t ? '<p>' + t + '</p>' : ''; }).join('');
            if (ed !== edAtual || !s.m) { return; }
            ed.innerHTML = h || novoHtml();
            edRng = null;
            if (!s.m.t) {
                s.m.t = f.name.replace(/\.[^.]+$/, '');
                const nome = el.telaMod.querySelector('[data-bja-md="t"]');
                if (nome) { nome.value = s.m.t; }
            }
            pintarPrevias();
            avisar(T('mod.importado'));
        } catch (e) { avisar(T('mod.importar_erro'), 'erro'); }
    }

    /* Salvar modelo: as conferências do desenho na ordem dele; o servidor repete e recusa com o texto (422). */
    async function salvarMod(botao) {
        const m = s.m, r = lerEd();
        if (!m.t.trim()) { erroMd(T('mod.erro_nome')); return; }
        if (!r.corpo.some(b => b.p != null)) { erroMd(T('mod.erro_texto')); return; }
        if (!r.sign.length) { erroMd(T('mod.erro_assinatura')); return; }
        if (m.layout === '2col' && !r.corpo.some(b => b.t != null)) { erroMd(T('mod.erro_titulo')); return; }
        erroMd('');
        const corpo = { nome: m.t.trim(), categoria: m.cat.trim(), layout: m.layout === '2col' ? '2col' : '', lateral: m.lateral || '', folhaBranca: !!m.limpa, campos: r.campos, signatarios: r.sign.map(x => ({ papel: x.papel })), corpo: r.corpo };
        if (botao) { botao.disabled = true; }
        try {
            await postJson(m.id ? comId(url.modeloSalvar, m.id) : url.modeloCriar, corpo);
        } catch (e) {
            if (botao) { botao.disabled = false; }
            falha(e);
            if (e.status === 404) { irParaModelos(); await carregarModelos(); }
            return;
        }
        irParaModelos();
        avisar(T('mod.salvo'));
        await carregarModelos();
    }

    function pedirExclusaoDoModelo(m) {
        s.ctx = null;
        s.exc = { mod: { id: m.idServ, nome: m.t } };
        desenharCamadas();
        const b = el.modal.querySelector('[data-bja-acao="exc-nao"]');
        if (b) { b.focus(); }
    }

    async function excluirModelo(id) {
        s.exc = null;
        desenharCamadas();
        try { await pedir(comId(url.modeloExcluir, id), { method: 'POST' }); } catch (e) { falha(e); if (e.status === 404) { await carregarModelos(); } return; }
        avisar(T('mod.excluido'), 'aviso');
        await carregarModelos();
    }

    /* ── Menu de contexto, modal de exclusão ────────────────────────────── */
    function idsDoCtx() {
        const d0 = s.ctx && doc(s.ctx.id);
        if (!d0) { return []; }
        const sv = selDe(visiveis()), id = String(d0.id);
        return s.sel[id] && sv.length > 1 ? sv : [id];
    }

    function abrirCtx(ev, id, botao) {
        ev.preventDefault();
        ev.stopPropagation();
        const r = botao ? botao.getBoundingClientRect() : null;
        const x = ev.clientX || (r ? r.left - 200 : 0), y = ev.clientY || (r ? r.bottom : 0);
        s.ctx = { id: id, x: Math.max(6, Math.min(x, window.innerWidth - 244)), y: Math.max(6, Math.min(y, window.innerHeight - 420)) };
        desenharCamadas();
        const primeiro = el.ctx.querySelector('.bjaCtx');
        if (primeiro && !ev.clientX) { primeiro.focus(); }
    }

    function fecharCtx() { s.ctx = null; desenharCamadas(); }

    function desenharCamadas() {
        el.ctx.innerHTML = s.ctx ? ctxHtml() : '';
        el.modal.innerHTML = s.exc ? excHtml() : s.canc ? cancHtml() : '';
    }

    function ctxHtml() {
        const d0 = doc(s.ctx.id);
        if (!d0) { s.ctx = null; return ''; }
        const ids = idsDoCtx(), um = ids.length === 1, L = [];
        if (um) {
            L.push(['bi-box-arrow-up-right', T('ctx.abrir'), 'ctx-abrir']);
            if (d0.stA === 'rascunho' && !d0.naLixeira && url.documento) { L.push(['bi-pencil', T('ctx.continuar'), 'ctx-continuar']); }
            if (temLink(d0) && url.documento) { L.push(['bi-link-45deg', T('ctx.copiar'), 'ctx-copiar']); }
            if (temLink(d0) && url.lembrete) { L.push(['bi-bell', T('ctx.lembrete'), 'ctx-lembrete']); }
            if (url.duplicar) { L.push(['bi-copy', T('ctx.duplicar'), 'ctx-duplicar']); }
        }
        L.push(['bi-check2-square', s.sel[String(d0.id)] ? T('ctx.desmarcar') : T('ctx.selecionar'), 'ctx-sel']);
        L.push(['bi-ui-checks', T('ctx.selecionar_tudo'), 'ctx-sel-tudo']);
        if (d0.naLixeira) {
            if (url.restaurar) { L.push(['bi-arrow-counterclockwise', T('ctx.restaurar'), 'ctx-restaurar']); }
            if (url.excluir && ids.every(i => (doc(i) || {}).situacao === 'rascunho')) { L.push(['bi-trash3', T('ctx.excluir_def'), 'ctx-excluir-def', 1]); }
        } else if (url.lixeira) {
            L.push(['bi-trash3', T('ctx.excluir'), 'ctx-excluir', 1]);
        }
        return '<div class="bjaCtxFundo" data-bja-acao="ctx-fechar"></div>'
            + '<div class="bjaCard bjaCtxMenu" role="menu" aria-label="' + esc(T('ctx.rotulo')) + '" style="left:' + s.ctx.x + 'px;top:' + s.ctx.y + 'px">'
            + '<div class="bjaCtxTit">' + esc(um ? d0.nome : T('ctx.titulo_varios', { n: ids.length })) + '</div>'
            + L.map(([ic, r, ac, perigo]) => '<button type="button" role="menuitem" class="bjaCtx' + (perigo ? ' is-perigo' : '') + '" data-bja-acao="' + ac + '"><i class="bi ' + ic + '"></i>' + esc(r) + '</button>').join('')
            + (url.cor ? '<div class="bjaCtxCores"><div class="bjaCtxCoresTit">' + esc(T('ctx.cor_linha')) + '</div><div class="bjaCtxCoresLin">' + coresHtml('ctx') + '</div></div>' : '')
            + '</div>';
    }

    function excHtml() {
        if (s.exc.mod) {
            return '<div class="bjaModal"><div class="bjaCard bjaOculto bjaModalCx" role="dialog" aria-modal="true" aria-label="' + esc(T('mod.exc_titulo')) + '">'
                + '<div class="bjaHd is-perigo"><i class="bi bi-trash3"></i>' + esc(T('mod.exc_titulo')) + '</div>'
                + '<div class="bjaModalTx">' + esc(T('mod.exc_texto')) + '</div>'
                + '<div class="bjaModalPe"><button type="button" class="bjaB bjaN" data-bja-acao="exc-nao">' + esc(T('exc.voltar')) + '</button>'
                + '<button type="button" class="bjaB bjaR" data-bja-acao="exc-sim">' + esc(T('mod.exc_botao')) + '</button></div></div></div>';
        }
        const def = !!s.exc.def;
        return '<div class="bjaModal"><div class="bjaCard bjaOculto bjaModalCx" role="dialog" aria-modal="true" aria-label="' + esc(T('exc.rotulo')) + '">'
            + '<div class="bjaHd is-perigo"><i class="bi bi-trash3"></i>' + esc(def ? T('exc.titulo_def') : T('exc.titulo_lixeira')) + '</div>'
            + '<div class="bjaModalTx">' + esc(def ? T('exc.texto_def') : T('exc.texto_lixeira', { n: s.exc.ids.length })) + '</div>'
            + '<div class="bjaModalPe"><button type="button" class="bjaB bjaN" data-bja-acao="exc-nao">' + esc(T('exc.voltar')) + '</button>'
            + '<button type="button" class="bjaB bjaR" data-bja-acao="exc-sim">' + esc(def ? T('exc.botao_def') : T('exc.botao')) + '</button></div></div></div>';
    }

    /* Confirmação do Cancelar envio: a janela do Excluir (D), com o ícone de Cancelado (bi-slash-circle). */
    function cancHtml() {
        return '<div class="bjaModal"><div class="bjaCard bjaOculto bjaModalCx" role="dialog" aria-modal="true" aria-label="' + esc(T('canc.rotulo')) + '">'
            + '<div class="bjaHd is-perigo"><i class="bi bi-slash-circle"></i>' + esc(T('canc.titulo')) + '</div>'
            + '<div class="bjaModalTx">' + esc(T('canc.texto')) + '</div>'
            + '<div class="bjaModalPe"><button type="button" class="bjaB bjaN" data-bja-acao="canc-nao">' + esc(T('canc.voltar')) + '</button>'
            + '<button type="button" class="bjaB bjaR" data-bja-acao="canc-sim">' + esc(T('canc.botao')) + '</button></div></div></div>';
    }

    function pedirCancelamento() {
        s.canc = true;
        desenharCamadas();
        const b = el.modal.querySelector('[data-bja-acao="canc-nao"]');
        if (b) { b.focus(); }
    }

    function pedirExclusao(ids, def) {
        s.ctx = null;
        s.exc = { ids: ids, def: !!def };
        desenharCamadas();
        const b = el.modal.querySelector('[data-bja-acao="exc-nao"]');
        if (b) { b.focus(); }
    }

    /* ── Ações no servidor ──────────────────────────────────────────────── */
    async function moverParaLixeira(ids) {
        try { await postJson(url.lixeira, { ids: idsReais(ids) }); } catch (e) { falha(e); return; }
        s.sel = {}; s.exc = null; s.ctx = null;
        desenharCamadas();
        avisar(T('toast.lixeira', { n: ids.length }), 'aviso');
        await carregar();
    }

    async function restaurar(ids) {
        try { await postJson(url.restaurar, { ids: idsReais(ids) }); } catch (e) { falha(e); return; }
        s.sel = {}; s.ctx = null; s.filtro = 'todos';
        desenharCamadas();
        avisar(T('toast.restaurados'));
        await carregar();
    }

    async function apagar(ids) {
        if (ids.some(k => (doc(k) || {}).situacao !== 'rascunho')) { avisar(T('toast.enviados_ficam'), 'erro'); return; }
        try { await postJson(url.excluir, { ids: idsReais(ids) }); } catch (e) { falha(e); return; }
        s.sel = {}; s.exc = null; s.ctx = null;
        desenharCamadas();
        avisar(T('toast.excluido_def'));
        await carregar();
    }

    async function pintarCor(ids, cor) {
        if (!ids.length) { return; }
        try { await postJson(url.cor, { ids: idsReais(ids), cor: cor }); } catch (e) { falha(e); return; }
        s.ctx = null;
        desenharCamadas();
        await carregar();
    }

    /* Cópia do link (copiar e copiaF do desenho): área de transferência; senão a seleção de texto; senão o link no aviso azul. */
    function copiarTexto(t) {
        const ok = () => avisar(T('toast.link_copiado'));
        const reserva = () => {
            const a = document.createElement('textarea');
            a.value = t;
            document.body.appendChild(a);
            a.select();
            let foi = false;
            try { foi = document.execCommand('copy'); } catch (e) { foi = false; }
            a.remove();
            if (foi) { ok(); } else { avisar(t, 'info'); }
        };
        try { navigator.clipboard.writeText(t).then(ok, reserva); } catch (e) { reserva(); }
    }

    function copiarLink(link) {
        if (!link) { avisar(T('toast.sem_chave'), 'erro'); return; }
        copiarTexto(link);
    }

    /* Copiar link da lista: o resumo não traz o link, então o detalhe é lido na hora e o alvo é o primeiro pendente. */
    async function copiarDaLista(id) {
        let d = null;
        try { d = await pedir(comId(url.documento, id)); } catch (e) { falha(e); return; }
        const p1 = primeiroPendente(d);
        if (!p1 || stAtual(d) !== 'aguardando') { await carregar(); return; }
        copiarLink(p1.link);
    }

    async function lembrar(id) {
        const d0 = doc(id), p1 = d0 && primeiroPendente(d0);
        if (!d0 || !url.lembrete) { return; }
        let r = null;
        try { r = await pedir(comId(url.lembrete, d0.id), { method: 'POST' }); } catch (e) { falha(e); return; }
        avisar(T('toast.lembrete', { nome: (r && r.para) || (p1 ? p1.nome : '') }));
    }

    async function cancelarEnvio() {
        const d = s.det;
        s.canc = false;
        desenharCamadas();
        if (!d || !url.cancelar) { return; }
        let r = null;
        try { r = await pedir(comId(url.cancelar, d.id), { method: 'POST' }); } catch (e) { falha(e); return; }
        if (s.tela !== 'det' || !s.det || s.det.id !== d.id) { await carregar(); return; }
        if (r && r.documento) { s.det = r.documento; }
        desenharDetalhe();
        avisar(T('toast.cancelado'), 'aviso');
        await carregar();
    }

    function signatarioDoBotao(alvo) {
        const caixa = alvo.closest('[data-bja-sig]');
        return caixa && s.det ? (s.det.signatarios || [])[+caixa.getAttribute('data-bja-sig')] || null : null;
    }

    async function duplicar(id) {
        try { await pedir(comId(url.duplicar, (doc(id) || {}).id), { method: 'POST' }); } catch (e) { falha(e); return; }
        s.ctx = null; s.filtro = 'rascunho';
        desenharCamadas();
        avisar(T('toast.copia'));
        await carregar();
    }

    /* ── Ações da tela (data-bja-acao) ──────────────────────────────────── */
    function acao(nome, alvo, ev) {
        const linha = alvo.closest('tr[data-bja-id]'), idLinha = linha ? linha.getAttribute('data-bja-id') : null;
        const vis = () => visiveis();
        switch (nome) {
            case 'novo': abrirNovo(); break;
            case 'abrir': if (idLinha) { abrirDetalhe((doc(idLinha) || {}).id); } break;
            case 'ctx': if (idLinha) { abrirCtx(ev, idLinha, alvo); } break;
            case 'ctx-fechar': fecharCtx(); break;
            case 'ctx-abrir': { const d = doc(s.ctx.id); fecharCtx(); if (d) { abrirDetalhe(d.id); } break; }
            case 'ctx-continuar': { const d = doc(s.ctx.id); fecharCtx(); if (d) { carregarDetalheERetomar(d.id); } break; }
            case 'ctx-duplicar': { const id = s.ctx.id; fecharCtx(); duplicar(id); break; }
            case 'ctx-sel': { const id = s.ctx.id; s.sel[id] = !s.sel[id]; fecharCtx(); desenharDocs(); break; }
            case 'ctx-sel-tudo': { const o = {}; vis().forEach(d => { o[String(d.id)] = true; }); s.sel = o; fecharCtx(); desenharDocs(); break; }
            case 'ctx-restaurar': { const ids = idsDoCtx(); fecharCtx(); restaurar(ids); break; }
            case 'ctx-excluir': pedirExclusao(idsDoCtx(), false); break;
            case 'ctx-excluir-def': pedirExclusao(idsDoCtx(), true); break;
            case 'exc-nao': s.exc = null; desenharCamadas(); break;
            case 'exc-sim': if (s.exc.mod) { excluirModelo(s.exc.mod.id); } else if (s.exc.def) { apagar(s.exc.ids); } else { moverParaLixeira(s.exc.ids); } break;
            case 'restaurar-lote': restaurar(selDe(vis())); break;
            case 'excluir-lote': pedirExclusao(selDe(vis()), false); break;
            case 'limpar-sel': s.sel = {}; desenharDocs(); break;
            case 'voltar-lista': irParaLista(); break;
            case 'w-voltar': voltar(); break;
            case 'w-avancar': avancar(); break;
            case 'w-salvar': salvarRascunho(alvo); break;
            case 'w-enviar': enviar(alvo); break;
            case 'add-eu': s.w.sign = s.w.sign.concat([{ nome: eu.nome || '', email: eu.email || '', cpf: '', papel: T('p3.papel_eu'), nivel: 'simples' }]); erroW(''); redesenharCorpo(); break;
            case 'add-sig': s.w.sign = s.w.sign.concat([sigVazio()]); erroW(''); redesenharCorpo(); break;
            case 'tira-sig': { const cx = alvo.closest('[data-bja-i]'); if (cx) { const i = +cx.getAttribute('data-bja-i'); s.w.sign = s.w.sign.filter((_, j) => j !== i); erroW(''); redesenharCorpo(); } break; }
            case 'det-retomar': if (s.det) { retomar(s.det); } break;
            case 'lin-copiar': if (idLinha) { copiarDaLista((doc(idLinha) || {}).id); } break;
            case 'lin-lembrete': if (idLinha) { lembrar(idLinha); } break;
            case 'ctx-copiar': { const d = doc(s.ctx.id); fecharCtx(); if (d) { copiarDaLista(d.id); } break; }
            case 'ctx-lembrete': { const id = s.ctx.id; fecharCtx(); lembrar(id); break; }
            case 'sig-copiar': { const x = signatarioDoBotao(alvo); if (x) { copiarLink(x.link); } break; }
            case 'sig-previa': { const x = signatarioDoBotao(alvo); if (x && s.det) { window.open(comIdOrdem(url.previa, s.det.id, x.ordem), '_blank', 'noopener'); } break; }
            case 'sem-link': ev.preventDefault(); avisar(T('toast.sem_chave'), 'erro'); break;
            case 'det-cancelar': pedirCancelamento(); break;
            case 'canc-nao': s.canc = false; desenharCamadas(); break;
            case 'canc-sim': cancelarEnvio(); break;
            default: acaoDoModelo(nome, alvo); break;
        }
    }

    /* Ações da aba Modelos e do editor (data-bja-acao="mod-..."). */
    function acaoDoModelo(nome, alvo) {
        const m = modelo(alvo.getAttribute('data-bja-mod'));
        switch (nome) {
            case 'mod-criar': abrirMod({}); break;
            case 'mod-editar': if (m && m.meu) { abrirMod({ id: m.idServ, t: m.t, cat: m.cat, layout: m.layout, lateral: m.lateral, limpa: !!m.limpa, html: htmlDe(m) }); } break;
            case 'mod-duplicar': if (m) { abrirMod({ t: T('mod.copia', { nome: m.t }), cat: T('mod.cat_padrao'), layout: m.layout || '', lateral: m.lateral || '', html: htmlDe(m) }); } break;
            case 'mod-excluir': if (m && m.meu) { pedirExclusaoDoModelo(m); } break;
            case 'mod-sair': irParaModelos(); break;
            case 'mod-salvar': if (s.m) { salvarMod(alvo); } break;
            case 'mod-ed': formatar(alvo.getAttribute('data-bja-ed')); break;
            case 'mod-campo': criarCampo(); break;
            case 'mod-assinatura': criarAssinatura(); break;
            default: break;
        }
    }

    /* ── Eventos ────────────────────────────────────────────────────────── */
    /* A barra de formatação não tira o foco nem a seleção do texto do modelo (onMouseDown do desenho). */
    raiz.addEventListener('mousedown', ev => { if (ev.target.closest && ev.target.closest('[data-bja-ed]')) { ev.preventDefault(); } });

    raiz.addEventListener('click', ev => {
        const t = ev.target;
        const aba = t.closest('[data-bja-aba]');
        if (aba) { s.aba = aba.getAttribute('data-bja-aba'); desenharLista(); return; }
        const fil = t.closest('[data-bja-filtro]');
        if (fil) { s.filtro = fil.getAttribute('data-bja-filtro'); desenharDocs(); return; }
        const corF = t.closest('[data-bja-corf]');
        if (corF) { const k = corF.getAttribute('data-bja-corf'); s.corF = !k || s.corF === k ? '' : k; desenharDocs(); return; }
        const cor = t.closest('[data-bja-cor]');
        if (cor) {
            const k = cor.getAttribute('data-bja-cor'), doCtx = cor.getAttribute('data-bja-cor-alvo') === 'ctx';
            const ids = doCtx ? idsDoCtx() : selDe(visiveis());
            if (doCtx) { fecharCtx(); }
            pintarCor(ids, k);
            return;
        }
        const mod = t.closest('[data-bja-modelo]');
        if (mod) { usar(modelo(mod.getAttribute('data-bja-modelo'))); return; }
        const lt = t.closest('[data-bja-logo-tipo]');
        if (lt && s.w) {
            const k = lt.getAttribute('data-bja-logo-tipo');
            s.w.logo = k === 'escritorio' && logos.escritorio ? { tipo: 'escritorio', url: logos.escritorio } : { tipo: 'bluejus', url: logos.bluejus || '' };
            redesenharCorpo();
            return;
        }
        const ac = t.closest('[data-bja-acao]');
        if (ac) { acao(ac.getAttribute('data-bja-acao'), ac, ev); }
    });

    raiz.addEventListener('contextmenu', ev => {
        if (ev.target.closest('.bjaCtxFundo')) { ev.preventDefault(); fecharCtx(); return; }
        const tr = ev.target.closest('tr.bjaLin');
        if (tr && el.linhas.contains(tr)) { abrirCtx(ev, tr.getAttribute('data-bja-id'), null); }
    });

    raiz.addEventListener('change', ev => {
        const t = ev.target;
        if (t === el.todos) {
            const o = {};
            if (t.checked) { visiveis().forEach(d => { o[String(d.id)] = true; }); }
            s.sel = o;
            desenharDocs();
            return;
        }
        if (t.hasAttribute('data-bja-sel')) { s.sel[t.getAttribute('data-bja-sel')] = t.checked; comFoco(desenharDocs); return; }
        if (t === el.pasta) { s.pasta = t.value; desenharDocs(); return; }
        if (t.hasAttribute('data-bja-subir')) { const f = t.files && t.files[0]; t.value = ''; subir(f); return; }
        if (t.hasAttribute('data-bja-logo')) { const f = t.files && t.files[0]; t.value = ''; subirLogo(f); return; }
        if (t.hasAttribute('data-bja-impword')) { const f = t.files && t.files[0]; t.value = ''; importarWord(f); return; }
        if (s.m && t.hasAttribute('data-bja-md') && t.type !== 'text') { mudarMod(t); return; }
        if (s.m && t.hasAttribute('data-bja-ins')) {
            const k = t.value, c = camposCon().find(x => x[0] === k);
            t.value = '';
            if (c) { insChip(c[0], c[1]); }
            return;
        }
        if (!s.w) { return; }
        if (t.hasAttribute('data-bja-wchk')) {
            const k = t.getAttribute('data-bja-wchk');
            s.w[k] = t.checked;
            erroW('');
            if (k === 'limpa') { redesenharCorpo(); }
            return;
        }
        if (t.hasAttribute('data-bja-cl')) { tgCl(t.getAttribute('data-bja-cl')); return; }
        if (t.tagName === 'SELECT' && t.hasAttribute('data-bja-c')) { s.w.c = Object.assign({}, s.w.c, { [t.getAttribute('data-bja-c')]: t.value }); erroW(''); pintarPrevias(); return; }
        if (t.tagName === 'SELECT' && t.hasAttribute('data-bja-s')) { mudarSignatario(t); }
    });

    function mudarSignatario(t) {
        const cx = t.closest('[data-bja-i]');
        if (!cx || !s.w) { return; }
        const i = +cx.getAttribute('data-bja-i'), k = t.getAttribute('data-bja-s');
        s.w.sign = s.w.sign.map((y, j) => (j === i ? Object.assign({}, y, { [k]: t.value }) : y));
        erroW('');
    }

    raiz.addEventListener('input', ev => {
        const t = ev.target;
        if (t === el.busca) { s.busca = t.value; desenharDocs(); return; }
        if (t.hasAttribute('data-bja-mbusca')) {
            s.mBusca = t.value;
            const g = el.telaNovo.querySelector('[data-bja-grupos]');
            if (g) { g.innerHTML = gruposHtml(s.mBusca, false); }
            return;
        }
        if (s.m && t.hasAttribute('data-bja-md') && t.type === 'text') { mudarMod(t); return; }
        if (!s.w) { return; }
        if (t.hasAttribute('data-bja-w')) {
            const k = t.getAttribute('data-bja-w');
            s.w[k] = t.value;
            erroW('');
            const n = el.telaNovo.querySelector('[data-bja-rnome]');
            if (n) { n.textContent = s.w.nome; }
            const r = el.telaNovo.querySelector('[data-bja-resumo]');
            if (r) { r.textContent = resumo(); }
            return;
        }
        if (t.tagName === 'INPUT' && t.hasAttribute('data-bja-c')) { s.w.c = Object.assign({}, s.w.c, { [t.getAttribute('data-bja-c')]: t.value }); erroW(''); pintarPrevias(); return; }
        if (t.tagName === 'INPUT' && t.hasAttribute('data-bja-s')) { mudarSignatario(t); }
    });

    document.addEventListener('keydown', ev => {
        if (ev.key !== 'Escape') { return; }
        if (s.ctx) { fecharCtx(); } else if (s.exc) { s.exc = null; desenharCamadas(); } else if (s.canc) { s.canc = false; desenharCamadas(); }
    });

    /* ── Início ─────────────────────────────────────────────────────────── */
    mostrarTela();
    desenharLista();
    carregar();
    carregarModelos();
})();
