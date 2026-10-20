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
    const KD = 'bj-lembretes-disp';
    const AZ = '#0a6fa0';
    const PERF = {
        aud: { r: 'AUDIÊNCIA', ic: 'bi-bank', cor: '#5b3f9e', antes: 60, notas: [523, 659, 784, 1047, 784, 1047], dica: 'Confira a pauta, os documentos e o link da sala virtual.' },
        reun: { r: 'REUNIÃO DE EQUIPE', ic: 'bi-people-fill', cor: '#1e7b45', antes: 10, notas: [784, 988], dica: 'A equipe vai se reunir. Leve as pendências da semana.' },
        prazo: { r: 'PRAZO', ic: 'bi-hourglass-split', cor: '#c0392b', antes: 15, notas: [880, 660, 880, 660], dica: 'Prazo com horário: confirme o protocolo antes do limite.' },
        meus: { r: 'COMPROMISSO', ic: 'bi-calendar-event', cor: AZ, antes: 15, notas: [660, 880, 1100], dica: '' },
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
        const h = ev.quando.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        if (min > 59) { return 'Em ' + Math.round(min / 60) + 'h · ' + h; }
        if (min > 0) { return 'Em ' + min + ' min · às ' + h; }
        return min > -5 ? 'Agora · ' + h : 'Começou às ' + h;
    }

    function mostrar(ev) {
        if (fila.some(x => x.k === ev.k)) { return; }
        fila.push(ev);
        desenhar();
        som(ev.tipo);
        try {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('BlueJus · ' + ev.t, { body: rotulo(ev), icon: ICONE, tag: ev.k });
            }
        } catch (e) { /* sem notificação */ }
    }

    function linkDe(ev) {
        if (ev.pastaId && ev.tipo !== 'meus') { return URL_PASTA.replace(/0(?=[^0]*$)/, String(ev.pastaId)); }
        return URL_AGENDA + (ev.eventoId ? '#ev=ev-' + ev.eventoId + '-' + ev.dia : '');
    }

    function desenhar() {
        let box = document.getElementById('bjl-box');
        if (!fila.length) { if (box) { box.remove(); } return; }
        if (!box) {
            box = document.createElement('div');
            box.id = 'bjl-box';
            box.setAttribute('role', 'region');
            box.setAttribute('aria-label', 'Lembretes');
            box.className = 'bjl-box';
            document.body.appendChild(box);
        }
        const ids = fila.map(ev => idDe(ev.k));
        [...box.children].forEach(c => { if (!ids.includes(c.id)) { c.remove(); } });
        fila.filter(ev => !document.getElementById(idDe(ev.k))).forEach(ev => box.insertAdjacentHTML('beforeend', htmlCartao(ev)));
    }

    function htmlCartao(ev) {
        const P = PERF[ev.tipo] || PERF.meus;
        const dica = ev.obs || P.dica;
        return '<div class="bjl-cartao" id="' + idDe(ev.k) + '" role="alert" style="border-left-color:' + P.cor + ';">'
            + '<div class="bjl-topo"><span class="bjl-icone" style="background:' + P.cor + ';"><i class="bi ' + P.ic + '"></i></span>'
            + '<div class="bjl-textos"><div class="bjl-rotulo" style="color:' + P.cor + ';">' + P.r + ' · ' + esc(rotulo(ev)) + '</div><b>' + esc(ev.t) + '</b>'
            + (dica ? '<div class="bjl-dica">' + esc(dica) + '</div>' : '') + '</div>'
            + '<button type="button" class="bjl-x" data-bjl-x="' + esc(ev.k) + '" title="Dispensar" aria-label="Dispensar">×</button></div>'
            + '<div class="bjl-botoes"><button type="button" class="bjl-abrir" data-bjl-abrir="' + esc(ev.k) + '">' + ((ev.pastaId || ev.pastaDeExemplo) && ev.tipo !== 'meus' ? 'Abrir pasta' : 'Ver na agenda') + '</button>'
            + '<button type="button" class="bjl-adiar" data-bjl-adiar="' + esc(ev.k) + '">Lembrar em 10 min</button></div>'
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
        aud: ['Audiência de conciliação · Pasta 1180', 'aud'],
        reun: ['Reunião de equipe semanal', 'reun'],
        prazo: ['Prazo de contestação · Pasta 1180', 'prazo'],
        meus: ['Atendimento ao cliente', 'meus'],
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
        /* Pede a permissão de notificação do navegador; só chamar a partir de um clique. */
        permitir() {
            try { if ('Notification' in window && Notification.permission === 'default') { Notification.requestPermission(); } } catch (e) { /* sem notificação */ }
        },
    };

    function iniciar() {
        buscar();
        setInterval(checar, 30000);
        relogioBusca = setInterval(buscar, 5 * 60000);
        addEventListener('bj-agenda:mudou', buscar);
    }
    if (document.readyState === 'loading') { addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 1200)); }
    else { setTimeout(iniciar, 1200); }
})();
