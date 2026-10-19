/*
 * Página pública do link externo do Push Processual (/p/<endereco>): as proteções do desenho
 * ("Push Compartilhado.dc.html", [PC] 118-126, seção D.3 da spec). Vanilla, sem dependência.
 *
 * - bloqueia copiar, recortar, menu de contexto, arrastar e selecionar (menos nos campos);
 * - bloqueia Ctrl/Cmd + P, S, C, X, A, U (menos nos campos) e avisa;
 * - PrintScreen e os atalhos de captura do Mac cobrem a página e sujam a área de transferência;
 * - ao perder o foco, trocar de aba ou imprimir, a página fica coberta ("Conteúdo protegido");
 * - o @media print do CSS troca a impressão por um aviso.
 *
 * Nada aqui escreve dado na página: só liga e desliga elementos que o servidor já desenhou.
 */
(function () {
    'use strict';

    var veu = document.querySelector('[data-pc-oculto]');
    var aviso = document.querySelector('[data-pc-aviso]');
    var timerAviso = null;
    var CAPTURA = 'Captura bloqueada pelo BlueJus.';

    function emCampo(alvo) {
        return !!(alvo && alvo.closest && alvo.closest('input,textarea'));
    }

    function ocultar(sim) {
        if (veu) {
            veu.hidden = !sim;
        }
    }

    function avisar() {
        if (!aviso) {
            return;
        }
        aviso.hidden = false;
        clearTimeout(timerAviso);
        timerAviso = setTimeout(function () { aviso.hidden = true; }, 2200);
    }

    function sujarAreaDeTransferencia() {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(CAPTURA).catch(function () {});
            }
        } catch (e) { /* sem permissão: segue */ }
    }

    function bloquear(evento) {
        if (emCampo(evento.target)) {
            return;
        }
        evento.preventDefault();
        avisar();
    }

    function teclaBaixa(evento) {
        var tecla = String(evento.key || '').toLowerCase();
        if ((evento.ctrlKey || evento.metaKey) && ['p', 's', 'c', 'x', 'a', 'u'].indexOf(tecla) !== -1 && !emCampo(evento.target)) {
            evento.preventDefault();
            avisar();
        }
        if (tecla === 'printscreen' || (evento.metaKey && evento.shiftKey && ['3', '4', '5', 's'].indexOf(tecla) !== -1)) {
            ocultar(true);
            sujarAreaDeTransferencia();
            setTimeout(function () { ocultar(!document.hasFocus()); }, 1500);
        }
    }

    function teclaSolta(evento) {
        if (String(evento.key || '').toLowerCase() === 'printscreen') {
            sujarAreaDeTransferencia();
        }
    }

    ['copy', 'cut', 'contextmenu', 'dragstart'].forEach(function (tipo) {
        window.addEventListener(tipo, bloquear, true);
    });
    window.addEventListener('selectstart', function (evento) {
        if (!emCampo(evento.target)) {
            evento.preventDefault();
        }
    }, true);
    window.addEventListener('keydown', teclaBaixa, true);
    window.addEventListener('keyup', teclaSolta, true);
    window.addEventListener('beforeprint', function () { ocultar(true); });
    window.addEventListener('afterprint', function () { ocultar(false); });
    window.addEventListener('blur', function () { ocultar(true); });
    window.addEventListener('focus', function () { ocultar(false); });
    document.addEventListener('visibilitychange', function () { ocultar(document.hidden); });

    // Código de acesso ([PC] 148-149): só dígitos, até 6, com foco ao abrir.
    var campoCodigo = document.querySelector('[data-pc-codigo]');
    if (campoCodigo) {
        campoCodigo.addEventListener('input', function () {
            var limpo = campoCodigo.value.replace(/\D/g, '').slice(0, 6);
            if (limpo !== campoCodigo.value) {
                campoCodigo.value = limpo;
            }
        });
        setTimeout(function () { campoCodigo.focus(); }, 50);
    }
})();
