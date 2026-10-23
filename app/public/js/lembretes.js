/* =============================================================================
   lembretes.js: lembretes da Agenda em qualquer tela (desenho 09, bj-lembretes.js).

   Pede ao servidor os lembretes das próximas horas (GET /agenda/lembretes) e confere a cada 30 s se
   algum chegou na hora. No horário, mostra o cartão no canto (ícone, som próprio por tipo, "Lembrar em
   10 min", "Ver na agenda" ou "Abrir pasta"). O que já foi dispensado ou adiado fica guardado neste
   navegador (localStorage), por chave do lembrete.

   Respeito ao navegador: o som só toca depois que a pessoa interage com a página (o áudio é liberado no
   primeiro clique ou toque), e a notificação do sistema só aparece se a permissão já foi dada. O pedido
   de permissão (BJLembretes.permitir) só é chamado a partir de um clique.
   ============================================================================= */
(function () {
    'use strict';

    if (window.BJLembretes) { return; }
    const raiz = document.getElementById('bjLembretes');
    if (!raiz) { return; }

    const URL_LEMBRETES = raiz.dataset.urlLembretes;
    const URL_AGENDA = raiz.dataset.urlAgenda;
    const URL_PASTA = raiz.dataset.urlPasta;
    const ICONE = raiz.dataset.icone;
    const URL_PUSH_CHAVE = raiz.dataset.urlPushChave;
    const URL_PUSH_INSCREVER = raiz.dataset.urlPushInscrever;
    const URL_PUSH_CANCELAR = raiz.dataset.urlPushCancelar;
    const URL_SAIR = raiz.dataset.urlSair;
    const SW = raiz.dataset.sw;
    const EU = raiz.dataset.eu || '';
    const KD = 'bj-lembretes-disp';
    const AZ = '#0a6fa0';
    const PERF = {
        aud: { r: BJ_T.t('moldura.js.lembretes.tipo_audiencia'), ic: 'bi-bank', cor: '#5b3f9e', antes: 60, notas: [523, 659, 784, 1047, 784, 1047], dica: BJ_T.t('moldura.js.lembretes.dica_audiencia') },
        reun: { r: BJ_T.t('moldura.js.lembretes.tipo_reuniao'), ic: 'bi-people-fill', cor: '#1e7b45', antes: 10, notas: [784, 988], dica: BJ_T.t('moldura.js.lembretes.dica_reuniao') },
        prazo: { r: BJ_T.t('moldura.js.lembretes.tipo_prazo'), ic: 'bi-hourglass-split', cor: '#c0392b', antes: 15, notas: [880, 660, 880, 660], dica: BJ_T.t('moldura.js.lembretes.dica_prazo') },
        meus: { r: BJ_T.t('moldura.js.lembretes.tipo_compromisso'), ic: 'bi-calendar-event', cor: AZ, antes: 15, notas: [660, 880, 1100], dica: '' },
    };
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    let lista = [];

    /* ── O que já foi dispensado ou adiado (neste navegador) ────────────── */
    function disp() {
        try { return JSON.parse(localStorage.getItem(KD) || '{}') || {}; } catch (e) { return {}; }
    }
    function marca(k, v) {
        const D = disp();
        D[k] = v;
        Object.keys(D).forEach(x => {
            const v = D[x];
            if (typeof v === 'number' && Date.now() - v > 7 * 864e5) { delete D[x]; }
            if (typeof v === 'string' && v.startsWith('adiar:') && Date.now() - Number(v.slice(6)) > 864e5) { delete D[x]; }
        });
        try { localStorage.setItem(KD, JSON.stringify(D)); } catch (e) { /* sem armazenamento */ }
    }

    /* ── Som: só depois da primeira interação com a página ──────────────── */
    let ctx = null;
    let liberado = false;
    function liberarAudio() {
        liberado = true;
        try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); if (ctx.state === 'suspended') { ctx.resume(); } } catch (e) { /* sem áudio */ }
    }
    addEventListener('pointerdown', liberarAudio, { once: true, capture: true });
    addEventListener('keydown', liberarAudio, { once: true, capture: true });
    addEventListener('touchend', liberarAudio, { once: true, capture: true });

    function som(tipo) {
        if (!liberado || !ctx) { return; }
        try {
            if (ctx.state === 'suspended') { ctx.resume(); }
            const t0 = ctx.currentTime;
            (PERF[tipo] || PERF.meus).notas.forEach((f, i) => {
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = f;
                g.gain.setValueAtTime(0.0001, t0 + i * 0.18);
                g.gain.exponentialRampToValueAtTime(0.22, t0 + i * 0.18 + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t0 + i * 0.18 + 0.32);
                o.connect(g).connect(ctx.destination);
                o.start(t0 + i * 0.18);
                o.stop(t0 + i * 0.18 + 0.34);
            });
        } catch (e) { /* sem áudio */ }
    }

    /* ── Cartões ────────────────────────────────────────────────────────── */
    const fila = [];
    const idDe = k => 'bjl-' + String(k).replace(/[^\w-]/g, '_');

    function rotulo(ev) {
        const min = Math.round((ev.quando - Date.now()) / 6e4);
        const h = BJFormato.hora(ev.quando);
        if (min > 59) { return BJ_T.t('moldura.js.lembretes.em_horas', { horas: Math.round(min / 60), hora: h }); }
        if (min > 0) { return BJ_T.t('moldura.js.lembretes.em_minutos', { minutos: min, hora: h }); }
        return min > -5 ? BJ_T.t('moldura.js.lembretes.agora', { hora: h }) : BJ_T.t('moldura.js.lembretes.comecou', { hora: h });
    }

    function mostrar(ev) {
        if (fila.some(x => x.k === ev.k)) { return; }
        fila.push(ev);
        desenhar();
        som(ev.tipo);
        try {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(BJ_T.t('moldura.js.lembretes.notificacao_titulo', { titulo: ev.t }), { body: rotulo(ev), icon: ICONE, tag: ev.k });
            }
        } catch (e) { /* sem notificação */ }
    }

    function linkDe(ev) {
        if (ev.pastaId && ev.tipo !== 'meus') { return URL_PASTA.replace(/0(?=[^0]*$)/, String(ev.pastaId)); }
        return URL_AGENDA + (ev.eventoId ? '#ev=ev-' + ev.eventoId + '-' + ev.dia : '');
    }

    // A pilha #bjl-box é compartilhada (o cartão do ponto, ponto-pendente.js, entra nela): aqui só os cartões da Agenda
    // (data-bjl-dono="agenda") saem, e a pilha só some vazia.
    function desenhar() {
        let box = document.getElementById('bjl-box');
        if (!fila.length) {
            if (box) { box.querySelectorAll('[data-bjl-dono="agenda"]').forEach(c => c.remove()); if (!box.children.length) { box.remove(); } }
            return;
        }
        if (!box) {
            box = document.createElement('div');
            box.id = 'bjl-box';
            box.setAttribute('role', 'region');
            box.setAttribute('aria-label', BJ_T.t('moldura.js.lembretes.regiao'));
            box.className = 'bjl-box';
            document.body.appendChild(box);
        }
        const ids = fila.map(ev => idDe(ev.k));
        box.querySelectorAll('[data-bjl-dono="agenda"]').forEach(c => { if (!ids.includes(c.id)) { c.remove(); } });
        fila.filter(ev => !document.getElementById(idDe(ev.k))).forEach(ev => box.insertAdjacentHTML('beforeend', htmlCartao(ev)));
    }

    function htmlCartao(ev) {
        const P = PERF[ev.tipo] || PERF.meus;
        const dica = ev.obs || P.dica;
        return '<div class="bjl-cartao" data-bjl-dono="agenda" id="' + idDe(ev.k) + '" role="alert" style="border-left-color:' + P.cor + ';">'
            + '<div class="bjl-topo"><span class="bjl-icone" style="background:' + P.cor + ';"><i class="bi ' + P.ic + '"></i></span>'
            + '<div class="bjl-textos"><div class="bjl-rotulo" style="color:' + P.cor + ';">' + esc(P.r) + ' · ' + esc(rotulo(ev)) + '</div><b>' + esc(ev.t) + '</b>'
            + (dica ? '<div class="bjl-dica">' + esc(dica) + '</div>' : '') + '</div>'
            + '<button type="button" class="bjl-x" data-bjl-x="' + esc(ev.k) + '" title="' + esc(BJ_T.t('moldura.js.lembretes.dispensar')) + '" aria-label="' + esc(BJ_T.t('moldura.js.lembretes.dispensar')) + '">\u00d7</button></div>'
            + '<div class="bjl-botoes"><button type="button" class="bjl-abrir" data-bjl-abrir="' + esc(ev.k) + '">' + ((ev.pastaId || ev.pastaDeExemplo) && ev.tipo !== 'meus' ? esc(BJ_T.t('moldura.js.lembretes.abrir_pasta')) : esc(BJ_T.t('moldura.js.lembretes.ver_na_agenda'))) + '</button>'
            + '<button type="button" class="bjl-adiar" data-bjl-adiar="' + esc(ev.k) + '">' + esc(BJ_T.t('moldura.js.lembretes.lembrar_em_10_min')) + '</button></div>'
            + '<div class="bjl-barra" style="background:' + P.cor + ';"></div></div>';
    }

    document.addEventListener('click', e => {
        const alvo = e.target.closest('[data-bjl-x], [data-bjl-abrir], [data-bjl-adiar]');
        if (!alvo) { return; }
        const k = alvo.dataset.bjlX || alvo.dataset.bjlAbrir || alvo.dataset.bjlAdiar;
        const ev = fila.find(x => x.k === k);
        if (!ev) { return; }
        fila.splice(fila.indexOf(ev), 1);
        if (alvo.dataset.bjlAdiar && !ev.teste) { marca(k, 'adiar:' + (Date.now() + 10 * 6e4)); }
        else if (!ev.teste) { marca(k, Date.now()); }
        desenhar();
        if (alvo.dataset.bjlAbrir && !ev.teste) { location.href = linkDe(ev); }
    });

    /* ── Busca no servidor e conferência ────────────────────────────────── */
    let semSessao = false;
    let relogioBusca = null;

    async function buscar() {
        if (semSessao) { return; }
        try {
            const r = await fetch(URL_LEMBRETES, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
            // 401 = sessão vencida: para de sondar (o login responde em JSON aos pedidos de dados).
            if (r.status === 401) { semSessao = true; clearInterval(relogioBusca); return; }
            if (!r.ok) { return; }
            const j = await r.json();
            lista = (j.itens || []).map(i => ({ k: i.k, t: i.titulo, quando: new Date(i.quando), aviso: new Date(i.aviso), tipo: i.tipo, obs: i.observacao, eventoId: i.eventoId, pastaId: i.pastaId, dia: i.dia }));
            checar();
        } catch (e) { /* tenta de novo no próximo ciclo */ }
    }

    function checar() {
        fila.forEach(ev => {
            const el = document.querySelector('#' + idDe(ev.k) + ' .bjl-rotulo');
            if (el) { el.textContent = (PERF[ev.tipo] || PERF.meus).r + ' · ' + rotulo(ev); }
        });
        const agora = Date.now();
        const D = disp();
        lista.forEach(ev => {
            const st = D[ev.k];
            if (typeof st === 'number') { return; }
            if (typeof st === 'string' && st.startsWith('adiar:') && agora < Number(st.slice(6))) { return; }
            if (agora >= ev.aviso.getTime() && agora - ev.quando.getTime() < 2 * 36e5) { mostrar(ev); }
        });
    }

    const EXEMPLOS = {
        aud: [BJ_T.t('moldura.js.lembretes.exemplo_audiencia'), 'aud'],
        reun: [BJ_T.t('moldura.js.lembretes.exemplo_reuniao'), 'reun'],
        prazo: [BJ_T.t('moldura.js.lembretes.exemplo_prazo'), 'prazo'],
        meus: [BJ_T.t('moldura.js.lembretes.exemplo_compromisso'), 'meus'],
    };

    window.BJLembretes = {
        checar,
        atualizar: buscar,
        /* "Testar lembretes": vem de um clique, então o som já pode tocar. */
        testar(tipo) {
            liberarAudio();
            const [t, tp] = EXEMPLOS[tipo] || EXEMPLOS.aud;
            // Como no desenho: audiência e prazo de exemplo são da "Pasta 1180" (o botão do teste só fecha).
            mostrar({ k: 'teste:' + Date.now(), t, quando: new Date(Date.now() + (PERF[tp].antes) * 6e4), tipo: tp, obs: '', teste: true, pastaDeExemplo: tp === 'aud' || tp === 'prazo' });
        },
        /* Pede a permissão de notificação do navegador; só chamar a partir de um clique. Com a permissão
           dada, inscreve este navegador no push (lembrete com o navegador fechado, entrega 5b). */
        permitir() {
            try {
                if (!('Notification' in window)) { return; }
                if (Notification.permission === 'default') { Notification.requestPermission().then(p => { if (p === 'granted') { ativarPush(); } }); }
                else if (Notification.permission === 'granted') { ativarPush(); }
            } catch (e) { /* sem notificação */ }
        },
    };

    /* Push com o navegador fechado: só com permissão já dada, Service Worker e chave VAPID no servidor. */
    const paraBytes = b64 => {
        const s = (b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(s), c => c.charCodeAt(0));
    };
    const JSON_POST = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const JA_INSCRITO = 'bjl-push-inscrito';
    const mesmaChave = (inscricao, chave) => {
        const atual = inscricao.options && inscricao.options.applicationServerKey;
        if (!atual) { return true; }
        const a = new Uint8Array(atual);
        const b = paraBytes(chave);
        return a.length === b.length && a.every((x, i) => x === b[i]);
    };
    const sessao = (k, v) => { try { if (v === undefined) { return sessionStorage.getItem(k); } if (v === null) { sessionStorage.removeItem(k); } else { sessionStorage.setItem(k, v); } } catch (e) { /* sem armazenamento */ } return null; };

    /* Inscrição feita com outra chave VAPID (troca de chave no servidor) não recebe nada: refaz. */
    async function inscricaoValida(reg, chave) {
        let inscricao = await reg.pushManager.getSubscription();
        if (inscricao && !mesmaChave(inscricao, chave)) { await inscricao.unsubscribe(); inscricao = null; }
        return inscricao || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: paraBytes(chave) });
    }

    async function ativarPush() {
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || Notification.permission !== 'granted' || !SW) { return; }
            const chave = (await (await fetch(URL_PUSH_CHAVE, { headers: { Accept: 'application/json' } })).json()).chave;
            if (!chave) { return; }
            const reg = await navigator.serviceWorker.register(SW, { scope: '/' });
            const inscricao = await inscricaoValida(reg, chave);
            if (sessao(JA_INSCRITO) === EU + '|' + inscricao.endpoint) { return; } // esta aba já mandou esta inscrição desta pessoa
            const corpo = Object.assign(inscricao.toJSON(), { fuso: Intl.DateTimeFormat().resolvedOptions().timeZone || '' });
            const r = await fetch(URL_PUSH_INSCREVER, { method: 'POST', headers: JSON_POST, body: JSON.stringify(corpo) });
            if (r.ok) { sessao(JA_INSCRITO, EU + '|' + inscricao.endpoint); }
        } catch (e) { /* sem push: o cartão na página continua valendo */ }
    }

    /* Ao sair, o navegador deixa de receber os avisos desta pessoa (computador compartilhado). Ouve na
       bolha da janela, depois do confirm do menu: saída cancelada (defaultPrevented) não mexe em nada. A
       navegação espera o cancelamento (no máximo 2 s), porque depois do logout a sessão e o CSRF somem. */
    function cancelarAoSair() {
        if (!URL_SAIR || !('serviceWorker' in navigator)) { return; }
        addEventListener('click', e => {
            const link = e.target.closest && e.target.closest('a[href]');
            if (e.defaultPrevented || !link || link.getAttribute('href') !== URL_SAIR) { return; }
            e.preventDefault();
            sessao(JA_INSCRITO, null);
            const cancelar = navigator.serviceWorker.getRegistration('/')
                .then(reg => reg && reg.pushManager.getSubscription())
                .then(inscricao => inscricao && fetch(URL_PUSH_CANCELAR, { method: 'POST', keepalive: true, headers: JSON_POST, body: JSON.stringify({ endpoint: inscricao.endpoint }) }));
            Promise.race([cancelar, new Promise(ok => setTimeout(ok, 2000))]).catch(() => {}).then(() => { location.href = URL_SAIR; });
        });
    }

    function iniciar() {
        buscar();
        ativarPush();
        cancelarAoSair();
        setInterval(checar, 30000);
        relogioBusca = setInterval(buscar, 5 * 60000);
        addEventListener('bj-agenda:mudou', buscar);
        addEventListener('storage', ev => { if (ev.key === 'bj-agenda-mudou') { buscar(); } });
    }
    if (document.readyState === 'loading') { addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 1200)); }
    else { setTimeout(iniciar, 1200); }
})();
