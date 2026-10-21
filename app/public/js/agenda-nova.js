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
    const DO_SISTEMA = ['fer', 'est', 'forense', 'com', 'trib', 'aniv'];
    const MES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    const SEM = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
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

    const horaDe = e => (e.dia ? 'Dia inteiro' : (e.h1 || '') + (e.h2 ? ' às ' + e.h2 : ''));
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
        // Como no desenho: todos os compromissos meus daquele tipo, em qualquer data (vem do servidor).
        const contagens = s.contagens || {};
        document.getElementById('agCals').innerHTML = Object.entries(CAL).map(([k, v]) => {
            const n = contagens[k] || 0;
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
        return '<div class="' + cls + '" data-dia="' + k + '" tabindex="0" role="button" aria-label="' + esc(brData(k)) + '">'
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
        const completa = url.lista ? '<div class="ag-lista-completa"><a href="' + esc(url.lista) + '"><i class="bi bi-list-ul"></i>Lista completa, com eventos passados e filtros</a></div>' : '';
        if (!dias.length) { return '<div class="ag-lista-vazia">Nenhum compromisso nos próximos 60 dias.</div>' + completa; }
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
        if (rec) { L.push(Object.assign({}, rec, { t: 'Início do recesso forense' })); }
        L.sort((a, b) => a.d.localeCompare(b.d));
        document.getElementById('agPfTit').textContent = 'Próximos feriados · ' + MES[h.getMonth()] + ' e ' + MES[(h.getMonth() + 1) % 12];
        const caixa = document.getElementById('agPf');
        if (!L.length) { caixa.innerHTML = '<div class="ag-pf-vazio">Nenhum feriado neste mês nem no próximo.</div>'; return; }
        caixa.innerHTML = '<div class="ag-pf-grade">' + L.map(e => {
            const d = dataDe(e.d);
            const n = Math.round((d - dataDe(iso(h))) / 864e5);
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
        const base = origemBase(e);
        const editado = e.editado ? ' Nome alterado pelo escritório.' : '';
        const dica = DO_SISTEMA.includes(e.src) && s.podeAjustar ? ' Clique com o botão direito no dia para editar ou ocultar.' : '';
        return base + editado + dica;
    }

    function origemBase(e) {
        const ponto = e.ponto ? ' Conta como feriado no ponto.' : ' Não conta no ponto: para contar, cadastre em Feriados e Folgas.';
        if (e.tipo === 'acrescentada') { return 'Incluído pelo escritório na Agenda. Não conta no ponto: para contar, cadastre em Feriados e Folgas.'; }
        if (e.src === 'pasta') { return 'Vem dos prazos e audiências registrados na pasta. Para alterar, edite na pasta.'; }
        if (e.src === 'fer' && e.tipo === 'escritorio') { return 'Feriado cadastrado pelo escritório em Feriados e Folgas. Conta como feriado no ponto.'; }
        if (e.src === 'fer' && e.tipo === 'ponto_facultativo') { return 'Ponto facultativo, já incluído pelo sistema.' + ponto; }
        if (e.src === 'fer') { return 'Feriado nacional, já incluído pelo sistema.' + ponto; }
        if (e.src === 'est') { return 'Feriado estadual incluído pela localização cadastrada (' + (e.uf || '') + ').' + ponto; }
        if (e.src === 'forense') { return 'Recesso forense incluído pelo sistema.'; }
        if (e.src === 'com') { return 'Data comemorativa incluída pelo sistema.'; }
        if (e.src === 'trib') { return 'Data dos tribunais incluída pelo sistema.'; }
        if (e.src === 'aniv') { return 'Aniversário cadastrado no Meu Perfil.'; }
        if (e.doGoogle) { return 'Importado do Google Agenda.'; }
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
        const fimOutroDia = e.fimOriginal && e.inicioOriginal && e.fimOriginal.slice(0, 10) !== e.inicioOriginal.slice(0, 10) ? e.fimOriginal : '';
        const ate = fimOutroDia ? ' até ' + brData(fimOutroDia.slice(0, 10)) + (e.dia ? '' : ' às ' + fimOutroDia.slice(11, 16)) : '';
        const quando = dataDe(e.d).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
            + (e.dia || !e.h1 ? ' · dia inteiro' + ate : ' · ' + e.h1 + (ate || (e.h2 ? ' às ' + e.h2 : '')));
        const linhas = [
            '<b>' + esc(e.t) + '</b>',
            '<span><i class="bi bi-clock"></i>' + esc(quando) + '</span>',
            '<span><i class="bi bi-tag"></i>' + esc(corDe(e)[0]) + '</span>',
        ];
        if (e.src === 'meus' && !e.meu && e.dono) { linhas.push('<span><i class="bi bi-person"></i>Agenda de ' + esc(e.dono) + '</span>'); }
        if (e.src === 'meus') { linhas.push('<span><i class="bi bi-eye"></i>' + (e.privado ? 'Só você vê' : 'Visível para a equipe do escritório') + '</span>'); }
        if (e.repetir || e.recorrente) { linhas.push('<span><i class="bi bi-arrow-repeat"></i>Repete: ' + esc(({ diario: 'todo dia', semanal: 'toda semana', mensal: 'todo mês', anual: 'todo ano' })[e.repetir] || 'sim') + (e.repetirAte ? ' até ' + esc(brData(e.repetirAte)) : '') + '</span>'); }
        if (e.status === 'concluido' || e.status === 'cancelado') { linhas.push('<span class="ag-modal-situacao is-' + e.status + '"><i class="bi ' + (e.status === 'concluido' ? 'bi-check2-circle' : 'bi-x-circle') + '"></i>' + (e.status === 'concluido' ? 'Concluído' : 'Cancelado') + '</span>'); }
        if (e.pastaId) { linhas.push('<a href="' + esc(comId(url.pasta, e.pastaId)) + '"><i class="bi bi-folder2-open"></i>Abrir a Pasta ' + esc(e.pasta || '') + '</a>'); }
        if (e.obs) { linhas.push('<span class="ag-modal-obs">' + esc(e.obs) + '</span>'); }
        if ((e.avisados || []).length) { linhas.push('<span class="ag-modal-avisados"><i class="bi bi-chat-dots"></i>Avisados no Chat: ' + esc(nomesAvisados(e.avisados)) + '</span>'); }
        linhas.push('<div class="ag-modal-origem">' + esc(origemDe(e)) + '</div>');
        if (s.podeAjustar && url.feriados && DO_SISTEMA.includes(e.src) && e.src !== 'aniv' && !e.ponto) {
            linhas.push('<a class="ag-modal-feriados" href="' + esc(url.feriados) + '"><i class="bi bi-box-arrow-up-right"></i>Cadastrar em Feriados e Folgas, para contar no ponto</a>');
        }
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="Compromisso">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>Compromisso</b><button type="button" class="ag-modal-x" data-fechar title="Fechar"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo">' + linhas.join('') + '</div>'
            + '<div class="ag-modal-rodape">' + botoesDoDetalhe(e) + '</div></div></div>';
        const x = document.querySelector('#agModal .ag-modal-x');
        if (x) { x.focus(); }
    }

    function botoesDoDetalhe(e) {
        const fechar = '<button type="button" class="ag-mbtn is-fim" data-fechar><i class="bi bi-x"></i>Fechar</button>';
        if (DO_SISTEMA.includes(e.src)) { return fechar; }
        const google = '<a class="ag-mbtn" href="' + esc(googleUrl(e)) + '" target="_blank" rel="noopener"><i class="bi bi-google"></i>Google Agenda</a>';
        const det = (acao, rotulo, ic, classe) => '<button type="button" class="ag-mbtn' + classe + '" data-det="' + acao + '" data-ev="' + esc(e.id) + '"><i class="bi ' + ic + '"></i>' + rotulo + '</button>';
        /* Desenho 09, L238: todo item que não é data do sistema, inclusive prazo e audiência de pasta. */
        const avisarBtn = (e.eventoId && !e.privado) || prazoId(e) ? det('avisar', 'Avisar no Chat', 'bi-chat-dots', '') : '';
        if (e.src === 'meus' && e.editavel && e.eventoId) {
            const sit = (e.status === 'agendado' || !e.status ? det('concluir', 'Concluir', 'bi-check2-circle', '') : '')
                + (e.status !== 'cancelado' ? det('cancelar', 'Cancelar compromisso', 'bi-x-circle', '') : '')
                + (e.status && e.status !== 'agendado' ? det('reabrir', 'Reabrir', 'bi-arrow-counterclockwise', '') : '');
            return google + avisarBtn + sit + det('excluir', 'Excluir', 'bi-trash3', ' is-perigo') + det('editar', 'Editar', 'bi-pencil', ' is-pri is-fim');
        }

        return google + avisarBtn + fechar;
    }

    function fecharModal() { document.getElementById('agModal').innerHTML = ''; document.getElementById('agPerg').innerHTML = ''; f = null; at = null; formData = null; }

    /* ── Menu de botão direito ──────────────────────────────────────────── */
    function abrirMenu(x, y, dia, e) {
        const itens = [];
        if (e && !DO_SISTEMA.includes(e.src)) { itens.push(['Abrir compromisso', 'bi-box-arrow-up-right', 'abrir']); }
        if (e && DO_SISTEMA.includes(e.src)) { itens.push(['Ver detalhes', 'bi-info-circle', 'abrir']); }
        if (e && DO_SISTEMA.includes(e.src) && s.podeAjustar) {
            itens.push(['Editar nome', 'bi-pencil', 'renomear']);
            itens.push(String(e.id).startsWith('x-') ? ['Excluir', 'bi-eye-slash', 'ocultar', 'perigo'] : ['Ocultar da agenda', 'bi-eye-slash', 'ocultar', 'perigo']);
        }
        if (s.podeAjustar) { itens.push(['Acrescentar feriado ou data em ' + brData(dia), 'bi-plus-lg', 'acrescentar']); }
        itens.push(['Novo compromisso em ' + brData(dia), 'bi-calendar-plus', 'novo']);
        if (s.podeAjustar && s.temAjustes) { itens.push(['Restaurar datas do sistema', 'bi-arrow-counterclockwise', 'restaurar']); }
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
    const LEMBRETES = [['0', 'Sem lembrete'], ['10', '10 min antes'], ['15', '15 min antes'], ['30', '30 min antes'], ['60', '1 hora antes'], ['1440', '1 dia antes']];
    const REPETIR = [['', 'Não repete'], ['diario', 'Todo dia'], ['semanal', 'Toda semana'], ['mensal', 'Todo mês'], ['anual', 'Todo ano']];
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

    function rotulo(r, campo) { return '<label class="ag-f-rot">' + r + campo + '</label>'; }

    function htmlForm(erro) {
        const tipos = TIPOS.map(k => {
            const on = f.tipo === k;
            return '<button type="button" class="ag-f-tipo" data-f-tipo="' + k + '" style="border-color:' + (on ? CAL[k][1] : '') + ';background:' + (on ? CAL[k][2] : '') + ';color:' + (on ? CAL[k][1] : '') + ';"><span style="background:' + CAL[k][1] + ';"></span>' + CAL[k][0].replace('Prazos das pastas', 'Prazo') + '</button>';
        }).join('');
        const vis = [['equipe', 'Equipe do escritório', 'bi-people', f.part.length ? 'Só os participantes e você veem' : 'Todos da conta veem'], ['privado', 'Só eu', 'bi-lock', 'Ninguém mais vê, nem o primário']].map(([k, r, ic, d]) =>
            '<button type="button" class="ag-f-vis' + (f.vis === k ? ' is-on' : '') + '" data-f-vis="' + k + '"><i class="bi ' + ic + '"></i><span><b>' + r + '</b><span>' + d + '</span></span></button>').join('');
        const opcoes = (lista, v) => lista.map(([k, r]) => '<option value="' + k + '"' + (String(v) === k ? ' selected' : '') + '>' + r + '</option>').join('');
        const part = daEquipe().map(u => '<button type="button" class="ag-f-pessoa' + (f.part.includes(u.id) ? ' is-on' : '') + '" data-f-part="' + u.id + '">' + esc(nomeCurto(u.nome)) + '</button>').join('');
        const avisados = nomesAvisados(f.avis);
        return '<input class="ag-f-titulo" data-f="t" value="' + esc(f.t) + '" placeholder="Título" maxlength="255">'
            + '<div class="ag-f-tipos">' + tipos + '</div>'
            + '<div class="ag-f-grade">' + rotulo('DATA', '<input type="date" data-f="d" value="' + esc(f.d) + '">')
            + rotulo('INÍCIO', '<input type="time" data-f="h1" value="' + esc(f.h1) + '"' + (f.dia ? ' disabled' : '') + '>')
            + rotulo('FIM', '<input type="time" data-f="h2" value="' + esc(f.h2) + '"' + (f.dia ? ' disabled' : '') + '>') + '</div>'
            + (f.dias > 0 ? '<div class="ag-f-nota"><i class="bi bi-info-circle"></i> Termina em ' + esc(brData(iso(soma(dataDe(f.d), f.dias)))) + ' (' + f.dias + ' dia(s) depois do início). <button type="button" class="ag-f-link" data-f-mesmodia>Terminar no mesmo dia</button></div>' : '')
            + (f.serie ? '<div class="ag-f-nota"><i class="bi bi-arrow-repeat"></i> Este compromisso se repete: a alteração vale para todas as repetições.</div>' : '')
            + '<div class="ag-f-viss">' + vis + '</div>'
            + '<label class="ag-f-check"><input type="checkbox" data-f-dia' + (f.dia ? ' checked' : '') + '>Dia inteiro</label>'
            + '<div class="ag-f-grade2">' + (podeVerPastas ? rotulo('PASTA (NUP, OPCIONAL)', '<input data-f="pasta" value="' + esc(f.pasta) + '" placeholder="Ex.: número da pasta">') : '')
            + rotulo('LEMBRETE', '<select data-f="lem">' + opcoes(LEMBRETES, f.lem) + '</select>') + '</div>'
            + '<div class="ag-f-grade2">' + rotulo('REPETIR', '<select data-f="rep">' + opcoes(REPETIR, f.rep) + '</select>')
            + rotulo('ATÉ (OPCIONAL)', '<input type="date" data-f="repAte" value="' + esc(f.repAte) + '"' + (f.rep ? '' : ' disabled') + '>') + '</div>'
            + (part ? '<div class="ag-f-bloco"><span class="ag-f-rot-linha">PARTICIPANTES <span class="ag-f-dica">' + (f.vis === 'privado' ? 'em "Só eu" os participantes não veem nem são avisados' : 'só eles e você veem o compromisso') + '</span></span><div class="ag-f-pessoas">' + part + '</div></div>' : '')
            + '<div class="ag-f-avisar"><span class="ag-f-rot-linha">AVISAR NO CHAT I.A <span class="ag-f-dica">' + (f.vis === 'privado' ? 'em "Só eu" ninguém é avisado' : 'cada @pessoa recebe o resumo e o atalho') + '</span></span>'
            + '<textarea data-f="obs" rows="2" placeholder="Local, link da reunião ou observações">' + esc(f.obs) + '</textarea>'
            + '<div id="agAt"></div>'
            + (avisados ? '<div class="ag-f-avisados"><i class="bi bi-chat-dots"></i> Serão avisados: ' + esc(avisados) + '</div>' : '') + '</div>'
            + (erro ? '<div class="ag-f-erro" role="alert">' + esc(erro) + '</div>' : '');
    }

    function desenharForm(erro) {
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + (editId ? 'Editar compromisso' : 'Novo compromisso') + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>' + (editId ? 'Editar compromisso' : 'Novo compromisso') + '</b><button type="button" class="ag-modal-x" data-fechar title="Fechar"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo ag-form">' + htmlForm(erro) + '</div>'
            + '<div class="ag-modal-rodape"><button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>Cancelar</button>'
            + '<button type="button" class="ag-mbtn is-pri is-fim" data-f-salvar><i class="bi bi-check2"></i>Salvar</button></div></div></div>';
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
        const m = ta.value.slice(0, pos).match(/(^|\s)@([\wÀ-ÿ]*)$/);
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
        if (av) { av.innerHTML = nomes ? '<i class="bi bi-chat-dots"></i> Serão avisados: ' + esc(nomes) : ''; }
        else if (nomes) { caixa.insertAdjacentHTML('afterend', '<div class="ag-f-avisados"><i class="bi bi-chat-dots"></i> Serão avisados: ' + esc(nomes) + '</div>'); }
        if (!at) { caixa.innerHTML = ''; return; }
        const L = candidatos();
        const r = ta.getBoundingClientRect();
        const h = Math.min(200, Math.max(1, L.length) * 38 + 8);
        const embaixo = innerHeight - r.bottom - 8 >= h;
        caixa.innerHTML = '<div class="ag-at" role="listbox" style="left:' + Math.max(8, r.left) + 'px;width:' + Math.min(innerWidth - 16, r.width) + 'px;top:' + (embaixo ? r.bottom + 4 : Math.max(8, r.top - h - 4)) + 'px;max-height:' + h + 'px;">'
            + (L.length ? L.map((u, i) => '<button type="button" role="option" class="ag-at-item' + (i === at.i % L.length ? ' is-on' : '') + '" data-at="' + u.id + '"><span>' + esc(nomeCurto(u.nome).split(' ').map(p => p[0]).join('').toUpperCase()) + '</span>' + esc(u.nome) + '</button>').join('')
                : '<div class="ag-at-vazio">Nenhum colaborador com esse nome</div>') + '</div>';
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
        if (!resp.ok) { throw new Error(json.erro || 'Não foi possível salvar. Tente de novo.'); }
        return json;
    }

    function erroLocal() {
        if (!f.t.trim()) { return 'Informe o título.'; }
        if (!f.d) { return 'Informe a data.'; }
        if (!f.dia && !f.dias && f.h2 && f.h2 <= f.h1) { return 'O fim precisa ser depois do início.'; }
        if (f.rep && f.repAte && f.repAte < f.d) { return 'A repetição precisa terminar depois do início.'; }
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
            avisar('Compromisso salvo.' + (lembra ? ' Você será lembrado com aviso e som.' : '') + (r.avisados ? ' ' + r.avisados + ' pessoa(s) avisada(s) no Chat I.A.' : ''));
        } catch (e) {
            desenharForm(e.message);
        }
    }

    function perguntarCompartilhar() {
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="Compartilhar com a equipe?">'
            + '<div class="ag-perg-cab"><i class="bi bi-people"></i> Compartilhar com a equipe?</div>'
            + '<div class="ag-perg-txt">Você está em <b>Minha agenda</b>. Deseja que <b>' + esc(f.t) + '</b> apareça também na <b>Equipe do escritório</b>?</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-perg="privado"><i class="bi bi-lock"></i>Não, só eu</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-perg="equipe"><i class="bi bi-people"></i>Sim, compartilhar</button></div></div></div>';
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
        if (!confirm(e.repetir ? 'Excluir este compromisso e todas as repetições?' : 'Excluir este compromisso?')) { return; }
        try {
            await postar(comId(url.excluir, e.eventoId), {});
            fecharModal();
            recarregarEm(s.ref);
            avisar('Compromisso excluído.');
        } catch (x) { avisar(x.message); }
    }

    let escolhaAviso = [];

    function avisarItem(e) {
        escolhaAviso = [];
        desenharEscolhaAviso(e);
    }

    function desenharEscolhaAviso(e) {
        const ja = e.avisados || [];
        const pessoas = daEquipe().map(u => '<button type="button" class="ag-f-pessoa' + (ja.includes(u.id) || escolhaAviso.includes(u.id) ? ' is-on' : '') + '"' + (ja.includes(u.id) ? ' disabled title="Já avisado"' : '') + ' data-av-p="' + u.id + '" data-ev="' + esc(e.id) + '">' + esc(nomeCurto(u.nome)) + '</button>').join('');
        const corpo = document.querySelector('#agModal .ag-modal-corpo');
        const rodape = document.querySelector('#agModal .ag-modal-rodape');
        if (!corpo || !rodape) { return; }
        let bloco = corpo.querySelector('.ag-av');
        if (!bloco) { corpo.insertAdjacentHTML('beforeend', '<div class="ag-av ag-f-bloco"></div>'); bloco = corpo.querySelector('.ag-av'); }
        bloco.innerHTML = '<span class="ag-f-rot-linha">AVISAR NO CHAT I.A <span class="ag-f-dica">cada pessoa recebe o resumo e o atalho</span></span><div class="ag-f-pessoas">' + (pessoas || '<span class="ag-f-dica">Nenhum colega no escritório.</span>') + '</div>';
        rodape.innerHTML = '<button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>Cancelar</button><button type="button" class="ag-mbtn is-pri is-fim" data-av-ok data-ev="' + esc(e.id) + '"' + (escolhaAviso.length ? '' : ' disabled') + '><i class="bi bi-chat-dots"></i>Avisar' + (escolhaAviso.length ? ' ' + escolhaAviso.length : '') + '</button>';
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
            avisar(r.avisados ? r.avisados + ' pessoa(s) avisada(s) no Chat I.A.' : 'Essas pessoas já tinham sido avisadas.');
        } catch (x) { avisar(x.message); }
    }

    async function mudarSituacao(e, status) {
        const serie = e.repetir || e.recorrente ? ' Vale para todas as repetições.' : '';
        const temParticipantes = (e.participantes || []).some(id => id !== eu.id);
        const pergunta = { concluido: 'Concluir este compromisso?', agendado: 'Reabrir este compromisso?', cancelado: 'Cancelar este compromisso?' + (temParticipantes ? ' Os participantes serão avisados.' : '') }[status];
        if ((serie || status === 'cancelado') && !confirm(pergunta + serie)) { return; }
        try {
            await postar(comId(url.status, e.eventoId), { status });
            fecharModal();
            recarregarEm(s.ref);
            avisar({ concluido: 'Compromisso concluído.', cancelado: 'Compromisso cancelado.', agendado: 'Compromisso reaberto.' }[status]);
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
            avisar('Compromisso movido para ' + brData(novoInicio) + (destino.hora && !e.dia ? ' às ' + destino.hora : '') + '.');
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
    const TIPOS_DE_DATA = [['fer', 'Nacional'], ['est', 'Estadual'], ['trib', 'Tribunal'], ['com', 'Comemorativa']];
    let formData = null;

    function formDeData(dia, e) {
        formData = { dia, e, cal: 'trib' };
        const titulo = e ? 'Editar nome' : 'Acrescentar feriado ou data';
        const tipos = e ? '' : '<div class="ag-f-rot-linha">TIPO</div><div class="ag-f-tipos">' + TIPOS_DE_DATA.map(([k, r]) => '<button type="button" class="ag-f-tipo' + (k === 'trib' ? ' is-on' : '') + '" data-dt-tipo="' + k + '"><span style="background:' + CAL[k][1] + ';"></span>' + r + '</button>').join('') + '</div>';
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + titulo + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-plus"></i><b>' + titulo + '</b><button type="button" class="ag-modal-x" data-fechar title="Fechar"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo ag-form"><span class="ag-f-nota"><i class="bi bi-calendar3"></i> ' + esc(brData(dia)) + '</span>'
            + '<label class="ag-f-rot">NOME<input data-dt="titulo" maxlength="255" value="' + esc(e ? e.t : '') + '" placeholder="Ex.: Feriado municipal, Suspensão de expediente no TJ"></label>'
            + tipos
            + (e ? '' : '<label class="ag-f-rot">FONTE (OPCIONAL)<input data-dt="fonte" maxlength="255" placeholder="Portaria, lei ou link"></label>')
            + '<span class="ag-f-nota"><i class="bi bi-info-circle"></i> Vale para a Agenda de todo o escritório. Não muda o ponto: para contar como feriado na jornada, cadastre em Feriados e Folgas.</span>'
            + '<div class="ag-f-erro" id="agDtErro"></div></div>'
            + '<div class="ag-modal-rodape"><button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>Cancelar</button><button type="button" class="ag-mbtn is-pri is-fim" data-dt-salvar><i class="bi bi-check2"></i>' + (e ? 'Salvar' : 'Incluir') + '</button></div></div></div>';
        const campo = document.querySelector('#agModal [data-dt="titulo"]');
        if (campo) { campo.focus(); }
    }

    async function salvarData() {
        const titulo = (document.querySelector('#agModal [data-dt="titulo"]') || {}).value || '';
        const fonte = (document.querySelector('#agModal [data-dt="fonte"]') || {}).value || '';
        const erro = document.getElementById('agDtErro');
        if (!titulo.trim()) { erro.textContent = 'Informe o nome.'; return; }
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
            if (!renomeou) { avisar('Incluído em ' + brData(dia) + '.'); }
        } catch (x) { erro.textContent = x.message; }
    }

    async function ocultarData(e) {
        try {
            await postar(url.ocultar, { id: e.id });
            recarregarDatas();
            const acrescentada = String(e.id).startsWith('x-');
            avisar('"' + e.t + '" removido da agenda.' + (acrescentada ? '' : ' Restaure quando quiser.') + (e.ponto ? ' Ele continua contando no ponto.' : ''));
        } catch (x) { avisar(x.message); }
    }

    async function restaurarDatas() {
        if (!confirm('Restaurar todos os feriados e datas que você ocultou ou editou?')) { return; }
        try {
            await postar(url.restaurar, {});
            recarregarDatas();
            avisar('Datas do sistema restauradas.');
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
        if (!e) { avisar('Compromisso não encontrado. Ele pode ter sido excluído ou não estar visível para você.'); return; }
        s.ref = dataDe(e.d);
        s.ref.setHours(0, 0, 0, 0);
        await carregar();
        abrir(e);
    }

    /* Google Agenda (entrega 6). Clique como no desenho (linha 233): com o servidor liberado, vai ao
       Google com volta=<página atual>; sem, avisa. A volta traz #google=<resultado> para o toast. */
    const AVISOS_GOOGLE = {
        conectado: 'Google Agenda conectado. Os compromissos já estão sendo sincronizados.',
        cancelado: 'A conexão com o Google Agenda foi cancelada.',
        falhou: 'Não foi possível conectar ao Google Agenda. Tente de novo.',
        limite: 'Muitas tentativas com o Google Agenda. Tente de novo em alguns minutos.',
    };
    function irAoGoogle() {
        const u = url.googleConectar;
        location.href = u + (u.includes('?') ? '&' : '?') + 'volta=' + encodeURIComponent(location.href.split('#')[0]);
    }
    /* Antes de ir ao Google (decisão do dono, 21/10/2026): avisa da permissão da agenda, que o Google mostra como caixinha. */
    function googleSincronizar() {
        if (!url.googleConectar) {
            avisar('A sincronização com o Google Agenda será liberada assim que a conexão for configurada no servidor do BlueJus.');
            return;
        }
        // Só na primeira conexão: quem já está conectado vai direto.
        if ('conectado' === raiz.dataset.google) { irAoGoogle(); return; }
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="Conectar o Google Agenda">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> Conectar o Google Agenda</div>'
            + '<div class="ag-perg-txt">Na próxima tela, o Google pede para você marcar a permissão da agenda. Deixe-a marcada: é ela que deixa o BlueJus criar a agenda do escritório na sua conta.</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-ir="cancelar"><i class="bi bi-x"></i>Cancelar</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-ir="continuar"><i class="bi bi-google"></i>Continuar no Google</button></div></div></div>';
        const continuar = document.querySelector('#agPerg [data-google-ir="continuar"]');
        if (continuar) { continuar.focus(); }
    }
    /* Desconectar (decisão do dono, 20/10/2026): pergunta se apaga também a agenda do escritório no Google,
       com "Não, manter" marcado; mantida, ela fica lá parada. */
    function googleDesconectar() {
        const nome = esc(raiz.dataset.escritorio || 'do escritório');
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="Desconectar o Google Agenda?">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> Desconectar o Google Agenda?</div>'
            + '<div class="ag-perg-txt">Os compromissos param de sincronizar. Apagar também a agenda “' + nome + '” do seu Google?</div>'
            + '<div class="ag-perg-txt"><label><input type="radio" name="agGoogleAgenda" value="manter" checked> Não, manter</label><br>'
            + '<label><input type="radio" name="agGoogleAgenda" value="apagar"> Sim, apagar a agenda “' + nome + '” do Google</label></div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-sair="cancelar"><i class="bi bi-x"></i>Cancelar</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-sair="ok"><i class="bi bi-box-arrow-right"></i>Desconectar</button></div></div></div>';
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
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="Falta a permissão da agenda">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> Falta a permissão da agenda</div>'
            + '<div class="ag-perg-txt">Marque a permissão da agenda na tela do Google e tente de novo. Sem ela, o BlueJus não consegue criar a agenda do escritório na sua conta Google.</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-perm="fechar"><i class="bi bi-x"></i>Agora não</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-perm="tentar"><i class="bi bi-google"></i>Tentar de novo</button></div></div></div>';
        const tentar = document.querySelector('#agPerg [data-google-perm="tentar"]');
        if (tentar) { tentar.focus(); }
    }
    function avisoDaVoltaDoGoogle() {
        const m = location.hash.match(/google=(conectado|cancelado|falhou|limite|sem-permissao)/);
        if (!m) { return; }
        history.replaceState(null, '', location.pathname + location.search);
        if ('sem-permissao' === m[1]) { googleSemPermissao(); return; }
        avisar(AVISOS_GOOGLE[m[1]]);
    }

    window.addEventListener('hashchange', abrirDoEndereco);
    avisoDaVoltaDoGoogle();
    abrirDoEndereco();
})();
