/**
 * Dashboard: troféu do campeão e cartão do colaborador (spec dashboard-parte-1, itens 3 a 5).
 *
 *  - Fanfarra do campeão (README do desenho, "Troféu" e "Som do campeão"): o cursor entra na linha
 *    (ou no card do celular) marcada com `[data-db-campeao]` e toca um "pam… paaam" gerado no
 *    navegador (Web Audio, sem arquivo). No máximo 2 vezes por carregamento da página (contador em
 *    memória) e uma a cada 1,4 s. Mudo com a opção "Sons" do menu ⋮ desligada (`db-page--sem-som`)
 *    ou com "Sons de alerta" desligado no Meu Perfil (`data-perfil-sons="0"`). O crescimento e o
 *    brilho do troféu são CSS (dashboard.css), não daqui.
 *  - Cartão do colaborador (README "Botão direito no colaborador"): botão direito no nome
 *    (`[data-db-colab-url]`), ou toque longo no celular, abre uma janela flutuante
 *    (dashboard-janelas.js) com os blocos do desenho: posição no período do painel, Comentários,
 *    "1º do mês" (12 quadradinhos por ano ou Todos) e Rendimento contra a média dos outros. O
 *    seletor Período · Mês · Trimestre · Semestre · Ano (decisão do dono) recorta posição,
 *    Comentários e Rendimento; o recorte e o ano escolhidos valem para os próximos cartões.
 *
 * Delegado no `[data-filtro-root]` persistente (o fragmento da tabela é trocado a cada filtro).
 * Nada de HTML montado por string: o que vem do servidor entra por textContent.
 */
