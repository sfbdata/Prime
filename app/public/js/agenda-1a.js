/* =============================================================================
   agenda-1a.js: tela da Agenda 1A (desenho "09 - Agenda 1A (restaurada)", spec docs/specs/agenda-1a.md).

   Dados: GET /agenda/compromissos?de=&ate=&escopo=equipe (uma linha por dia). Cada busca guarda o intervalo
   já trazido; navegar só pede o que falta. Busca, filtros e legendas são do cliente, como no desenho.

   Reaproveitado por cópia de agenda-nova.js (a tela de 18/10 não é alterada): a janela de criar e editar
   compromisso e suas gravações, a mudança de situação e o Google Agenda. Os endpoints são os mesmos.
   ============================================================================= */
(function () {
    'use strict';

    const raiz = document.getElementById('a1Pagina');
    if (!raiz) { return; }

    const U = (chave, p) => BJ_T.t(['agenda', 'js', 'um_a', chave].join('.'), p);

    /* As seis legendas fixas do desenho (cor e tom do chip; a tinta fica no CSS). */
    const LEG = {
        aud: { cor: '#0073b7', tint: '#e8f2fa' },
        prazo: { cor: '#00a65a', tint: '#e7f6ee' },
        reun: { cor: '#f39c12', tint: '#fdf3e1' },
        urg: { cor: '#f56954', tint: '#fdeeea' },
        pess: { cor: '#605ca8', tint: '#efeef8' },
        lemb: { cor: '#00c0ef', tint: '#e5f6fb' },
    };
    const ORDEM = ['aud', 'prazo', 'reun', 'urg', 'pess', 'lemb'];
    const TIPOS_VALIDOS = ORDEM.concat('meus');
    const MES = [BJ_T.t('agenda.js.datas.mes.janeiro'), BJ_T.t('agenda.js.datas.mes.fevereiro'), BJ_T.t('agenda.js.datas.mes.marco'), BJ_T.t('agenda.js.datas.mes.abril'), BJ_T.t('agenda.js.datas.mes.maio'), BJ_T.t('agenda.js.datas.mes.junho'), BJ_T.t('agenda.js.datas.mes.julho'), BJ_T.t('agenda.js.datas.mes.agosto'), BJ_T.t('agenda.js.datas.mes.setembro'), BJ_T.t('agenda.js.datas.mes.outubro'), BJ_T.t('agenda.js.datas.mes.novembro'), BJ_T.t('agenda.js.datas.mes.dezembro')];
    const SEM = [BJ_T.t('agenda.js.datas.semana.dom'), BJ_T.t('agenda.js.datas.semana.seg'), BJ_T.t('agenda.js.datas.semana.ter'), BJ_T.t('agenda.js.datas.semana.qua'), BJ_T.t('agenda.js.datas.semana.qui'), BJ_T.t('agenda.js.datas.semana.sex'), BJ_T.t('agenda.js.datas.semana.sab')];
    const DIA_LONGO = [U('dia_longo.dom'), U('dia_longo.seg'), U('dia_longo.ter'), U('dia_longo.qua'), U('dia_longo.qui'), U('dia_longo.sex'), U('dia_longo.sab')];
    const H0 = 7;
    const H1 = 21;
    const ALTURA_HORA = 52;
    const MAX_CHIPS = 3;

    const url = {
        compromissos: raiz.dataset.urlCompromissos,
        criar: raiz.dataset.urlCriar,
        salvar: raiz.dataset.urlSalvar,
        excluir: raiz.dataset.urlExcluir,
        status: raiz.dataset.urlStatus,
        pasta: raiz.dataset.urlPasta,
        googleConectar: raiz.dataset.urlGoogleConectar,
        googleDesconectar: raiz.dataset.urlGoogleDesconectar,
        googleAvisoDispensar: raiz.dataset.urlGoogleAvisoDispensar,
    };
    const lerJson = (texto, padrao) => { try { return JSON.parse(texto || ''); } catch (e) { return padrao; } };
    const eu = lerJson(raiz.dataset.eu, {});
    const equipe = lerJson(raiz.dataset.equipe, []);
    const podeVerPastas = raiz.dataset.podeVerPastas === '1';
    const comId = (modelo, id) => modelo.replace(/0(?=[^0]*$)/, String(id));

    /* ── Utilidades ─────────────────────────────────────────────────────── */
    const pad = n => String(n).padStart(2, '0');
    const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const hhmm = d => pad(d.getHours()) + ':' + pad(d.getMinutes());
    const dataDe = k => { const d = new Date(k + 'T12:00'); d.setHours(0, 0, 0, 0); return d; };
    const soma = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
    const inicioSemana = d => { const x = new Date(d); x.setDate(x.getDate() - x.getDay()); x.setHours(0, 0, 0, 0); return x; };
    const hoje0 = () => { const h = new Date(); h.setHours(0, 0, 0, 0); return h; };
    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const cap = t => t.charAt(0).toUpperCase() + t.slice(1);
    const brData = k => { const [a, m, d] = k.split('-'); return d + '/' + m + '/' + a; };
    const mesmoDia = (a, b) => iso(a) === iso(b);

    /* ── Estado ─────────────────────────────────────────────────────────── */
    const s = {
        vista: 'mes',
        ref: hoje0(),
        busca: '',
        pessoa: '',
        status: 'todos',
        verCancelados: true,
        ocultos: [],
        expandido: null,
        selId: null,
        itens: new Map(),
        coberto: [],
    };

    let tAviso = null;
    function avisar(texto) {
        const el = document.getElementById('agToast');
        el.innerHTML = '<div class="ag-toast" role="status">' + esc(texto) + '</div>';
        clearTimeout(tAviso);
        tAviso = setTimeout(() => { el.innerHTML = ''; }, 3000);
    }

    /* ── Busca de dados por intervalo ───────────────────────────────────── */
    const coberto = (de, ate) => s.coberto.some(([a, b]) => a <= de && b >= ate);
    const emCurso = new Map();
    function umaVez(chave, fazer) {
        if (!emCurso.has(chave)) { emCurso.set(chave, fazer().finally(() => emCurso.delete(chave))); }
        return emCurso.get(chave);
    }

    async function buscar(endereco) {
        const resp = await fetch(endereco, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
        if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
        return resp.json();
    }

    async function garantir(de, ate) {
        if (coberto(de, ate)) { return; }
        const r = await umaVez('c' + de + ate, () => buscar(url.compromissos + '?de=' + de + '&ate=' + ate + '&escopo=equipe'));
        (r.itens || []).forEach(c => { if (c.origem !== 'aniv') { s.itens.set(c.id, c); } });
        s.coberto.push([de, ate]);
    }

    function intervaloDaVista() {
        if (s.vista === 'semana') { const st = inicioSemana(s.ref); return [st, soma(st, 6)]; }
        if (s.vista === 'lista') { return [new Date(s.ref.getFullYear(), s.ref.getMonth(), 1), new Date(s.ref.getFullYear(), s.ref.getMonth() + 1, 0)]; }
        const st = inicioSemana(new Date(s.ref.getFullYear(), s.ref.getMonth(), 1));
        return [st, soma(st, 41)];
    }

    /* O que a tela pede agora: a vista e, sempre, de hoje a 60 dias (cartão Hoje e A seguir). */
    function intervalos() {
        const [vDe, vAte] = intervaloDaVista();
        const h = hoje0();
        return [[iso(vDe), iso(vAte)], [iso(h), iso(soma(h, 60))]];
    }

    /* Uma busca só quando os intervalos cabem nos 400 dias do servidor. */
    function juntar(lista) {
        const falta = lista.filter(([de, ate]) => !coberto(de, ate));
        if (!falta.length) { return []; }
        const de = falta.map(x => x[0]).sort()[0];
        const ate = falta.map(x => x[1]).sort().slice(-1)[0];
        const dias = (dataDe(ate) - dataDe(de)) / 864e5;

        return dias <= 400 ? [[de, ate]] : falta;
    }

    async function carregar(anima, manterTela) {
        if (!manterTela) { desenhar(!!anima); }
        try {
            const pedidos = juntar(intervalos());
            if (pedidos.length) {
                await Promise.all(pedidos.map(([de, ate]) => garantir(de, ate)));
                desenhar(false);
            }
        } catch (e) {
            avisar(BJ_T.t('agenda.js.avisos.carregar_falhou'));
        }
    }

    /* ── Itens ──────────────────────────────────────────────────────────── */
    const legDe = cal => (LEG[cal] ? cal : 'pess');
    const nomeDe = (id, e) => {
        const u = equipe.find(x => x.id === id);
        return u ? u.nome : (e && id === e.criador ? e.donoNome || '' : '');
    };

    function normalizar(c) {
        const dia = !!c.dia || !c.h1;
        const ini = new Date(c.data + 'T' + (dia ? '00:00' : c.h1) + ':00');
        let fim;
        if (dia) { fim = new Date(ini.getTime() + 864e5); }
        else if (c.h2 && c.h2 > c.h1) { fim = new Date(c.data + 'T' + c.h2 + ':00'); }
        else { fim = new Date(ini.getTime() + 18e5); }

        return {
            raw: c,
            id: c.id,
            d: c.data,
            t: c.titulo,
            leg: legDe(c.calendario),
            dia,
            h1: c.h1 || '',
            h2: c.h2 || '',
            ini,
            fim,
            local: c.local || '',
            desc: c.descricao || '',
            status: ['agendado', 'concluido', 'cancelado'].includes(c.status) ? c.status : 'agendado',
            privado: !!c.privado,
            recorrente: !!(c.recorrente || c.repetir),
            repetir: c.repetir || null,
            part: (c.participantes || []).map(Number),
            criador: c.usuarioId == null ? null : Number(c.usuarioId),
            donoNome: c.donoNome || '',
            daPasta: c.origem === 'pasta',
            pastaId: c.pastaId,
            nup: c.pastaNup || '',
            eventoId: c.eventoId,
            editavel: !!c.editavel,
        };
    }

    const todosOsItens = () => Array.from(s.itens.values()).map(normalizar);
    const acharItem = id => todosOsItens().find(e => e.id === id || (e.eventoId && String(e.eventoId) === String(id)));

    function filtrados() {
        const q = s.busca.trim().toLowerCase();
        return todosOsItens().filter(e => {
            if (s.ocultos.includes(e.leg)) { return false; }
            if (!s.verCancelados && e.status === 'cancelado') { return false; }
            if (s.status !== 'todos' && e.status !== s.status) { return false; }
            if (s.pessoa && !e.part.includes(Number(s.pessoa)) && e.criador !== Number(s.pessoa)) { return false; }
            if (q) {
                const alvo = (e.t + ' ' + e.local + ' ' + e.part.map(id => nomeDe(id, e)).join(' ')).toLowerCase();
                if (!alvo.includes(q)) { return false; }
            }
            return true;
        }).sort((a, b) => a.ini - b.ini);
    }

    const sit = e => (e.status === 'cancelado' ? ' is-cancelado' : e.status === 'concluido' ? ' is-concluido' : '');
    const nomeLeg = k => U('legenda.' + k);
    const statusLabel = st => U('status.' + st);
    const horaCurta = e => (e.dia ? U('dia_inteiro_curto') : e.h1);
    const metaTexto = e => [e.local, e.part.length > 1 ? U('meta.participantes', { n: String(e.part.length) }) : nomeDe(e.criador, e)].filter(Boolean).join(' · ');
    const nEventos = n => (n === 1 ? U('lista.evento_um') : U('lista.evento_n', { n: String(n) }));

    /* ── Desenho ────────────────────────────────────────────────────────── */
    function desenhar(anima) {
        const agora = new Date();
        const L = filtrados();
        const por = {};
        L.forEach(e => { (por[e.d] = por[e.d] || []).push(e); });

        desenharGrade(por, L, agora, anima);
        desenharCabecalho(L);
        desenharHoje(por, L, agora);
        desenharLegendas(L);
        desenharFiltros();
        desenharDetalhe();
    }

    function chipHtml(e, semHora) {
        return '<div class="a1-chip a1-l-' + e.leg + sit(e) + '" tabindex="0" role="button" data-a1-ev="' + esc(e.id) + '">'
            + '<span class="a1-chip-barra"></span>'
            + (semHora ? '' : '<span class="a1-chip-hora">' + esc(horaCurta(e)) + '</span>')
            + '<span class="a1-chip-tit">' + esc(e.t) + '</span>'
            + (e.recorrente ? '<i class="bi bi-arrow-repeat a1-chip-rep"></i>' : '')
            + (e.privado ? '<i class="bi bi-lock-fill a1-chip-cad"></i>' : '')
            + '</div>';
    }

    function htmlMes(por, agora) {
        const primeiro = new Date(s.ref.getFullYear(), s.ref.getMonth(), 1);
        const gradeIni = inicioSemana(primeiro);
        let cab = '';
        for (let i = 0; i < 7; i++) { cab += '<div class="a1-mes-dia' + (i === 0 || i === 6 ? ' is-fds' : '') + '">' + esc(SEM[i]) + '</div>'; }
        let grade = '';
        for (let i = 0; i < 42; i++) {
            const d = soma(gradeIni, i);
            if (i >= 35 && d.getMonth() !== s.ref.getMonth()) { continue; }
            const k = iso(d);
            const todos = por[k] || [];
            const expandido = s.expandido === k;
            const fora = d.getMonth() !== s.ref.getMonth();
            const ehHoje = mesmoDia(d, agora);
            const mostra = expandido ? todos : todos.slice(0, MAX_CHIPS);
            grade += '<div class="a1-cel' + (ehHoje ? ' is-hoje' : fora ? ' is-fora' : '') + '">'
                + '<div class="a1-cel-topo"><span class="a1-cel-n">' + d.getDate() + '</span><div class="a1-vao"></div>'
                + '<span class="a1-cel-cont">' + (todos.length > MAX_CHIPS ? todos.length : '') + '</span></div>'
                + '<div class="a1-cel-lista">' + mostra.map(e => chipHtml(e, false)).join('')
                + (!expandido && todos.length > MAX_CHIPS ? '<button type="button" class="a1-mais" data-a1-mais="' + k + '">' + esc(U('mes.mais', { n: String(todos.length - MAX_CHIPS) })) + '</button>' : '')
                + '</div></div>';
        }

        return '<div class="a1-mes-cab">' + cab + '</div><div class="a1-mes-grade">' + grade + '</div>';
    }

    function htmlSemana(por, agora) {
        const semIni = inicioSemana(s.ref);
        const colunas = [];
        let temDiaInteiro = false;
        for (let i = 0; i < 7; i++) {
            const d = soma(semIni, i);
            const todos = por[iso(d)] || [];
            const di = todos.filter(e => e.dia);
            if (di.length) { temDiaInteiro = true; }
            colunas.push({ d, ehHoje: mesmoDia(d, agora), di, eventos: todos.filter(e => !e.dia) });
        }
        const cab = colunas.map(c => '<div class="a1-sem-col-cab' + (c.ehHoje ? ' is-hoje' : '') + '"><div class="a1-sem-dia">' + esc(SEM[c.d.getDay()]) + '</div><div class="a1-sem-num">' + c.d.getDate() + '</div></div>').join('');
        const diaInteiro = temDiaInteiro
            ? '<div class="a1-sem-di"><div class="a1-sem-di-rot">' + esc(BJ_T.t('agenda.js.comum.dia_inteiro')) + '</div>'
                + colunas.map(c => '<div class="a1-sem-di-cel">' + c.di.map(e => chipHtml(e, true)).join('') + '</div>').join('') + '</div>'
            : '';
        let horasRot = '';
        let linhas = '';
        for (let h = H0; h < H1; h++) {
            horasRot += '<div class="a1-sem-hora"><span>' + (h === H0 ? '' : pad(h) + ':00') + '</span></div>';
            linhas += '<div class="a1-sem-slot"></div>';
        }
        const topoAgora = ((agora.getHours() - H0) * 60 + agora.getMinutes()) / 60 * ALTURA_HORA;
        const corpo = colunas.map(c => '<div class="a1-sem-col' + (c.ehHoje ? ' is-hoje' : '') + '">' + linhas
            + (c.ehHoje && topoAgora >= 0 && topoAgora <= (H1 - H0) * ALTURA_HORA ? '<div class="a1-agora" style="top:' + topoAgora + 'px"></div>' : '')
            + c.eventos.map(e => {
                const min = (e.ini.getHours() - H0) * 60 + e.ini.getMinutes();
                const topo = Math.max(0, min / 60 * ALTURA_HORA);
                const alt = Math.max(30, (e.fim - e.ini) / 36e5 * ALTURA_HORA);
                return '<div class="a1-bloco a1-l-' + e.leg + sit(e) + '" tabindex="0" role="button" data-a1-ev="' + esc(e.id) + '" style="top:' + topo + 'px;height:' + alt + 'px">'
                    + '<div class="a1-bloco-hora">' + esc(e.h1) + '</div><div class="a1-bloco-tit">' + esc(e.t) + '</div>'
                    + (alt > 60 && e.local ? '<div class="a1-bloco-local">' + esc(e.local) + '</div>' : '') + '</div>';
            }).join('') + '</div>').join('');

        return '<div class="a1-sem-cab"><div class="a1-sem-vazio"></div>' + cab + '</div>' + diaInteiro
            + '<div class="a1-sem-rolo"><div class="a1-sem-corpo"><div class="a1-sem-horas">' + horasRot + '</div>' + corpo + '</div></div>';
    }

    function htmlLista(por, agora) {
        const mes = s.ref.getMonth();
        const ano = s.ref.getFullYear();
        const chaves = Object.keys(por).sort().filter(k => { const d = dataDe(k); return d.getMonth() === mes && d.getFullYear() === ano; });
        const grupos = chaves.map(k => {
            const d = dataDe(k);
            const ehHoje = mesmoDia(d, agora);
            const rotulo = ehHoje ? U('lista.hoje_dia', { dia: DIA_LONGO[d.getDay()] }) : cap(DIA_LONGO[d.getDay()]);
            return '<div><div class="a1-grupo-cab' + (ehHoje ? ' is-hoje' : '') + '"><span class="a1-grupo-dia">' + d.getDate() + '</span>'
                + '<span class="a1-grupo-rot">' + esc(rotulo) + '</span><span class="a1-grupo-n">' + esc(nEventos(por[k].length)) + '</span></div>'
                + por[k].map(e => '<div class="a1-lin a1-l-' + e.leg + sit(e) + '" tabindex="0" role="button" data-a1-ev="' + esc(e.id) + '">'
                    + '<span class="a1-lin-ponto"></span><span class="a1-lin-hora">' + esc(horaCurta(e)) + '</span>'
                    + '<div class="a1-lin-bloco"><div class="a1-lin-tit">' + esc(e.t) + '</div><div class="a1-lin-meta">' + esc(metaTexto(e)) + '</div></div>'
                    + '<span class="a1-tag">' + esc(nomeLeg(e.leg)) + '</span><span class="a1-st is-' + e.status + '">' + esc(statusLabel(e.status)) + '</span></div>').join('')
                + '</div>';
        }).join('');
        const vazio = chaves.length ? '' : '<div class="a1-lista-vazia"><i class="bi bi-calendar-x"></i><div>' + esc(U('lista.vazio')) + '</div></div>';

        return grupos + vazio;
    }

    function desenharGrade(por, L, agora, anima) {
        const caixa = document.getElementById('a1Grade');
        const rolo = caixa.querySelector('.a1-sem-rolo');
        const rolagem = rolo ? rolo.scrollTop : 0;
        const interno = s.vista === 'semana' ? htmlSemana(por, agora) : s.vista === 'lista' ? htmlLista(por, agora) : htmlMes(por, agora);
        let painel = caixa.firstElementChild;
        if (anima || !painel || painel.dataset.vista !== s.vista) {
            caixa.innerHTML = '<div class="a1-painel a1-vista-' + s.vista + (anima ? ' a1-anima' : '') + '" data-vista="' + s.vista + '"></div>';
            painel = caixa.firstElementChild;
        }
        painel.innerHTML = interno;
        const novoRolo = painel.querySelector('.a1-sem-rolo');
        if (novoRolo && rolagem) { novoRolo.scrollTop = rolagem; }
        document.querySelectorAll('[data-a1-vista]').forEach(b => b.classList.toggle('is-on', b.dataset.a1Vista === s.vista));
    }

    function periodoDaVista() {
        if (s.vista === 'semana') { const ini = inicioSemana(s.ref); return L => L.filter(e => e.ini >= ini && e.ini < soma(ini, 7)); }

        return L => L.filter(e => e.ini.getMonth() === s.ref.getMonth() && e.ini.getFullYear() === s.ref.getFullYear());
    }

    function tituloDoPeriodo() {
        if (s.vista !== 'semana') { return BJ_T.t('agenda.js.periodo.mes', { mes: cap(MES[s.ref.getMonth()]), ano: String(s.ref.getFullYear()) }); }
        const ini = inicioSemana(s.ref);
        const fim = soma(ini, 6);
        if (ini.getMonth() === fim.getMonth()) {
            return BJ_T.t('agenda.js.periodo.semana', { dia1: String(ini.getDate()), dia2: String(fim.getDate()), mes2: MES[fim.getMonth()], ano: String(fim.getFullYear()) });
        }

        return BJ_T.t('agenda.js.periodo.semana_dois_meses', { dia1: String(ini.getDate()), mes1: MES[ini.getMonth()], dia2: String(fim.getDate()), mes2: MES[fim.getMonth()], ano: String(fim.getFullYear()) });
    }

    function desenharCabecalho(L) {
        const noPeriodo = periodoDaVista()(L);
        const nCancel = noPeriodo.filter(e => e.status === 'cancelado').length;
        const nOcultas = s.ocultos.length;
        const partes = [noPeriodo.length === 1 ? U('resumo.evento_um') : U('resumo.evento_n', { n: String(noPeriodo.length) })];
        if (nCancel) { partes.push(nCancel === 1 ? U('resumo.cancelado_um') : U('resumo.cancelado_n', { n: String(nCancel) })); }
        if (nOcultas) { partes.push(nOcultas === 1 ? U('resumo.oculta_um') : U('resumo.oculta_n', { n: String(nOcultas) })); }
        document.getElementById('a1Titulo').textContent = tituloDoPeriodo();
        document.getElementById('a1Resumo').textContent = partes.join(' · ');
    }

    function quando(e, agora) {
        const dias = Math.round((dataDe(iso(e.ini)) - dataDe(iso(agora))) / 864e5);
        const hora = e.dia ? '' : ' ' + hhmm(e.ini);
        if (dias === 1) { return U('proximos.amanha') + hora; }
        if (dias < 7) { return SEM[e.ini.getDay()].toLowerCase() + hora; }

        return pad(e.ini.getDate()) + '/' + pad(e.ini.getMonth() + 1);
    }

    function desenharHoje(por, L, agora) {
        document.getElementById('a1HojeRotulo').textContent = U('data_longa', { semana: cap(DIA_LONGO[agora.getDay()]), dia: String(agora.getDate()), mes: MES[agora.getMonth()] });
        const eventos = por[iso(agora)] || [];
        document.getElementById('a1HojeLista').innerHTML = eventos.map(e => {
            const emCurso = agora >= e.ini && agora <= e.fim;
            return '<div class="a1-hoje-lin a1-l-' + e.leg + sit(e) + '" tabindex="0" role="button" data-a1-ev="' + esc(e.id) + '">'
                + '<div class="a1-hoje-hora">' + esc(horaCurta(e)) + '</div><span class="a1-chip-barra"></span>'
                + '<div class="a1-hoje-bloco"><div class="a1-hoje-tit">' + esc(e.t) + '</div><div class="a1-lin-meta">' + esc(metaTexto(e)) + '</div></div>'
                + (emCurso ? '<span class="a1-agora-tag">' + esc(U('hoje.agora')) + '</span>' : '') + '</div>';
        }).join('') + (eventos.length ? '' : '<div class="a1-hoje-vazio">' + esc(U('hoje.vazio')) + '</div>');
        const proximos = L.filter(e => e.ini > agora && e.status !== 'cancelado').slice(0, 4);
        document.getElementById('a1Proximos').innerHTML = proximos.map(e =>
            '<div class="a1-prox a1-l-' + e.leg + '" tabindex="0" role="button" data-a1-ev="' + esc(e.id) + '"><span class="a1-ponto"></span>'
            + '<span class="a1-prox-quando">' + esc(quando(e, agora)) + '</span><span class="a1-prox-tit">' + esc(e.t) + '</span></div>').join('');
    }

    function desenharLegendas(L) {
        const noPeriodo = periodoDaVista()(L);
        document.getElementById('a1LegTodas').hidden = s.ocultos.length === 0;
        document.getElementById('a1LegLista').innerHTML = ORDEM.map(k => {
            const oculto = s.ocultos.includes(k);
            return '<button type="button" class="a1-leg a1-l-' + k + (oculto ? ' is-oculto' : '') + '" data-a1-leg="' + k + '"><span class="a1-leg-ponto"></span>'
                + '<span class="a1-leg-nome">' + esc(nomeLeg(k)) + '</span><div class="a1-vao"></div><span class="a1-leg-n">' + noPeriodo.filter(e => e.leg === k).length + '</span></button>';
        }).join('');
    }

    function desenharFiltros() {
        document.querySelectorAll('[data-a1-st]').forEach(b => b.classList.toggle('is-on', b.dataset.a1St === s.status));
        document.getElementById('a1Cancelados').checked = s.verCancelados;
        document.getElementById('a1Limpar').hidden = !(s.pessoa || s.busca || s.status !== 'todos' || !s.verCancelados || s.ocultos.length);
    }

    /* ── Detalhe ────────────────────────────────────────────────────────── */
    function duracao(e) {
        const min = Math.round((e.fim - e.ini) / 6e4);
        if (min < 60) { return U('detalhe.duracao_minutos', { n: String(min) }); }
        const h = min / 60;
        if (h === 1) { return U('detalhe.duracao_hora'); }

        return U('detalhe.duracao_horas', { n: String(Math.round(h * 100) / 100).replace('.', ',') });
    }

    function recorrenciaTexto(e) {
        return ({ diario: U('detalhe.repete_diario'), semanal: U('detalhe.repete_semanal'), mensal: U('detalhe.repete_mensal'), anual: U('detalhe.repete_anual') })[e.repetir] || U('detalhe.repete_outro');
    }

    function htmlDetalhe(e) {
        const ids = e.part.slice();
        if (e.criador != null && !ids.includes(e.criador)) { ids.unshift(e.criador); }
        const pessoas = ids.map(id => ({ id, nome: nomeDe(id, e) })).filter(p => p.nome).map(p =>
            '<div class="a1-pessoa"><div class="a1-av a1-av-' + (Math.abs(p.id) % 6) + '">' + esc(p.nome.split(' ').map(x => x[0]).slice(0, 2).join('')) + '</div>'
            + '<span class="a1-pessoa-nome">' + esc(p.nome) + '</span>'
            + (p.id === e.criador ? '<span class="a1-organizador">' + esc(U('detalhe.organizador')) + '</span>' : '') + '</div>').join('');
        const horario = e.dia
            ? BJ_T.t('agenda.js.comum.dia_inteiro')
            : (e.h2 ? BJ_T.t('agenda.js.grade.hora_ate', { inicio: e.h1, fim: e.h2 }) + ' · ' + duracao(e) : e.h1);
        const linha = (icone, conteudo) => '<div class="a1-det-lin"><i class="bi ' + icone + '"></i><div class="a1-det-txt">' + conteudo + '</div></div>';
        const podeAgir = !e.daPasta && e.editavel && !!e.eventoId;
        const acoes = podeAgir
            ? (e.status === 'agendado' ? '<button type="button" class="a1-bt-concluir" data-a1-acao="concluir"><i class="bi bi-check2"></i>' + esc(BJ_T.t('agenda.js.acoes.concluir')) + '</button>' : '')
                + '<button type="button" class="a1-bt-editar" data-a1-acao="editar"><i class="bi bi-pencil"></i>' + esc(BJ_T.t('agenda.js.acoes.editar')) + '</button>'
                + (e.status !== 'cancelado' ? '<button type="button" class="a1-bt-cancelar" data-a1-acao="cancelar" title="' + esc(U('detalhe.cancelar_evento')) + '"><i class="bi bi-slash-circle"></i></button>' : '')
            : (e.daPasta && e.pastaId ? '<a class="a1-link" href="' + esc(comId(url.pasta, e.pastaId)) + '"><i class="bi bi-folder2-open"></i> ' + esc(BJ_T.t('agenda.js.detalhe.abrir_pasta', { nup: e.nup })) + '</a>' : '');

        return '<div class="a1-det-cab"><span class="a1-det-barra"></span><div class="a1-det-cabtxt">'
            + '<div class="a1-det-tags"><span class="a1-tag">' + esc(nomeLeg(e.leg)) + '</span><span class="a1-st is-' + e.status + '">' + esc(statusLabel(e.status)) + '</span></div>'
            + '<div class="a1-det-tit">' + esc(e.t) + '</div></div>'
            + '<button type="button" class="a1-det-x" data-a1-acao="fechar" title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="a1-det-corpo">'
            + linha('bi-clock', '<span>' + esc(U('data_longa', { semana: cap(DIA_LONGO[e.ini.getDay()]), dia: String(e.ini.getDate()), mes: MES[e.ini.getMonth()] })) + '</span><br><span class="a1-det-hor">' + esc(horario) + '</span>')
            + (e.local ? linha('bi-geo-alt', esc(e.local)) : '')
            + (e.recorrente ? linha('bi-arrow-repeat', esc(recorrenciaTexto(e))) : '')
            + linha('bi-eye', esc(e.privado ? U('detalhe.so_eu') : U('detalhe.escritorio')))
            + (e.desc ? '<div class="a1-det-desc">' + esc(e.desc) + '</div>' : '')
            + (pessoas ? '<div class="a1-det-pessoas"><div class="a1-rotulo">' + esc(U('detalhe.participantes')) + '</div><div class="a1-pessoas">' + pessoas + '</div></div>' : '')
            + '</div>'
            + (acoes ? '<div class="a1-det-acoes">' + acoes + '</div>' : '');
    }

    let detalheId = null;
    function desenharDetalhe() {
        const lateral = document.getElementById('a1Lateral');
        let caixa = document.getElementById('a1Detalhe');
        const e = s.selId == null ? null : normalizarPorId(s.selId);
        if (!e) {
            if (caixa) { caixa.remove(); }
            detalheId = null;
            return;
        }
        if (caixa && detalheId !== e.id) { caixa.remove(); caixa = null; }
        if (!caixa) {
            caixa = document.createElement('section');
            caixa.id = 'a1Detalhe';
            lateral.insertBefore(caixa, lateral.firstChild);
        }
        detalheId = e.id;
        caixa.className = 'a1-detalhe a1-l-' + e.leg + (e.status === 'cancelado' ? ' is-cancelado' : '');
        caixa.innerHTML = htmlDetalhe(e);
    }

    function normalizarPorId(id) {
        const c = s.itens.get(id) || Array.from(s.itens.values()).find(x => String(x.id) === String(id));
        return c ? normalizar(c) : null;
    }

    function selecionar(id) {
        s.selId = id;
        desenharDetalhe();
    }

    /* ── Janela de criar e editar compromisso (cópia do agenda-nova.js) ─── */
    const LEMBRETE_PADRAO = { aud: '60', reun: '10', prazo: '15', urg: '15', pess: '15', lemb: '15', meus: '15' };
    const LEMBRETES = [['0', BJ_T.t('agenda.js.form.lembrete.sem')], ['10', BJ_T.t('agenda.js.form.lembrete.min_10')], ['15', BJ_T.t('agenda.js.form.lembrete.min_15')], ['30', BJ_T.t('agenda.js.form.lembrete.min_30')], ['60', BJ_T.t('agenda.js.form.lembrete.hora_1')], ['1440', BJ_T.t('agenda.js.form.lembrete.dia_1')]];
    const REPETIR = [['', BJ_T.t('agenda.js.form.repetir.nao')], ['diario', BJ_T.t('agenda.js.form.repetir.diario')], ['semanal', BJ_T.t('agenda.js.form.repetir.semanal')], ['mensal', BJ_T.t('agenda.js.form.repetir.mensal')], ['anual', BJ_T.t('agenda.js.form.repetir.anual')]];
    const nomeCurto = n => String(n || '').split(' ').slice(0, 2).join(' ');
    const daEquipe = () => equipe.filter(u => u.id !== eu.id);
    let f = null;
    let editId = null;
    let editItem = null;
    let at = null;

    function novoEm(dia, hora) {
        const h2 = hora ? String(Number(hora.slice(0, 2)) + 1).padStart(2, '0') + ':00' : '10:00';
        f = { t: '', d: dia, h1: hora || '09:00', h2, dia: false, dias: 0, tipo: 'pess', vis: 'equipe', pasta: '', lem: '15', obs: '', avis: [], rep: '', repAte: '', part: [], lemManual: false, serie: false };
        editId = null;
        editItem = null;
        desenharForm('');
    }

    function editar(e) {
        const c = e.raw;
        const ini = c.inicioOriginal || (e.d + 'T' + (e.h1 || '09:00'));
        const fim = c.fimOriginal || '';
        const tipo = TIPOS_VALIDOS.includes(c.tipo) ? c.tipo : legDe(c.calendario);
        f = {
            t: e.t, d: ini.slice(0, 10), h1: e.dia ? '09:00' : ini.slice(11, 16), h2: e.dia ? '10:00' : fim.slice(11, 16),
            dia: e.dia, tipo, vis: e.privado ? 'privado' : 'equipe',
            pasta: c.pastaNup || '', lem: String(c.lembreteMinutos ?? LEMBRETE_PADRAO[tipo]), obs: c.observacao || '',
            avis: (c.avisados || []).slice(), rep: c.repetir || '', repAte: c.repetirAte || '', part: (c.participantes || []).slice(), lemManual: true,
            dias: fim ? Math.max(0, Math.round((dataDe(fim.slice(0, 10)) - dataDe(ini.slice(0, 10))) / 864e5)) : 0, serie: !!c.repetir,
            eraMeus: tipo === 'meus',
        };
        editId = e.eventoId;
        editItem = e;
        desenharForm('');
    }

    function rotulo(r, campo) { return '<label class="ag-f-rot">' + esc(r) + campo + '</label>'; }

    /* Seis tipos do desenho. Um compromisso antigo do tipo "meus" mostra Pessoal e continua gravando "meus". */
    function tiposDoForm() {
        return ORDEM.map(k => (k === 'pess' && f.eraMeus ? 'meus' : k));
    }

    function htmlForm(erro) {
        const tipos = tiposDoForm().map(k => {
            const on = f.tipo === k;
            const l = LEG[legDe(k)];
            return '<button type="button" class="ag-f-tipo" data-f-tipo="' + k + '" style="border-color:' + (on ? l.cor : '') + ';background:' + (on ? l.tint : '') + ';color:' + (on ? l.cor : '') + ';"><span style="background:' + l.cor + ';"></span>' + esc(nomeLeg(legDe(k))) + '</button>';
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
        const titulo = editId ? BJ_T.t('agenda.js.form.editar_titulo') : BJ_T.t('agenda.js.form.novo_titulo');
        document.getElementById('agModal').innerHTML = '<div class="ag-modal-fundo" data-fechar></div><div class="ag-modal-caixa"><div class="ag-modal" role="dialog" aria-modal="true" aria-label="' + esc(titulo) + '">'
            + '<div class="ag-modal-cab"><i class="bi bi-calendar-event"></i><b>' + esc(titulo) + '</b><button type="button" class="ag-modal-x" data-fechar title="' + esc(BJ_T.t('agenda.js.comum.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
            + '<div class="ag-modal-corpo ag-form">' + htmlForm(erro) + '</div>'
            + '<div class="ag-modal-rodape">'
            + (editId ? '<button type="button" class="ag-mbtn is-perigo" data-f-excluir><i class="bi bi-trash3"></i>' + esc(BJ_T.t('agenda.js.comum.excluir')) + '</button>' : '')
            + '<button type="button" class="ag-mbtn" data-fechar><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.comum.cancelar')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri is-fim" data-f-salvar><i class="bi bi-check2"></i>' + esc(BJ_T.t('agenda.js.comum.salvar')) + '</button></div></div></div>';
        const tit = document.querySelector('#agModal [data-f="t"]');
        if (tit && !erro) { tit.focus(); }
        const ta = document.querySelector('#agModal textarea');
        if (ta) { ajustarAltura(ta); }
    }

    function ajustarAltura(el) { el.style.height = 'auto'; el.style.height = Math.min(240, Math.max(56, el.scrollHeight + 2)) + 'px'; }

    function fecharModal() { document.getElementById('agModal').innerHTML = ''; document.getElementById('agPerg').innerHTML = ''; f = null; at = null; editItem = null; }

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

    async function salvar() {
        const erro = erroLocal();
        if (erro) { desenharForm(erro); return; }
        const corpo = { titulo: f.t, tipo: f.tipo, data: f.d, diaInteiro: f.dia, inicio: f.h1, fim: f.h2, visibilidade: f.vis, pasta: f.pasta.trim(), lembreteMinutos: Number(f.lem), observacao: f.obs, avisados: f.avis, repetir: f.rep, repetirAte: f.rep ? f.repAte : '', participantes: f.part, dias: f.dias };
        try {
            if (window.BJLembretes) { window.BJLembretes.permitir(); }
            const r = await postar(editId ? comId(url.salvar, editId) : url.criar, corpo);
            const destino = f.serie ? s.ref : dataDe(r.data);
            fecharModal();
            recarregarEm(destino);
            const lembra = !corpo.diaInteiro && corpo.inicio && corpo.lembreteMinutos !== 0;
            avisar(BJ_T.t('agenda.js.avisos.salvo') + (lembra ? ' ' + BJ_T.t('agenda.js.avisos.lembrete') : '') + (r.avisados ? ' ' + BJ_T.t('agenda.js.avisos.pessoas_avisadas', { n: String(r.avisados) }) : ''));
        } catch (e) {
            desenharForm(e.message);
        }
    }

    async function excluirItem(e) {
        if (!confirm(e.repetir ? BJ_T.t('agenda.js.confirmar.excluir_serie') : BJ_T.t('agenda.js.confirmar.excluir'))) { return; }
        try {
            await postar(comId(url.excluir, e.eventoId), {});
            fecharModal();
            s.selId = null;
            recarregarEm(s.ref);
            avisar(BJ_T.t('agenda.js.avisos.excluido'));
        } catch (x) { avisar(x.message); }
    }

    async function mudarSituacao(e, status) {
        const serie = e.recorrente ? ' ' + BJ_T.t('agenda.js.confirmar.vale_serie') : '';
        const temParticipantes = e.part.some(id => id !== eu.id);
        const pergunta = { concluido: BJ_T.t('agenda.js.confirmar.concluir'), agendado: BJ_T.t('agenda.js.confirmar.reabrir'), cancelado: BJ_T.t('agenda.js.confirmar.cancelar') + (temParticipantes ? ' ' + BJ_T.t('agenda.js.confirmar.participantes_avisados') : '') }[status];
        if ((serie || status === 'cancelado') && !confirm(pergunta + serie)) { return; }
        try {
            await postar(comId(url.status, e.eventoId), { status });
            recarregarEm(s.ref);
            avisar({ concluido: BJ_T.t('agenda.js.avisos.concluido'), cancelado: BJ_T.t('agenda.js.avisos.cancelado'), agendado: BJ_T.t('agenda.js.avisos.reaberto') }[status]);
        } catch (x) { avisar(x.message); }
    }

    function recarregarEm(ref) {
        dispatchEvent(new Event('bj-agenda:mudou'));
        // Outras abas abertas também refazem os lembretes.
        try { localStorage.setItem('bj-agenda-mudou', String(Date.now())); } catch (e) { /* sem armazenamento */ }
        s.itens = new Map();
        s.coberto = [];
        s.ref = ref;
        s.ref.setHours(0, 0, 0, 0);
        s.expandido = null;
        // A tela antiga fica até a resposta chegar: desenhar agora com o mapa vazio faria o Detalhe piscar.
        carregar(false, true);
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

    /* Cliques da janela; devolve true quando tratou. */
    function cliqueDoModal(t) {
        let a;
        if ((a = t.closest('[data-f-tipo]'))) { f.tipo = a.dataset.fTipo; if (!f.lemManual) { f.lem = LEMBRETE_PADRAO[f.tipo]; } desenharForm(''); return true; }
        if ((a = t.closest('[data-f-vis]'))) { f.vis = a.dataset.fVis; desenharForm(''); return true; }
        if (t.closest('[data-f-dia]')) { f.dia = !f.dia; desenharForm(''); return true; }
        if (t.closest('[data-f-mesmodia]')) { f.dias = 0; desenharForm(''); return true; }
        if ((a = t.closest('[data-f-part]'))) { const id = Number(a.dataset.fPart); f.part = f.part.includes(id) ? f.part.filter(x => x !== id) : f.part.concat(id); desenharForm(''); return true; }
        if (t.closest('[data-f-salvar]')) { salvar(); return true; }
        if (t.closest('[data-f-excluir]')) { if (editItem) { excluirItem(editItem); } return true; }
        if (t.closest('#agModal .ag-modal') && !t.closest('[data-fechar]')) { return true; }
        return false;
    }

    /* ── Eventos da página ──────────────────────────────────────────────── */
    function irPara(ref) {
        s.ref = ref;
        s.expandido = null;
        carregar(true);
    }

    function navegar(dir) {
        if (s.vista === 'semana') { irPara(soma(s.ref, 7 * dir)); return; }
        irPara(new Date(s.ref.getFullYear(), s.ref.getMonth() + dir, 1));
    }

    function acaoDoDetalhe(acao) {
        const e = s.selId == null ? null : normalizarPorId(s.selId);
        if (acao === 'fechar') { s.selId = null; desenharDetalhe(); return; }
        if (!e) { return; }
        if (acao === 'concluir') { mudarSituacao(e, 'concluido'); }
        if (acao === 'cancelar') { mudarSituacao(e, 'cancelado'); }
        if (acao === 'editar') { editar(e); }
    }

    raiz.addEventListener('click', ev => {
        const t = ev.target;
        const alvo = sel => t.closest(sel);
        let a;
        if (alvo('[data-fechar]')) { fecharModal(); return; }
        // Clicar no campo logo depois de um "@nome" reabre a lista.
        if (f && t.matches && t.matches('#agModal [data-f="obs"]')) { lerArroba(t); return; }
        if (cliqueDoModal(t)) { return; }
        if ((a = alvo('[data-a1-ev]'))) { selecionar(a.dataset.a1Ev); return; }
        if ((a = alvo('[data-a1-mais]'))) { s.expandido = a.dataset.a1Mais; desenhar(false); return; }
        if ((a = alvo('[data-a1-acao]'))) { acaoDoDetalhe(a.dataset.a1Acao); return; }
        if ((a = alvo('[data-a1-vista]'))) { s.vista = a.dataset.a1Vista; s.expandido = null; carregar(true); return; }
        if ((a = alvo('[data-a1-leg]'))) { const k = a.dataset.a1Leg; s.ocultos = s.ocultos.includes(k) ? s.ocultos.filter(x => x !== k) : s.ocultos.concat(k); desenhar(false); return; }
        if (alvo('#a1LegTodas')) { s.ocultos = []; desenhar(false); return; }
        if ((a = alvo('[data-a1-st]'))) { s.status = a.dataset.a1St; desenhar(false); return; }
        if (alvo('#a1Limpar')) {
            s.pessoa = ''; s.busca = ''; s.status = 'todos'; s.verCancelados = true; s.ocultos = [];
            document.getElementById('a1Busca').value = '';
            document.getElementById('a1Pessoa').value = '';
            desenhar(false);
            return;
        }
        if (alvo('#a1Novo')) {
            const sel = s.selId == null ? null : normalizarPorId(s.selId);
            novoEm(sel ? sel.d : iso(new Date()));
            return;
        }
        if (alvo('#a1Hoje')) { irPara(hoje0()); return; }
        if (alvo('#a1Ant')) { navegar(-1); return; }
        if (alvo('#a1Prox')) { navegar(1); return; }
        if (alvo('#a1Google')) { googleSincronizar(); return; }
        if (alvo('#a1GoogleSair')) { googleDesconectar(); }
        if (alvo('[data-google-sair="cancelar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-sair="ok"]')) { confirmarDesconectar(); }
        if (alvo('[data-google-perm="fechar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-perm="tentar"]')) { document.getElementById('agPerg').innerHTML = ''; irAoGoogle(); }
        if (alvo('[data-google-ir="cancelar"]')) { document.getElementById('agPerg').innerHTML = ''; }
        if (alvo('[data-google-ir="continuar"]')) { document.getElementById('agPerg').innerHTML = ''; irAoGoogle(); }
        if (alvo('[data-google-orientar="fechar"]')) { fecharAvisoDoGoogle(false); return; }
        if (alvo('[data-google-orientar="sincronizar"]')) { fecharAvisoDoGoogle(true); }
    });

    raiz.addEventListener('input', ev => {
        if (ev.target.id === 'a1Busca') { s.busca = ev.target.value; desenhar(false); }
    });
    raiz.addEventListener('change', ev => {
        if (ev.target.id === 'a1Pessoa') { s.pessoa = ev.target.value; desenhar(false); }
        if (ev.target.id === 'a1Cancelados') { s.verCancelados = ev.target.checked; desenhar(false); }
    });

    /* Teclado: Enter ou espaço num evento faz o mesmo que o clique. */
    raiz.addEventListener('keydown', ev => {
        const t = ev.target;
        if (!t.matches || !t.matches('[data-a1-ev]') || t.closest('#agModal')) { return; }
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); t.click(); }
    });

    document.addEventListener('keydown', ev => {
        if (ev.key === 'Escape' && document.querySelector('#agModal .ag-modal')) { fecharModal(); }
    });

    /* Link direto #ev=<id> (aviso do Chat, lembrete): vai ao dia do compromisso e o seleciona no Detalhe.
       Os ids terminam em AAAA-MM-DD. */
    async function abrirDoEndereco() {
        const m = location.hash.match(/ev=([^&]+)/);
        if (!m) { return; }
        const id = decodeURIComponent(m[1]);
        const dia = id.match(/(\d{4}-\d{2}-\d{2})$/);
        let e = acharItem(id);
        if (!e && dia) {
            s.ref = dataDe(dia[1]);
            await carregar(true);
            e = acharItem(id);
        }
        if (!e) { avisar(BJ_T.t('agenda.js.avisos.nao_encontrado')); return; }
        s.ref = dataDe(e.d);
        s.selId = e.id;
        await carregar(true);
        desenharDetalhe();
    }

    /* Google Agenda (cópia do agenda-nova.js). Com o servidor liberado, vai ao Google com volta=<página atual>;
       sem, avisa. A volta traz #google=<resultado> para o aviso. */
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
    function googleSemPermissao() {
        document.getElementById('agPerg').innerHTML = '<div class="ag-perg-fundo"></div><div class="ag-modal-caixa ag-perg-caixa"><div class="ag-perg" role="dialog" aria-modal="true" aria-label="' + esc(BJ_T.t('agenda.js.google.sem_permissao_titulo')) + '">'
            + '<div class="ag-perg-cab"><i class="bi bi-google"></i> ' + esc(BJ_T.t('agenda.js.google.sem_permissao_titulo')) + '</div>'
            + '<div class="ag-perg-txt">' + esc(BJ_T.t('agenda.js.google.sem_permissao_texto')) + '</div>'
            + '<div class="ag-perg-rodape"><button type="button" class="ag-mbtn ag-perg-nao" data-google-perm="fechar"><i class="bi bi-x"></i>' + esc(BJ_T.t('agenda.js.google.agora_nao')) + '</button>'
            + '<button type="button" class="ag-mbtn is-pri" data-google-perm="tentar"><i class="bi bi-google"></i>' + esc(BJ_T.t('agenda.js.google.tentar_de_novo')) + '</button></div></div></div>';
        const tentar = document.querySelector('#agPerg [data-google-perm="tentar"]');
        if (tentar) { tentar.focus(); }
    }
    /* Orientação para conectar: o servidor decide se abre (data-google-aviso: não conectado e sem dispensa). */
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

    /* ── Partida ────────────────────────────────────────────────────────── */
    const selPessoa = document.getElementById('a1Pessoa');
    selPessoa.insertAdjacentHTML('beforeend', equipe.map(u => '<option value="' + esc(u.id) + '">' + esc(u.nome) + '</option>').join(''));

    window.addEventListener('hashchange', abrirDoEndereco);
    // O que o link abre (volta do Google, #ev= do Chat ou do lembrete) vem antes; a orientação fica para a próxima entrada.
    const abreAlgoPeloLink = /(?:google|ev)=/.test(location.hash);
    avisoDaVoltaDoGoogle();
    if (!abreAlgoPeloLink) { googleAviso(); }
    // A linha do "agora" e o selo "agora" andam com o relógio.
    setInterval(() => { if (!raiz.contains(document.activeElement)) { desenhar(false); } }, 60000);
    carregar(true).then(abrirDoEndereco);
})();
