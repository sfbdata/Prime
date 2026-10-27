/* =============================================================================
   agenda-nova.js: tela da Agenda nova (desenho 09 - Agenda - VS 18.10.26).

   Dados: GET /agenda/datas (feriados, recesso, tribunais, comemorativas, já cruzados com o Ponto)
   e GET /agenda/compromissos (compromissos, prazos das pastas, aniversários). Cada busca guarda o
   intervalo já trazido; navegar só pede o que falta.

   Spec docs/specs/agenda-vs-18-10-26.md. Entrega 3: o modal de criar e editar (POST em
   /agenda/compromissos/...), "Avisar no Chat" e "Excluir". O menu de botão direito ainda tem só os
   itens que não gravam datas (a entrega 4 acrescenta os ajustes de feriados).
   ============================================================================= */
(function () {
    'use strict';

    const raiz = document.getElementById('agPagina');
    if (!raiz) { return; }

    /* Calendários do desenho: chave => [nome, cor, fundo do chip]. */
    const CAL = {
        meus: [BJ_T.t('agenda.js.calendario.meus'), '#0a6fa0', '#e5f1f7'],
        aud: [BJ_T.t('agenda.js.calendario.aud'), '#5b3f9e', '#efeaf8'],
        prazo: [BJ_T.t('agenda.js.calendario.prazo'), '#c0392b', '#fdecee'],
        reun: [BJ_T.t('agenda.js.calendario.reun'), '#1e7b45', '#e9f6ee'],
        fer: [BJ_T.t('agenda.js.calendario.fer'), '#9a5b00', '#fff4d6'],
        est: [BJ_T.t('agenda.js.calendario.est'), '#b45309', '#ffedd5'],
        forense: [BJ_T.t('agenda.js.calendario.forense'), '#7a1f2b', '#f8e1e4'],
        com: [BJ_T.t('agenda.js.calendario.com'), '#c2457a', '#fce7f0'],
        trib: [BJ_T.t('agenda.js.calendario.trib'), '#334e8a', '#e4eaf6'],
        aniv: [BJ_T.t('agenda.js.calendario.aniv'), '#d1477a', '#fde8f0'],
        /* Tipos da tela 1A (docs/specs/agenda-1a.md): a tela de 18/10 só os mostra, não os oferece no formulário. */
        urg: [BJ_T.t('agenda.js.calendario.urg'), '#f56954', '#fdeeea'],
        pess: [BJ_T.t('agenda.js.calendario.pess'), '#605ca8', '#efeef8'],
        lemb: [BJ_T.t('agenda.js.calendario.lemb'), '#00c0ef', '#e5f6fb'],
    };
    const DO_SISTEMA = ['fer', 'est', 'forense', 'com', 'trib', 'aniv'];
    const MES = [BJ_T.t('agenda.js.datas.mes.janeiro'), BJ_T.t('agenda.js.datas.mes.fevereiro'), BJ_T.t('agenda.js.datas.mes.marco'), BJ_T.t('agenda.js.datas.mes.abril'), BJ_T.t('agenda.js.datas.mes.maio'), BJ_T.t('agenda.js.datas.mes.junho'), BJ_T.t('agenda.js.datas.mes.julho'), BJ_T.t('agenda.js.datas.mes.agosto'), BJ_T.t('agenda.js.datas.mes.setembro'), BJ_T.t('agenda.js.datas.mes.outubro'), BJ_T.t('agenda.js.datas.mes.novembro'), BJ_T.t('agenda.js.datas.mes.dezembro')];
    const SEM = [BJ_T.t('agenda.js.datas.semana.dom'), BJ_T.t('agenda.js.datas.semana.seg'), BJ_T.t('agenda.js.datas.semana.ter'), BJ_T.t('agenda.js.datas.semana.qua'), BJ_T.t('agenda.js.datas.semana.qui'), BJ_T.t('agenda.js.datas.semana.sex'), BJ_T.t('agenda.js.datas.semana.sab')];
    const SEM_LETRA = [BJ_T.t('agenda.js.datas.letra.dom'), BJ_T.t('agenda.js.datas.letra.seg'), BJ_T.t('agenda.js.datas.letra.ter'), BJ_T.t('agenda.js.datas.letra.qua'), BJ_T.t('agenda.js.datas.letra.qui'), BJ_T.t('agenda.js.datas.letra.sex'), BJ_T.t('agenda.js.datas.letra.sab')];
    const H0 = 7;
    const H1 = 22;
    const CHAVE_VIS = 'bj-agenda-v1-vis';

    const url = {
        datas: raiz.dataset.urlDatas,
        compromissos: raiz.dataset.urlCompromissos,
        criar: raiz.dataset.urlCriar,
        salvar: raiz.dataset.urlSalvar,
        excluir: raiz.dataset.urlExcluir,
        avisar: raiz.dataset.urlAvisar,
        avisarPrazo: raiz.dataset.urlAvisarPrazo,
        mover: raiz.dataset.urlMover,
        status: raiz.dataset.urlStatus,
        pasta: raiz.dataset.urlPasta,
        lista: raiz.dataset.urlLista,
        acrescentar: raiz.dataset.urlAcrescentar,
        renomear: raiz.dataset.urlRenomear,
        ocultar: raiz.dataset.urlOcultar,
        restaurar: raiz.dataset.urlRestaurar,
        feriados: raiz.dataset.urlFeriados,
        googleConectar: raiz.dataset.urlGoogleConectar,
        googleDesconectar: raiz.dataset.urlGoogleDesconectar,
        googleAvisoDispensar: raiz.dataset.urlGoogleAvisoDispensar,
    };
    const lerJson = (texto, padrao) => { try { return JSON.parse(texto || ''); } catch (e) { return padrao; } };
    const eu = lerJson(raiz.dataset.eu, {});
    const equipe = lerJson(raiz.dataset.equipe, []);
    const podeVerPastas = raiz.dataset.podeVerPastas === '1';
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
    const brData = k => BJFormato.data(dataDe(k));
    /* Frase inteira numa chave, com partes em negrito: o parâmetro vai como lugar(nome) e volta como <b>parte</b>,
       tudo escapado, numa passada só. */
    const lugar = k => '\u0001' + k + '\u0001';
    const comNegrito = (texto, partes) => esc(texto).replace(/\u0001(\w+)\u0001/g, (m, k) => '<b>' + esc(partes[k]) + '</b>');

    let tAviso = null;
    function avisar(texto) {
        const el = document.getElementById('agToast');
        el.innerHTML = '<div class="ag-toast" role="status">' + esc(texto) + '</div>';
        clearTimeout(tAviso);
        tAviso = setTimeout(() => { el.innerHTML = ''; }, 3000);
    }

    /* ── Busca de dados por intervalo ───────────────────────────────────── */
    const coberto = (lista, de, ate) => lista.some(([a, b]) => a <= de && b >= ate);

    const emCurso = new Map();
    function umaVez(chave, fazer) {
        if (!emCurso.has(chave)) { emCurso.set(chave, fazer().finally(() => emCurso.delete(chave))); }
        return emCurso.get(chave);
    }

    async function garantir(de, ate) {
        const pedidos = [];
        if (!coberto(s.cobertoDatas, de, ate)) {
            pedidos.push(umaVez('d' + de + ate, () => buscar(url.datas + '?de=' + de + '&ate=' + ate)).then(r => {
                s.ufs = r.ufs;
                s.podeAjustar = !!r.podeAjustar;
                s.temAjustes = !!r.temAjustes;
                r.datas.forEach(d => s.datas.set(d.id, d));
                s.cobertoDatas.push([de, ate]);
            }));
        }
        if (!coberto(s.cobertoItens, de, ate)) {
            const escopo = s.escopo;
            pedidos.push(umaVez('c' + escopo + de + ate, () => buscar(url.compromissos + '?de=' + de + '&ate=' + ate + '&escopo=' + escopo)).then(r => {
                if (escopo !== s.escopo) { return; }
                if (r.contagens) { s.contagens = r.contagens; }
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

    /* Uma busca só por endpoint quando os intervalos cabem nos 400 dias do servidor; o que já está
       coberto não é pedido de novo. */
    function juntar(lista) {
        const falta = lista.filter(([de, ate]) => !coberto(s.cobertoDatas, de, ate) || !coberto(s.cobertoItens, de, ate));
        if (!falta.length) { return []; }
        const de = falta.map(x => x[0]).sort()[0];
        const ate = falta.map(x => x[1]).sort().slice(-1)[0];
        const dias = (dataDe(ate) - dataDe(de)) / 864e5;

        return dias <= 400 ? [[de, ate]] : falta;
    }

    async function carregar() {
        desenhar();
        try {
            await Promise.all(juntar(intervalos()).map(([de, ate]) => garantir(de, ate)));
        } catch (e) {
            avisar(BJ_T.t('agenda.js.avisos.carregar_falhou'));
        }
        desenhar();
    }

    /* ── Itens unificados ───────────────────────────────────────────────── */
    function todos() {
        const L = [];
        s.datas.forEach(d => L.push({
            id: d.id, d: d.data, t: d.titulo, cal: d.calendario, src: d.calendario, dia: true, h1: '', h2: '',
            obs: d.observacao || (d.fonte && d.calendario === 'fer' ? BJ_T.t('agenda.js.detalhe.fonte', { fonte: d.fonte }) : ''),
            uf: d.uf, tipo: d.tipo, ponto: d.contaNoPonto, editado: d.editado,
        }));
        s.itens.forEach(c => L.push({
            id: c.id, d: c.data, t: c.titulo, cal: c.calendario, src: c.origem === 'agenda' ? 'meus' : c.origem,
            dia: c.dia || !c.h1, h1: c.h1, h2: c.h2, obs: c.observacao || '', meu: c.meu, dono: c.donoNome,
            privado: c.privado, status: c.status, eventoId: c.eventoId, pastaId: c.pastaId, pasta: c.pastaNup, nome: c.donoNome,
            tipo: c.tipo, lembreteMinutos: c.lembreteMinutos, avisados: c.avisados, participantes: c.participantes, repetir: c.repetir,
            repetirAte: c.repetirAte, editavel: c.editavel, inicioOriginal: c.inicioOriginal, fimOriginal: c.fimOriginal, recorrente: c.recorrente, editado: c.editado,
            doGoogle: c.doGoogle,
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

    const horaDe = e => (e.dia ? BJ_T.t('agenda.js.comum.dia_inteiro') : (e.h2 ? BJ_T.t('agenda.js.grade.hora_ate', { inicio: e.h1 || '', fim: e.h2 }) : (e.h1 || '')));
    const tituloNaTela = e => (e.privado ? '🔒 ' : '') + (s.escopo === 'equipe' && e.src === 'meus' && !e.meu && e.dono ? String(e.dono).split(' ')[0] + ': ' : '') + e.t;
    const corDe = e => CAL[e.cal] || CAL.meus;

    const movivel = e => e.src === 'meus' && e.editavel && e.eventoId && !e.repetir && !e.recorrente;
    const arrastar = e => (movivel(e) ? ' draggable="true" data-arrastar="' + esc(e.id) + '"' : '');
    const situacao = e => (e.status === 'cancelado' ? ' is-cancelado' : e.status === 'concluido' ? ' is-concluido' : '');

    function chip(e, semHora) {
        const c = corDe(e);
        return '<div class="ag-chip' + situacao(e) + '"' + arrastar(e) + ' tabindex="0" role="button" data-ev="' + esc(e.id) + '" data-tip="' + esc(e.t + (e.dia ? '' : ' · ' + (e.h1 || ''))) + '" title="' + esc(e.t + (e.dia ? '' : ' · ' + (e.h1 || ''))) + '"'
            + ' style="border-left-color:' + c[1] + ';background:' + c[2] + ';">'
            + (semHora ? '' : '<b>' + esc(horaDe(e)) + '</b>') + '<span>' + esc(tituloNaTela(e)) + '</span></div>';
    }

    /* ── Desenho ────────────────────────────────────────────────────────── */
    function desenhar() {
        const L = visiveis();
        const por = porDia(L);
        desenharResumo();
        desenharMini(porDia(L.filter(e => e.cal !== 'fer')));
        desenharCals();
        document.getElementById('agUfs').textContent = s.ufs === null ? '…' : (s.ufs.join(BJ_T.t('agenda.js.lateral.ufs_separador')) || BJ_T.t('agenda.js.lateral.nenhum_estado'));
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
        if (s.vista === 'mes') { return BJ_T.t('agenda.js.periodo.mes', { mes: maiuscula(MES[r.getMonth()]), ano: String(r.getFullYear()) }); }
        if (s.vista === 'dia') { return BJ_T.t('agenda.js.periodo.dia', { dia: String(r.getDate()), mes: MES[r.getMonth()], ano: String(r.getFullYear()) }); }
        if (s.vista === 'semana') {
            const a = inicioSemana(r);
            const b = soma(a, 6);
            const p = { dia1: String(a.getDate()), mes1: MES[a.getMonth()], dia2: String(b.getDate()), mes2: MES[b.getMonth()], ano: String(b.getFullYear()) };
            return a.getMonth() !== b.getMonth() ? BJ_T.t('agenda.js.periodo.semana_dois_meses', p) : BJ_T.t('agenda.js.periodo.semana', p);
        }
        return BJ_T.t('agenda.js.periodo.lista');
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
            [BJ_T.t('agenda.js.resumo.prazos_hoje'), pz.filter(e => e.d === k0).length, '#c0392b', 'bi-hourglass-split', BJ_T.t('agenda.js.resumo.prazos_hoje_dica'), 'dia', h],
            [BJ_T.t('agenda.js.resumo.amanha'), pz.filter(e => e.d === k1).length, '#c76a12', 'bi-hourglass', BJ_T.t('agenda.js.resumo.amanha_dica'), 'dia', soma(h, 1)],
            [BJ_T.t('agenda.js.resumo.proximos_7'), pz.filter(e => e.d >= k0 && e.d <= k7).length, '#0a6fa0', 'bi-calendar-week', BJ_T.t('agenda.js.resumo.proximos_7_dica', { data: brData(k7) }), 'semana', h],
            [BJ_T.t('agenda.js.resumo.audiencias_hoje'), au.filter(e => e.d === k0).length, '#5b3f9e', 'bi-bank', BJ_T.t('agenda.js.resumo.audiencias_hoje_dica'), 'dia', h],
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
            + '<button type="button" data-mini-nav="-1" title="' + esc(BJ_T.t('agenda.js.mini.mes_anterior')) + '"><i class="bi bi-chevron-left"></i></button><button type="button" data-mini-nav="1" title="' + esc(BJ_T.t('agenda.js.mini.proximo_mes')) + '"><i class="bi bi-chevron-right"></i></button></div>'
            + '<div class="ag-mini-grade">' + SEM_LETRA.map(d => '<span class="ag-mini-sem">' + esc(d) + '</span>').join('') + dias + '</div>';
    }

    function desenharCals() {
        // Como no desenho: todos os compromissos meus daquele tipo, em qualquer data (vem do servidor).
        const contagens = s.contagens || {};
        document.getElementById('agCals').innerHTML = Object.entries(CAL).map(([k, v]) => {
            const n = contagens[k] || 0;
            return '<button type="button" class="ag-cal-item" data-cal="' + k + '" aria-pressed="' + (s.V[k] === false ? 'false' : 'true') + '">'
                + '<i class="bi ' + (s.V[k] === false ? 'bi-square' : 'bi-check-square-fill') + '" style="color:' + v[1] + ';"></i><span>' + esc(v[0]) + '</span><span class="ag-cal-n">' + (n || '') + '</span></button>';
        }).join('');
    }

    function desenharSegmentos() {
        document.getElementById('agEscopos').innerHTML = [['minha', BJ_T.t('agenda.js.comum.minha_agenda'), 'bi-person'], ['equipe', BJ_T.t('agenda.js.comum.equipe_escritorio'), 'bi-people']]
            .map(([k, r, ic]) => '<button type="button" data-escopo="' + k + '" class="' + (s.escopo === k ? 'is-on' : '') + '" aria-pressed="' + (s.escopo === k) + '"><i class="bi ' + ic + '"></i>' + esc(r) + '</button>').join('');
        document.getElementById('agVistas').innerHTML = [['dia', BJ_T.t('agenda.js.vista.dia')], ['semana', BJ_T.t('agenda.js.vista.semana')], ['mes', BJ_T.t('agenda.js.vista.mes')], ['lista', BJ_T.t('agenda.js.vista.lista')]]
            .map(([k, r]) => '<button type="button" data-vista-btn="' + k + '" class="' + (s.vista === k ? 'is-on' : '') + '" aria-pressed="' + (s.vista === k) + '">' + esc(r) + '</button>').join('');
    }

    function desenharAniversario(por) {
        const L = (por[iso(hoje0())] || []).filter(e => e.cal === 'aniv');
        document.getElementById('agAniv').innerHTML = L.length
            ? '<div class="ag-aniv"><span class="ag-aniv-emoji">🎉</span><b>' + esc(BJ_T.t('agenda.js.aniversario.hoje', { nomes: L.map(e => String(e.nome || '').split(' ')[0]).join(', ') })) + '</b><span>' + esc(BJ_T.t('agenda.js.aniversario.parabens')) + '</span></div>'
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
        return '<div class="' + cls + '" data-dia="' + k + '" tabindex="0" role="button" aria-label="' + esc(brData(k)) + '">'
            + (aniv ? '<span class="ag-cel-balao" aria-hidden="true">🎈</span>' : '')
            + (aniv && ehHoje ? '<span class="ag-cel-confete" aria-hidden="true"></span>' : '')
            + '<div class="ag-cel-topo"><span class="ag-cel-n">' + d.getDate() + '</span>'
            + (fer ? '<span class="ag-cel-fer" data-ev="' + esc(fer.id) + '" data-tip="' + esc(fer.t) + '" title="' + esc(fer.t) + '">' + esc(fer.t) + '</span>' : '') + '</div>'
            + resto.slice(0, 3).map(e => chip(e)).join('')
            + (resto.length > 3 ? '<button type="button" class="ag-mais" data-ver-dia="' + k + '">' + esc(BJ_T.t('agenda.js.grade.mais', { n: String(resto.length - 3) })) + '</button>' : '')
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
            return '<div class="ag-grade-col-cab' + (k === hk ? ' is-hoje' : '') + '" data-ir-dia="' + k + '"><div>' + SEM[d.getDay()] + '</div><b>' + d.getDate() + '</b>' + diaInt.map(e => chip(e, true)).join('') + '</div>';
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
            return '<div class="ag-bloco' + situacao(e) + '"' + arrastar(e) + ' data-ev="' + esc(e.id) + '" data-tip="' + esc(e.t) + '" title="' + esc(e.t + (e.dia ? '' : ' · ' + (e.h1 || ''))) + '" style="top:' + Math.max(0, (t0 - H0 * 60) * 48 / 60) + 'px;height:' + Math.max(22, (t1 - t0) * 48 / 60 - 2) + 'px;border-left-color:' + c[1] + ';background:' + c[2] + ';">'
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
        const completa = url.lista ? '<div class="ag-lista-completa"><a href="' + esc(url.lista) + '"><i class="bi bi-list-ul"></i>' + esc(BJ_T.t('agenda.js.lista.completa')) + '</a></div>' : '';
        if (!dias.length) { return '<div class="ag-lista-vazia">' + esc(BJ_T.t('agenda.js.lista.vazia')) + '</div>' + completa; }
        return dias.map(k => {
            const d = dataDe(k);
            return '<div class="ag-lista-dia"><div class="ag-lista-data"><b>' + d.getDate() + ' ' + MES[d.getMonth()].slice(0, 3) + '</b><span>' + SEM[d.getDay()] + '</span></div><div>'
                + por[k].map(e => '<div class="ag-lista-item" data-ev="' + esc(e.id) + '"><span class="ag-lista-cor" style="background:' + corDe(e)[1] + ';"></span><b class="ag-lista-hora">' + esc(horaDe(e)) + '</b><span class="ag-lista-tit">' + esc(tituloNaTela(e)) + '</span><span class="ag-lista-cal">' + esc(corDe(e)[0]) + '</span></div>').join('')
                + '</div></div>';
        }).join('') + completa;
    }

    function desenharProximosFeriados() {
        const h = hoje0();
        const ini = iso(h);
        const fim = iso(new Date(h.getFullYear(), h.getMonth() + 2, 0));
        const T = todos();
        const visto = new Set();
        const L = T.filter(e => ['fer', 'est'].includes(e.cal) && e.d >= ini && e.d <= fim).filter(e => { const k = e.d + e.t; if (visto.has(k)) { return false; } visto.add(k); return true; });
        const rec = T.find(e => e.cal === 'forense' && e.d >= ini && e.d <= fim);
        if (rec) { L.push(Object.assign({}, rec, { t: BJ_T.t('agenda.js.feriados.inicio_recesso') })); }
        L.sort((a, b) => a.d.localeCompare(b.d));
        document.getElementById('agPfTit').textContent = BJ_T.t('agenda.js.feriados.titulo', { mes1: MES[h.getMonth()], mes2: MES[(h.getMonth() + 1) % 12] });
        const caixa = document.getElementById('agPf');
        if (!L.length) { caixa.innerHTML = '<div class="ag-pf-vazio">' + esc(BJ_T.t('agenda.js.feriados.vazio')) + '</div>'; return; }
        caixa.innerHTML = '<div class="ag-pf-grade">' + L.map(e => {
            const d = dataDe(e.d);
            const n = Math.round((d - dataDe(iso(h))) / 864e5);
            const falta = n <= 0 ? BJ_T.t('agenda.js.feriados.hoje') : n === 1 ? BJ_T.t('agenda.js.feriados.amanha') : BJ_T.t('agenda.js.feriados.em_dias', { n: String(n) });
            const ponte = d.getDay() === 2 || d.getDay() === 4;
            return '<button type="button" class="ag-pf-item" data-pf="' + e.d + '"><span class="ag-pf-data"><span style="background:' + corDe(e)[1] + ';">' + SEM[d.getDay()] + '</span><b>' + String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '</b></span>'
                + '<span class="ag-pf-txt"><b>' + esc(e.t) + '</b><span>' + esc(tipoDoFeriado(e)) + ' · ' + esc(falta) + '</span></span>'
                + (ponte ? '<span class="ag-ponte" title="' + esc(BJ_T.t('agenda.js.feriados.ponte_dica')) + '">' + esc(BJ_T.t('agenda.js.feriados.ponte')) + '</span>' : '') + '</button>';
        }).join('') + '</div>';
    }

    function tipoDoFeriado(e) {
        if (e.cal === 'est') { return BJ_T.t('agenda.js.tipo_feriado.estadual', { uf: e.uf || '' }); }
        if (e.cal === 'forense') { return BJ_T.t('agenda.js.tipo_feriado.forense'); }
        if (e.tipo === 'ponto_facultativo') { return BJ_T.t('agenda.js.tipo_feriado.ponto_facultativo'); }
        if (e.tipo === 'escritorio') { return BJ_T.t('agenda.js.tipo_feriado.escritorio'); }
        return BJ_T.t('agenda.js.tipo_feriado.nacional');
    }

    /* ── Detalhe ────────────────────────────────────────────────────────── */
    function acharItem(id) {
        return todos().find(e => e.id === id || (e.eventoId && String(e.eventoId) === String(id)));
    }

    function origemDe(e) {
        const base = origemBase(e);
        const editado = e.editado ? ' ' + BJ_T.t('agenda.js.origem.nome_alterado') : '';
        const dica = DO_SISTEMA.includes(e.src) && s.podeAjustar ? ' ' + BJ_T.t('agenda.js.origem.dica_botao_direito') : '';
        return base + editado + dica;
    }

    function origemBase(e) {
        const ponto = ' ' + (e.ponto ? BJ_T.t('agenda.js.origem.conta_no_ponto') : BJ_T.t('agenda.js.origem.nao_conta_no_ponto'));
        if (e.tipo === 'acrescentada') { return BJ_T.t('agenda.js.origem.acrescentada'); }
        if (e.src === 'pasta') { return BJ_T.t('agenda.js.origem.pasta'); }
        if (e.src === 'fer' && e.tipo === 'escritorio') { return BJ_T.t('agenda.js.origem.feriado_escritorio'); }
        if (e.src === 'fer' && e.tipo === 'ponto_facultativo') { return BJ_T.t('agenda.js.origem.ponto_facultativo') + ponto; }
        if (e.src === 'fer') { return BJ_T.t('agenda.js.origem.feriado_nacional') + ponto; }
        if (e.src === 'est') { return BJ_T.t('agenda.js.origem.feriado_estadual', { uf: e.uf || '' }) + ponto; }
        if (e.src === 'forense') { return BJ_T.t('agenda.js.origem.recesso'); }
        if (e.src === 'com') { return BJ_T.t('agenda.js.origem.comemorativa'); }
        if (e.src === 'trib') { return BJ_T.t('agenda.js.origem.tribunais'); }
        if (e.src === 'aniv') { return BJ_T.t('agenda.js.origem.aniversario'); }
        if (e.doGoogle) { return BJ_T.t('agenda.js.origem.google'); }
        return BJ_T.t('agenda.js.origem.bluejus');
    }

    function googleUrl(e) {
        const dt = (d, h) => d.replace(/-/g, '') + (h ? 'T' + h.replace(':', '') + '00' : '');
        const fim = e.dia ? iso(soma(dataDe(e.d), 1)).replace(/-/g, '') : dt(e.d, e.h2 || e.h1);
        return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + encodeURIComponent(e.t)
            + '&dates=' + (e.dia ? dt(e.d) : dt(e.d, e.h1)) + '/' + fim
            + '&details=' + encodeURIComponent((e.obs || '') + (e.pasta ? '\n' + BJ_T.t('agenda.js.detalhe.google_pasta', { nup: e.pasta }) : ''));
    }

    function abrir(e) {
        const fimOutroDia = e.fimOriginal && e.inicioOriginal && e.fimOriginal.slice(0, 10) !== e.inicioOriginal.slice(0, 10) ? e.fimOriginal : '';
        const ate = fimOutroDia ? ' ' + (e.dia ? BJ_T.t('agenda.js.detalhe.ate_data', { data: brData(fimOutroDia.slice(0, 10)) }) : BJ_T.t('agenda.js.detalhe.ate_data_hora', { data: brData(fimOutroDia.slice(0, 10)), hora: fimOutroDia.slice(11, 16) })) : '';
        const quando = dataDe(e.d).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
            + (e.dia || !e.h1 ? ' · ' + BJ_T.t('agenda.js.detalhe.dia_inteiro') + ate : ' · ' + e.h1 + (ate || (e.h2 ? ' ' + BJ_T.t('agenda.js.detalhe.as_hora', { hora: e.h2 }) : '')));
        const linhas = [
            '<b>' + esc(e.t) + '</b>',
            '<span><i class="bi bi-clock"></i>' + esc(quando) + '</span>',
            '<span><i class="bi bi-tag"></i>' + esc(corDe(e)[0]) + '</span>',
        ];
        if (e.src === 'meus' && !e.meu && e.dono) { linhas.push('<span><i class="bi bi-person"></i>' + esc(BJ_T.t('agenda.js.detalhe.agenda_de', { nome: e.dono })) + '</span>'); }
        if (e.src === 'meus') { linhas.push('<span><i class="bi bi-eye"></i>' + esc(e.privado ? BJ_T.t('agenda.js.detalhe.so_voce_ve') : BJ_T.t('agenda.js.detalhe.visivel_equipe')) + '</span>'); }
        if (e.repetir || e.recorrente) {
            const frequencia = ({ diario: BJ_T.t('agenda.js.detalhe.frequencia.diario'), semanal: BJ_T.t('agenda.js.detalhe.frequencia.semanal'), mensal: BJ_T.t('agenda.js.detalhe.frequencia.mensal'), anual: BJ_T.t('agenda.js.detalhe.frequencia.anual') })[e.repetir] || BJ_T.t('agenda.js.detalhe.frequencia.sim');
            linhas.push('<span><i class="bi bi-arrow-repeat"></i>' + esc(e.repetirAte ? BJ_T.t('agenda.js.detalhe.repete_ate', { frequencia, data: brData(e.repetirAte) }) : BJ_T.t('agenda.js.detalhe.repete', { frequencia })) + '</span>');
        }
        if (e.status === 'concluido' || e.status === 'cancelado') { linhas.push('<span class="ag-modal-situacao is-' + e.status + '"><i class="bi ' + (e.status === 'concluido' ? 'bi-check2-circle' : 'bi-x-circle') + '"></i>' + esc(e.status === 'concluido' ? BJ_T.t('agenda.js.situacao.concluido') : BJ_T.t('agenda.js.situacao.cancelado')) + '</span>'); }
        if (e.pastaId) { linhas.push('<a href="' + esc(comId(url.pasta, e.pastaId)) + '"><i class="bi bi-folder2-open"></i>' + esc(BJ_T.t('agenda.js.detalhe.abrir_pasta', { nup: e.pasta || '' })) + '</a>'); }
        if (e.obs) { linhas.push('<span class="ag-modal-obs">' + esc(e.obs) + '</span>'); }
        if ((e.avisados || []).length) { linhas.push('<span class="ag-modal-avisados"><i class="bi bi-chat-dots"></i>' + esc(BJ_T.t('agenda.js.detalhe.avisados_chat', { nomes: nomesAvisados(e.avisados) })) + '</span>'); }
        linhas.push('<div class="ag-modal-origem">' + esc(origemDe(e)) + '</div>');
        if (s.podeAjustar && url.feriados && DO_SISTEMA.includes(e.src) && e.src !== 'aniv' && !e.ponto) {
            linhas.push('<a class="ag-modal-feriados" href="' + esc(url.feriados) + '"><i class="bi bi-box-arrow-up-right"></i>' + esc(BJ_T.t('agenda.js.detalhe.cadastrar_feriado')) + '</a>');
        }
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.detalhe.titulo')) + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>' + esc(BJ_T.t('agenda.js.detalhe.titulo')) + '</b><button type="button" class="ag-modal-x" data-fechar title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo">' + linhas.join('') + '</div>'
            + '<div class="ag-modal-rodape">' + botoesDoDetalhe(e) + '</div></div></div>';
        const x = document.querySelector('#agModal .ag-modal-x');
        if (x) { x.focus(); }
    }

    function botoesDoDetalhe(e) {
        const fechar = '<button type="button" class="ag-mbtn is-fim" data-fechar><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.fechar')) + '</button>';
        if (DO_SISTEMA.includes(e.src)) { return fechar; }
        const google = '<a class="ag-mbtn" href="' + esc(googleUrl(e)) + '" target="_blank" rel="noopener"><i class="bi bi-google"></i>' + esc(BJ_T.t('agenda.js.detalhe.google_agenda')) + '</a>';
        const det = (acao, rotulo, ic, classe) => '<button type="button" class="ag-mbtn' + classe + '" data-det="' + acao + '" data-ev="' + esc(e.id) + '"><i class="bi ' + ic + '"></i>' + esc(rotulo) + '</button>';
        /* Desenho 09, L238: todo item que não é data do sistema, inclusive prazo e audiência de pasta. */
        const avisarBtn = (e.eventoId && !e.privado) || prazoId(e) ? det('avisar', BJ_T.t('agenda.js.acoes.avisar_chat'), 'bi-chat-dots', '') : '';
        if (e.src === 'meus' && e.editavel && e.eventoId) {
            const sit = (e.status === 'agendado' || !e.status ? det('concluir', BJ_T.t('agenda.js.acoes.concluir'), 'bi-check2-circle', '') : '')
                + (e.status !== 'cancelado' ? det('cancelar', BJ_T.t('agenda.js.acoes.cancelar_compromisso'), 'bi-x-circle', '') : '')
                + (e.status && e.status !== 'agendado' ? det('reabrir', BJ_T.t('agenda.js.acoes.reabrir'), 'bi-arrow-counterclockwise', '') : '');
            return google + avisarBtn + sit + det('excluir', BJ_T.t('agenda.js.comum.excluir'), 'bi-trash3', ' is-perigo') + det('editar', BJ_T.t('agenda.js.acoes.editar'), 'bi-pencil', ' is-pri is-fim');
        }

        return google + avisarBtn + fechar;
    }

    function fecharModal() { document.getElementById('agModal').innerHTML = ''; document.getElementById('agPerg').innerHTML = ''; f = null; at = null; formData = null; }

    /* ── Menu de botão direito ──────────────────────────────────────────── */
    function abrirMenu(x, y, dia, e) {
        const itens = [];
        if (e && !DO_SISTEMA.includes(e.src)) { itens.push([BJ_T.t('agenda.js.menu.abrir_compromisso'), 'bi-box-arrow-up-right', 'abrir']); }
        if (e && DO_SISTEMA.includes(e.src)) { itens.push([BJ_T.t('agenda.js.menu.ver_detalhes'), 'bi-info-circle', 'abrir']); }
        if (e && DO_SISTEMA.includes(e.src) && s.podeAjustar) {
            itens.push([BJ_T.t('agenda.js.menu.editar_nome'), 'bi-pencil', 'renomear']);
            itens.push(String(e.id).startsWith('x-') ? [BJ_T.t('agenda.js.comum.excluir'), 'bi-eye-slash', 'ocultar', 'perigo'] : [BJ_T.t('agenda.js.menu.ocultar'), 'bi-eye-slash', 'ocultar', 'perigo']);
        }
        if (s.podeAjustar) { itens.push([BJ_T.t('agenda.js.menu.acrescentar_em', { data: brData(dia) }), 'bi-plus-lg', 'acrescentar']); }
        itens.push([BJ_T.t('agenda.js.menu.novo_em', { data: brData(dia) }), 'bi-calendar-plus', 'novo']);
        if (s.podeAjustar && s.temAjustes) { itens.push([BJ_T.t('agenda.js.menu.restaurar'), 'bi-arrow-counterclockwise', 'restaurar']); }
        const el = document.getElementById('agCtx');
        el.innerHTML = '<div class="ag-ctx-fundo" data-ctx-fechar></div><div class="ag-ctx" role="menu" style="left:' + Math.min(x, innerWidth - 250) + 'px;top:' + Math.min(y, innerHeight - 260) + 'px;">'
            + '<div class="ag-ctx-tit">' + esc(e ? e.t : brData(dia)) + '</div>'
            + itens.map(([r, ic, acao, perigo]) => '<button type="button" class="ag-ctx-item' + (perigo ? ' is-perigo' : '') + '" role="menuitem" data-ctx="' + acao + '"><i class="bi ' + ic + '"></i>' + esc(r) + '</button>').join('') + '</div>';
        el.dataset.dia = dia;
        el.dataset.ev = e ? e.id : '';
        const primeiro = el.querySelector('.ag-ctx-item');
        if (primeiro) { primeiro.focus(); }
    }

    function fecharMenu() { document.getElementById('agCtx').innerHTML = ''; }

    /* ── Modal de criar e editar (V16, V17) ─────────────────────────────── */
    const TIPOS = ['meus', 'aud', 'reun', 'prazo'];
    const LEMBRETE_PADRAO = { aud: '60', reun: '10', prazo: '15', meus: '15' };
    const LEMBRETES = [['0', BJ_T.t('agenda.js.form.lembrete.sem')], ['10', BJ_T.t('agenda.js.form.lembrete.min_10')], ['15', BJ_T.t('agenda.js.form.lembrete.min_15')], ['30', BJ_T.t('agenda.js.form.lembrete.min_30')], ['60', BJ_T.t('agenda.js.form.lembrete.hora_1')], ['1440', BJ_T.t('agenda.js.form.lembrete.dia_1')]];
    const REPETIR = [['', BJ_T.t('agenda.js.form.repetir.nao')], ['diario', BJ_T.t('agenda.js.form.repetir.diario')], ['semanal', BJ_T.t('agenda.js.form.repetir.semanal')], ['mensal', BJ_T.t('agenda.js.form.repetir.mensal')], ['anual', BJ_T.t('agenda.js.form.repetir.anual')]];
    const nomeCurto = n => String(n || '').split(' ').slice(0, 2).join(' ');
    const daEquipe = () => equipe.filter(u => u.id !== eu.id);
    let f = null;
    let editId = null;
    let at = null;

    function novoEm(dia, hora) {
        const h2 = hora ? String(Number(hora.slice(0, 2)) + 1).padStart(2, '0') + ':00' : '10:00';
        f = { t: '', d: dia, h1: hora || '09:00', h2, dia: false, dias: 0, tipo: 'meus', vis: 'equipe', pasta: '', lem: '15', obs: '', avis: [], rep: '', repAte: '', part: [], lemManual: false, serie: false };
        editId = null;
        desenharForm('');
    }

    function editar(e) {
        const ini = e.inicioOriginal || (e.d + 'T' + (e.h1 || '09:00'));
        const fim = e.fimOriginal || '';
        f = {
            t: e.t, d: ini.slice(0, 10), h1: e.dia ? '09:00' : ini.slice(11, 16), h2: e.dia ? '10:00' : fim.slice(11, 16),
            dia: e.dia, tipo: e.tipo || (TIPOS.includes(e.cal) ? e.cal : 'meus'), vis: e.privado ? 'privado' : 'equipe',
            pasta: e.pasta || '', lem: String(e.lembreteMinutos ?? LEMBRETE_PADRAO[e.tipo || 'meus']), obs: e.obs || '',
            avis: (e.avisados || []).slice(), rep: e.repetir || '', repAte: e.repetirAte || '', part: (e.participantes || []).slice(), lemManual: true,
            dias: fim ? Math.max(0, Math.round((dataDe(fim.slice(0, 10)) - dataDe(ini.slice(0, 10))) / 864e5)) : 0, serie: !!e.repetir,
        };
        editId = e.eventoId;
        desenharForm('');
    }

    function rotulo(r, campo) { return '<label class="ag-f-rot">' + esc(r) + campo + '</label>'; }

    function htmlForm(erro) {
        const tipos = TIPOS.map(k => {
            const on = f.tipo === k;
            return '<button type="button" class="ag-f-tipo" data-f-tipo="' + k + '" style="border-color:' + (on ? CAL[k][1] : '') + ';background:' + (on ? CAL[k][2] : '') + ';color:' + (on ? CAL[k][1] : '') + ';"><span style="background:' + CAL[k][1] + ';"></span>' + esc(k === 'prazo' ? BJ_T.t('agenda.js.form.tipo_prazo') : CAL[k][0]) + '</button>';
        }).join('');
        const vis = [['equipe', BJ_T.t('agenda.js.comum.equipe_escritorio'), 'bi-people', f.part.length ? BJ_T.t('agenda.js.form.vis.participantes_veem') : BJ_T.t('agenda.js.form.vis.todos_veem')], ['privado', BJ_T.t('agenda.js.form.vis.so_eu'), 'bi-lock', BJ_T.t('agenda.js.form.vis.ninguem_ve')]].map(([k, r, ic, d]) =>
            '<button type="button" class="ag-f-vis' + (f.vis === k ? ' is-on' : '') + '" data-f-vis="' + k + '"><i class="bi ' + ic + '"></i><span><b>' + esc(r) + '</b><span>' + esc(d) + '</span></span></button>').join('');
        const opcoes = (lista, v) => lista.map(([k, r]) => '<option value="' + k + '"' + (String(v) === k ? ' selected' : '') + '>' + esc(r) + '</option>').join('');
        const part = daEquipe().map(u => '<button type="button" class="ag-f-pessoa' + (f.part.includes(u.id) ? ' is-on' : '') + '" data-f-part="' + u.id + '">' + esc(nomeCurto(u.nome)) + '</button>').join('');
        const avisados = nomesAvisados(f.avis);
        return '<input class="ag-f-titulo" data-f="t" value="' + esc(f.t) + '" placeholder="' + esc(BJ_T.t('agenda.js.form.titulo_placeholder')) + '" maxlength="255">'
            + '<div class="ag-f-tipos">' + tipos + '</div>'
            + '<div class="ag-f-grade">' + rotulo(BJ_T.t('agenda.js.form.rotulo.data'), '<input type="date" data-f="d" value="' + esc(f.d) + '">')
            + rotulo(BJ_T.t('agenda.js.form.rotulo.inicio'), '<input type="time" data-f="h1" value="' + esc(f.h1) + '"' + (f.dia ? ' disabled' : '') + '>')
            + rotulo(BJ_T.t('agenda.js.form.rotulo.fim'), '<input type="time" data-f="h2" value="' + esc(f.h2) + '"' + (f.dia ? ' disabled' : '') + '>') + '</div>'
            + (f.dias > 0 ? '<div class="ag-f-nota"><i class="bi bi-info-circle"></i> ' + esc(BJ_T.t('agenda.js.form.termina_em', { data: brData(iso(soma(dataDe(f.d), f.dias))), dias: String(f.dias) })) + ' <button type="button" class="ag-f-link" data-f-mesmodia>' + esc(BJ_T.t('agenda.js.form.mesmo_dia')) + '</button></div>' : '')
            + (f.serie ? '<div class="ag-f-nota"><i class="bi bi-arrow-repeat"></i> ' + esc(BJ_T.t('agenda.js.form.serie')) + '</div>' : '')
            + '<div class="ag-f-viss">' + vis + '</div>'
            + '<label class="ag-f-check"><input type="checkbox" data-f-dia' + (f.dia ? ' checked' : '') + '>' + esc(BJ_T.t('agenda.js.comum.dia_inteiro')) + '</label>'
            + '<div class="ag-f-grade2">' + (podeVerPastas ? rotulo(BJ_T.t('agenda.js.form.rotulo.pasta'), '<input data-f="pasta" value="' + esc(f.pasta) + '" placeholder="' + esc(BJ_T.t('agenda.js.form.pasta_placeholder')) + '">') : '')
            + rotulo(BJ_T.t('agenda.js.form.rotulo.lembrete'), '<select data-f="lem">' + opcoes(LEMBRETES, f.lem) + '</select>') + '</div>'
            + '<div class="ag-f-grade2">' + rotulo(BJ_T.t('agenda.js.form.rotulo.repetir'), '<select data-f="rep">' + opcoes(REPETIR, f.rep) + '</select>')
            + rotulo(BJ_T.t('agenda.js.form.rotulo.repetir_ate'), '<input type="date" data-f="repAte" value="' + esc(f.repAte) + '"' + (f.rep ? '' : ' disabled') + '>') + '</div>'
            + (part ? '<div class="ag-f-bloco"><span class="ag-f-rot-linha">' + esc(BJ_T.t('agenda.js.form.participantes')) + ' <span class="ag-f-dica">' + esc(f.vis === 'privado' ? BJ_T.t('agenda.js.form.participantes_dica_privado') : BJ_T.t('agenda.js.form.participantes_dica')) + '</span></span><div class="ag-f-pessoas">' + part + '</div></div>' : '')
            + '<div class="ag-f-avisar"><span class="ag-f-rot-linha">' + esc(BJ_T.t('agenda.js.comum.avisar_chat_ia')) + ' <span class="ag-f-dica">' + esc(f.vis === 'privado' ? BJ_T.t('agenda.js.form.avisar_dica_privado') : BJ_T.t('agenda.js.form.avisar_dica')) + '</span></span>'
            + '<textarea data-f="obs" rows="2" placeholder="' + esc(BJ_T.t('agenda.js.form.obs_placeholder')) + '">' + esc(f.obs) + '</textarea>'
            + '<div id="agAt"></div>'
            + (avisados ? '<div class="ag-f-avisados"><i class="bi bi-chat-dots"></i> ' + esc(BJ_T.t('agenda.js.form.serao_avisados', { nomes: avisados })) + '</div>' : '') + '</div>'
            + (erro ? '<div class="ag-f-erro" role="alert">' + esc(erro) + '</div>' : '');
    }

    function desenharForm(erro) {
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + esc(editId ? BJ_T.t('agenda.js.form.editar_titulo') : BJ_T.t('agenda.js.form.novo_titulo')) + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>' + esc(editId ? BJ_T.t('agenda.js.form.editar_titulo') : BJ_T.t('agenda.js.form.novo_titulo')) + '</b><button type="button" class="ag-modal-x" data-fechar title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo ag-form">' + htmlForm(erro) + '</div>'
            + '<div class="ag-modal-rodape"><button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri is-fim" data-f-salvar><i class="bi bi-check2"></i>' + esc(BJ_T.t('agenda.js.comum.salvar')) + '</button></div></div></div>';
        const tit = document.querySelector('#agModal [data-f="t"]');
        if (tit && !erro) { tit.focus(); }
        const ta = document.querySelector('#agModal textarea');
        if (ta) { ajustarAltura(ta); }
    }

    function ajustarAltura(el) { el.style.height = 'auto'; el.style.height = Math.min(240, Math.max(56, el.scrollHeight + 2)) + 'px'; }

    function nomesAvisados(ids) {
        return equipe.filter(u => ids.includes(u.id)).map(u => '@' + String(u.nome).split(' ')[0]).join(', ');
    }

    /* @colega no campo de observações: abre a lista, navega com setas, escolhe com Enter ou Tab. */
    function lerArroba(ta) {
        const pos = ta.selectionStart || ta.value.length;
        const m = ta.value.slice(0, pos).match(/(^|\s)@([\w\u00C0-\u00FF]*)$/);
        at = m ? { q: m[2].toLowerCase(), ini: pos - m[2].length - 1, i: at && at.q === m[2].toLowerCase() ? at.i : 0 } : null;
        f.avis = Array.from(new Set(f.avis.filter(id => (equipe.find(u => u.id === id) || {}).nome && ta.value.includes('@' + nomeCurto(equipe.find(u => u.id === id).nome))).concat(
            daEquipe().filter(u => ta.value.includes('@' + nomeCurto(u.nome))).map(u => u.id))));
        desenharArroba(ta);
    }

    function candidatos() {
        if (!at) { return []; }
        return daEquipe().filter(u => String(u.nome).toLowerCase().split(' ').some(p => p.startsWith(at.q)) || String(u.nome).toLowerCase().includes(at.q)).slice(0, 8);
    }

    function desenharArroba(ta) {
        const caixa = document.getElementById('agAt');
        const av = document.querySelector('#agModal .ag-f-avisados');
        const nomes = nomesAvisados(f.avis);
        if (av) { av.innerHTML = nomes ? '<i class="bi bi-chat-dots"></i> ' + esc(BJ_T.t('agenda.js.form.serao_avisados', { nomes })) : ''; }
        else if (nomes) { caixa.insertAdjacentHTML('afterend', '<div class="ag-f-avisados"><i class="bi bi-chat-dots"></i> ' + esc(BJ_T.t('agenda.js.form.serao_avisados', { nomes })) + '</div>'); }
        if (!at) { caixa.innerHTML = ''; return; }
        const L = candidatos();
        const r = ta.getBoundingClientRect();
        const h = Math.min(200, Math.max(1, L.length) * 38 + 8);
        const embaixo = innerHeight - r.bottom - 8 >= h;
        caixa.innerHTML = '<div class="ag-at" role="listbox" style="left:' + Math.max(8, r.left) + 'px;width:' + Math.min(innerWidth - 16, r.width) + 'px;top:' + (embaixo ? r.bottom + 4 : Math.max(8, r.top - h - 4)) + 'px;max-height:' + h + 'px;">'
            + (L.length ? L.map((u, i) => '<button type="button" role="option" class="ag-at-item' + (i === at.i % L.length ? ' is-on' : '') + '" data-at="' + u.id + '"><span>' + esc(nomeCurto(u.nome).split(' ').map(p => p[0]).join('').toUpperCase()) + '</span>' + esc(u.nome) + '</button>').join('')
                : '<div class="ag-at-vazio">' + esc(BJ_T.t('agenda.js.form.arroba_vazio')) + '</div>') + '</div>';
    }

    function escolherArroba(id) {
        const ta = document.querySelector('#agModal textarea');
        const u = equipe.find(x => x.id === id);
        if (!ta || !u || !at) { return; }
        const nm = nomeCurto(u.nome);
        const resto = ta.value.slice(at.ini);
        const fimPalavra = resto.search(/\s|$/);
        ta.value = ta.value.slice(0, at.ini) + '@' + nm + ' ' + ta.value.slice(at.ini + (fimPalavra < 0 ? resto.length : fimPalavra));
        f.obs = ta.value;
        f.avis = Array.from(new Set(f.avis.concat(u.id)));
        const p = at.ini + nm.length + 2;
        at = null;
        desenharArroba(ta);
        ta.focus();
        ta.setSelectionRange(p, p);
    }

    async function postar(endereco, corpo) {
        const resp = await fetch(endereco, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: JSON.stringify(corpo) });
        const json = await resp.json().catch(() => ({}));
        if (!resp.ok) { throw new Error(json.erro || BJ_T.t('agenda.js.erros.salvar')); }
        return json;
    }

    function erroLocal() {
        if (!f.t.trim()) { return BJ_T.t('agenda.js.erros.titulo'); }
        if (!f.d) { return BJ_T.t('agenda.js.erros.data'); }
        if (!f.dia && !f.dias && f.h2 && f.h2 <= f.h1) { return BJ_T.t('agenda.js.erros.fim_antes'); }
        if (f.rep && f.repAte && f.repAte < f.d) { return BJ_T.t('agenda.js.erros.repeticao_antes'); }
        return '';
    }

    async function salvar(perguntado) {
        const erro = erroLocal();
        if (erro) { desenharForm(erro); return; }
        if (!editId && s.escopo === 'minha' && !perguntado) { perguntarCompartilhar(); return; }
        const corpo = { titulo: f.t, tipo: f.tipo, data: f.d, diaInteiro: f.dia, inicio: f.h1, fim: f.h2, visibilidade: f.vis, pasta: f.pasta.trim(), lembreteMinutos: Number(f.lem), observacao: f.obs, avisados: f.avis, repetir: f.rep, repetirAte: f.rep ? f.repAte : '', participantes: f.part, dias: f.dias };
        try {
            if (window.BJLembretes) { window.BJLembretes.permitir(); }
            const r = await postar(editId ? comId(url.salvar, editId) : url.criar, corpo);
            const serie = f.serie;
            fecharModal();
            f = { serie };
            recarregarEm(f.serie ? s.ref : dataDe(r.data));
            f = null;
            const lembra = !corpo.diaInteiro && corpo.inicio && corpo.lembreteMinutos !== 0;
            avisar(BJ_T.t('agenda.js.avisos.salvo') + (lembra ? ' ' + BJ_T.t('agenda.js.avisos.lembrete') : '') + (r.avisados ? ' ' + BJ_T.t('agenda.js.avisos.pessoas_avisadas', { n: String(r.avisados) }) : ''));
        } catch (e) {
            desenharForm(e.message);
        }
    }

    function perguntarCompartilhar() {
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.compartilhar.titulo')) + '">'
            + '<div class="ag-perg-cab"><i class="bi bi-people"></i> ' + esc(BJ_T.t('agenda.js.compartilhar.titulo')) + '</div>'
            + '<div class="ag-perg-txt">' + comNegrito(BJ_T.t('agenda.js.compartilhar.texto', { agenda: lugar('agenda'), titulo: lugar('titulo'), equipe: lugar('equipe') }), { agenda: BJ_T.t('agenda.js.comum.minha_agenda'), titulo: f.t, equipe: BJ_T.t('agenda.js.comum.equipe_escritorio') }) + '</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-perg="privado"><i class="bi bi-lock"></i>' + esc(BJ_T.t('agenda.js.compartilhar.nao')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-perg="equipe"><i class="bi bi-people"></i>' + esc(BJ_T.t('agenda.js.compartilhar.sim')) + '</button></div></div></div>';
        const sim = document.querySelector('#agPerg [data-perg="equipe"]');
        if (sim) { sim.focus(); }
    }

    function responderPergunta(vis) {
        document.getElementById('agPerg').innerHTML = '';
        if (!f) { return; }
        f.vis = vis;
        salvar(true);
    }

    async function excluirItem(e) {
        if (!confirm(e.repetir ? BJ_T.t('agenda.js.confirmar.excluir_serie') : BJ_T.t('agenda.js.confirmar.excluir'))) { return; }
        try {
            await postar(comId(url.excluir, e.eventoId), {});
            fecharModal();
            recarregarEm(s.ref);
            avisar(BJ_T.t('agenda.js.avisos.excluido'));
        } catch (x) { avisar(x.message); }
    }

    let escolhaAviso = [];

    function avisarItem(e) {
        escolhaAviso = [];
        desenharEscolhaAviso(e);
    }

    function desenharEscolhaAviso(e) {
        const ja = e.avisados || [];
        const pessoas = daEquipe().map(u => '<button type="button" class="ag-f-pessoa' + (ja.includes(u.id) || escolhaAviso.includes(u.id) ? ' is-on' : '') + '"' + (ja.includes(u.id) ? ' disabled title="' + esc(BJ_T.t('agenda.js.aviso_chat.ja_avisado')) + '"' : '') + ' data-av-p="' + u.id + '" data-ev="' + esc(e.id) + '">' + esc(nomeCurto(u.nome)) + '</button>').join('');
        const corpo = document.querySelector('#agModal .ag-modal-corpo');
        const rodape = document.querySelector('#agModal .ag-modal-rodape');
        if (!corpo || !rodape) { return; }
        let bloco = corpo.querySelector('.ag-av');
        if (!bloco) { corpo.insertAdjacentHTML('beforeend', '<div class="ag-av ag-f-bloco"></div>'); bloco = corpo.querySelector('.ag-av'); }
        bloco.innerHTML = '<span class="ag-f-rot-linha">' + esc(BJ_T.t('agenda.js.comum.avisar_chat_ia')) + ' <span class="ag-f-dica">' + esc(BJ_T.t('agenda.js.aviso_chat.dica')) + '</span></span><div class="ag-f-pessoas">' + (pessoas || '<span class="ag-f-dica">' + esc(BJ_T.t('agenda.js.aviso_chat.sem_colegas')) + '</span>') + '</div>';
        rodape.innerHTML = '<button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button><button type="button" class="ag-mbtn is-pri is-fim" data-av-ok data-ev="' + esc(e.id) + '"' + (escolhaAviso.length ? '' : ' disabled') + '><i class="bi bi-chat-dots"></i>' + esc(escolhaAviso.length ? BJ_T.t('agenda.js.aviso_chat.avisar_n', { n: String(escolhaAviso.length) }) : BJ_T.t('agenda.js.aviso_chat.avisar')) + '</button>';
    }

    /* Prazo de pasta vem com id "pz-<id do prazo>". */
    function prazoId(e) {
        const m = e.src === 'pasta' ? /^pz-(\d+)$/.exec(String(e.id)) : null;

        return m ? m[1] : null;
    }

    async function confirmarAviso(e) {
        try {
            const r = await postar(prazoId(e) ? comId(url.avisarPrazo, prazoId(e)) : comId(url.avisar, e.eventoId), { ids: escolhaAviso });
            fecharModal();
            recarregarEm(s.ref);
            avisar(r.avisados ? BJ_T.t('agenda.js.avisos.pessoas_avisadas', { n: String(r.avisados) }) : BJ_T.t('agenda.js.avisos.ja_avisadas'));
        } catch (x) { avisar(x.message); }
    }

    async function mudarSituacao(e, status) {
        const serie = e.repetir || e.recorrente ? ' ' + BJ_T.t('agenda.js.confirmar.vale_serie') : '';
        const temParticipantes = (e.participantes || []).some(id => id !== eu.id);
        const pergunta = { concluido: BJ_T.t('agenda.js.confirmar.concluir'), agendado: BJ_T.t('agenda.js.confirmar.reabrir'), cancelado: BJ_T.t('agenda.js.confirmar.cancelar') + (temParticipantes ? ' ' + BJ_T.t('agenda.js.confirmar.participantes_avisados') : '') }[status];
        if ((serie || status === 'cancelado') && !confirm(pergunta + serie)) { return; }
        try {
            await postar(comId(url.status, e.eventoId), { status });
            fecharModal();
            recarregarEm(s.ref);
            avisar({ concluido: BJ_T.t('agenda.js.avisos.concluido'), cancelado: BJ_T.t('agenda.js.avisos.cancelado'), agendado: BJ_T.t('agenda.js.avisos.reaberto') }[status]);
        } catch (x) { avisar(x.message); }
    }

    /* Arrastar e soltar: no Mês troca o dia; na Semana e no Dia, o dia e a hora. A duração fica. */
    let arrastado = null;
    raiz.addEventListener('dragstart', ev => {
        const el = ev.target.closest('[data-arrastar]');
        if (!el) { return; }
        arrastado = el.dataset.arrastar;
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', arrastado);
        el.classList.add('is-arrastando');
    });
    raiz.addEventListener('dragend', ev => {
        const el = ev.target.closest('[data-arrastar]');
        if (el) { el.classList.remove('is-arrastando'); }
        raiz.querySelectorAll('.is-alvo').forEach(x => x.classList.remove('is-alvo'));
        arrastado = null;
    });
    /* Onde caiu: célula do Mês, ou a coluna da Semana/Dia (hora pela altura, de meia em meia hora). */
    function destinoDoSoltar(ev) {
        const cel = ev.target.closest('.ag-cel');
        if (cel) { return { el: cel, dia: cel.dataset.dia, hora: '' }; }
        const col = ev.target.closest('.ag-grade-col');
        const slot0 = col && col.querySelector('.ag-slot');
        if (!slot0) { return null; }
        const meias = Math.max(0, Math.min((H1 - H0) * 2 - 1, Math.floor((ev.clientY - col.getBoundingClientRect().top) / 24)));
        const hora = String(H0 + Math.floor(meias / 2)).padStart(2, '0') + ':' + (meias % 2 ? '30' : '00');
        return { el: col.querySelectorAll('.ag-slot')[Math.floor(meias / 2)], dia: slot0.dataset.dia, hora };
    }

    raiz.addEventListener('dragover', ev => {
        const destino = arrastado && destinoDoSoltar(ev);
        const alvo = destino && destino.el;
        if (!alvo) { return; }
        ev.preventDefault();
        raiz.querySelectorAll('.is-alvo').forEach(x => { if (x !== alvo) { x.classList.remove('is-alvo'); } });
        alvo.classList.add('is-alvo');
    });
    raiz.addEventListener('drop', async ev => {
        const destino = arrastado && destinoDoSoltar(ev);
        if (!destino) { return; }
        ev.preventDefault();
        const e = acharItem(arrastado);
        arrastado = null;
        raiz.querySelectorAll('.is-alvo').forEach(x => x.classList.remove('is-alvo'));
        if (!e) { return; }
        // Desloca o compromisso pela diferença entre o dia arrastado e o de destino (o 3º dia de um
        // compromisso de vários dias, solto no dia 10, leva o início para o dia 8, como no calendário antigo).
        const inicio = (e.inicioOriginal || e.d).slice(0, 10);
        const novoInicio = iso(soma(dataDe(inicio), Math.round((dataDe(destino.dia) - dataDe(e.d)) / 864e5)));
        try {
            await postar(comId(url.mover, e.eventoId), { data: novoInicio, hora: destino.hora });
            recarregarEm(s.ref);
            avisar(destino.hora && !e.dia ? BJ_T.t('agenda.js.avisos.movido_hora', { data: brData(novoInicio), hora: destino.hora }) : BJ_T.t('agenda.js.avisos.movido', { data: brData(novoInicio) }));
        } catch (x) { avisar(x.message); }
    });

    function recarregarEm(ref) {
        dispatchEvent(new Event('bj-agenda:mudou'));
        // Outras abas abertas também refazem os lembretes, como o `storage` do bj-lembretes.js do desenho.
        try { localStorage.setItem('bj-agenda-mudou', String(Date.now())); } catch (e) { /* sem armazenamento */ }
        s.itens = new Map();
        s.cobertoItens = [];
        s.ref = ref;
        s.ref.setHours(0, 0, 0, 0);
        s.mini = new Date(s.ref.getFullYear(), s.ref.getMonth(), 1);
        carregar();
    }

    raiz.addEventListener('input', ev => {
        const el = ev.target.closest('#agModal [data-f]');
        if (!el || !f) { return; }
        const k = el.dataset.f;
        f[k] = el.value;
        if (k === 'lem') { f.lemManual = true; }
        if (k === 'obs') { ajustarAltura(el); lerArroba(el); }
        if (k === 'rep') { desenharForm(''); }
    });

    raiz.addEventListener('keydown', ev => {
        if (!at || !ev.target.matches('#agModal textarea')) { return; }
        const L = candidatos();
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            at.i = (at.i + (ev.key === 'ArrowDown' ? 1 : -1) + Math.max(1, L.length)) % Math.max(1, L.length);
            desenharArroba(ev.target);
        } else if ((ev.key === 'Enter' || ev.key === 'Tab') && L.length) {
            ev.preventDefault();
            escolherArroba(L[at.i % L.length].id);
        } else if (ev.key === 'Escape') {
            ev.stopPropagation();
            at = null;
            desenharArroba(ev.target);
        }
    }, true);

    raiz.addEventListener('mousedown', ev => {
        const b = ev.target.closest('[data-at]');
        if (b) { ev.preventDefault(); escolherArroba(Number(b.dataset.at)); }
    });

    /* Cliques do formulário e da pergunta; devolve true quando tratou. */
    function cliqueDoModal(t) {
        let a;
        if ((a = t.closest('[data-f-tipo]'))) { f.tipo = a.dataset.fTipo; if (!f.lemManual) { f.lem = LEMBRETE_PADRAO[f.tipo]; } desenharForm(''); return true; }
        if ((a = t.closest('[data-f-vis]'))) { f.vis = a.dataset.fVis; desenharForm(''); return true; }
        if (t.closest('[data-f-dia]')) { f.dia = !f.dia; desenharForm(''); return true; }
        if (t.closest('[data-f-mesmodia]')) { f.dias = 0; desenharForm(''); return true; }
        if ((a = t.closest('[data-f-part]'))) { const id = Number(a.dataset.fPart); f.part = f.part.includes(id) ? f.part.filter(x => x !== id) : f.part.concat(id); desenharForm(''); return true; }
        if (t.closest('[data-f-salvar]')) { salvar(false); return true; }
        if ((a = t.closest('[data-perg]'))) { responderPergunta(a.dataset.perg); return true; }
        if ((a = t.closest('[data-dt-tipo]')) && formData) { formData.cal = a.dataset.dtTipo; document.querySelectorAll('#agModal [data-dt-tipo]').forEach(b => b.classList.toggle('is-on', b === a)); return true; }
        if (t.closest('[data-dt-salvar]') && formData) { salvarData(); return true; }
        if ((a = t.closest('[data-av-p]'))) { const id = Number(a.dataset.avP); escolhaAviso = escolhaAviso.includes(id) ? escolhaAviso.filter(x => x !== id) : escolhaAviso.concat(id); const e = acharItem(a.dataset.ev); if (e) { desenharEscolhaAviso(e); } return true; }
        if ((a = t.closest('[data-av-ok]'))) { const e = acharItem(a.dataset.ev); if (e && escolhaAviso.length) { confirmarAviso(e); } return true; }
        if ((a = t.closest('[data-det]'))) { const e = acharItem(a.dataset.ev); if (e) { ({ editar, excluir: excluirItem, avisar: avisarItem, concluir: x => mudarSituacao(x, 'concluido'), cancelar: x => mudarSituacao(x, 'cancelado'), reabrir: x => mudarSituacao(x, 'agendado') })[a.dataset.det](e); } return true; }
        if (t.closest('#agModal .ag-modal') && !t.closest('[data-fechar]')) { return true; }
        return false;
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
        // Como no desenho (onClick=obsAuto): clicar no campo logo depois de um "@nome" reabre a lista.
        if (f && t.matches && t.matches('#agModal [data-f="obs"]')) { lerArroba(t); return; }
        if (cliqueDoModal(t)) { return; }
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
        if ((a = alvo('[data-testar]'))) { if (window.BJLembretes) { window.BJLembretes.permitir(); window.BJLembretes.testar(a.dataset.testar); } return; }
        if (alvo('#agGoogle')) { googleSincronizar(); return; }
        if (alvo('#agGoogleSair')) { googleDesconectar(); }
        if (alvo('[data-google-sair="cancelar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-sair="ok"]')) { confirmarDesconectar(); }
        if (alvo('[data-google-perm="fechar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-perm="tentar"]')) { document.getElementById('agPerg').innerHTML = ''; irAoGoogle(); }
        if (alvo('[data-google-ir="cancelar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-ir="continuar"]')) { document.getElementById('agPerg').innerHTML = ''; irAoGoogle(); }
        if (alvo('[data-google-orientar="fechar"]')) { fecharAvisoDoGoogle(false); return; }
        if (alvo('[data-google-orientar="sincronizar"]')) { fecharAvisoDoGoogle(true); }
    });

    raiz.addEventListener('contextmenu', ev => {
        const t = ev.target;
        if (t.closest('[data-ctx-fechar]')) { ev.preventDefault(); fecharMenu(); return; }
        // Dentro do nosso menu (a tecla Menu do Windows chega no keyup, já com o foco nele): sem o menu do navegador.
        if (t.closest('#agCtx')) { ev.preventDefault(); return; }
        if (t.closest('#agModal')) { return; }
        if (menuDoAlvo(t, ev.clientX, ev.clientY)) { ev.preventDefault(); }
    });

    /* O menu do desenho existe só no Mês: na célula, no chip e no rótulo do feriado. Pelo teclado (tecla
       Menu, Shift+F10) o navegador não dá posição: o menu abre junto do elemento com foco. */
    function menuDoAlvo(t, x, y) {
        const cel = t.closest('.ag-cel[data-dia]');
        if (!cel) { return false; }
        const marcado = t.closest('[data-ev]');
        if (!x && !y) {
            const r = (marcado || cel).getBoundingClientRect();
            [x, y] = [r.left + 8, r.bottom - 4];
        }
        abrirMenu(x, y, cel.dataset.dia, marcado ? acharItem(marcado.dataset.ev) : null);
        return true;
    }

    /* Teclado: Enter ou espaço no dia ou no compromisso faz o mesmo que o clique; Shift+F10 abre o menu. */
    raiz.addEventListener('keydown', ev => {
        const t = ev.target;
        if (!t.matches || !t.matches('.ag-cel[data-dia], .ag-chip[data-ev]') || t.closest('#agModal, #agCtx')) { return; }
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); t.click(); return; }
        if ((ev.key === 'F10' && ev.shiftKey) || ev.key === 'ContextMenu') { ev.preventDefault(); menuDoAlvo(t, 0, 0); }
    });

    /* Toque longo (iPhone: o Safari não dispara contextmenu). Meio segundo parado abre o menu; mexer o dedo
       cancela; o clique que viria depois do toque longo não acontece. */
    let toqueLongo = null;
    const esquecerToque = () => { if (toqueLongo) { clearTimeout(toqueLongo.timer); } toqueLongo = null; };
    raiz.addEventListener('touchstart', ev => {
        esquecerToque();
        const t = ev.target;
        if (ev.touches.length !== 1 || !t.closest('.ag-cel[data-dia]') || t.closest('#agModal, #agCtx')) { return; }
        const p = ev.touches[0];
        const toque = { x: p.clientX, y: p.clientY, abriu: false };
        toque.timer = setTimeout(() => { if (toqueLongo === toque && t.isConnected) { toque.abriu = menuDoAlvo(t, p.clientX, p.clientY); } }, 500);
        toqueLongo = toque;
    }, { passive: true });
    raiz.addEventListener('touchmove', ev => {
        if (!toqueLongo) { return; }
        const p = ev.touches[0];
        if (Math.abs(p.clientX - toqueLongo.x) > 10 || Math.abs(p.clientY - toqueLongo.y) > 10) { esquecerToque(); }
    }, { passive: true });
    // Gesto cancelado pelo sistema ou que virou arrastar (chips arrastáveis): não abre o menu.
    raiz.addEventListener('touchcancel', esquecerToque);
    raiz.addEventListener('dragstart', esquecerToque);
    raiz.addEventListener('touchend', ev => {
        if (!toqueLongo) { return; }
        if (toqueLongo.abriu) { ev.preventDefault(); }
        esquecerToque();
    });

    function acaoDoMenu(acao) {
        const el = document.getElementById('agCtx');
        const dia = el.dataset.dia;
        const e = el.dataset.ev ? acharItem(el.dataset.ev) : null;
        fecharMenu();
        if (acao === 'abrir' && e) { abrir(e); }
        if (acao === 'novo') { novoEm(dia); }
        if (acao === 'acrescentar') { formDeData(dia, null); }
        if (acao === 'renomear' && e) { formDeData(dia, e); }
        if (acao === 'ocultar' && e) { ocultarData(e); }
        if (acao === 'restaurar') { restaurarDatas(); }
    }

    /* ── Ajustes de datas do sistema (botão direito, entrega 4) ─────────── */
    const TIPOS_DE_DATA = [['fer', BJ_T.t('agenda.js.data_form.tipo.fer')], ['est', BJ_T.t('agenda.js.data_form.tipo.est')], ['trib', BJ_T.t('agenda.js.data_form.tipo.trib')], ['com', BJ_T.t('agenda.js.data_form.tipo.com')]];
    let formData = null;

    function formDeData(dia, e) {
        formData = { dia, e, cal: 'trib' };
        const titulo = e ? BJ_T.t('agenda.js.menu.editar_nome') : BJ_T.t('agenda.js.data_form.acrescentar_titulo');
        const tipos = e ? '' : '<div class="ag-f-rot-linha">' + esc(BJ_T.t('agenda.js.data_form.tipo_rotulo')) + '</div><div class="ag-f-tipos">' + TIPOS_DE_DATA.map(([k, r]) => '<button type="button" class="ag-f-tipo' + (k === 'trib' ? ' is-on' : '') + '" data-dt-tipo="' + k + '"><span style="background:' + CAL[k][1] + ';"></span>' + esc(r) + '</button>').join('') + '</div>';
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + esc(titulo) + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-plus"></i><b>' + esc(titulo) + '</b><button type="button" class="ag-modal-x" data-fechar title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo ag-form"><span class="ag-f-nota"><i class="bi bi-calendar3"></i> ' + esc(brData(dia)) + '</span>'
            + '<label class="ag-f-rot">' + esc(BJ_T.t('agenda.js.data_form.nome_rotulo')) + '<input data-dt="titulo" maxlength="255" value="' + esc(e ? e.t : '') + '" placeholder="' + esc(BJ_T.t('agenda.js.data_form.nome_placeholder')) + '"></label>'
            + tipos
            + (e ? '' : '<label class="ag-f-rot">' + esc(BJ_T.t('agenda.js.data_form.fonte_rotulo')) + '<input data-dt="fonte" maxlength="255" placeholder="' + esc(BJ_T.t('agenda.js.data_form.fonte_placeholder')) + '"></label>')
            + '<span class="ag-f-nota"><i class="bi bi-info-circle"></i> ' + esc(BJ_T.t('agenda.js.data_form.nota')) + '</span>'
            + '<div class="ag-f-erro" id="agDtErro"></div></div>'
            + '<div class="ag-modal-rodape"><button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button><button type="button" class="ag-mbtn is-pri is-fim" data-dt-salvar><i class="bi bi-check2"></i>' + esc(e ? BJ_T.t('agenda.js.comum.salvar') : BJ_T.t('agenda.js.data_form.incluir')) + '</button></div></div></div>';
        const campo = document.querySelector('#agModal [data-dt="titulo"]');
        if (campo) { campo.focus(); }
    }

    async function salvarData() {
        const titulo = (document.querySelector('#agModal [data-dt="titulo"]') || {}).value || '';
        const fonte = (document.querySelector('#agModal [data-dt="fonte"]') || {}).value || '';
        const erro = document.getElementById('agDtErro');
        if (!titulo.trim()) { erro.textContent = BJ_T.t('agenda.js.erros.nome'); return; }
        try {
            if (formData.e) {
                await postar(url.renomear, { id: formData.e.id, titulo });
            } else {
                await postar(url.acrescentar, { data: formData.dia, titulo, calendario: formData.cal, fonte });
            }
            const dia = formData.dia;
            const renomeou = !!formData.e;
            fecharModal();
            recarregarDatas();
            if (!renomeou) { avisar(BJ_T.t('agenda.js.avisos.incluido_em', { data: brData(dia) })); }
        } catch (x) { erro.textContent = x.message; }
    }

    async function ocultarData(e) {
        try {
            await postar(url.ocultar, { id: e.id });
            recarregarDatas();
            const acrescentada = String(e.id).startsWith('x-');
            avisar(BJ_T.t('agenda.js.avisos.removido', { titulo: e.t }) + (acrescentada ? '' : ' ' + BJ_T.t('agenda.js.avisos.restaure')) + (e.ponto ? ' ' + BJ_T.t('agenda.js.avisos.continua_no_ponto') : ''));
        } catch (x) { avisar(x.message); }
    }

    async function restaurarDatas() {
        if (!confirm(BJ_T.t('agenda.js.confirmar.restaurar'))) { return; }
        try {
            await postar(url.restaurar, {});
            recarregarDatas();
            avisar(BJ_T.t('agenda.js.avisos.restauradas'));
        } catch (x) { avisar(x.message); }
    }

    function recarregarDatas() {
        s.datas = new Map();
        s.cobertoDatas = [];
        s.itens = new Map();
        s.cobertoItens = [];
        carregar();
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
        const id = decodeURIComponent(m[1]);
        let e = acharItem(id);
        const dia = id.match(/(\d{4}-\d{2}-\d{2})$/);
        if (!e && dia) {
            s.ref = dataDe(dia[1]);
            s.ref.setHours(0, 0, 0, 0);
            s.mini = new Date(s.ref.getFullYear(), s.ref.getMonth(), 1);
            await carregar();
            e = acharItem(id);
        }
        if (!e) { avisar(BJ_T.t('agenda.js.avisos.nao_encontrado')); return; }
        s.ref = dataDe(e.d);
        s.ref.setHours(0, 0, 0, 0);
        await carregar();
        abrir(e);
    }

    /* Google Agenda (entrega 6). Clique como no desenho (linha 233): com o servidor liberado, vai ao
       Google com volta=<página atual>; sem, avisa. A volta traz #google=<resultado> para o toast. */
    const AVISOS_GOOGLE = {
        conectado: BJ_T.t('agenda.js.google.volta.conectado'),
        cancelado: BJ_T.t('agenda.js.google.volta.cancelado'),
        falhou: BJ_T.t('agenda.js.google.volta.falhou'),
        limite: BJ_T.t('agenda.js.google.volta.limite'),
    };
    function irAoGoogle() {
        const u = url.googleConectar;
        location.href = u + (u.includes('?') ? '&' : '?') + 'volta=' + encodeURIComponent(location.href.split('#')[0]);
    }
    /* Antes de ir ao Google (decisão do dono, 21/10/2026): avisa da permissão da agenda, que o Google mostra como caixinha. */
    function googleSincronizar() {
        if (!url.googleConectar) {
            avisar(BJ_T.t('agenda.js.google.nao_configurado'));
            return;
        }
        // Só na primeira conexão: quem já está conectado vai direto.
        if ('conectado' === raiz.dataset.google) { irAoGoogle(); return; }
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.google.conectar_titulo')) + '">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> ' + esc(BJ_T.t('agenda.js.google.conectar_titulo')) + '</div>'
            + '<div class="ag-perg-txt">' + esc(BJ_T.t('agenda.js.google.conectar_texto')) + '</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-ir="cancelar"><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-ir="continuar"><i class="bi bi-google"></i>' + esc(BJ_T.t('agenda.js.google.continuar')) + '</button></div></div></div>';
        const continuar = document.querySelector('#agPerg [data-google-ir="continuar"]');
        if (continuar) { continuar.focus(); }
    }
    /* Desconectar (decisão do dono, 20/10/2026): pergunta se apaga também a agenda do escritório no Google,
       com "Não, manter" marcado; mantida, ela fica lá parada. */
    function googleDesconectar() {
        const nome = raiz.dataset.escritorio || BJ_T.t('agenda.js.google.nome_padrao');
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.google.desconectar_titulo')) + '">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> ' + esc(BJ_T.t('agenda.js.google.desconectar_titulo')) + '</div>'
            + '<div class="ag-perg-txt">' + esc(BJ_T.t('agenda.js.google.desconectar_texto', { nome })) + '</div>'
            + '<div class="ag-perg-txt"><label><input type="radio" name="agGoogleAgenda" value="manter" checked> ' + esc(BJ_T.t('agenda.js.google.manter')) + '</label><br>'
            + '<label><input type="radio" name="agGoogleAgenda" value="apagar"> ' + esc(BJ_T.t('agenda.js.google.apagar', { nome })) + '</label></div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-sair="cancelar"><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-sair="ok"><i class="bi bi-box-arrow-right"></i>' + esc(BJ_T.t('agenda.js.google.desconectar')) + '</button></div></div></div>';
        const ok = document.querySelector('#agPerg [data-google-sair="ok"]');
        if (ok) { ok.focus(); }
    }
    async function confirmarDesconectar() {
        const apagar = !!document.querySelector('#agPerg input[name="agGoogleAgenda"][value="apagar"]:checked');
        document.getElementById('agPerg').innerHTML = '';
        try {
            await postar(url.googleDesconectar, { apagarAgenda: apagar });
            location.reload();
        } catch (e) { avisar(e.message); }
    }
    /* Voltou do Google sem a permissão da agenda (a caixinha desmarcada no consentimento): explica e oferece tentar de novo. */
    function googleSemPermissao() {
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.google.sem_permissao_titulo')) + '">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> ' + esc(BJ_T.t('agenda.js.google.sem_permissao_titulo')) + '</div>'
            + '<div class="ag-perg-txt">' + esc(BJ_T.t('agenda.js.google.sem_permissao_texto')) + '</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-perm="fechar"><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.google.agora_nao')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-perm="tentar"><i class="bi bi-google"></i>' + esc(BJ_T.t('agenda.js.google.tentar_de_novo')) + '</button></div></div></div>';
        const tentar = document.querySelector('#agPerg [data-google-perm="tentar"]');
        if (tentar) { tentar.focus(); }
    }
    /* Janela que orienta a conectar (pedido do dono, 23/10/2026, spec docs/specs/agenda-aviso-google.md). O servidor
       decide se abre (data-google-aviso: não conectado e sem dispensa). A caixa "Não mostrar" fora do desenho (l.125)
       é pedido do dono. */
    function googleAviso() {
        if ('1' !== raiz.dataset.googleAviso || !url.googleConectar) { return; }
        const titulo = BJ_T.t('agenda.js.google.aviso.titulo');
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(titulo) + '">'
            + '<div class="ag-perg-cab ag-perg-cab-x"><span><i class="bi bi-google"></i> ' + esc(titulo) + '</span><button type="button" class="ag-modal-x" data-google-orientar="fechar" title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-perg-txt">' + esc(BJ_T.t('agenda.js.google.aviso.texto')) + '</div>'
            + '<div class="ag-perg-txt ag-perg-opcao"><label><input type="checkbox" id="agGoogleAvisoNao"> ' + esc(BJ_T.t('agenda.js.google.aviso.nao_mostrar')) + '</label></div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-orientar="fechar"><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.google.aviso.agora_nao')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-orientar="sincronizar"><i class="bi bi-arrow-repeat"></i>' + esc(BJ_T.t('agenda.js.google.aviso.sincronizar')) + '</button></div></div></div>';
        const sincronizar = document.querySelector('#agPerg [data-google-orientar="sincronizar"]');
        if (sincronizar) { sincronizar.focus(); }
    }
    /* Grava a dispensa só com a caixa marcada; "Sincronizar agora" segue o mesmo caminho do botão #agGoogle. */
    function fecharAvisoDoGoogle(sincronizar) {
        const naoMostrar = !!document.querySelector('#agGoogleAvisoNao:checked');
        document.getElementById('agPerg').innerHTML = '';
        if (naoMostrar) { postar(url.googleAvisoDispensar, {}).catch(() => { /* volta a aparecer na próxima entrada */ }); }
        if (sincronizar) { googleSincronizar(); }
    }
    function avisoDaVoltaDoGoogle() {
        const m = location.hash.match(/google=(conectado|cancelado|falhou|limite|sem-permissao)/);
        if (!m) { return; }
        history.replaceState(null, '', location.pathname + location.search);
        if ('sem-permissao' === m[1]) { googleSemPermissao(); return; }
        avisar(AVISOS_GOOGLE[m[1]]);
    }

    window.addEventListener('hashchange', abrirDoEndereco);
    // Entrada por link que já abre algo na Agenda (volta do Google #google=..., compromisso #ev=... do Chat ou do
    // lembrete): o que o link abre vem antes; a orientação fica para a próxima entrada.
    const abreAlgoPeloLink = /(?:google|ev)=/.test(location.hash);
    avisoDaVoltaDoGoogle();
    if (!abreAlgoPeloLink) { googleAviso(); }
    abrirDoEndereco();
})();
