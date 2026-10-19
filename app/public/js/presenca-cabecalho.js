/* =============================================================================
   presenca-cabecalho.js: selo de presença no avatar e painel "quem está on-line" (desenho 03 VS 18.10.26, dc
   L.67 e L.299-309, lógica L.662-685; spec docs/specs/presenca-e-ponto-no-cabecalho.md §2).

   Não há segunda presença: quem está on-line chega pelo batimento da Central (evento `bj-central:presenca`, a cada
   20 s); nomes e cargos vêm de `central_equipe`, guardados na sessão do navegador e só pedidos de novo quando aparece
   alguém que não está no mapa ou quando o painel abre. Horas on-line de hoje: só suas, contadas neste navegador.
   DOM só com textContent (regra 5 da Central).
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-presenca-cabecalho]');
    if (!raiz) { return; }
    var selo = raiz.querySelector('[data-presenca-selo]');
    var chave = raiz.getAttribute('data-chave') || '0:0';
    var euId = Number(raiz.getAttribute('data-eu-id')) || 0;
    var urlEquipe = raiz.getAttribute('data-url-equipe');

    // dc L.665 `ST`: rótulo, cor e ordem do grupo.
    var ST = { on: ['On-line', '#2ecc71', 1], ausente: ['Ausente', '#f0b429', 2], ocupado: ['Não perturbe', '#e74c3c', 3], off: ['Desligado', '#b7c4cd', 4] };
    var NOTA = 'Ponto e horas on-line são só seus: ninguém da equipe vê. Contagem de 00:00 às 23:59, zera todo dia.';
    var HORAS_A_CADA_MS = 20000;
    var HORAS_INTERVALO_MAX_MS = 90000;   // dc L.667: só soma intervalo de até 90 s

    var online = [];          // [{id, status, semAtividadeMin}] do último batimento
    var anteriores = null;    // ids não desligados na leitura anterior (dc `_onAnt`)
    var evento = '';
    var painel = null;
    var veu = null;
    var alternar = false;     // dc `_alt`: a sua linha alterna "Você" e o ponto a cada 4 s quando o ponto não tem ação
    function ponto() { return window.PresencaPonto || null; }

    function ler(armazem, k, padrao) {
        try { var v = JSON.parse(armazem.getItem(k)); return v === null ? padrao : v; } catch (e) { return padrao; }
    }
    function gravar(armazem, k, v) {
        try { armazem.setItem(k, JSON.stringify(v)); } catch (e) { /* sem armazenamento: só não lembra */ }
    }
    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = texto; }
        return n;
    }
    function icone(classe) { var i = el('i', 'bi ' + classe); i.setAttribute('aria-hidden', 'true'); return i; }
    function primeiroNome(nome) { return String(nome || '').split(' ')[0]; }
    function iniciais(nome) {
        return String(nome || '').split(' ').filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
    }
    function horaAgora() { return new Date().toTimeString().slice(0, 5); }
    function hmDe(iso) {   // o mesmo da Central (central.js `hmDe`): "hoje às HH:MM" ou "dd/mm às HH:MM"
        var d = new Date(iso);
        var hoje = new Date();
        var dia = d.toDateString() === hoje.toDateString() ? 'hoje' : String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');

        return dia + ' às ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }
    /* O X de fechar que o protótipo põe em toda janela sem um (bluejus-central.js, "X de fechar em toda janelinha"). */
    function botaoFechar() {
        var b = el('button', 'presenca-fechar');
        b.type = 'button';
        b.setAttribute('aria-label', 'Fechar');
        b.title = 'Fechar (Esc)';
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '12'); svg.setAttribute('height', '12'); svg.setAttribute('viewBox', '0 0 12 12'); svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('d', 'M2 2l8 8M10 2l-8 8'); p.setAttribute('stroke', 'currentColor'); p.setAttribute('stroke-width', '1.8'); p.setAttribute('stroke-linecap', 'round');
        svg.appendChild(p);
        b.appendChild(svg);
        b.addEventListener('click', function (e) { e.stopPropagation(); fechar(); selo.focus(); });

        return b;
    }

    /* ── Equipe (nomes e cargos), na sessão do navegador ─────────────────────── */
    var CHAVE_EQUIPE = 'presenca-equipe:' + chave;
    var equipe = ler(window.sessionStorage, CHAVE_EQUIPE, null);   // [{id, nome, cargo}]
    var pedindo = null;
    function carregarEquipe() {
        if (pedindo || !urlEquipe) { return pedindo || Promise.resolve(); }
        pedindo = fetch(urlEquipe, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok && (r.headers.get('Content-Type') || '').indexOf('application/json') !== -1 ? r.json() : null; })
            .then(function (d) {
                if (d && d.equipe) {
                    equipe = d.equipe.map(function (m) { return { id: m.id, nome: m.nome, cargo: m.cargo || '', vistoEm: m.vistoEm || null }; });
                    gravar(window.sessionStorage, CHAVE_EQUIPE, equipe);
                }
            })
            .catch(function () { /* sem a equipe, o selo segue contando */ })
            .finally(function () { pedindo = null; });
        return pedindo;
    }
    function pessoaDe(id) { return (equipe || []).filter(function (m) { return m.id === id; })[0] || null; }

    /* ── Estado de cada pessoa (dc L.666) ───────────────────────────────────── */
    function chaveDeStatus(id) {
        if (id === euId) { return 'on'; }   // dc: você aparece on-line enquanto a página está aberta
        var p = online.filter(function (o) { return o.id === id; })[0];
        if (!p) { return 'off'; }
        if (p.status === 'ocupado') { return 'ocupado'; }
        if (p.status === 'ausente' || p.status === 'inativo') { return 'ausente'; }

        return 'on';
    }
    function detalhe(m, k) {
        if (m.id === euId) { return 'Você'; }
        if (k === 'off') { return m.vistoEm ? 'visto por último ' + hmDe(m.vistoEm) : 'sem atividade recente'; }   // det da Central
        if (k === 'ausente') {
            var p = online.filter(function (o) { return o.id === m.id; })[0];
            return p && p.semAtividadeMin ? 'sem atividade há ' + p.semAtividadeMin + ' min' : 'ausente';
        }

        return m.cargo || 'on-line';
    }
    function pessoas() {
        var lista = (equipe || []).map(function (m) { var k = chaveDeStatus(m.id); return { m: m, k: k, eu: m.id === euId }; });
        return lista.sort(function (a, b) {
            return ST[a.k][2] - ST[b.k][2] || (b.eu - a.eu) || String(a.m.nome).localeCompare(String(b.m.nome), 'pt-BR');
        });
    }

    /* ── Horas on-line hoje (dc L.667): o último instante é COMPARTILHADO, duas abas não contam em dobro ── */
    var CHAVE_HORAS = 'presenca-horas:' + chave;
    function hojeLocal() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
    function contarHoras() {
        var hr = ler(window.localStorage, CHAVE_HORAS, {});
        if (hr.d !== hojeLocal()) { hr = { d: hojeLocal(), s: 0, l: 0 }; }
        var agora = Date.now();
        var meiaNoite = new Date(); meiaNoite.setHours(0, 0, 0, 0);
        var dt = agora - Math.max(hr.l || agora, meiaNoite.getTime());
        if (dt > 0 && dt <= HORAS_INTERVALO_MAX_MS) { hr.s = (hr.s || 0) + dt / 1000; }
        hr.l = agora;
        gravar(window.localStorage, CHAVE_HORAS, hr);
    }
    function textoHoras() {
        var hr = ler(window.localStorage, CHAVE_HORAS, {});
        if (hr.d !== hojeLocal() || !hr.s) { return 'iniciando'; }
        var m = Math.floor(hr.s / 60);
        if (m < 1) { return 'menos de 1 min hoje'; }
        var h = Math.floor(m / 60);

        return (h ? h + 'h ' + String(m % 60).padStart(2, '0') : String(m % 60)) + 'min hoje';
    }

    /* ── Som de entrada e saída (dc L.664), uma vez só com duas janelas à vista ── */
    var CHAVE_SOM = 'presenca-som:' + chave;
    var CHAVE_BIPE = 'presenca-bipe:' + chave;
    var audio = null;
    function somLigado() { return ler(window.localStorage, CHAVE_SOM, 'on') !== 'off'; }
    function bipe(sobe) {
        if (!somLigado()) { return; }
        try {
            var ctx = audio || (audio = new (window.AudioContext || window.webkitAudioContext)());
            var t = ctx.currentTime;
            [0, 0.12].forEach(function (d, i) {
                var o = ctx.createOscillator();
                var g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = sobe ? (i ? 880 : 660) : (i ? 520 : 700);
                g.gain.setValueAtTime(0.0001, t + d);
                g.gain.exponentialRampToValueAtTime(0.12, t + d + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.16);
                o.connect(g); g.connect(ctx.destination);
                o.start(t + d); o.stop(t + d + 0.18);
            });
        } catch (e) { /* sem áudio */ }
    }
    function bipeUmaVez(assinatura, sobe) {
        var ultimo = ler(window.localStorage, CHAVE_BIPE, {});
        if (ultimo.a === assinatura && Date.now() - (ultimo.t || 0) < 30000) { return; }
        gravar(window.localStorage, CHAVE_BIPE, { a: assinatura, t: Date.now() });
        bipe(sobe);
    }

    /* ── Entrou / saiu (dc L.681): compara quem NÃO está desligado entre duas leituras ── */
    function detectarEvento() {
        if (!equipe) { return; }   // sem a equipe não há com o que comparar (e a primeira leitura válida não gera evento)
        var agora = (equipe || []).filter(function (m) { return chaveDeStatus(m.id) !== 'off'; }).map(function (m) { return m.id; });
        if (anteriores) {
            var entrou = agora.filter(function (id) { return anteriores.indexOf(id) === -1; });
            var saiu = anteriores.filter(function (id) { return agora.indexOf(id) === -1; });
            var quem = pessoaDe(entrou.length ? entrou[0] : saiu[0]);
            if (quem && (entrou.length || saiu.length)) {
                evento = primeiroNome(quem.nome) + (entrou.length ? ' entrou' : ' saiu') + ' · ' + horaAgora();
                bipeUmaVez(quem.id + (entrou.length ? '+' : '-'), !!entrou.length);
            }
        }
        anteriores = agora;
    }

    /* ── Selo (dc L.67): N = quem não está desligado, você incluído ───────── */
    function desenharSelo() {
        var ligados = pessoas().filter(function (x) { return x.k !== 'off'; });
        var n = equipe ? ligados.length : online.length;
        selo.textContent = String(n);
        selo.hidden = n < 1;
        var nomes = ligados.map(function (x) { return primeiroNome(x.m.nome); });
        selo.title = n + ' on-line' + (nomes.length ? ': ' + nomes.join(', ') : '');
        selo.setAttribute('aria-label', 'Quem está on-line: ' + n);
    }

    /* ── Painel (dc L.300-308) ──────────────────────────────────────────── */
    function linha(x, i, lista) {
        var frag = document.createDocumentFragment();
        if (i === 0 || lista[i - 1].k !== x.k) {
            var n = lista.filter(function (y) { return y.k === x.k; }).length;
            frag.appendChild(el('div', 'presenca-grupo', ST[x.k][0].toUpperCase() + ' · ' + n));
        }
        var l = el('div', 'presenca-linha' + (x.k === 'off' ? ' is-off' : ''));
        var av = el('span', 'presenca-av', iniciais(x.m.nome));
        var pt = el('span', 'presenca-av-ponto');
        pt.style.background = ST[x.k][1];
        pt.title = ST[x.k][0];
        av.appendChild(pt);
        l.appendChild(av);
        var tx = el('span', 'presenca-textos');
        tx.appendChild(el('b', 'presenca-nome', x.m.nome));
        tx.appendChild(subtitulo(x));
        l.appendChild(tx);
        var dir = el('span', 'presenca-dir');
        var st = el('span', 'presenca-status', ST[x.k][0]);
        st.style.color = ST[x.k][1];
        dir.appendChild(st);
        if (x.eu) {   // horas: só na sua linha (dc `temHoras`)
            var h = el('span', 'presenca-horas');
            h.title = 'Tempo on-line hoje';
            h.appendChild(icone('bi-clock'));
            h.appendChild(document.createTextNode(' ' + textoHoras()));
            dir.appendChild(h);
        }
        l.appendChild(dir);
        frag.appendChild(l);

        return frag;
    }
    /* Subtítulo da linha; na sua, o atalho de ponto (presenca-ponto.js, dc `pontoDe`): fixo quando tem ação. */
    function subtitulo(x) {
        var pt = x.eu && ponto() ? ponto().linha() : null;
        if (!pt || !(pt.acao || alternar)) { return el('span', 'presenca-sub', detalhe(x.m, x.k)); }
        var sub = el('span', 'presenca-sub presenca-sub--ponto' + (pt.acao ? ' is-acao' : ''));
        sub.style.color = pt.cor;
        sub.appendChild(icone(pt.icone));
        sub.appendChild(document.createTextNode(pt.texto));
        if (pt.acao) {
            sub.setAttribute('role', 'button');
            sub.setAttribute('tabindex', '0');
            sub.title = pt.dica;
            var bater = function (e) { e.stopPropagation(); ponto().bater(); };
            sub.addEventListener('click', bater);
            sub.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bater(e); } });
        }

        return sub;
    }

    function desenharPainel() {
        if (!painel) { return; }
        var focoNoSom = document.activeElement && document.activeElement.classList.contains('presenca-som');
        var lista = pessoas();
        var nOn = lista.filter(function (x) { return x.k === 'on'; }).length;
        var nAus = lista.filter(function (x) { return x.k === 'ausente'; }).length;
        painel.textContent = '';

        var faixa = el('div', 'presenca-faixa');
        faixa.appendChild(el('span', 'presenca-faixa-ponto'));
        faixa.appendChild(el('span', 'presenca-faixa-resumo', nOn + ' on-line' + (nAus ? ' · ' + nAus + ' ausente(s)' : '')));
        var som = el('button', 'presenca-som');
        som.type = 'button';
        som.title = somLigado() ? 'Som de entrada e saída ligado' : 'Som desligado';
        som.setAttribute('aria-label', som.title);
        som.appendChild(icone(somLigado() ? 'bi-volume-up' : 'bi-volume-mute'));
        som.addEventListener('click', function (e) {
            e.stopPropagation();
            var ligar = !somLigado();
            gravar(window.localStorage, CHAVE_SOM, ligar ? 'on' : 'off');
            if (ligar) { bipe(true); }
            desenharPainel();
        });
        faixa.appendChild(som);
        faixa.appendChild(botaoFechar());
        painel.appendChild(faixa);

        if (evento) {
            var ev = el('div', 'presenca-evento');
            ev.appendChild(icone('bi-arrow-left-right'));
            ev.appendChild(document.createTextNode(' ' + evento));
            painel.appendChild(ev);
        }
        lista.forEach(function (x, i) { painel.appendChild(linha(x, i, lista)); });
        var nota = el('div', 'presenca-nota');
        nota.appendChild(icone('bi-info-circle'));
        nota.appendChild(document.createTextNode(' ' + NOTA));
        painel.appendChild(nota);
        if (equipe && lista.length <= 1) { painel.appendChild(el('div', 'presenca-vazio', 'Só você está na equipe por enquanto.')); }
        if (focoNoSom) { som.focus(); }   // redesenhar a cada 20 s não tira o foco de quem está no botão
    }
    function fecharMenuDoAvatar() {   // dc `onTg`: abrir o painel fecha o menu do avatar
        document.querySelectorAll('[data-mu-toggle]').forEach(function (t) {
            var d = window.bootstrap && window.bootstrap.Dropdown && window.bootstrap.Dropdown.getInstance(t);
            if (d) { d.hide(); }
        });
    }
    function abrir() {
        fecharMenuDoAvatar();
        veu = el('div', 'presenca-veu');
        veu.addEventListener('click', function () { fechar(); selo.focus(); });
        painel = el('div', 'presenca-painel');
        painel.setAttribute('role', 'dialog');
        painel.setAttribute('aria-label', 'Quem está on-line');
        document.body.appendChild(veu);
        document.body.appendChild(painel);
        selo.setAttribute('aria-expanded', 'true');
        if (ponto()) { ponto().atualizar(); }
        desenharPainel();
        var som = painel.querySelector('.presenca-som');
        if (som) { som.focus(); }   // o foco entra no painel (Esc fecha e volta ao selo)
        carregarEquipe().then(function () { desenharSelo(); desenharPainel(); });
    }
    function fechar() {
        if (painel) { painel.remove(); painel = null; }
        if (veu) { veu.remove(); veu = null; }
        selo.setAttribute('aria-expanded', 'false');
    }

    selo.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (painel) { fechar(); selo.focus(); } else { abrir(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && painel) { fechar(); selo.focus(); }
    });

    /* ── Batimento da Central ──────────────────────────────────────────── */
    document.addEventListener('bj-central:presenca', function (ev) {
        online = (ev.detail && ev.detail.online) || [];
        if (painel && ponto()) { ponto().atualizar(); }   // com o painel aberto, o ponto é relido a cada batimento
        var desconhecido = online.some(function (o) { return !pessoaDe(o.id); });
        (equipe && !desconhecido ? Promise.resolve() : carregarEquipe()).then(function () {
            detectarEvento();
            desenharSelo();
            desenharPainel();
        });
    });

    if (ponto()) { ponto().aoMudar(function () { desenharPainel(); }); }
    window.setInterval(function () { if (painel) { alternar = !alternar; desenharPainel(); } }, 4000);   // dc `_altT`

    // Relógio próprio das horas: segue com a aba oculta (dc), o navegador só o espaça.
    contarHoras();
    window.setInterval(function () { contarHoras(); if (painel) { desenharPainel(); } }, HORAS_A_CADA_MS);
}());
