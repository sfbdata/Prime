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
                if (!res.ok) { avisar(res.j.erro || 'Não foi possível mudar a fase'); return; }
                window.location.reload();
            }, function () { avisar('Falha de conexão. Tente de novo.'); });
    }

    function perguntar(i, semPular, motivo) {
        var pergunta;
        if (i === ultima && atual === ultima) {
            pergunta = { titulo: 'Reativar pasta', texto: 'A pasta está finalizada. Reativar abre duas novas etapas: "Em andamento" (reativada) e um novo "Finalizada". O histórico anterior é mantido.', rotulo: 'Reativar', icone: 'bi-arrow-repeat', variante: 'azul' };
        } else {
            if (i === atual) { return; }
            var fin = i === ultima;
            var pulam = semPular ? 0 : i - atual - 1;
            pergunta = {
                titulo: fin ? 'Finalizar pasta' : (i > atual ? 'Avançar fase da pasta' : 'Voltar fase da pasta'),
                texto: (pulam > 0 ? 'Atenção: ' + pulam + ' fase(s) intermediária(s) ficarão marcadas em vermelho como puladas. ' : '')
                    + (fin ? 'Marcar a pasta como finalizada? Depois, se precisar, clique em "Finalizada" para reativar.'
                        : 'Mudar de "' + nomeDa(atual) + '" para "' + nomeDa(i) + '"? A mudança fica registrada no Registro dos expedientes com data e hora.'),
                rotulo: fin ? 'Finalizar' : (i > atual ? 'Avançar' : 'Voltar'),
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
        faixa.querySelector('.js-and-sug-titulo').textContent = 'Avançar para "' + s.nome + '"?';
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
