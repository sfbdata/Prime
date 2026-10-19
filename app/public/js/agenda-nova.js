/* =============================================================================
   agenda-nova.js: tela da Agenda nova (desenho 09 - Agenda - VS 18.10.26).

   Dados: GET /agenda/datas (feriados, recesso, tribunais, comemorativas, já cruzados com o Ponto)
   e GET /agenda/compromissos (compromissos, prazos das pastas, aniversários). Cada busca guarda o
   intervalo já trazido; navegar só pede o que falta.

   Entrega 2 da spec docs/specs/agenda-vs-18-10-26.md: leitura. Criar e editar ainda usam o
   formulário da agenda antiga (entrega 3 traz o modal do desenho); o menu de botão direito tem
   só os itens que não gravam nada (entrega 4 acrescenta os ajustes de datas).
   ============================================================================= */
(function () {
    'use strict';

    const raiz = document.getElementById('agPagina');
    if (!raiz) { return; }

    /* Calendários do desenho: chave => [nome, cor, fundo do chip]. */
    const CAL = {
        meus: ['Meus compromissos', '#0a6fa0', '#e5f1f7'],
        aud: ['Audiências', '#5b3f9e', '#efeaf8'],
        prazo: ['Prazos das pastas', '#c0392b', '#fdecee'],
        reun: ['Reuniões', '#1e7b45', '#e9f6ee'],
        fer: ['Feriados nacionais', '#9a5b00', '#fff4d6'],
        est: ['Feriados do estado', '#b45309', '#ffedd5'],
        forense: ['Recesso forense', '#7a1f2b', '#f8e1e4'],
        com: ['Datas comemorativas', '#c2457a', '#fce7f0'],
        trib: ['Tribunais e eleições', '#334e8a', '#e4eaf6'],
        aniv: ['Aniversários da equipe', '#d1477a', '#fde8f0'],
    };
    const DO_SISTEMA = ['fer', 'est', 'forense', 'com', 'trib'];
    const MES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    const SEM = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
    const H0 = 7;
    const H1 = 22;
    const CHAVE_VIS = 'bj-agenda-v1-vis';

    const url = {
        datas: raiz.dataset.urlDatas,
        compromissos: raiz.dataset.urlCompromissos,
        novo: raiz.dataset.urlNovo,
        evento: raiz.dataset.urlEvento,
        editar: raiz.dataset.urlEditar,
        pasta: raiz.dataset.urlPasta,
    };
    const comId = (modelo, id) => modelo.replace(/0(?=[^0]*$)/, String(id));

    /* ── Estado ─────────────────────────────────────────────────────────── */
    const hoje0 = () => { const h = new Date(); h.setHours(0, 0, 0, 0); return h; };
    const s = {
        ref: hoje0(),
        mini: new Date(hoje0().getFullYear(), hoje0().getMonth(), 1),
        vista: 'mes',
        escopo: 'equipe',
        busca: '',
        V: lerVis(),
        ufs: null,
        datas: new Map(),
        itens: new Map(),
        cobertoDatas: [],
        cobertoItens: [],
    };

    function lerVis() {
        try { return JSON.parse(localStorage.getItem(CHAVE_VIS) || '{}') || {}; } catch (e) { return {}; }
    }
    function gravarVis() {
        try { localStorage.setItem(CHAVE_VIS, JSON.stringify(s.V)); } catch (e) { /* sem armazenamento */ }
    }

    /* ── Utilidades ─────────────────────────────────────────────────────── */
    const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const dataDe = k => new Date(k + 'T12:00');
    const soma = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
    const inicioSemana = d => { const x = new Date(d); x.setDate(x.getDate() - x.getDay()); x.setHours(0, 0, 0, 0); return x; };
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const maiuscula = t => t.charAt(0).toUpperCase() + t.slice(1);
    const brData = k => dataDe(k).toLocaleDateString('pt-BR');

    let tAviso = null;
    function avisar(texto) {
        const el = document.getElementById('agToast');
        el.innerHTML = '<div class="ag-toast" role="status">' + esc(texto) + '</div>';
        clearTimeout(tAviso);
        tAviso = setTimeout(() => { el.innerHTML = ''; }, 3000);
    }

    /* ── Busca de dados por intervalo ───────────────────────────────────── */
    const coberto = (lista, de, ate) => lista.some(([a, b]) => a <= de && b >= ate);

    async function garantir(de, ate) {
        const pedidos = [];
        if (!coberto(s.cobertoDatas, de, ate)) {
            pedidos.push(buscar(url.datas + '?de=' + de + '&ate=' + ate).then(r => {
                s.ufs = r.ufs;
                r.datas.forEach(d => s.datas.set(d.id, d));
                s.cobertoDatas.push([de, ate]);
            }));
        }
        if (!coberto(s.cobertoItens, de, ate)) {
            const escopo = s.escopo;
            pedidos.push(buscar(url.compromissos + '?de=' + de + '&ate=' + ate + '&escopo=' + escopo).then(r => {
                if (escopo !== s.escopo) { return; }
                r.itens.forEach(c => s.itens.set(c.id, c));
                s.cobertoItens.push([de, ate]);
            }));
        }
        if (pedidos.length) {
            await Promise.all(pedidos);
        }
    }

    async function buscar(endereco) {
        const resp = await fetch(endereco, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
        if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
        return resp.json();
    }

    /* Intervalos que a tela precisa agora: a visão, o mini calendário e a faixa fixa de hoje até o
       fim do mês seguinte e 60 dias à frente (cartões, lista e próximos feriados). */
    function intervalos() {
        const h = hoje0();
        const fimFixo = new Date(Math.max(soma(h, 60), new Date(h.getFullYear(), h.getMonth() + 2, 0)));
        const lista = [[iso(new Date(h.getFullYear(), h.getMonth(), 1)), iso(fimFixo)]];
        const [vDe, vAte] = intervaloDaVista();
        lista.push([iso(vDe), iso(vAte)]);
        const mIni = inicioSemana(s.mini);
        lista.push([iso(mIni), iso(soma(mIni, 41))]);

        return lista;
    }

    function intervaloDaVista() {
        if (s.vista === 'mes') { const st = inicioSemana(new Date(s.ref.getFullYear(), s.ref.getMonth(), 1)); return [st, soma(st, 41)]; }
        if (s.vista === 'semana') { const st = inicioSemana(s.ref); return [st, soma(st, 6)]; }
        if (s.vista === 'dia') { return [s.ref, s.ref]; }
        return [hoje0(), soma(hoje0(), 60)];
    }

    async function carregar() {
        desenhar();
        try {
            await Promise.all(intervalos().map(([de, ate]) => garantir(de, ate)));
        } catch (e) {
            avisar('Não foi possível carregar a agenda. Tente de novo em instantes.');
        }
        desenhar();
    }

    /* ── Itens unificados ───────────────────────────────────────────────── */
    function todos() {
        const L = [];
        s.datas.forEach(d => L.push({
            id: d.id, d: d.data, t: d.titulo, cal: d.calendario, src: d.calendario, dia: true, h1: '', h2: '',
            obs: d.observacao || (d.fonte && d.calendario === 'fer' ? 'Fonte: ' + d.fonte + '.' : ''),
            uf: d.uf, tipo: d.tipo, ponto: d.contaNoPonto,
        }));
        s.itens.forEach(c => L.push({
            id: c.id, d: c.data, t: c.titulo, cal: c.calendario, src: c.origem === 'agenda' ? 'meus' : c.origem,
            dia: c.dia || !c.h1, h1: c.h1, h2: c.h2, obs: c.observacao || '', meu: c.meu, dono: c.donoNome,
            privado: c.privado, status: c.status, eventoId: c.eventoId, pastaId: c.pastaId, pasta: c.pastaNup, nome: c.donoNome,
        }));

        return L.sort((a, b) => (a.d + (a.dia ? '' : a.h1)).localeCompare(b.d + (b.dia ? '' : b.h1)));
    }

    function visiveis() {
        const q = s.busca.trim().toLowerCase();
        return todos().filter(e => s.V[e.cal] !== false && (!q || (e.t + ' ' + e.obs + ' ' + (e.pasta || '')).toLowerCase().includes(q)));
    }

    function porDia(L) {
        const por = {};
        L.forEach(e => { (por[e.d] = por[e.d] || []).push(e); });
        return por;
    }

    const horaDe = e => (e.dia ? 'Dia inteiro' : (e.h1 || '') + (e.h2 ? ' às ' + e.h2 : ''));
    const tituloNaTela = e => (e.privado ? '🔒 ' : '') + (s.escopo === 'equipe' && e.src === 'meus' && !e.meu && e.dono ? String(e.dono).split(' ')[0] + ': ' : '') + e.t;
    const corDe = e => CAL[e.cal] || CAL.meus;

    function chip(e, classe) {
        const c = corDe(e);
        return '<div class="' + (classe || 'ag-chip') + (e.status === 'cancelado' ? ' is-cancelado' : '') + '" data-ev="' + esc(e.id) + '" data-tip="' + esc(e.t + (e.dia ? '' : ' · ' + (e.h1 || ''))) + '"'
            + ' style="border-left-color:' + c[1] + ';background:' + c[2] + ';">'
            + '<b>' + esc(horaDe(e)) + '</b><span>' + esc(tituloNaTela(e)) + '</span></div>';
    }

    /* ── Desenho ────────────────────────────────────────────────────────── */
    function desenhar() {
        const L = visiveis();
        const por = porDia(L);
        desenharResumo();
        desenharMini(porDia(todos().filter(e => e.cal !== 'fer')));
        desenharCals();
        document.getElementById('agUfs').textContent = s.ufs === null ? '…' : (s.ufs.join(' e ') || 'nenhum estado cadastrado');
        desenharSegmentos();
        desenharAniversario(por);
        const grade = document.getElementById('agGrade');
        if (s.vista === 'mes') { grade.innerHTML = htmlMes(por); }
        else if (s.vista === 'lista') { grade.innerHTML = htmlLista(por); }
        else { grade.innerHTML = htmlGrade(por); }
        document.getElementById('agPeriodo').textContent = tituloDoPeriodo();
        desenharProximosFeriados();
    }

    function tituloDoPeriodo() {
        const r = s.ref;
        if (s.vista === 'mes') { return maiuscula(MES[r.getMonth()]) + ' de ' + r.getFullYear(); }
        if (s.vista === 'dia') { return r.getDate() + ' de ' + MES[r.getMonth()] + ' de ' + r.getFullYear(); }
        if (s.vista === 'semana') {
            const a = inicioSemana(r);
            const b = soma(a, 6);
            return a.getDate() + (a.getMonth() !== b.getMonth() ? ' de ' + MES[a.getMonth()] : '') + ' a ' + b.getDate() + ' de ' + MES[b.getMonth()] + ' de ' + b.getFullYear();
        }
        return 'Próximos 60 dias';
    }

    function desenharResumo() {
        const T = todos();
        const h = hoje0();
        const k0 = iso(h);
        const k1 = iso(soma(h, 1));
        const k7 = iso(soma(h, 6));
        const pz = T.filter(e => e.cal === 'prazo');
        const au = T.filter(e => e.cal === 'aud');
        const cartoes = [
            ['Prazos hoje', pz.filter(e => e.d === k0).length, '#c0392b', 'bi-hourglass-split', 'Prazos que vencem hoje', 'dia', h],
            ['Amanhã', pz.filter(e => e.d === k1).length, '#c76a12', 'bi-hourglass', 'Prazos que vencem amanhã', 'dia', soma(h, 1)],
            ['Nos próximos 7 dias', pz.filter(e => e.d >= k0 && e.d <= k7).length, '#0a6fa0', 'bi-calendar-week', 'Prazos de hoje até ' + brData(k7), 'semana', h],
            ['Audiências hoje', au.filter(e => e.d === k0).length, '#5b3f9e', 'bi-bank', 'Audiências marcadas para hoje', 'dia', h],
        ];
        document.getElementById('agResumo').innerHTML = cartoes.map(([r, n, cor, ic, tip, vista, ref], i) => {
            const c = n ? cor : '#9aabb7';
            return '<button type="button" class="ag-rz' + (n ? '' : ' is-zero') + '" data-rz="' + i + '" data-vista="' + vista + '" data-ref="' + iso(ref) + '" data-tip="' + esc(tip) + '" title="' + esc(tip) + '" style="border-left-color:' + c + ';">'
                + '<i class="bi ' + ic + '" style="color:' + c + ';"></i><span>' + esc(r) + '</span><b style="color:' + c + ';">' + n + '</b></button>';
        }).join('');
    }

    function desenharMini(por) {
        const mi = s.mini;
        const st = inicioSemana(mi);
        const hk = iso(hoje0());
        const rk = iso(s.ref);
        let dias = '';
        for (let i = 0; i < 42; i++) {
            const d = soma(st, i);
            const k = iso(d);
            if (i >= 35 && d.getMonth() !== mi.getMonth()) { break; }
            const cls = ['ag-mini-dia', d.getMonth() !== mi.getMonth() ? 'is-fora' : '', k === rk ? 'is-sel' : '', k === hk ? 'is-hoje' : ''].join(' ');
            dias += '<button type="button" class="' + cls + '" data-mini="' + k + '">' + d.getDate() + ((por[k] || []).length ? '<span class="ag-mini-ponto"></span>' : '') + '</button>';
        }
        document.getElementById('agMini').innerHTML = '<div class="ag-mini-cab"><b>' + maiuscula(MES[mi.getMonth()]) + ' ' + mi.getFullYear() + '</b>'
            + '<button type="button" data-mini-nav="-1" title="Mês anterior"><i class="bi bi-chevron-left"></i></button><button type="button" data-mini-nav="1" title="Próximo mês"><i class="bi bi-chevron-right"></i></button></div>'
            + '<div class="ag-mini-grade">' + ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map(d => '<span class="ag-mini-sem">' + d + '</span>').join('') + dias + '</div>';
    }

    function desenharCals() {
        const meus = todos().filter(e => e.src === 'meus' && e.meu);
        document.getElementById('agCals').innerHTML = Object.entries(CAL).map(([k, v]) => {
            const n = ['meus', 'aud', 'reun', 'prazo'].includes(k) ? meus.filter(e => e.cal === k).length : 0;
            return '<button type="button" class="ag-cal-item" data-cal="' + k + '" aria-pressed="' + (s.V[k] === false ? 'false' : 'true') + '">'
                + '<i class="bi ' + (s.V[k] === false ? 'bi-square' : 'bi-check-square-fill') + '" style="color:' + v[1] + ';"></i><span>' + esc(v[0]) + '</span><span class="ag-cal-n">' + (n || '') + '</span></button>';
        }).join('');
    }

    function desenharSegmentos() {
        document.getElementById('agEscopos').innerHTML = [['minha', 'Minha agenda', 'bi-person'], ['equipe', 'Equipe do escritório', 'bi-people']]
            .map(([k, r, ic]) => '<button type="button" data-escopo="' + k + '" class="' + (s.escopo === k ? 'is-on' : '') + '" aria-pressed="' + (s.escopo === k) + '"><i class="bi ' + ic + '"></i>' + r + '</button>').join('');
        document.getElementById('agVistas').innerHTML = [['dia', 'DIA'], ['semana', 'SEMANA'], ['mes', 'MÊS'], ['lista', 'LISTA']]
            .map(([k, r]) => '<button type="button" data-vista-btn="' + k + '" class="' + (s.vista === k ? 'is-on' : '') + '" aria-pressed="' + (s.vista === k) + '">' + r + '</button>').join('');
    }

    function desenharAniversario(por) {
        const L = (por[iso(hoje0())] || []).filter(e => e.cal === 'aniv');
        document.getElementById('agAniv').innerHTML = L.length
            ? '<div class="ag-aniv"><span class="ag-aniv-emoji">🎉</span><b>Hoje é aniversário de ' + esc(L.map(e => String(e.nome || '').split(' ')[0]).join(', ')) + '!</b><span>Que tal mandar os parabéns no Chat?</span></div>'
            : '';
    }

    function htmlMes(por) {
        const st = inicioSemana(new Date(s.ref.getFullYear(), s.ref.getMonth(), 1));
        const hk = iso(hoje0());
        const celulas = [];
        for (let i = 0; i < 42; i++) {
            const d = soma(st, i);
            const k = iso(d);
            const dm = d.getMonth() === s.ref.getMonth();
            if (i >= 35 && !dm) { break; }
            celulas.push(htmlCelula(d, k, por[k] || [], dm, k === hk));
        }
        const nSem = Math.max(4, Math.ceil(celulas.length / 7));
        return '<div class="ag-rolagem"><div class="ag-mes"><div class="ag-mes-cab">' + SEM.map(x => '<div>' + x + '</div>').join('') + '</div>'
            + '<div class="ag-mes-grade" style="grid-auto-rows:minmax(max(92px, calc((100vh - 340px) / ' + nSem + ')), auto);">' + celulas.join('') + '</div></div></div>';
    }

    function htmlCelula(d, k, L, dm, ehHoje) {
        const fer = ['fer', 'est', 'trib', 'forense'].map(c => L.find(e => e.cal === c)).find(Boolean);
        const resto = L.filter(e => e !== fer);
        const aniv = L.some(e => e.cal === 'aniv');
        const cls = ['ag-cel', !dm ? 'is-fora' : (d.getDay() % 6 === 0 ? 'is-fds' : ''), ehHoje ? 'is-hoje' : ''].join(' ');
        return '<div class="' + cls + '" data-dia="' + k + '">'
            + (aniv ? '<span class="ag-cel-balao" aria-hidden="true">🎈</span>' : '')
            + (aniv && ehHoje ? '<span class="ag-cel-confete" aria-hidden="true"></span>' : '')
            + '<div class="ag-cel-topo"><span class="ag-cel-n">' + d.getDate() + '</span>'
            + (fer ? '<span class="ag-cel-fer" data-ev="' + esc(fer.id) + '" data-tip="' + esc(fer.t) + '" title="' + esc(fer.t) + '">' + esc(fer.t) + '</span>' : '') + '</div>'
            + resto.slice(0, 3).map(e => chip(e)).join('')
            + (resto.length > 3 ? '<button type="button" class="ag-mais" data-ver-dia="' + k + '">+ ' + (resto.length - 3) + ' mais</button>' : '')
            + '</div>';
    }

    function htmlGrade(por) {
        const n = s.vista === 'dia' ? 1 : 7;
        const st = s.vista === 'dia' ? s.ref : inicioSemana(s.ref);
        const hk = iso(hoje0());
        const colunas = Array.from({ length: n }, (_, i) => soma(st, i));
        const tpl = 'grid-template-columns:56px repeat(' + n + ', minmax(0,1fr));';
        const cab = colunas.map(d => {
            const k = iso(d);
            const diaInt = (por[k] || []).filter(e => e.dia || !e.h1);
            return '<div class="ag-grade-col-cab' + (k === hk ? ' is-hoje' : '') + '" data-ir-dia="' + k + '"><div>' + SEM[d.getDay()] + '</div><b>' + d.getDate() + '</b>' + diaInt.map(e => chip(e)).join('') + '</div>';
        }).join('');
        const horas = Array.from({ length: H1 - H0 }, (_, j) => '<div class="ag-hora">' + String(H0 + j).padStart(2, '0') + ':00</div>').join('');
        const cols = colunas.map(d => htmlColuna(d, iso(d), por[iso(d)] || [], iso(d) === hk)).join('');
        return '<div class="ag-rolagem"><div style="min-width:' + (s.vista === 'dia' ? '0' : '620px') + ';">'
            + '<div class="ag-grade-cab" style="' + tpl + '"><span></span>' + cab + '</div>'
            + '<div class="ag-grade-rolo"><div class="ag-grade-corpo" style="' + tpl + '"><div>' + horas + '</div>' + cols + '</div></div></div></div>';
    }

    function htmlColuna(d, k, L, ehHoje) {
        const slots = Array.from({ length: H1 - H0 }, (_, j) => '<div class="ag-slot" data-dia="' + k + '" data-hora="' + String(H0 + j).padStart(2, '0') + ':00"></div>').join('');
        const blocos = L.filter(e => !e.dia && e.h1).map(e => {
            const [a, b] = e.h1.split(':').map(Number);
            const t0 = a * 60 + b;
            const t1 = e.h2 ? (([c, x]) => c * 60 + x)(e.h2.split(':').map(Number)) : t0 + 60;
            const c = corDe(e);
            return '<div class="ag-bloco" data-ev="' + esc(e.id) + '" data-tip="' + esc(e.t) + '" style="top:' + Math.max(0, (t0 - H0 * 60) * 48 / 60) + 'px;height:' + Math.max(22, (t1 - t0) * 48 / 60 - 2) + 'px;border-left-color:' + c[1] + ';background:' + c[2] + ';">'
                + '<b>' + esc(horaDe(e)) + '</b>' + esc(tituloNaTela(e)) + '</div>';
        }).join('');
        const agora = new Date();
        const mins = agora.getHours() * 60 + agora.getMinutes();
        const linha = ehHoje && mins >= H0 * 60 && mins <= H1 * 60 ? '<div class="ag-agora" style="top:' + ((mins - H0 * 60) * 48 / 60) + 'px;"></div>' : '';
        return '<div class="ag-grade-col">' + slots + blocos + linha + '</div>';
    }

    function htmlLista(por) {
        const hk = iso(hoje0());
        const lim = iso(soma(hoje0(), 60));
        const dias = Object.keys(por).filter(k => k >= hk && k <= lim).sort();
        if (!dias.length) { return '<div class="ag-lista-vazia">Nenhum compromisso nos próximos 60 dias.</div>'; }
        return dias.map(k => {
            const d = dataDe(k);
            return '<div class="ag-lista-dia"><div class="ag-lista-data"><b>' + d.getDate() + ' ' + MES[d.getMonth()].slice(0, 3) + '</b><span>' + SEM[d.getDay()] + '</span></div><div>'
                + por[k].map(e => '<div class="ag-lista-item" data-ev="' + esc(e.id) + '"><span class="ag-lista-cor" style="background:' + corDe(e)[1] + ';"></span><b class="ag-lista-hora">' + esc(horaDe(e)) + '</b><span class="ag-lista-tit">' + esc(tituloNaTela(e)) + '</span><span class="ag-lista-cal">' + esc(corDe(e)[0]) + '</span></div>').join('')
                + '</div></div>';
        }).join('');
    }

    function desenharProximosFeriados() {
        const h = hoje0();
        const ini = iso(h);
        const fim = iso(new Date(h.getFullYear(), h.getMonth() + 2, 0));
        const T = todos();
        const visto = new Set();
        const L = T.filter(e => ['fer', 'est'].includes(e.cal) && e.d >= ini && e.d <= fim).filter(e => { const k = e.d + e.t; if (visto.has(k)) { return false; } visto.add(k); return true; });
        const rec = T.find(e => e.cal === 'forense' && e.d >= ini && e.d <= fim);
        if (rec) { L.push(Object.assign({}, rec, { t: 'Início do recesso forense' })); }
        L.sort((a, b) => a.d.localeCompare(b.d));
        document.getElementById('agPfTit').textContent = 'Próximos feriados · ' + MES[h.getMonth()] + ' e ' + MES[(h.getMonth() + 1) % 12];
        const caixa = document.getElementById('agPf');
        if (!L.length) { caixa.innerHTML = '<div class="ag-pf-vazio">Nenhum feriado neste mês nem no próximo.</div>'; return; }
        caixa.innerHTML = '<div class="ag-pf-grade">' + L.map(e => {
            const d = dataDe(e.d);
            const n = Math.round((d - h) / 864e5);
            const falta = n <= 0 ? 'hoje' : n === 1 ? 'amanhã' : 'em ' + n + ' dias';
            const ponte = d.getDay() === 2 || d.getDay() === 4;
            return '<button type="button" class="ag-pf-item" data-pf="' + e.d + '"><span class="ag-pf-data"><span style="background:' + corDe(e)[1] + ';">' + SEM[d.getDay()] + '</span><b>' + String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '</b></span>'
                + '<span class="ag-pf-txt"><b>' + esc(e.t) + '</b><span>' + esc(tipoDoFeriado(e)) + ' · ' + falta + '</span></span>'
                + (ponte ? '<span class="ag-ponte" title="Feriado em terça ou quinta: possível ponte">PONTE</span>' : '') + '</button>';
        }).join('') + '</div>';
    }

    function tipoDoFeriado(e) {
        if (e.cal === 'est') { return 'Estadual · ' + (e.uf || ''); }
        if (e.cal === 'forense') { return 'Forense'; }
        if (e.tipo === 'ponto_facultativo') { return 'Ponto facultativo'; }
        if (e.tipo === 'escritorio') { return 'Escritório'; }
        return 'Nacional';
    }

    /* ── Detalhe ────────────────────────────────────────────────────────── */
    function acharItem(id) {
        return todos().find(e => e.id === id || (e.eventoId && String(e.eventoId) === String(id)));
    }

    function origemDe(e) {
        const ponto = e.ponto ? ' Conta como feriado no ponto.' : ' Não conta no ponto: para contar, cadastre em Feriados e Folgas.';
        if (e.src === 'pasta') { return 'Vem dos prazos e audiências registrados na pasta. Para alterar, edite na pasta.'; }
        if (e.src === 'fer' && e.tipo === 'escritorio') { return 'Feriado cadastrado pelo escritório em Feriados e Folgas. Conta como feriado no ponto.'; }
        if (e.src === 'fer' && e.tipo === 'ponto_facultativo') { return 'Ponto facultativo, já incluído pelo sistema.' + ponto; }
        if (e.src === 'fer') { return 'Feriado nacional, já incluído pelo sistema.' + ponto; }
        if (e.src === 'est') { return 'Feriado estadual incluído pela localização cadastrada (' + (e.uf || '') + ').' + ponto; }
        if (e.src === 'forense') { return 'Recesso forense incluído pelo sistema.'; }
        if (e.src === 'com') { return 'Data comemorativa incluída pelo sistema.'; }
        if (e.src === 'trib') { return 'Data dos tribunais incluída pelo sistema.'; }
        if (e.src === 'aniv') { return 'Aniversário cadastrado no Meu Perfil.'; }
        return 'Criado na agenda do BlueJus.';
    }

    function googleUrl(e) {
        const dt = (d, h) => d.replace(/-/g, '') + (h ? 'T' + h.replace(':', '') + '00' : '');
        const fim = e.dia ? iso(soma(dataDe(e.d), 1)).replace(/-/g, '') : dt(e.d, e.h2 || e.h1);
        return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + encodeURIComponent(e.t)
            + '&dates=' + (e.dia ? dt(e.d) : dt(e.d, e.h1)) + '/' + fim
            + '&details=' + encodeURIComponent((e.obs || '') + (e.pasta ? '\nPasta ' + e.pasta + ' · BlueJus' : ''));
    }

    function abrir(e) {
        const quando = dataDe(e.d).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) + (e.dia || !e.h1 ? ' · dia inteiro' : ' · ' + e.h1 + (e.h2 ? ' às ' + e.h2 : ''));
        const linhas = [
            '<b>' + esc(e.t) + '</b>',
            '<span><i class="bi bi-clock"></i>' + esc(quando) + '</span>',
            '<span><i class="bi bi-tag"></i>' + esc(corDe(e)[0]) + '</span>',
        ];
        if (e.src === 'meus' && !e.meu && e.dono) { linhas.push('<span><i class="bi bi-person"></i>Agenda de ' + esc(e.dono) + '</span>'); }
        if (e.src === 'meus') { linhas.push('<span><i class="bi bi-eye"></i>' + (e.privado ? 'Só você vê' : 'Visível para a equipe do escritório') + '</span>'); }
        if (e.pastaId) { linhas.push('<a href="' + esc(comId(url.pasta, e.pastaId)) + '"><i class="bi bi-folder2-open"></i>Abrir a Pasta ' + esc(e.pasta || '') + '</a>'); }
        if (e.obs) { linhas.push('<span class="ag-modal-obs">' + esc(e.obs) + '</span>'); }
        linhas.push('<div class="ag-modal-origem">' + esc(origemDe(e)) + '</div>');
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="Compromisso">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>Compromisso</b><button type="button" class="ag-modal-x" data-fechar title="Fechar"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo">' + linhas.join('') + '</div>'
            + '<div class="ag-modal-rodape">' + botoesDoDetalhe(e) + '</div></div></div>';
        const x = document.querySelector('#agModal .ag-modal-x');
        if (x) { x.focus(); }
    }

    function botoesDoDetalhe(e) {
        const fechar = '<button type="button" class="ag-mbtn is-pri is-fim" data-fechar><i class="bi bi-x"></i>Fechar</button>';
        if (DO_SISTEMA.includes(e.src) || e.src === 'aniv') { return fechar; }
        const google = '<a class="ag-mbtn" href="' + esc(googleUrl(e)) + '" target="_blank" rel="noopener"><i class="bi bi-google"></i>Google Agenda</a>';
        if (e.src === 'meus' && e.meu && e.eventoId) {
            return google + '<a class="ag-mbtn is-pri is-fim" href="' + esc(comId(url.editar, e.eventoId)) + '"><i class="bi bi-pencil"></i>Editar</a>';
        }

        return google + fechar;
    }

    function fecharModal() { document.getElementById('agModal').innerHTML = ''; }

    /* ── Menu de botão direito ──────────────────────────────────────────── */
    function abrirMenu(x, y, dia, e) {
        const itens = [];
        if (e && !DO_SISTEMA.includes(e.src)) { itens.push(['Abrir compromisso', 'bi-box-arrow-up-right', 'abrir']); }
        if (e && DO_SISTEMA.includes(e.src)) { itens.push(['Ver detalhes', 'bi-info-circle', 'abrir']); }
        itens.push(['Novo compromisso em ' + brData(dia), 'bi-calendar-plus', 'novo']);
        const el = document.getElementById('agCtx');
        el.innerHTML = '<div class="ag-ctx-fundo" data-ctx-fechar></div><div class="ag-ctx" role="menu" style="left:' + Math.min(x, innerWidth - 250) + 'px;top:' + Math.min(y, innerHeight - 160) + 'px;">'
            + '<div class="ag-ctx-tit">' + esc(e ? e.t : brData(dia)) + '</div>'
            + itens.map(([r, ic, acao]) => '<button type="button" class="ag-ctx-item" role="menuitem" data-ctx="' + acao + '"><i class="bi ' + ic + '"></i>' + esc(r) + '</button>').join('') + '</div>';
        el.dataset.dia = dia;
        el.dataset.ev = e ? e.id : '';
        const primeiro = el.querySelector('.ag-ctx-item');
        if (primeiro) { primeiro.focus(); }
    }

    function fecharMenu() { document.getElementById('agCtx').innerHTML = ''; }

    function novoEm(dia, hora) {
        location.href = url.novo + '?data=' + encodeURIComponent(dia) + (hora ? '&hora=' + encodeURIComponent(hora) : '');
    }

    /* ── Eventos da página ──────────────────────────────────────────────── */
    function irPara(ref, vista) {
        s.ref = ref;
        if (vista) { s.vista = vista; }
        s.mini = new Date(ref.getFullYear(), ref.getMonth(), 1);
        carregar();
    }

    function navegar(dir) {
        const r = new Date(s.ref);
        if (s.vista === 'mes') { r.setMonth(r.getMonth() + dir, 1); }
        else if (s.vista === 'semana') { r.setDate(r.getDate() + 7 * dir); }
        else if (s.vista === 'dia') { r.setDate(r.getDate() + dir); }
        else { r.setDate(r.getDate() + 30 * dir); }
        irPara(r);
    }

    raiz.addEventListener('click', ev => {
        const t = ev.target;
        const alvo = sel => t.closest(sel);
        let a;
        if (alvo('[data-fechar]')) { fecharModal(); return; }
        if (alvo('[data-ctx-fechar]')) { fecharMenu(); return; }
        if ((a = alvo('[data-ctx]'))) { acaoDoMenu(a.dataset.ctx); return; }
        if ((a = alvo('[data-ev]'))) { const e = acharItem(a.dataset.ev); if (e) { abrir(e); } return; }
        if ((a = alvo('[data-ver-dia]'))) { irPara(dataDe(a.dataset.verDia), 'dia'); return; }
        if ((a = alvo('[data-ir-dia]'))) { irPara(dataDe(a.dataset.irDia), 'dia'); return; }
        if ((a = alvo('.ag-slot'))) { novoEm(a.dataset.dia, a.dataset.hora); return; }
        if ((a = alvo('.ag-cel'))) { novoEm(a.dataset.dia); return; }
        if ((a = alvo('[data-rz]'))) { s.busca = ''; document.getElementById('agBusca').value = ''; irPara(dataDe(a.dataset.ref), a.dataset.vista); return; }
        if ((a = alvo('[data-mini-nav]'))) { s.mini = new Date(s.mini.getFullYear(), s.mini.getMonth() + Number(a.dataset.miniNav), 1); carregar(); return; }
        if ((a = alvo('[data-mini]'))) { s.ref = dataDe(a.dataset.mini); s.ref.setHours(0, 0, 0, 0); if (s.vista === 'lista') { s.vista = 'dia'; } carregar(); return; }
        if ((a = alvo('[data-cal]'))) { s.V[a.dataset.cal] = s.V[a.dataset.cal] === false; gravarVis(); desenhar(); return; }
        if ((a = alvo('[data-escopo]'))) { trocarEscopo(a.dataset.escopo); return; }
        if ((a = alvo('[data-vista-btn]'))) { s.vista = a.dataset.vistaBtn; carregar(); return; }
        if ((a = alvo('[data-pf]'))) { irPara(dataDe(a.dataset.pf), 'mes'); return; }
        if (alvo('#agNovo')) { novoEm(iso(s.ref)); return; }
        if (alvo('#agHoje')) { irPara(hoje0()); return; }
        if (alvo('#agAnt')) { navegar(-1); return; }
        if (alvo('#agProx')) { navegar(1); return; }
        if (alvo('#agGoogle')) { avisar('A sincronização com o Google Agenda será liberada assim que a conexão for configurada no servidor do BlueJus.'); }
    });

    raiz.addEventListener('contextmenu', ev => {
        const t = ev.target;
        if (t.closest('#agModal, #agCtx')) { return; }
        const cel = t.closest('[data-dia]');
        if (!cel) { return; }
        ev.preventDefault();
        const marcado = t.closest('[data-ev]');
        abrirMenu(ev.clientX, ev.clientY, cel.dataset.dia, marcado ? acharItem(marcado.dataset.ev) : null);
    });

    function acaoDoMenu(acao) {
        const el = document.getElementById('agCtx');
        const dia = el.dataset.dia;
        const e = el.dataset.ev ? acharItem(el.dataset.ev) : null;
        fecharMenu();
        if (acao === 'abrir' && e) { abrir(e); }
        if (acao === 'novo') { novoEm(dia); }
    }

    function trocarEscopo(escopo) {
        if (s.escopo === escopo) { return; }
        s.escopo = escopo;
        s.itens = new Map();
        s.cobertoItens = [];
        carregar();
    }

    document.getElementById('agBusca').addEventListener('input', ev => {
        s.busca = ev.target.value;
        if (s.busca) { s.vista = 'lista'; }
        carregar();
    });

    document.addEventListener('keydown', ev => {
        if (ev.key !== 'Escape') { return; }
        if (document.querySelector('#agCtx .ag-ctx')) { fecharMenu(); return; }
        if (document.querySelector('#agModal .ag-modal')) { fecharModal(); }
    });

    /* Link direto #ev=<id> (aviso do Chat, lembrete): abre o compromisso no dia dele. */
    async function abrirDoEndereco() {
        const m = location.hash.match(/ev=([^&]+)/);
        await carregar();
        if (!m) { return; }
        const e = acharItem(decodeURIComponent(m[1]));
        if (e) { irPara(dataDe(e.d)); abrir(e); }
    }

    abrirDoEndereco();
})();
