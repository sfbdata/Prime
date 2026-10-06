/**
 * Dashboard — controles próprios da barra de filtros (Lote 7: F13 + F14).
 *
 * Desenho: docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html
 * + design_handoff_dashboard/README.md ("Filtros em cascata com avatar" e
 * "Calendário das datas").
 *
 * Princípio: estes controles NÃO são campos do form. A fonte de verdade continua
 * sendo o controle nativo do parcial compartilhado (_partials/_filtro_barra.html.twig),
 * com o mesmo `name`. Cada escolha é ESCRITA no nativo e dispara `change` nele — o
 * filtro-tabela.js (que não muda) recarrega exatamente como se o usuário tivesse
 * mexido no <select>/<input type="date">. E o caminho inverso: quando outro código
 * mexe nos nativos (segmentado de período do dashboard.js, chip removido, "Limpar
 * filtros"), estes controles se RELEEM dos nativos.
 *
 * Sem JS: o bloco .db-fp fica escondido (db-fp--sem-js) e os nativos aparecem.
 * Com JS: os nativos saem da vista e do Tab (continuam no DOM para o motor).
 *
 * Acessibilidade: listbox/option com aria-activedescendant; teclado Enter/Espaço/↓
 * abre, ↑↓ Home End navegam, Enter escolhe, Esc fecha e devolve o foco ao botão,
 * Tab fecha. Calendário em diálogo não modal: setas movem o dia, PageUp/PageDown
 * trocam o mês, Enter escolhe, Esc fecha.
 *
 * Som (README "Calendário das datas"; dc L2104-2122, 2153, 2159): clique curto gerado no
 * navegador ao passar o mouse num dia (seno ~1.3 kHz, um pouco mais agudo a cada dia da semana,
 * fim de semana mais grave) e nas setas de mês (som próprio). 80ms, volume 0.045, passa-baixa,
 * no máximo um a cada 28ms; nenhum arquivo de áudio. Desliga pela opção pessoal "Sons" do menu ⋮
 * (preferência `dashboard.sons`, gravada no servidor): o `.db-page` ganha `db-page--sem-som`, e
 * a classe é lida a cada toque — a escolha vale na hora.
 */
