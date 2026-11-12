/*
 * Cédula da assembleia de condomínio (/votar/{token}). Vanilla, sem dependência, sem Bootstrap.
 *
 * - O voto acontece na cabine (js/voto-cabine.js).
 * - "Encerra em mm:ss." conta para trás a partir dos segundos que o servidor mandou (não usa o relógio do aparelho);
 *   ao zerar, recarrega a página, que mostra a pauta encerrada.
 * - Com pauta à espera da mesa (body[data-vc-recarregar]), recarrega a cada N segundos, nunca com a cabine aberta.
 * - O toast some em 3600 ms, como no desenho.
 *
 * Nada aqui escreve HTML vindo de fora: só textos que o servidor já pôs em atributos data-*.
 */
(function () {
    'use strict';

    function doisDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    var inicio = Date.now();
    var prazos = Array.prototype.slice.call(document.querySelectorAll('[data-vc-prazo]'));
    var recarregando = false;
    var zerou = false;

    function recarregar() {
        if (recarregando) {
            return;
        }
        if (document.body.classList.contains('uAberta')) {
            setTimeout(recarregar, 2000);
            return;
        }
        recarregando = true;
        window.location.reload();
    }

    function contar() {
        var passados = Math.floor((Date.now() - inicio) / 1000);
        prazos.forEach(function (el) {
            var restam = Math.max(0, parseInt(el.getAttribute('data-vc-prazo'), 10) - passados);
            var texto = el.querySelector('[data-vc-prazo-texto]');
            var modelo = el.getAttribute('data-vc-modelo') || '';
            if (texto) {
                texto.textContent = modelo.replace('{t}', doisDigitos(Math.floor(restam / 60)) + ':' + doisDigitos(restam % 60));
            }
            if (restam === 0 && !zerou) {
                zerou = true;
                setTimeout(recarregar, 1000);
            }
        });
    }

    if (prazos.length > 0) {
        setInterval(contar, 1000);
    }

    var intervalo = parseInt(document.body.getAttribute('data-vc-recarregar') || '', 10);
    if (intervalo > 0) {
        setTimeout(recarregar, intervalo * 1000);
    }

    document.querySelectorAll('[data-vc-toast]').forEach(function (toast) {
        setTimeout(function () {
            toast.hidden = true;
        }, 3600);
    });
})();
