/**
 * Dashboard: troféu do campeão e cartão do colaborador (spec dashboard-parte-1, itens 3 a 5).
 *
 *  - Fanfarra do campeão (README do desenho, "Troféu" e "Som do campeão"): o cursor entra na linha
 *    (ou no card do celular) marcada com `[data-db-campeao]` e toca um "pam… paaam" gerado no
 *    navegador (Web Audio, sem arquivo). No máximo 2 vezes por carregamento da página (contador em
 *    memória) e uma a cada 1,4 s. Mudo com a opção "Sons" do menu ⋮ desligada (`db-page--sem-som`)
 *    ou com "Sons de alerta" desligado no Meu Perfil (`data-perfil-sons="0"`). O crescimento e o
 *    brilho do troféu são CSS (dashboard.css), não daqui.
 *  - Cartão do colaborador: botão direito no nome (`[data-db-colab-url]`) abre uma janela flutuante
 *    (dashboard-janelas.js) com a posição no ranking de metas concluídas e o seletor Mês ·
 *    Trimestre · Semestre · Ano. A janela escolhida vale para os próximos cartões da mesma página.
 *
 * Delegado no `[data-filtro-root]` persistente (o fragmento da tabela é trocado a cada filtro).
 * Nada de HTML montado por string: o que vem do servidor entra por textContent.
 */
(function () {
    'use strict';

    var JANELAS = ['mes', 'trimestre', 'semestre', 'ano'];
    var MAX_FANFARRAS = 2;
    var INTERVALO_MS = 1400;

    var fanfarras = 0;
    var ultimaFanfarra = 0;
    var contexto = null;
    var janelaEscolhida = 'mes';

    function t(chave, vars) {
        return window.BJ_T.t('dashboard.js.colaborador.' + chave, vars);
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

    function linha(icone, rotulo, valor, extra) {
        var l = el('div', 'db-colab-linha');
        var i = el('i', 'bi ' + icone + ' db-colab-linha-ico');
        i.setAttribute('aria-hidden', 'true');
        l.appendChild(i);
        l.appendChild(el('span', 'db-colab-linha-rotulo', rotulo));
        l.appendChild(el('span', 'db-colab-linha-espaco'));
        l.appendChild(el('span', 'db-colab-linha-valor', valor));
        if (extra) { l.appendChild(el('span', 'db-colab-linha-extra', extra)); }

        return l;
    }

    function dataBr(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');

        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }

    function desenhar(janela, d, seletor) {
        var sub = janela.elemento.querySelector('.db-janela-sub');
        if (sub) {
            sub.textContent = (d.cargo || t('sem_cargo')) + ' · ' + t('posicao_' + d.janela, { pos: d.posicao, n: d.pessoas });
        }
        var corpo = el('div', 'db-colab-corpo');
        // Ordem do desenho: Comentários, a linha do troféu e, abaixo, o seletor que a recorta.
        corpo.appendChild(linha('bi-chat-left-text-fill', t('comentarios'), d.registros));
        corpo.appendChild(linha('bi-trophy-fill db-colab-ico--ouro', t('metas_concluidas'), d.metasConcluidas, d.campeao ? t('campeao') : ''));
        corpo.appendChild(seletor);
        janela.corpo.replaceChildren(
            corpo,
            el('div', 'db-hist-nota', t('nota', { de: dataBr(d.dataDe), ate: dataBr(d.dataAte), equipe: d.metasConcluidasEquipe }))
        );
    }

    function montarSeletor(aoEscolher) {
        var grupo = el('div', 'db-hist-anos db-colab-janelas');
        grupo.setAttribute('role', 'group');
        grupo.setAttribute('aria-label', t('periodo'));
        JANELAS.forEach(function (j) {
            var b = el('button', 'db-hist-ano', t(j));
            b.type = 'button';
            b.dataset.janela = j;
            b.addEventListener('click', function () { aoEscolher(j); });
            grupo.appendChild(b);
        });

        return grupo;
    }

    function marcar(seletor, escolhida) {
        seletor.querySelectorAll('[data-janela]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.janela === escolhida ? 'true' : 'false');
        });
    }

    function carregar(url, janela, seletor, escolhida) {
        var alvo = new URL(url, window.location.href);
        alvo.searchParams.set('janela', escolhida);
        marcar(seletor, escolhida);
        fetch(alvo.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
            .then(function (d) { if (janela.elemento.isConnected) { desenhar(janela, d, seletor); } })
            .catch(function () { if (janela.elemento.isConnected) { aviso(janela.corpo, t('erro')); } });
    }

    function avatar(janela, nome, av) {
        var caixa = janela.elemento.querySelector('.db-janela-icone');
        if (!caixa) { return; }
        var partes = String(nome || '').split(' ').filter(function (p) { return p !== ''; });
        caixa.className = 'db-janela-icone db-colab-av db-colab-av--' + (parseInt(av, 10) || 0);
        caixa.textContent = partes.slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
    }

    function abrirCartao(alvo, e) {
        var url = alvo.getAttribute('data-db-colab-url');
        var nome = alvo.getAttribute('data-db-colab-nome') || '';
        window.DbJanelas.abrir({
            chave: 'colab:' + url,
            titulo: nome,
            subtitulo: t('carregando'),
            icone: 'bi-person-fill',
            classe: 'db-hist db-colab',
            largura: 320,
            altura: 260, // altura da janela pronta: a posição já reserva o espaço
            x: e.clientX,
            y: e.clientY,
            origem: alvo,
            montar: function (corpo, janela) {
                avatar(janela, nome, alvo.getAttribute('data-db-colab-av'));
                var seletor = montarSeletor(function (j) {
                    janelaEscolhida = j;
                    carregar(url, janela, seletor, j);
                });
                aviso(corpo, t('carregando'));
                carregar(url, janela, seletor, janelaEscolhida);
            }
        });
    }

    function ligarCartao(root) {
        root.addEventListener('contextmenu', function (e) {
            var alvo = e.target.closest('[data-db-colab-url]');
            if (!alvo || !root.contains(alvo) || !window.DbJanelas || e.target.closest('a')) { return; }
            e.preventDefault();
            abrirCartao(alvo, e);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-filtro-root]').forEach(function (root) {
            ligarFanfarra(root);
            ligarCartao(root);
        });
    });
})();
