/* BlueJus Voto: caixas "Acompanhar" da cédula. UMA requisição por cédula, a cada 10 s, traz a participação de todas as
   pautas (só contagem de unidades, nunca voto por opção) e atualiza número e barra, que animam por transição CSS.
   Para com a aba oculta (e volta ao mostrar), no 404 e quando todas as pautas terminam; no 429 ou erro de rede espaça
   (dobra até 60 s e volta a 10 s no sucesso). Um timer só. Ao uma pauta encerrar, recarrega a página para o resultado. */
(function () {
    'use strict';

    var BASE = 10000;
    var TETO = 60000;
    var caixas = Array.prototype.slice.call(document.querySelectorAll('[data-vc-acomp]'));
    if (caixas.length === 0) { return; }
    var url = caixas[0].getAttribute('data-vc-acomp-url');
    var espera = BASE;
    var timer = null;
    var parado = false;

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

    function aplicar(pautas) {
        var todasTerminaram = true;
        caixas.forEach(function (caixa) {
            var d = pautas.filter(function (p) { return String(p.id) === caixa.getAttribute('data-vc-acomp'); })[0];
            if (!d) { return; }
            atualizar(caixa, d);
            if (d.estado !== caixa.getAttribute('data-vc-acomp-estado')) { mudouDeEstado(caixa, d); }
            todasTerminaram = todasTerminaram && d.terminou;
        });
        if (todasTerminaram) { parado = true; }
    }

    function agendar() {
        clearTimeout(timer);
        if (!parado && !document.hidden) { timer = setTimeout(perguntar, espera); }
    }

    function falhou() {
        espera = Math.min(espera * 2, TETO);
        agendar();
    }

    function perguntar() {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (r.status === 404) { parado = true; return null; }
                if (!r.ok) { return falhou(); }
                return r.json().then(function (d) {
                    espera = BASE;
                    aplicar(d.pautas || []);
                    agendar();
                });
            })
            .catch(falhou);
    }

    document.addEventListener('visibilitychange', function () {
        clearTimeout(timer);
        if (!document.hidden && !parado) { perguntar(); }
    });

    var abertas = caixas.some(function (c) { var e = c.getAttribute('data-vc-acomp-estado'); return e !== 'encerrada' && e !== 'cancelada'; });
    if (!abertas) { parado = true; }
    agendar();
}());