(function () {
    'use strict';

    var JANELAS = ['periodo', 'mes', 'trimestre', 'semestre', 'ano'];
    var TOQUE_LONGO_MS = 550;
    var MAX_FANFARRAS = 2;
    var INTERVALO_MS = 1400;

    var fanfarras = 0;
    var ultimaFanfarra = 0;
    var contexto = null;
    var janelaEscolhida = 'periodo'; // o cartão abre no período do painel
    var anoEscolhido = null;         // ano do "1º do mês"; vale para os próximos cartões da página

    function t(chave, vars) {
        return window.BJ_T.t(chave, vars);
    }

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    // ── Fanfarra ──────────────────────────────────────────────────────────

    function somLigado() {
        if (document.querySelector('.db-page[data-perfil-sons="0"]')) { return false; }

        return !document.querySelector('.db-page.db-page--sem-som');
    }

    function podeTocar() {
        var agora = window.performance && performance.now ? performance.now() : Date.now();
        if (!somLigado() || fanfarras >= MAX_FANFARRAS) { return false; }
        if (ultimaFanfarra && agora - ultimaFanfarra < INTERVALO_MS) { return false; }
        ultimaFanfarra = agora;
        fanfarras++;

        return true;
    }

    /** Uma nota do desenho: dente de serra + quadrada + triangular, ataque curto e queda. */
    function nota(ac, saida, t0, freq, ini, dur, vol) {
        [['sawtooth', 0, 1], ['square', 4, 0.45], ['triangle', -3, 0.6]].forEach(function (voz) {
            var o = ac.createOscillator();
            var g = ac.createGain();
            o.type = voz[0];
            o.frequency.setValueAtTime(freq * 0.985, t0 + ini);
            o.frequency.exponentialRampToValueAtTime(freq, t0 + ini + 0.04);
            o.detune.value = voz[1];
            g.gain.setValueAtTime(0.0001, t0 + ini);
            g.gain.exponentialRampToValueAtTime(vol * voz[2], t0 + ini + 0.018);
            g.gain.exponentialRampToValueAtTime(vol * voz[2] * 0.55, t0 + ini + dur * 0.45);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + ini + dur);
            o.connect(g);
            g.connect(saida);
            o.start(t0 + ini);
            o.stop(t0 + ini + dur + 0.05);
        });
    }

    function fanfarra() {
        if (!podeTocar()) { return; }
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { return; }
            contexto = contexto || new AC();
            var ac = contexto;
            if (ac.state === 'suspended') { ac.resume(); }
            var t0 = ac.currentTime + 0.02;
            var mestre = ac.createGain();
            mestre.gain.value = 0.9;
            var filtro = ac.createBiquadFilter();
            filtro.type = 'lowpass';
            filtro.frequency.value = 2200;
            filtro.Q.value = 0.7;
            filtro.connect(mestre);
            mestre.connect(ac.destination);
            nota(ac, filtro, t0, 392.0, 0, 0.17, 0.05);      // pam (sol)
            nota(ac, filtro, t0, 523.25, 0.24, 0.42, 0.055); // paaam (dó)
            nota(ac, filtro, t0, 659.25, 0.24, 0.42, 0.022); // terça por cima, bem baixa
        } catch (e) { /* sem áudio no navegador: o troféu segue animando, mudo */ }
    }

    function ligarFanfarra(root) {
        // mouseover + relatedTarget: o "entrou na linha" delegado (mouseenter não borbulha).
        root.addEventListener('mouseover', function (e) {
            var alvo = e.target.closest('[data-db-campeao]');
            if (!alvo || !root.contains(alvo) || (e.relatedTarget && alvo.contains(e.relatedTarget))) { return; }
            fanfarra();
        });
    }

    // ── Cartão do colaborador ─────────────────────────────────────────────

    function aviso(corpo, texto) {
        corpo.replaceChildren(el('p', 'db-hist-aviso', texto));
    }

    function icone(classe) {
        var i = el('i', 'bi ' + classe);
        i.setAttribute('aria-hidden', 'true');

        return i;
    }

    function linha(ico, rotulo, valor, extra) {
        var l = el('div', 'db-colab-linha');
        l.appendChild(icone(ico + ' db-colab-linha-ico'));
        l.appendChild(el('span', 'db-colab-linha-rotulo', rotulo));
        l.appendChild(el('span', 'db-colab-linha-espaco'));
        l.appendChild(el('span', 'db-colab-linha-valor', valor));
        if (extra) { l.appendChild(el('span', 'db-colab-linha-extra', extra)); }

        return l;
    }

    /** Segmentado do desenho (o mesmo do histórico dos cards): opções [valor, rótulo]. */
    function segmentado(classe, aria, opcoes, ativo, aoEscolher) {
        var grupo = el('div', 'db-hist-anos ' + classe);
        grupo.setAttribute('role', 'group');
        grupo.setAttribute('aria-label', aria);
        opcoes.forEach(function (o) {
            var b = el('button', 'db-hist-ano', o[1]);
            b.type = 'button';
            b.setAttribute('aria-pressed', String(o[0]) === String(ativo) ? 'true' : 'false');
            b.addEventListener('click', function () { aoEscolher(o[0]); });
            grupo.appendChild(b);
        });

        return grupo;
    }

    // ── 1º do mês ──

    function ganhosDe(p, ano) {
        return (p.ganhos || {})[ano] || [];
    }

    /** Situação do mês (1 a 12): 'ganhou', 'nao' ou 'aberto' (ainda não fechou). */
    function situacao(p, ano, mes) {
        if (ano === p.anoAtual && mes > p.ultimoMesFechado) { return 'aberto'; }

        return ganhosDe(p, ano).indexOf(mes) >= 0 ? 'ganhou' : 'nao';
    }

    function tituloMes(sit, mes, ano) {
        var curto = t('dashboard.js.colaborador.meses_curtos').split(',')[mes - 1];

        return t(sit === 'aberto' ? 'mes_aberto' : sit === 'ganhou' ? 'mes_ganhou' : 'mes_nao', { mes: curto, ano: ano });
    }

    function mesesDoAno(p, ano) {
        var grade = el('div', 'db-colab-meses');
        t('dashboard.js.colaborador.meses_letras').split(',').forEach(function (letra, i) {
            var sit = situacao(p, ano, i + 1);
            var m = el('div', 'db-colab-mes db-colab-mes--' + sit);
            m.title = tituloMes(sit, i + 1, ano);
            var q = el('span', 'db-colab-mes-q');
            if (sit === 'ganhou') { q.appendChild(icone('bi-trophy-fill')); }
            m.appendChild(q);
            m.appendChild(el('span', 'db-colab-mes-rot', letra));
            grade.appendChild(m);
        });

        return grade;
    }

    function todosOsAnos(p) {
        var lista = el('div', 'db-colab-anos-todos');
        p.anos.forEach(function (ano) {
            var l = el('div', 'db-colab-ano-linha');
            l.appendChild(el('span', 'db-colab-ano-rot', ano));
            var faixa = el('div', 'db-colab-faixa');
            for (var mes = 1; mes <= 12; mes++) {
                var sit = situacao(p, ano, mes);
                var b = el('span', 'db-colab-faixa-b db-colab-mes--' + sit);
                b.title = tituloMes(sit, mes, ano);
                faixa.appendChild(b);
            }
            l.appendChild(faixa);
            l.appendChild(el('span', 'db-colab-ano-vezes', ganhosDe(p, ano).length));
            lista.appendChild(l);
        });

        return lista;
    }

    function blocoPrimeiros(p) {
        var caixa = el('div', 'db-colab-primeiros');
        if (p.anos.indexOf(anoEscolhido) < 0 && anoEscolhido !== 'todos') { anoEscolhido = p.anoAtual; }
        function desenharAno() {
            var todos = anoEscolhido === 'todos';
            var vezes = todos
                ? p.anos.reduce(function (s, a) { return s + ganhosDe(p, a).length; }, 0)
                : ganhosDe(p, anoEscolhido).length;
            var rotulo = t(vezes === 1 ? 'vez' : 'vezes') + ' ' + (todos ? t('dashboard.js.colaborador.no_total') : t('dashboard.js.colaborador.em_ano', { ano: anoEscolhido }));
            var opcoes = p.anos.map(function (a) { return [a, String(a)]; }).concat([['todos', t('dashboard.js.colaborador.todos')]]);
            caixa.replaceChildren(
                linha('bi-trophy-fill db-colab-ico--ouro', t('dashboard.js.colaborador.primeiro_mes'), vezes, rotulo),
                segmentado('db-colab-seg-ano', t('dashboard.js.colaborador.ano_aria'), opcoes, anoEscolhido, function (a) { anoEscolhido = a; desenharAno(); }),
                todos ? todosOsAnos(p) : mesesDoAno(p, anoEscolhido)
            );
        }
        desenharAno();

        return caixa;
    }

    // ── Rendimento ──

    function barra(nome, classe, valor, max, preenchimento) {
        var l = el('div', 'db-colab-barra ' + classe);
        l.appendChild(el('span', 'db-colab-barra-nome', nome));
        var trilho = el('div', 'db-colab-barra-trilho');
        var cheio = el('div', 'db-colab-barra-cheia ' + preenchimento);
        cheio.style.width = Math.round(valor / max * 100) + '%';
        trilho.appendChild(cheio);
        l.appendChild(trilho);
        l.appendChild(el('span', 'db-colab-barra-valor', String(Math.round(valor * 10) / 10).replace('.', ',')));

        return l;
    }

    function blocoRendimento(d) {
        var r = d.rendimento;
        var pct = r.media > 0 ? Math.round((r.eu - r.media) / r.media * 100) : 0;
        var tom = pct > 0 ? 'acima' : pct < 0 ? 'abaixo' : 'igual';
        var primeiro = String(d.nome || '').split(' ')[0];
        var caixa = el('div', 'db-colab-rend');
        var cab = el('div', 'db-colab-rend-cab');
        cab.appendChild(icone('bi-graph-up-arrow'));
        cab.appendChild(el('span', 'db-colab-rend-rot', t('dashboard.js.colaborador.rendimento')));
        var res = el('div', 'db-colab-rend-res');
        res.appendChild(el('span', 'db-colab-rend-pct db-colab-rend-pct--' + tom, (pct > 0 ? '+' : pct < 0 ? '−' : '') + Math.abs(pct) + '%'));
        res.appendChild(el('span', 'db-colab-rend-frase', t('dashboard.js.colaborador.frase_' + tom, { nome: primeiro, pct: Math.abs(pct) })));
        var max = Math.max(r.eu, r.media, 1);
        var barras = el('div', 'db-colab-barras');
        barras.appendChild(barra(primeiro, 'db-colab-barra--eu', r.eu, max, 'db-colab-barra-cheia--' + tom));
        barras.appendChild(barra(t('dashboard.js.colaborador.media_equipe'), 'db-colab-barra--media', r.media, max, 'db-colab-barra-cheia--media'));
        caixa.append(cab, res, barras, el('div', 'db-colab-rend-legenda', t('dashboard.js.colaborador.legenda', { quando: t('dashboard.js.colaborador.quando_' + d.janela), n: r.outros })));

        return caixa;
    }

    // ── Montagem ──

    function desenhar(janela, d, aoEscolher) {
        var sub = janela.elemento.querySelector('.db-janela-sub');
        if (sub) {
            sub.textContent = (d.cargo || t('dashboard.js.colaborador.sem_cargo')) + ' · ' + t('dashboard.js.colaborador.posicao_' + d.janela, { pos: d.posicao, n: d.pessoas });
        }
        var corpo = el('div', 'db-colab-corpo');
        // Alternativa do dono (fora do desenho): o recorte do cartão; abre no período do painel.
        corpo.appendChild(segmentado('db-colab-seg-janela', t('dashboard.js.colaborador.janela_aria'), JANELAS.map(function (j) { return [j, t(j)]; }), d.janela, aoEscolher));
        corpo.appendChild(linha('bi-chat-left-text-fill', t('dashboard.js.colaborador.comentarios'), d.registros));
        corpo.appendChild(blocoPrimeiros(d.primeiros));
        janela.corpo.replaceChildren(corpo, blocoRendimento(d));
    }

    /** O período aplicado no painel (o do troféu): os campos De/Até do formulário de filtros. */
    function periodoDoPainel(alvo) {
        var root = alvo.closest('[data-filtro-root]');
        var form = root ? root.querySelector('form[data-filtro-form]') : null;
        var ler = function (nome) {
            var c = form ? form.querySelector('input[name="' + nome + '"]') : null;

            return c ? c.value : '';
        };

        return { data_de: ler('data_de'), data_ate: ler('data_ate') };
    }

    function carregar(url, janela, periodo, escolhida) {
        var alvo = new URL(url, window.location.href);
        alvo.searchParams.set('data_de', periodo.data_de);
        alvo.searchParams.set('data_ate', periodo.data_ate);
        if (escolhida !== 'periodo') { alvo.searchParams.set('janela', escolhida); }
        fetch(alvo.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
            .then(function (d) {
                if (!janela.elemento.isConnected) { return; }
                desenhar(janela, d, function (j) { janelaEscolhida = j; carregar(url, janela, periodo, j); });
            })
            .catch(function () { if (janela.elemento.isConnected) { aviso(janela.corpo, t('dashboard.js.colaborador.erro')); } });
    }

    function avatar(janela, nome, av) {
        var caixa = janela.elemento.querySelector('.db-janela-icone');
        if (!caixa) { return; }
        var partes = String(nome || '').split(' ').filter(function (p) { return p !== ''; });
        caixa.className = 'db-janela-icone db-colab-av db-colab-av--' + (parseInt(av, 10) || 0);
        caixa.textContent = partes.slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
    }

    function abrirCartao(alvo, x, y) {
        var url = alvo.getAttribute('data-db-colab-url');
        var nome = alvo.getAttribute('data-db-colab-nome') || '';
        var periodo = periodoDoPainel(alvo);
        window.DbJanelas.abrir({
            chave: 'colab:' + url,
            titulo: nome,
            subtitulo: t('dashboard.js.colaborador.carregando'),
            icone: 'bi-person-fill',
            classe: 'db-hist db-colab-janela',
            largura: 320,
            altura: 440, // dc: 400 do desenho + o seletor de recorte
            x: x,
            y: y,
            origem: alvo,
            montar: function (corpo, janela) {
                avatar(janela, nome, alvo.getAttribute('data-db-colab-av'));
                aviso(corpo, t('dashboard.js.colaborador.carregando'));
                carregar(url, janela, periodo, janelaEscolhida);
            }
        });
    }

    function ligarCartao(root) {
        root.addEventListener('contextmenu', function (e) {
            var alvo = e.target.closest('[data-db-colab-url]');
            if (!alvo || !root.contains(alvo) || !window.DbJanelas || e.target.closest('a')) { return; }
            e.preventDefault();
            abrirCartao(alvo, e.clientX, e.clientY);
        });
    }

    /**
     * Toque longo (README: "ou toque longo no celular"): o iOS não dispara contextmenu, então
     * 550 ms parado sobre o nome abrem o cartão. Mover o dedo ou soltar antes cancela; depois de
     * abrir, o clique que viria do toque é engolido.
     */
    function ligarToqueLongo(root) {
        var timer = null;
        var inicio = null;
        var abriu = false;
        function cancelar() { if (timer) { clearTimeout(timer); timer = null; } }
        root.addEventListener('touchstart', function (e) {
            var alvo = e.target.closest('[data-db-colab-url]');
            if (!alvo || !root.contains(alvo) || !window.DbJanelas || e.touches.length !== 1) { return; }
            var toque = e.touches[0];
            inicio = { x: toque.clientX, y: toque.clientY };
            abriu = false;
            cancelar();
            timer = setTimeout(function () {
                timer = null;
                abriu = true;
                abrirCartao(alvo, inicio.x, inicio.y);
            }, TOQUE_LONGO_MS);
        }, { passive: true });
        root.addEventListener('touchmove', function (e) {
            var toque = e.touches[0];
            if (inicio && toque && Math.abs(toque.clientX - inicio.x) + Math.abs(toque.clientY - inicio.y) > 10) { cancelar(); }
        }, { passive: true });
        root.addEventListener('touchend', function (e) { cancelar(); if (abriu) { e.preventDefault(); abriu = false; } });
        root.addEventListener('touchcancel', cancelar);
        // Android: o próprio toque longo dispara contextmenu. Se chegou antes do tempo, ele abre o
        // cartão (ligarCartao) e o timer sai; se o toque já abriu, o contextmenu é engolido.
        root.addEventListener('contextmenu', function (e) {
            if (abriu) { e.preventDefault(); e.stopImmediatePropagation(); return; }
            cancelar();
        }, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-filtro-root]').forEach(function (root) {
            ligarFanfarra(root);
            ligarCartao(root);
            ligarToqueLongo(root);
        });
    });
})();
