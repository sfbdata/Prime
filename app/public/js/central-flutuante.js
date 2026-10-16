/*
 * Central de Comunicação — lançador flutuante e janela (bluejus-central.js do Designer 13/10; linha ao lado).
 *
 * Só a GEOMETRIA e os estados do componente: lançador (L6399-6412), janela que abre, fecha, minimiza até o lançador,
 * maximiza, move com ímã e encaixe em meia tela (L6432-6451), redimensiona por 8 alças (L6349-6358). As conversas são
 * do central.js, na mesma janela da página /central: ele avisa os contadores (`bj-central:contadores`) e recebe a
 * visibilidade (`bj-central:visibilidade`). Preferências de posição/tamanho/alertas ficam neste navegador, por
 * usuário (localStorage, como no desenho); a janela sempre começa fechada (o desenho não lembra "aberto").
 * Nada de innerHTML: SVG e textos vêm do Twig; aqui só textContent e atributos.
 */
(function () {
    'use strict';

    var V = 64;                       // parte do lançador que nunca sai da tela (L6418)
    var MW = 320, MH = 320;           // tamanho mínimo da janela (L6352)
    var IMA = 14;                     // ímã nas bordas (L6440)
    var TAM_PADRAO = { w: 380, h: 580 };   // L6325
    var POS_PADRAO = { r: 18, b: 18 };     // L5412
    var AVISO_MS = 2600;              // aviso(), L5398
    var SALTO_MS = 2400;              // ui.pulso, L141

    function iniciar(raizLancador) {
        var janela = document.querySelector('[data-flutuante-janela]');
        var central = janela ? janela.querySelector('[data-central]') : null;
        if (!janela || !central) { return; }

        var lancador = raizLancador.querySelector('[data-flutuante-lancador]');
        var badge = raizLancador.querySelector('[data-flutuante-badge]');
        var badgeMencoes = raizLancador.querySelector('[data-flutuante-mencoes]');
        var btnMinimizar = janela.querySelector('[data-flutuante-minimizar]');
        var btnMaximizar = janela.querySelector('[data-flutuante-maximizar]');
        var btnFechar = janela.querySelector('[data-flutuante-fechar]');
        var cab = central.querySelector('.bj-central-cab');

        var chave = 'bj-central-prefs:' + (raizLancador.dataset.euId || '0');
        var prefs = lerPrefs();
        var ui = { aberto: false, naoLidas: 0, mencoes: 0, timerSalto: null };

        // ── preferências (só este navegador) ─────────────────────────────────────────
        function lerPrefs() {
            var base = { pos: null, win: null, tam: null, snap: null, janela: 'normal', alertas: true, som: true, dnd: 0, texto: 'normal' };
            try {
                var salvo = JSON.parse(window.localStorage.getItem(chave) || '{}');
                Object.keys(base).forEach(function (k) { if (salvo && salvo[k] !== undefined) { base[k] = salvo[k]; } });
            } catch (e) { /* sem armazenamento (aba privada): fica o padrão */ }
            if (base.janela !== 'max') { base.janela = 'normal'; }   // L43: "min" de antes vira normal
            return base;
        }

        function salvarPrefs() {
            try { window.localStorage.setItem(chave, JSON.stringify(prefs)); } catch (e) { /* idem */ }
        }

        function silencio() {   // "Não perturbe" (L6266): sem som nem animação até a hora marcada
            return prefs.dnd && Date.now() < prefs.dnd;
        }

        // ── lançador ─────────────────────────────────────────────────────────────────
        function desenharLancador() {
            var nl = ui.naoLidas;
            lancador.dataset.estado = ui.aberto ? 'aberto' : (nl ? 'novas' : 'calmo');
            lancador.setAttribute('aria-expanded', String(ui.aberto));
            var resumo = nl ? (nl === 1 ? '1 não lida' : nl + ' não lidas') : 'tudo em dia';
            lancador.title = 'Central de Comunicação · ' + resumo;
            lancador.setAttribute('aria-label', 'Central de Comunicação, ' + resumo);
            lancador.classList.toggle('bj-flutuante--anim', !!(prefs.alertas && !silencio() && !ui.aberto && nl));
            badge.hidden = !nl;
            badge.textContent = nl > 99 ? '99+' : String(nl);
            badgeMencoes.hidden = !(ui.mencoes && !ui.aberto);
            badgeMencoes.textContent = '@' + ui.mencoes;
            badgeMencoes.title = ui.mencoes + (ui.mencoes === 1 ? ' menção' : ' menções');
        }

        function posicionarLancador() {
            var pos = ui.aberto && prefs.janela === 'max' ? { r: 12, b: 12 } : (prefs.pos || POS_PADRAO);
            raizLancador.style.right = pos.r + 'px';
            raizLancador.style.bottom = pos.b + 'px';
            window.requestAnimationFrame(function () {   // L6418: nunca menos de 64px à vista
                var r = raizLancador.getBoundingClientRect(), dr = 0, db = 0;
                if (r.top < 0) { db = r.top; }
                if (r.right < V) { dr = r.right - V; }
                if (r.left > window.innerWidth - V) { dr = r.left - window.innerWidth + V; }
                if (dr || db) {
                    raizLancador.style.right = (pos.r + dr) + 'px';
                    raizLancador.style.bottom = (pos.b + db) + 'px';
                }
            });
        }

        function saltar() {   // L141: salto de 2,4 s quando chega mensagem
            if (!prefs.alertas || silencio()) { return; }
            lancador.classList.remove('bj-flutuante--salto');
            void lancador.offsetWidth;
            lancador.classList.add('bj-flutuante--salto');
            window.clearTimeout(ui.timerSalto);
            ui.timerSalto = window.setTimeout(function () { lancador.classList.remove('bj-flutuante--salto'); }, SALTO_MS);
        }

        var ultimoToque = 0, audio = null;
        function tocar() {   // tocar(), L91-104: seno 880 Hz e 1320 Hz (+0,11 s), no mínimo 2,5 s entre toques
            if (!prefs.som || silencio() || Date.now() - ultimoToque < 2500) { return; }
            ultimoToque = Date.now();
            try {
                audio = audio || new (window.AudioContext || window.webkitAudioContext)();
                if (audio.state === 'suspended') { audio.resume().catch(function () { /* sem gesto do usuário ainda */ }); }
                [[880, 0], [1320, 0.11]].forEach(function (n) {
                    var o = audio.createOscillator(), g = audio.createGain(), t0 = audio.currentTime + n[1];
                    o.type = 'sine';
                    o.frequency.value = n[0];
                    o.connect(g);
                    g.connect(audio.destination);
                    g.gain.setValueAtTime(0, t0);
                    g.gain.linearRampToValueAtTime(0.12, t0 + 0.015);   // volume "médio" do desenho (VOL.medio = 1)
                    g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.28);
                    o.start(t0);
                    o.stop(t0 + 0.3);
                });
            } catch (e) { /* navegador bloqueou o áudio: segue só com o aviso visual (como no desenho) */ }
        }

        var avisoAtual = null;
        function avisar(texto) {   // L6410: acima do lançador, some em 2,6 s
            if (avisoAtual) { avisoAtual.remove(); }
            var caixa = document.createElement('div');
            caixa.className = 'bj-flutuante-aviso';
            caixa.setAttribute('role', 'status');
            var ic = document.createElement('i');
            ic.className = 'bi bi-bell-fill';
            ic.setAttribute('aria-hidden', 'true');
            caixa.appendChild(ic);
            caixa.appendChild(document.createTextNode(texto));
            raizLancador.insertBefore(caixa, lancador);
            avisoAtual = caixa;
            window.setTimeout(function () { if (avisoAtual === caixa) { caixa.remove(); avisoAtual = null; } }, AVISO_MS);
        }

        // ── janela: dimensões e posição (winDims/winClamp/winCss, L6423-6431) ──────
        function dims() {
            var t = prefs.tam || TAM_PADRAO;
            return { w: Math.min(t.w, window.innerWidth - 16), h: Math.min(t.h, window.innerHeight - 16) };
        }

        function prender(x, y, w, h) {
            return {
                x: Math.round(Math.min(Math.max(x, 120 - w), window.innerWidth - 120)),
                y: Math.round(Math.min(Math.max(y, 0), window.innerHeight - 52))
            };
        }

        function aplicarJanela() {
            // Sem transição: o desenho recria a janela a cada render, então maximizar, restaurar e encaixar são
            // instantâneos lá (L6326; a transition declarada nunca chega a correr). Aqui o elemento é o mesmo e a
            // transição de largura/altura deixava a janela ~1 s no tamanho máximo, fora da tela, ao restaurar.
            janela.classList.add('bj-flutuante--instantaneo');
            var st = janela.style;
            if (prefs.janela === 'max') {
                janela.dataset.modo = 'max';
                st.left = '12px'; st.top = '12px'; st.width = 'calc(100vw - 24px)'; st.height = 'calc(100vh - 24px)';
            } else if (prefs.snap) {
                janela.dataset.modo = 'encaixe';
                var W = Math.floor(window.innerWidth / 2) - 12;
                st.top = '8px'; st.height = 'calc(100vh - 16px)'; st.width = W + 'px';
                st.left = (prefs.snap === 'esq' ? 8 : window.innerWidth - W - 8) + 'px';
            } else {
                janela.dataset.modo = 'normal';
                var d = dims(), lp = prefs.pos || POS_PADRAO;
                var base = prefs.win || { x: window.innerWidth - lp.r - d.w, y: window.innerHeight - lp.b - 66 - d.h };
                var q = prender(base.x, base.y, d.w, d.h);
                if (q.y + d.h > window.innerHeight - 8) { q.y = Math.max(8, window.innerHeight - d.h - 8); }
                if (q.x + d.w < 160) { q.x = 8; }
                if (q.x > window.innerWidth - 160) { q.x = Math.max(8, window.innerWidth - d.w - 8); }
                // Tela estreita: o desenho reduz a largura a innerWidth-16 (margem de 8px), mas a posição padrão
                // (innerWidth-18-w) dá -2 e corta 2px. Quando cabe, prende a 8px — desvio registrado no HANDOFF.
                if (!prefs.win && q.x < 8 && d.w <= window.innerWidth - 16) { q.x = 8; }
                st.left = q.x + 'px'; st.top = q.y + 'px'; st.width = d.w + 'px'; st.height = d.h + 'px';
            }
            var max = prefs.janela === 'max';
            btnMaximizar.title = max ? 'Restaurar tamanho' : 'Maximizar';
            btnMaximizar.setAttribute('aria-label', btnMaximizar.title);
            btnMaximizar.querySelector('.bi').className = 'bi ' + (max ? 'bi-window-stack' : 'bi-square');
            void janela.offsetWidth;   // aplica o tamanho novo antes de devolver a transição
            janela.classList.remove('bj-flutuante--instantaneo');
        }

        function avisarVisibilidade() {
            central.dispatchEvent(new CustomEvent('bj-central:visibilidade', { detail: { aberta: ui.aberto } }));
        }

        function abrir() {
            ui.aberto = true;
            janela.hidden = false;
            aplicarJanela();
            janela.classList.remove('bj-flutuante--abrindo');
            void janela.offsetWidth;
            janela.classList.add('bj-flutuante--abrindo');   // bjcIn .2s, L6326
            // Terminou, sai: no desenho o render seguinte já não traz a animação (ui._anim, L6320); presa com
            // `both`, ela seguiria compondo com a de minimizar.
            function fim(e) {
                if (e.target !== janela) { return; }
                janela.classList.remove('bj-flutuante--abrindo');
                janela.removeEventListener('animationend', fim);
                janela.removeEventListener('animationcancel', fim);
            }
            janela.addEventListener('animationend', fim);
            janela.addEventListener('animationcancel', fim);
            desenharLancador();
            posicionarLancador();
            avisarVisibilidade();
        }

        function fechar() {   // fechar não marca nada como lido (L6345)
            ui.aberto = false;
            janela.hidden = true;
            desenharLancador();
            posicionarLancador();
            avisarVisibilidade();
            lancador.focus();
        }

        function minimizar() {   // L6341: a janela "voa" até o lançador e some
            var r = janela.getBoundingClientRect(), l = lancador.getBoundingClientRect();
            var reduzido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!janela.animate || reduzido) { fechar(); return; }
            var dx = (l.left + l.width / 2) - (r.left + r.width / 2), dy = (l.top + l.height / 2) - (r.top + r.height / 2);
            janela.style.transformOrigin = 'center';
            var an = janela.animate(
                [{ transform: 'none', opacity: 1 }, { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.08)', opacity: 0 }],
                { duration: 260, easing: 'cubic-bezier(.4,0,.6,1)' }
            );
            an.onfinish = fechar;
            an.oncancel = fechar;
        }

        function alternarMaximizar() {
            prefs.janela = prefs.janela === 'max' ? 'normal' : 'max';
            prefs.snap = null;
            salvarPrefs();
            aplicarJanela();
            posicionarLancador();
        }

        // ── arrastar o lançador (arrastavel, L6455-6465) ───────────────────────────
        var cliqueBloqueado = false;
        lancador.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) { return; }
            var num = function (v, d) { var n = parseFloat(v); return Number.isFinite(n) ? n : d; };
            var ini = { x: e.clientX, y: e.clientY, r: num(raizLancador.style.right, 18), b: num(raizLancador.style.bottom, 18) };
            var moveu = false;
            try { lancador.setPointerCapture(e.pointerId); } catch (x) { /* sem captura: os eventos da janela bastam */ }
            function mv(ev) {
                var dx = ev.clientX - ini.x, dy = ev.clientY - ini.y;
                if (!moveu && Math.hypot(dx, dy) < 5) { return; }
                moveu = true;
                document.body.style.userSelect = 'none';
                lancador.style.cursor = 'grabbing';
                var rc = raizLancador.getBoundingClientRect();
                raizLancador.style.right = Math.min(Math.max(V - rc.width, ini.r - dx), window.innerWidth - V) + 'px';
                raizLancador.style.bottom = Math.min(Math.max(V - rc.height, ini.b - dy), window.innerHeight - V) + 'px';
            }
            function up() {
                window.removeEventListener('pointermove', mv);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
                document.body.style.userSelect = '';
                lancador.style.cursor = '';
                if (!moveu) { return; }
                prefs.pos = { r: parseFloat(raizLancador.style.right), b: parseFloat(raizLancador.style.bottom) };
                salvarPrefs();
                cliqueBloqueado = true;   // o clique que vem depois de arrastar não abre/fecha
                window.setTimeout(function () { cliqueBloqueado = false; }, 50);
            }
            window.addEventListener('pointermove', mv);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        });
        lancador.addEventListener('click', function () {
            if (cliqueBloqueado) { return; }
            if (ui.aberto) { fechar(); } else { abrir(); }
        });

        // ── mover a janela pelo cabeçalho, com ímã e encaixe (moverJanela, L6432-6451) ─
        cab.tabIndex = 0;
        cab.title = 'Arraste para mover o chat';
        cab.setAttribute('aria-label', 'Barra da janela do chat. Arraste ou use as setas para mover.');
        cab.addEventListener('keydown', function (e) {
            if (e.target !== cab || !/^Arrow/.test(e.key) || prefs.janela === 'max') { return; }
            e.preventDefault();
            var r = janela.getBoundingClientRect(), s = e.shiftKey ? 80 : 20;
            var dx = e.key === 'ArrowLeft' ? -s : e.key === 'ArrowRight' ? s : 0;
            var dy = e.key === 'ArrowUp' ? -s : e.key === 'ArrowDown' ? s : 0;
            var q = prender(r.left + dx, r.top + dy, r.width, r.height);
            prefs.snap = null;
            prefs.win = q;
            salvarPrefs();
            aplicarJanela();
        });
        cab.addEventListener('dblclick', function (e) {   // L6347
            if (e.target.closest('button,input')) { return; }
            prefs.janela = prefs.janela === 'max' || prefs.snap ? 'normal' : 'max';
            prefs.snap = null;
            salvarPrefs();
            aplicarJanela();
            posicionarLancador();
        });
        cab.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('button,input,textarea,select,a')) { return; }
            var r0 = janela.getBoundingClientRect(), x0 = e.clientX, y0 = e.clientY;
            var moveu = false, guia = null, alvo = null, off = { x: x0 - r0.left, y: y0 - r0.top }, W = r0.width, H = r0.height;
            try { cab.setPointerCapture(e.pointerId); } catch (x) { /* sem captura: segue com os eventos do cabeçalho */ }
            function mostrarGuia(t) {
                if (t === alvo) { return; }
                alvo = t;
                if (guia) { guia.remove(); guia = null; }
                if (!t) { return; }
                guia = document.createElement('div');
                guia.className = 'bj-flutuante-guia';
                var Wm = Math.floor(window.innerWidth / 2) - 12;
                if (t === 'max') {
                    guia.style.cssText = 'left:12px;top:12px;right:12px;bottom:12px';
                } else {
                    guia.style.cssText = 'top:8px;bottom:8px;width:' + Wm + 'px;' + (t === 'esq' ? 'left:8px' : 'right:8px');
                }
                document.body.appendChild(guia);
            }
            function mv(ev) {
                var dx = ev.clientX - x0, dy = ev.clientY - y0;
                if (!moveu) {
                    if (Math.hypot(dx, dy) < 4) { return; }
                    moveu = true;
                    janela.classList.add('bj-flutuante--movendo');
                    document.body.style.userSelect = 'none';
                    if (prefs.janela === 'max' || prefs.snap) {   // arrastar a maximizada/encaixada a restaura
                        var t = dims();
                        W = t.w; H = t.h;
                        off = { x: Math.min(W - 60, Math.max(60, off.x * W / r0.width)), y: 26 };
                        prefs.janela = 'normal';
                        prefs.snap = null;
                        janela.dataset.modo = 'normal';
                        janela.style.width = W + 'px';
                        janela.style.height = H + 'px';
                    }
                }
                var nx = ev.clientX - off.x, ny = ev.clientY - off.y;
                if (Math.abs(nx - 8) < IMA) { nx = 8; }
                if (Math.abs(nx + W - window.innerWidth + 8) < IMA) { nx = window.innerWidth - W - 8; }
                if (Math.abs(ny - 8) < IMA) { ny = 8; }
                if (Math.abs(ny + H - window.innerHeight + 8) < IMA) { ny = window.innerHeight - H - 8; }
                var q = prender(nx, ny, W, H);
                janela.style.left = q.x + 'px';
                janela.style.top = q.y + 'px';
                mostrarGuia(ev.clientY <= 3 ? 'max' : ev.clientX <= 3 ? 'esq' : ev.clientX >= window.innerWidth - 4 ? 'dir' : null);
            }
            function up() {
                cab.removeEventListener('pointermove', mv);
                cab.removeEventListener('pointerup', up);
                cab.removeEventListener('pointercancel', up);
                document.body.style.userSelect = '';
                var r = janela.getBoundingClientRect();   // antes de devolver a transição (ver as alças)
                janela.classList.remove('bj-flutuante--movendo');
                if (guia) { guia.remove(); }
                if (!moveu) { return; }
                if (alvo === 'max') {
                    prefs.janela = 'max';
                    prefs.snap = null;
                } else if (alvo) {
                    prefs.snap = alvo;
                    prefs.janela = 'normal';
                } else {
                    prefs.snap = null;
                    prefs.win = { x: Math.round(r.left), y: Math.round(r.top) };
                }
                salvarPrefs();
                aplicarJanela();
                posicionarLancador();
            }
            cab.addEventListener('pointermove', mv);
            cab.addEventListener('pointerup', up);
            cab.addEventListener('pointercancel', up);
        });

        // ── redimensionar por 8 alças, só no tamanho normal (L6349-6358) ─────────────
        [['left:0;top:12px;bottom:12px;width:6px', 'w', 'ew-resize'],
         ['right:0;top:12px;bottom:12px;width:6px', 'e', 'ew-resize'],
         ['left:12px;right:12px;top:0;height:5px', 'n', 'ns-resize'],
         ['left:12px;right:12px;bottom:0;height:6px', 's', 'ns-resize'],
         ['left:0;top:0;width:12px;height:12px', 'nw', 'nwse-resize'],
         ['right:0;top:0;width:12px;height:12px', 'ne', 'nesw-resize'],
         ['left:0;bottom:0;width:14px;height:14px', 'sw', 'nesw-resize'],
         ['right:0;bottom:0;width:14px;height:14px', 'se', 'nwse-resize']].forEach(function (a) {
            var g = document.createElement('div');
            g.className = 'bj-flutuante-alca';
            g.style.cssText = a[0] + ';cursor:' + a[2];
            g.title = 'Arraste para redimensionar';
            g.dataset.flutuanteAlca = a[1];
            g.addEventListener('pointerdown', function (ev) {
                if (ev.button !== 0) { return; }
                ev.preventDefault();
                ev.stopPropagation();
                try { g.setPointerCapture(ev.pointerId); } catch (x) { /* idem */ }
                var r0 = janela.getBoundingClientRect(), x0 = ev.clientX, y0 = ev.clientY, eixo = a[1];
                janela.classList.add('bj-flutuante--movendo');
                document.body.style.userSelect = 'none';
                function mv(e) {
                    var dx = e.clientX - x0, dy = e.clientY - y0, L = r0.left, T = r0.top, W = r0.width, H = r0.height;
                    if (/w/.test(eixo)) { W = Math.max(MW, Math.min(r0.right - 4, r0.width - dx)); L = r0.right - W; }
                    if (/e/.test(eixo)) { W = Math.max(MW, Math.min(window.innerWidth - r0.left - 4, r0.width + dx)); }
                    if (/n/.test(eixo)) { H = Math.max(MH, Math.min(r0.bottom - 4, r0.height - dy)); T = r0.bottom - H; }
                    if (/s/.test(eixo)) { H = Math.max(MH, Math.min(window.innerHeight - r0.top - 4, r0.height + dy)); }
                    janela.style.left = L + 'px';
                    janela.style.top = T + 'px';
                    janela.style.width = W + 'px';
                    janela.style.height = H + 'px';
                }
                function up() {
                    g.removeEventListener('pointermove', mv);
                    g.removeEventListener('pointerup', up);
                    g.removeEventListener('pointercancel', up);
                    document.body.style.userSelect = '';
                    // Mede ANTES de devolver a transição de largura/altura: depois dela a medida sai a do tamanho antigo.
                    var r = janela.getBoundingClientRect();
                    janela.classList.remove('bj-flutuante--movendo');
                    prefs.tam = { w: Math.round(r.width), h: Math.round(r.height) };
                    prefs.win = { x: Math.round(r.left), y: Math.round(r.top) };
                    prefs.snap = null;
                    salvarPrefs();
                }
                g.addEventListener('pointermove', mv);
                g.addEventListener('pointerup', up);
                g.addEventListener('pointercancel', up);
            });
            janela.appendChild(g);
        });

        // ── preferências no ⋮ da janela (menu(), L6244-6293) — só o que tem efeito real ───
        // O central.js desenha o painel; daqui vêm as seções da janela. Chaves e valores redesenham o painel aberto.
        function hhmm(t) {
            var d = new Date(t);
            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function avisarPreferencias() {
            central.dispatchEvent(new CustomEvent('bj-central:preferencias'));
        }

        central.bjSilencio = silencio;
        central.bjMenuJanela = function () {
            var dnd = silencio();
            var final = [];
            if (prefs.pos || prefs.win || prefs.snap) {   // L6288: só quando há o que restaurar
                final.push({ icone: 'bi-arrows-move', rotulo: 'Restaurar posição do chat', acao: function () {
                    prefs.pos = null; prefs.win = null; prefs.snap = null; salvarPrefs(); aplicarJanela(); posicionarLancador();
                } });
            }
            if (prefs.tam) {                                // L6289
                final.push({ icone: 'bi-aspect-ratio', rotulo: 'Restaurar tamanho do chat', acao: function () {
                    prefs.tam = null; prefs.janela = 'normal'; salvarPrefs(); aplicarJanela(); posicionarLancador();
                } });
            }
            return [
                { titulo: 'Meu status', itens: [   // L6266 (o "Status" manual depende de presença: fora)
                    { icone: dnd ? 'bi-moon-fill' : 'bi-moon', rotulo: dnd ? 'Não perturbe até ' + hhmm(prefs.dnd) : 'Não perturbe por 1 hora', chave: dnd, acao: function () {
                        prefs.dnd = dnd ? 0 : Date.now() + 3600000; salvarPrefs(); desenharLancador(); avisarPreferencias();
                    } }
                ] },
                { titulo: 'Notificações', itens: [   // L6268-6272 (Volume, Toques e Avisar não têm efeito aqui: fora)
                    { icone: prefs.som ? 'bi-volume-up' : 'bi-volume-mute', rotulo: 'Som de notificações', chave: !!prefs.som, acao: function () {
                        prefs.som = !prefs.som; salvarPrefs();
                        if (prefs.som) { ultimoToque = 0; tocar(); }   // L6268: ligar toca uma vez, para ouvir
                    } },
                    { icone: 'bi-lightning', rotulo: 'Alertas visuais', chave: !!prefs.alertas, acao: function () {
                        prefs.alertas = !prefs.alertas; salvarPrefs(); desenharLancador();
                    } }
                ] },
                // No desenho o "Tamanho do texto" mora na seção "Ligações · BlueJus Connect" (L6278), que não existe
                // aqui (ligações não autorizadas); vai para "Mensagens", a seção de leitura das conversas.
                { titulo: 'Mensagens', itens: [
                    { icone: 'bi-fonts', rotulo: 'Tamanho do texto', valor: prefs.texto === 'grande' ? 'Grande' : 'Normal', acao: function () {
                        prefs.texto = prefs.texto === 'grande' ? 'normal' : 'grande'; salvarPrefs(); aplicarTexto();
                    } }
                ] },
                { titulo: null, itens: final }
            ];
        };

        function aplicarTexto() {
            central.classList.toggle('bj-central--texto-grande', prefs.texto === 'grande');
        }

        // ── ligação com o central.js ─────────────────────────────────────────────────
        central.addEventListener('bj-central:contadores', function (ev) {
            var d = ev.detail || {};
            ui.naoLidas = d.naoLidas || 0;
            ui.mencoes = d.mencoes || 0;
            desenharLancador();
            if (d.chegaram > 0) {
                saltar();
                if (!ui.aberto || document.hidden) { tocar(); }
            }
        });
        central.addEventListener('bj-central:aviso', function (ev) {
            if (ev.detail && ev.detail.texto) { avisar(ev.detail.texto); }
        });

        btnMinimizar.addEventListener('click', minimizar);
        btnMaximizar.addEventListener('click', alternarMaximizar);
        btnFechar.addEventListener('click', fechar);

        var redim = 0;
        window.addEventListener('resize', function () {   // L6453: refaz com debounce de 120 ms
            window.clearTimeout(redim);
            redim = window.setTimeout(function () {
                if (ui.aberto) { aplicarJanela(); }
                posicionarLancador();
            }, 120);
        });

        aplicarTexto();
        desenharLancador();
        posicionarLancador();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-central-flutuante]').forEach(iniciar);
    });
})();