(function () {
    'use strict';

    var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
        'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var SEMANA = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
    var SEMANA_LONGA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    function p2(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()); }
    function deIso(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function br(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }
    function semAcento(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    // ── Som do calendário ───────────────────────────────────────────────────

    var somContexto = null;
    var somUltimo = 0;

    function somLigado() {
        return !document.querySelector('.db-page.db-page--sem-som');
    }

    function tocarSom(freq) {
        if (!somLigado()) { return; }
        var agora = window.performance && performance.now ? performance.now() : Date.now();
        if (somUltimo && agora - somUltimo < 28) { return; }
        somUltimo = agora;
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { return; }
            somContexto = somContexto || new AC();
            var ac = somContexto;
            if (ac.state === 'suspended') { ac.resume(); }
            var t = ac.currentTime;
            var o = ac.createOscillator();
            var g = ac.createGain();
            var f = ac.createBiquadFilter();
            o.type = 'sine';
            o.frequency.setValueAtTime(freq, t);
            o.frequency.exponentialRampToValueAtTime(freq * 0.82, t + 0.07);
            f.type = 'lowpass';
            f.frequency.value = 2600;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.045, t + 0.006);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.08);
            o.connect(f);
            f.connect(g);
            g.connect(ac.destination);
            o.start(t);
            o.stop(t + 0.09);
        } catch (e) { /* sem áudio no navegador: o calendário segue mudo */ }
    }

    /** Frequência do dia (dc L2153): fim de semana mais grave; nos úteis sobe com o dia da semana. */
    function freqDoDia(v) {
        var d = deIso(v);
        if (!d) { return 0; }
        var dow = d.getDay();
        return (dow === 0 || dow === 6) ? 1180 : 1320 + dow * 22;
    }

    // ── Ponte com os nativos ────────────────────────────────────────────────

    function nativo(form, name) {
        return form.querySelector('.js-filtro-campo[name="' + name + '"]');
    }

    /** Grava no nativo e avisa o motor com UM change (ele lê o form inteiro). */
    function escrever(form, name, valor, extras) {
        var el = nativo(form, name);
        if (!el) {
            return;
        }
        (extras || []).forEach(function (x) {
            var o = nativo(form, x.name);
            if (o) { o.value = x.valor; }
        });
        el.value = valor;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // ── Select próprio (listbox) ─────────────────────────────────────────────

    function Dropdown(wrap, form, gerente) {
        this.wrap = wrap;
        this.form = form;
        this.gerente = gerente;
        this.name = wrap.getAttribute('data-db-dd');
        this.btn = wrap.querySelector('.db-dd-btn');
        this.painel = wrap.querySelector('.db-dd-painel');
        this.lista = wrap.querySelector('[role="listbox"]');
        this.busca = wrap.querySelector('.db-dd-busca-input');
        this.vazio = wrap.querySelector('.db-dd-vazio');
        this.foco = -1;
        this.ligar();
    }

    Dropdown.prototype.opcoes = function () {
        return Array.prototype.slice.call(this.lista.querySelectorAll('[role="option"]'));
    };
    Dropdown.prototype.visiveis = function () {
        return this.opcoes().filter(function (o) { return !o.hidden; });
    };
    Dropdown.prototype.aberto = function () { return !this.painel.hidden; };
    Dropdown.prototype.alvoDeTeclado = function () { return this.busca || this.lista; };

    Dropdown.prototype.abrir = function () {
        if (this.aberto()) { return; }
        this.gerente.fecharTodos(this);
        if (this.busca) { this.busca.value = ''; this.filtrar(''); }
        this.painel.hidden = false;
        this.wrap.classList.add('is-aberto');
        this.btn.setAttribute('aria-expanded', 'true');
        var vis = this.visiveis();
        var sel = vis.findIndex(function (o) { return o.getAttribute('aria-selected') === 'true'; });
        this.focar(sel >= 0 ? sel : 0);
        var alvo = this.alvoDeTeclado();
        window.setTimeout(function () { alvo.focus(); }, 0);
    };

    Dropdown.prototype.fechar = function (devolverFoco) {
        if (!this.aberto()) { return; }
        this.painel.hidden = true;
        this.wrap.classList.remove('is-aberto');
        this.btn.setAttribute('aria-expanded', 'false');
        this.alvoDeTeclado().removeAttribute('aria-activedescendant');
        if (devolverFoco) { this.btn.focus(); }
    };

    Dropdown.prototype.focar = function (i) {
        var vis = this.visiveis();
        this.opcoes().forEach(function (o) { o.classList.remove('is-foco'); });
        if (vis.length === 0) {
            this.foco = -1;
            this.alvoDeTeclado().removeAttribute('aria-activedescendant');
            return;
        }
        this.foco = Math.max(0, Math.min(vis.length - 1, i));
        var op = vis[this.foco];
        op.classList.add('is-foco');
        this.alvoDeTeclado().setAttribute('aria-activedescendant', op.id);
        if (op.scrollIntoView) { op.scrollIntoView({ block: 'nearest' }); }
    };

    Dropdown.prototype.filtrar = function (q) {
        var termo = semAcento(q.trim());
        var n = 0;
        this.opcoes().forEach(function (o) {
            var ok = termo === '' || semAcento(o.getAttribute('data-nome')).indexOf(termo) !== -1;
            o.hidden = !ok;
            if (ok) { n++; }
        });
        if (this.vazio) { this.vazio.hidden = n !== 0; }
        this.focar(0);
    };

    Dropdown.prototype.escolher = function (op) {
        if (!op) { return; }
        var valor = op.getAttribute('data-valor') || '';
        this.fechar(true);
        var el = nativo(this.form, this.name);
        if (el && el.value === valor) {
            this.sincronizar();
            return;
        }
        escrever(this.form, this.name, valor);
    };

    /** Relê o nativo: opção marcada, avatar e texto do botão. */
    Dropdown.prototype.sincronizar = function () {
        var el = nativo(this.form, this.name);
        var valor = el ? el.value : '';
        var escolhida = null;
        this.opcoes().forEach(function (o) {
            var sel = (o.getAttribute('data-valor') || '') === valor;
            o.setAttribute('aria-selected', sel ? 'true' : 'false');
            if (sel) { escolhida = o; }
        });
        if (!escolhida) {
            escolhida = this.opcoes()[0];
            if (escolhida) { escolhida.setAttribute('aria-selected', 'true'); }
        }
        if (!escolhida) { return; }
        var nome = escolhida.getAttribute('data-nome') || '';
        var alvoNome = this.btn.querySelector('.db-dd-btn-nome');
        if (alvoNome) { alvoNome.textContent = nome; }
        var rotulo = this.name === 'responsavel' ? 'Responsável' : 'Cargo';
        this.btn.setAttribute('aria-label', rotulo + ': ' + nome);
        var avBtn = this.btn.querySelector('.db-dd-av');
        var avOp = escolhida.querySelector('.db-dd-av');
        if (avBtn && avOp) {
            avBtn.innerHTML = avOp.innerHTML;
            avBtn.querySelectorAll('img').forEach(function (img) { img.removeAttribute('loading'); });
        }
    };

    Dropdown.prototype.ligar = function () {
        var self = this;

        this.btn.addEventListener('click', function () {
            if (self.aberto()) { self.fechar(true); } else { self.abrir(); }
        });
        this.btn.addEventListener('keydown', function (e) {
            if (!self.aberto() && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                self.abrir();
            } else if (self.aberto() && e.key === 'Escape') {
                e.preventDefault();
                self.fechar(true);
            }
        });

        var teclado = function (e) {
            var vis = self.visiveis();
            switch (e.key) {
                case 'ArrowDown': e.preventDefault(); self.focar(self.foco + 1); break;
                case 'ArrowUp': e.preventDefault(); self.focar(self.foco - 1); break;
                case 'Home': if (e.target === self.lista) { e.preventDefault(); self.focar(0); } break;
                case 'End': if (e.target === self.lista) { e.preventDefault(); self.focar(vis.length - 1); } break;
                case 'Enter': e.preventDefault(); self.escolher(vis[self.foco]); break;
                case ' ':
                    if (e.target === self.lista) { e.preventDefault(); self.escolher(vis[self.foco]); }
                    break;
                case 'Escape': e.preventDefault(); e.stopPropagation(); self.fechar(true); break;
                case 'Tab': self.fechar(false); break;
            }
        };
        this.lista.addEventListener('keydown', teclado);
        if (this.busca) {
            this.busca.addEventListener('keydown', teclado);
            this.busca.addEventListener('input', function () { self.filtrar(self.busca.value); });
        }

        this.lista.addEventListener('click', function (e) {
            var op = e.target.closest('[role="option"]');
            if (op) { self.escolher(op); }
        });
        this.lista.addEventListener('mousemove', function (e) {
            var op = e.target.closest('[role="option"]');
            if (!op) { return; }
            var i = self.visiveis().indexOf(op);
            if (i !== self.foco) { self.focar(i); }
        });
    };

    // ── Calendário próprio ───────────────────────────────────────────────────

    function Calendario(wrap, form, gerente) {
        this.wrap = wrap;
        this.form = form;
        this.gerente = gerente;
        this.name = wrap.getAttribute('data-db-cal');
        this.outro = this.name === 'data_de' ? 'data_ate' : 'data_de';
        this.btn = wrap.querySelector('.db-cal-btn');
        this.painel = wrap.querySelector('.db-cal-painel');
        this.ref = null;     // 1º dia do mês exibido
        this.cursor = null;  // dia com o foco do teclado (ISO)
        this.somDia = null;  // dia sob o mouse: o som toca só ao ENTRAR num dia novo
        this.ligar();
    }

    Calendario.prototype.valor = function () {
        var el = nativo(this.form, this.name);
        return el ? el.value : '';
    };
    Calendario.prototype.aberto = function () { return !this.painel.hidden; };

    Calendario.prototype.abrir = function () {
        if (this.aberto()) { return; }
        this.gerente.fecharTodos(this);
        var base = deIso(this.valor()) || new Date();
        this.ref = new Date(base.getFullYear(), base.getMonth(), 1);
        this.cursor = iso(base);
        this.painel.hidden = false;
        this.wrap.classList.add('is-aberto');
        this.btn.setAttribute('aria-expanded', 'true');
        this.desenhar(true);
    };

    Calendario.prototype.fechar = function (devolverFoco) {
        if (!this.aberto()) { return; }
        this.painel.hidden = true;
        this.wrap.classList.remove('is-aberto');
        this.btn.setAttribute('aria-expanded', 'false');
        if (devolverFoco) { this.btn.focus(); }
    };

    Calendario.prototype.mover = function (meses) {
        this.ref = new Date(this.ref.getFullYear(), this.ref.getMonth() + meses, 1);
        var c = deIso(this.cursor) || this.ref;
        var ultimo = new Date(this.ref.getFullYear(), this.ref.getMonth() + 1, 0).getDate();
        this.cursor = iso(new Date(this.ref.getFullYear(), this.ref.getMonth(), Math.min(c.getDate(), ultimo)));
        this.desenhar(false);
    };

    /** Monta o mês em this.ref. `focarDia`: põe o foco no dia do cursor. */
    Calendario.prototype.desenhar = function (focarDia) {
        var Y = this.ref.getFullYear();
        var M = this.ref.getMonth();
        var valor = this.valor();
        var de = (nativo(this.form, 'data_de') || {}).value || '';
        var ate = (nativo(this.form, 'data_ate') || {}).value || '';
        var hoje = iso(new Date());
        var titulo = MESES[M].charAt(0).toUpperCase() + MESES[M].slice(1) + ' de ' + Y;

        var g0 = new Date(Y, M, 1 - new Date(Y, M, 1).getDay());
        var h = [];
        h.push('<div class="db-cal-topo">'
            + '<span class="db-cal-titulo" aria-live="polite">' + titulo + '</span>'
            + '<button type="button" class="db-cal-nav" data-db-cal-mes="-1" aria-label="Mês anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>'
            + '<button type="button" class="db-cal-nav" data-db-cal-mes="1" aria-label="Próximo mês"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
            + '</div>');
        h.push('<div class="db-cal-semana" aria-hidden="true">');
        SEMANA.forEach(function (l, i) {
            h.push('<span' + (i === 0 || i === 6 ? ' class="is-fds"' : '') + '>' + l + '</span>');
        });
        h.push('</div><div class="db-cal-dias" role="grid" aria-label="' + titulo + '">');
        for (var i = 0; i < 42; i++) {
            var d = new Date(g0.getFullYear(), g0.getMonth(), g0.getDate() + i);
            if (i >= 35 && d.getMonth() !== M) { break; }
            if (i % 7 === 0) { h.push('<div class="db-cal-linha" role="row">'); }
            var k = iso(d);
            var cls = ['db-cal-dia'];
            if (d.getMonth() !== M) { cls.push('is-fora'); }
            if (d.getDay() === 0 || d.getDay() === 6) { cls.push('is-fds'); }
            if (k === valor) { cls.push('is-sel'); } else if (k === de || k === ate) { cls.push('is-ponta'); }
            if (de && ate && k > de && k < ate) { cls.push('is-entre'); }
            if (k === hoje) { cls.push('is-hoje'); }
            h.push('<span role="gridcell">'
                + '<button type="button" class="' + cls.join(' ') + '" data-dia="' + k + '"'
                + ' tabindex="' + (k === this.cursor ? '0' : '-1') + '"'
                + ' aria-label="' + SEMANA_LONGA[d.getDay()] + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()] + ' de ' + d.getFullYear()
                + (k === hoje ? ' (hoje)' : '') + '"'
                + (k === valor ? ' aria-pressed="true"' : '')
                + (k === hoje ? ' aria-current="date"' : '') + '>'
                + '<span class="db-cal-num">' + d.getDate() + '</span><span class="db-cal-ponto" aria-hidden="true"></span>'
                + '</button></span>');
            if (i % 7 === 6) { h.push('</div>'); }
        }
        h.push('</div>');
        h.push('<div class="db-cal-rodape">'
            + '<button type="button" class="db-cal-limpar" data-db-cal-acao="limpar">Limpar</button>'
            + '<button type="button" class="db-cal-hoje" data-db-cal-acao="hoje">Hoje</button>'
            + '</div>');
        this.painel.innerHTML = h.join('');

        if (focarDia) {
            var alvo = this.painel.querySelector('.db-cal-dia[tabindex="0"]');
            if (alvo) { alvo.focus(); }
        }
    };

    /** Escolhe a data; se passar da outra ponta, a outra acompanha (desenho). */
    Calendario.prototype.escolher = function (v) {
        var outro = nativo(this.form, this.outro);
        var extras = [];
        if (v && outro && outro.value) {
            if (this.name === 'data_de' && v > outro.value) { extras.push({ name: this.outro, valor: v }); }
            if (this.name === 'data_ate' && v < outro.value) { extras.push({ name: this.outro, valor: v }); }
        }
        this.fechar(true);
        if (v === this.valor() && extras.length === 0) { return; }
        escrever(this.form, this.name, v, extras);
    };

    Calendario.prototype.sincronizar = function () {
        var v = this.valor();
        var rot = this.btn.querySelector('.db-cal-rotulo');
        if (rot) { rot.textContent = v ? br(v) : 'dd/mm/aaaa'; }
        this.btn.classList.toggle('is-vazio', !v);
        var nome = this.name === 'data_de' ? 'Data inicial' : 'Data final';
        this.btn.setAttribute('aria-label', nome + ': ' + (v ? br(v) : 'sem data'));
        if (this.aberto()) { this.desenhar(false); }
    };

    Calendario.prototype.ligar = function () {
        var self = this;

        this.btn.addEventListener('click', function () {
            if (self.aberto()) { self.fechar(true); } else { self.abrir(); }
        });
        this.btn.addEventListener('keydown', function (e) {
            if (!self.aberto() && e.key === 'ArrowDown') { e.preventDefault(); self.abrir(); }
            else if (self.aberto() && e.key === 'Escape') { e.preventDefault(); self.fechar(true); }
        });

        this.painel.addEventListener('click', function (e) {
            var nav = e.target.closest('[data-db-cal-mes]');
            if (nav) {
                var sentido = nav.getAttribute('data-db-cal-mes');
                tocarSom(parseInt(sentido, 10) > 0 ? 980 : 880);
                self.mover(parseInt(sentido, 10));
                // o painel foi redesenhado: devolve o foco à mesma seta
                var nova = self.painel.querySelector('[data-db-cal-mes="' + sentido + '"]');
                if (nova) { nova.focus(); }
                return;
            }
            var dia = e.target.closest('.db-cal-dia');
            if (dia) { self.escolher(dia.getAttribute('data-dia')); return; }
            var acao = e.target.closest('[data-db-cal-acao]');
            if (acao) { self.escolher(acao.getAttribute('data-db-cal-acao') === 'hoje' ? iso(new Date()) : ''); }
        });

        // Som ao passar o mouse nas datas (só ao entrar num dia diferente).
        this.painel.addEventListener('mouseover', function (e) {
            var dia = e.target.closest('.db-cal-dia');
            var k = dia ? dia.getAttribute('data-dia') : null;
            if (!k || k === self.somDia) { return; }
            self.somDia = k;
            tocarSom(freqDoDia(k));
        });
        this.painel.addEventListener('mouseleave', function () { self.somDia = null; });

        this.painel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); self.fechar(true); return; }
            var dia = e.target.closest('.db-cal-dia');
            var passo = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
            if (dia && passo) {
                e.preventDefault();
                var c = deIso(dia.getAttribute('data-dia'));
                var n = new Date(c.getFullYear(), c.getMonth(), c.getDate() + passo);
                self.cursor = iso(n);
                if (n.getMonth() !== self.ref.getMonth() || n.getFullYear() !== self.ref.getFullYear()) {
                    self.ref = new Date(n.getFullYear(), n.getMonth(), 1);
                }
                self.desenhar(true);
                return;
            }
            if (dia && (e.key === 'PageUp' || e.key === 'PageDown')) {
                e.preventDefault();
                self.cursor = dia.getAttribute('data-dia');
                self.mover(e.key === 'PageDown' ? 1 : -1);
                var alvo = self.painel.querySelector('.db-cal-dia[tabindex="0"]');
                if (alvo) { alvo.focus(); }
            }
        });

        // Tab saindo do painel fecha (diálogo não modal).
        this.painel.addEventListener('focusout', function (e) {
            if (e.relatedTarget && !self.wrap.contains(e.relatedTarget)) { self.fechar(false); }
        });
    };

    // ── Montagem ────────────────────────────────────────────────────────────

    function iniciar(root) {
        var bloco = root.querySelector('[data-db-filtros]');
        var form = root.querySelector('[data-filtro-form]');
        if (!bloco || !form) {
            return;
        }
        // Sem os nativos o motor não teria onde ler: não troca os papéis.
        if (!nativo(form, 'data_de') || !nativo(form, 'data_ate')
            || !nativo(form, 'responsavel') || !nativo(form, 'cargo')) {
            return;
        }

        var controles = [];
        var gerente = {
            fecharTodos: function (exceto) {
                controles.forEach(function (c) { if (c !== exceto) { c.fechar(false); } });
            },
        };

        bloco.querySelectorAll('[data-db-cal]').forEach(function (w) { controles.push(new Calendario(w, form, gerente)); });
        bloco.querySelectorAll('[data-db-dd]').forEach(function (w) { controles.push(new Dropdown(w, form, gerente)); });

        // Troca de papéis: o próprio aparece; os nativos saem da vista, do Tab e da
        // árvore de acessibilidade (o próprio já é acessível), mas ficam no form.
        var linha = form.querySelector('.filtro-linha');
        if (linha) {
            linha.setAttribute('aria-hidden', 'true');
            linha.querySelectorAll('.js-filtro-campo, .js-filtro-calendario').forEach(function (el) {
                el.setAttribute('tabindex', '-1');
            });
        }
        bloco.classList.remove('db-fp--sem-js');
        var wrap = root.querySelector('.db-filtro-wrap');
        if (wrap) { wrap.classList.add('db-fp-ativo'); }

        function sincronizar() { controles.forEach(function (c) { c.sincronizar(); }); }

        // Mudança nos nativos (escolha aqui, segmentado de período, digitação): relê.
        root.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('js-filtro-campo')) { sincronizar(); }
        });
        // Chip removido / "Limpar filtros": o motor zera os nativos sem `change`.
        root.addEventListener('click', function (e) {
            if (e.target.closest('.js-filtro-chip-remover, .js-filtro-limpar')) {
                window.setTimeout(sincronizar, 0);
            }
        });

        // Clique fora fecha.
        document.addEventListener('mousedown', function (e) {
            controles.forEach(function (c) { if (!c.wrap.contains(e.target)) { c.fechar(false); } });
        });

        sincronizar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-filtro-root]').forEach(iniciar);
    });
})();
