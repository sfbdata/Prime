/* BlueJus Voto: caixa "Acompanhar" da cédula. Pergunta a cada 10 s quantas unidades já votaram (só contagem, nunca voto
   por opção) e atualiza o número e a barra, que animam por transição CSS. Para quando a aba está oculta e quando a
   pauta termina; ao encerrar, recarrega a página uma vez para mostrar o resultado. */
(function () {
    'use strict';

    var INTERVALO = 10000;

    function preencher(modelo, valores) {
        return Object.keys(valores).reduce(function (t, k) { return t.replace('{' + k + '}', valores[k]); }, modelo);
    }

    function atualizar(caixa, d) {
        var texto = caixa.querySelector('[data-vc-acomp-texto]');
        var novo = preencher(caixa.getAttribute('data-vc-acomp-modelo-participacao'), { votaram: d.votaram, aptas: d.aptas });
        if (texto.textContent !== novo) {
            texto.textContent = novo;
            texto.classList.add('vcAcompMudou');
            setTimeout(function () { texto.classList.remove('vcAcompMudou'); }, 1200);
        }
        caixa.querySelector('[data-vc-acomp-preenchimento]').style.width = d.percentual + '%';
        caixa.querySelector('[data-vc-acomp-barra]').setAttribute('aria-valuenow', d.percentual);
        caixa.querySelector('[data-vc-acomp-pct]').textContent = preencher(caixa.getAttribute('data-vc-acomp-modelo-percentual'), { pct: d.percentual });
    }

    function mudouDeEstado(caixa, d) {
        var estados = JSON.parse(caixa.getAttribute('data-vc-acomp-estados') || '{}');
        var chip = caixa.querySelector('[data-vc-acomp-chip]');
        caixa.setAttribute('data-vc-acomp-estado', d.estado);
        if (chip && estados[d.estado]) { chip.textContent = estados[d.estado]; }
        if (d.terminou) { setTimeout(function () { window.location.reload(); }, 1500); }
    }

    function acompanhar(caixa) {
        var parado = false;

        function agendar() { if (!parado) { setTimeout(perguntar, INTERVALO); } }

        function perguntar() {
            if (parado || document.hidden) { return agendar(); }
            fetch(caixa.getAttribute('data-vc-acomp-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) { return agendar(); }
                    atualizar(caixa, d);
                    if (d.estado !== caixa.getAttribute('data-vc-acomp-estado')) { mudouDeEstado(caixa, d); }
                    if (d.terminou) { parado = true; return; }
                    agendar();
                })
                .catch(agendar);
        }

        var estado = caixa.getAttribute('data-vc-acomp-estado');
        if (estado === 'encerrada' || estado === 'cancelada') { return; }
        document.addEventListener('visibilitychange', function () { if (!document.hidden && !parado) { perguntar(); } });
        agendar();
    }

    document.querySelectorAll('[data-vc-acomp]').forEach(acompanhar);
}());
