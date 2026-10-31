/* =============================================================================
   pasta-andamento.js: clique nas fases do andamento da pasta (desenho 1.2.3, `faseMarcar`, E12b).
   Pergunta na janela da tela (confirmarPs, azul) com os textos do desenho; confirmado, grava e recarrega.
   A faixa "Avançar para X?" mostra a primeira sugestão não ignorada nesta visita (Ignorar só vale na sessão).
   Também rola a trilha até a fase atual, como o desenho.
   ============================================================================= */
(function () {
    'use strict';

    var caixa = document.querySelector('.ps-and[data-url]');
    if (!caixa) { return; }
    var atual = +caixa.getAttribute('data-atual');
    var ultima = +caixa.getAttribute('data-ultima');
    var CHAVE_IGN = 'ps-and-ign-' + caixa.getAttribute('data-pasta');

    function avisar(t) { if (window.avisarPs) { window.avisarPs(t, 'erro'); } }

    function nomeDa(i) {
        var b = caixa.querySelector('.js-and-fase[data-fase="' + i + '"]');
        return b ? b.getAttribute('data-nome') : '';
    }

    function enviar(fase, semPular, motivo) {
        var corpo = new URLSearchParams({ _token: caixa.getAttribute('data-csrf'), fase: String(fase), sem_pular: semPular ? '1' : '0', motivo: motivo || '' });
        fetch(caixa.getAttribute('data-url'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) { avisar(res.j.erro || BJ_T.t('pasta_avisos.js.andamento_erro')); return; }
                window.location.reload();
            }, function () { avisar(BJ_T.t('pasta_avisos.js.andamento_sem_conexao')); });
    }

    function perguntar(i, semPular, motivo) {
        var pergunta;
        if (i === ultima && atual === ultima) {
            pergunta = { titulo: BJ_T.t('pasta_avisos.js.andamento_reativar_titulo'), texto: BJ_T.t('pasta_avisos.js.andamento_reativar_texto'), rotulo: BJ_T.t('pasta_avisos.js.andamento_reativar'), icone: 'bi-arrow-repeat', variante: 'azul' };
        } else {
            if (i === atual) { return; }
            var fin = i === ultima;
            var pulam = semPular ? 0 : i - atual - 1;
            pergunta = {
                titulo: fin ? BJ_T.t('pasta_avisos.js.andamento_finalizar_titulo') : (i > atual ? BJ_T.t('pasta_avisos.js.andamento_avancar_titulo') : BJ_T.t('pasta_avisos.js.andamento_voltar_titulo')),
                texto: (pulam > 0 ? BJ_T.t('pasta_avisos.js.andamento_pulam', { total: pulam }) + ' ' : '')
                    + (fin ? BJ_T.t('pasta_avisos.js.andamento_finalizar_texto')
                        : BJ_T.t('pasta_avisos.js.andamento_mudar_texto', { de: nomeDa(atual), para: nomeDa(i) })),
                rotulo: fin ? BJ_T.t('pasta_avisos.js.andamento_finalizar') : (i > atual ? BJ_T.t('pasta_avisos.js.andamento_avancar') : BJ_T.t('pasta_avisos.js.andamento_voltar')),
                icone: fin ? 'bi-flag-fill' : (i > atual ? 'bi-arrow-right' : 'bi-arrow-left'),
                variante: 'azul'
            };
        }
        var resposta = window.confirmarPs ? window.confirmarPs(pergunta) : Promise.resolve(window.confirm(pergunta.titulo + '\n\n' + pergunta.texto));
        resposta.then(function (sim) { if (sim) { enviar(i, semPular, motivo); } });
    }

    caixa.addEventListener('click', function (e) {
        var b = e.target.closest('.js-and-fase');
        if (!b || b.disabled) { return; }
        perguntar(+b.getAttribute('data-fase'), false, '');
    });

    /* Etapa entre fases (desenho 02 VS 31.10.26): "+" nomeia, clique edita, botão direito remove. */
    function gravarEtapa(origem, texto) {
        var corpo = new URLSearchParams({ _token: caixa.getAttribute('data-csrf'), fase: String(origem), texto: texto });
        fetch(caixa.getAttribute('data-url-etapa'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) { avisar(res.j.erro || BJ_T.t('pasta_avisos.js.etapa_erro')); return; }
                window.location.reload();
            }, function () { avisar(BJ_T.t('pasta_avisos.js.andamento_sem_conexao')); });
    }

    function criar(tag, classe, texto) {
        var el = document.createElement(tag);
        if (classe) { el.className = classe; }
        if (texto) { el.textContent = texto; }
        return el;
    }

    function abrirEtapa(gatilho) {
        var origem = +gatilho.getAttribute('data-origem');
        var nome = gatilho.getAttribute('data-nome') || '';
        var fundo = criar('div', 'ps-and-pop-fundo');
        var caixaPop = criar('div', 'ps-and-pop');
        var campo = criar('input', 'ps-and-pop-campo');
        var cont = criar('div', 'ps-and-pop-cont');
        var acoes = criar('div', 'ps-and-pop-acoes');
        var cancelar = criar('button', 'ps-and-pop-cancelar', BJ_T.t('pasta_avisos.js.etapa_cancelar'));
        var salvar = criar('button', 'ps-and-pop-salvar', BJ_T.t('pasta_avisos.js.etapa_salvar'));
        campo.type = 'text';
        campo.maxLength = 12;
        campo.value = nome;
        campo.placeholder = BJ_T.t('pasta_avisos.js.etapa_placeholder', { exemplo: gatilho.getAttribute('data-exemplo') });
        cancelar.type = 'button';
        salvar.type = 'button';
        function contar() { cont.textContent = BJ_T.t('pasta_avisos.js.etapa_letras', { total: campo.value.length }); }
        function fechar() { fundo.remove(); caixaPop.remove(); }
        campo.addEventListener('input', contar);
        campo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); fechar(); gravarEtapa(origem, campo.value); }
            if (e.key === 'Escape') { fechar(); }
        });
        fundo.addEventListener('click', fechar);
        cancelar.addEventListener('click', fechar);
        salvar.addEventListener('click', function () { fechar(); gravarEtapa(origem, campo.value); });
        acoes.append(cancelar, salvar);
        caixaPop.append(criar('div', 'ps-and-pop-entre', gatilho.getAttribute('data-entre')), campo, cont, acoes);
        if (nome !== '') {
            var remover = criar('button', 'ps-and-pop-remover');
            remover.type = 'button';
            remover.append(criar('i', 'bi bi-trash3'), document.createTextNode(BJ_T.t('pasta_avisos.js.etapa_remover')));
            remover.addEventListener('click', function () { fechar(); gravarEtapa(origem, ''); });
            caixaPop.append(remover);
        }
        var r = gatilho.getBoundingClientRect();
        caixaPop.style.left = Math.max(8, Math.min(window.innerWidth - 210, r.left + r.width / 2 - 100)) + 'px';
        caixaPop.style.top = Math.min(window.innerHeight - 140, r.bottom + 8) + 'px';
        document.body.append(fundo, caixaPop);
        contar();
        campo.focus();
        campo.select();
    }

    caixa.addEventListener('click', function (e) {
        var g = e.target.closest('.js-and-gap');
        if (g && !g.disabled) { abrirEtapa(g); }
    });
    caixa.addEventListener('contextmenu', function (e) {
        var g = e.target.closest('.js-and-gap');
        if (!g || g.disabled || (g.getAttribute('data-nome') || '') === '') { return; }
        e.preventDefault();
        gravarEtapa(+g.getAttribute('data-origem'), '');
    });

    /* Faixa histórico: abre a aba da ocorrência (mesmo caminho do fragmento #aba da URL). */
    caixa.addEventListener('click', function (e) {
        var h = e.target.closest('.js-and-hist');
        var gatilho = h ? document.getElementById(h.getAttribute('data-aba') + '-tab') : null;
        if (gatilho && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(gatilho).show(); }
    });

    /* Rola até a fase atual (dc `fasesRefFn`). */
    var rol = caixa.querySelector('.ps-and-rol');
    function rolar() {
        var at = rol.querySelector('[data-fase-at="1"]');
        if (at) { rol.scrollLeft = Math.max(0, at.offsetLeft + at.offsetWidth - rol.clientWidth + 90); }
    }
    requestAnimationFrame(rolar);
    if (window.ResizeObserver) { new ResizeObserver(function () { requestAnimationFrame(rolar); }).observe(rol); }

    /* Faixa de sugestão. */
    var faixa = document.querySelector('.ps-and-sug[data-sugestoes]');
    if (!faixa) { return; }
    var lista = [];
    try { lista = JSON.parse(faixa.getAttribute('data-sugestoes')) || []; } catch (x) { lista = []; }
    function ignoradas() { try { return JSON.parse(localStorage.getItem(CHAVE_IGN) || '{}') || {}; } catch (x) { return {}; } }
    function mostrar() {
        var ign = ignoradas();
        var s = lista.filter(function (x) { return !ign[x.nome]; })[0];
        faixa.hidden = !s;
        if (!s) { return; }
        faixa.querySelector('.js-and-sug-ic').className = 'bi ' + s.icone + ' js-and-sug-ic';
        faixa.querySelector('.js-and-sug-titulo').textContent = BJ_T.t('pasta_avisos.js.andamento_sugestao', { fase: s.nome });
        faixa.querySelector('.js-and-sug-motivo').textContent = s.motivo;
        faixa._s = s;
    }
    faixa.addEventListener('click', function (e) {
        var s = faixa._s;
        if (!s) { return; }
        if (e.target.closest('.js-and-sug-ok')) { perguntar(s.indice, true, s.motivo); return; }
        if (e.target.closest('.js-and-sug-ign')) {
            var ign = ignoradas();
            ign[s.nome] = true;
            try { localStorage.setItem(CHAVE_IGN, JSON.stringify(ign)); } catch (x) { /* só não lembra */ }
            mostrar();
        }
    });
    mostrar();
}());
