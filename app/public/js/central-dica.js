/*
 * Central de Comunicação: balão de dica do Designer (bj-dica.js do pacote de 13/10), RESTRITO à Central.
 *
 * O desenho troca a dica preta do navegador por um balão azul-claro em todo elemento com title="...". Por decisão do
 * dono (Fase A, 15/10) isso vale só dentro da Central: o lançador, a janela flutuante e a página /central. O resto do
 * sistema continua com a dica do navegador. Comportamento e medidas são os do bj-dica.js, linha a linha:
 *   - no hover, o title vira data-bj-tip (assim o navegador não mostra a dica dele por cima);
 *   - aparece 280 ms depois de o mouse chegar, abaixo do alvo, centralizado, sem sair da tela (margem de 8 px) e sem
 *     cobrir o alvo (se não couber embaixo, vai para cima);
 *   - some ao sair, ao clicar, ao teclar, ao rolar e no botão direito.
 * Diferenças obrigatórias:
 *   - a Central reescreve o title a cada atualização (contadores, maximizar...). O desenho redesenha o elemento inteiro
 *     e não precisa disso; aqui um MutationObserver move o title NOVO de quem já virou dica (tem data-bj-tip), senão a
 *     dica do navegador voltaria por cima do balão. Quem nunca recebeu o mouse continua com o title intacto;
 *   - ao tirar o title, o texto vai para aria-description quando o elemento não tem nome próprio (aria-label/texto),
 *     para o leitor de tela não perder a informação (ex.: a data completa da hora da mensagem).
 */
(function () {
    'use strict';

    if (window.__bjCentralDica) { return; }
    window.__bjCentralDica = true;

    var RAIZES = '[data-central], [data-central-flutuante], [data-flutuante-janela]';
    var ATRASO_MS = 280;
    var MARGEM = 8;

    var tip = null, alvo = null, timer = null;

    function naCentral(el) {
        return !!(el && el.closest && el.closest(RAIZES));
    }

    function criar() {
        tip = document.createElement('div');
        tip.className = 'bj-central-dica';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
    }

    function mover(el) {   // title → data-bj-tip, como o texto() do bj-dica.js
        var t = el.getAttribute('title');
        if (!t || el.closest('[data-tip-proprio]')) { return; }
        el.setAttribute('data-bj-tip', t);
        el.removeAttribute('title');
        if (el.hasAttribute('data-bj-tip-aria') || (!el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby'))) {
            el.setAttribute('aria-description', t);
            el.setAttribute('data-bj-tip-aria', '1');
        }
    }

    function texto(el) {
        mover(el);
        return el.getAttribute('data-bj-tip') || '';
    }

    function posicionar() {
        if (!alvo || !tip) { return; }
        var r = alvo.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
        var x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
        if (y + h > window.innerHeight - MARGEM) { y = r.top - h - 8; }
        if (y < MARGEM) { y = Math.min(window.innerHeight - h - MARGEM, r.bottom + 8); }
        x = Math.max(MARGEM, Math.min(window.innerWidth - w - MARGEM, x));
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    }

    function mostrar(el) {
        var t = texto(el);
        if (!t || !el.isConnected) { return; }
        if (!tip) { criar(); }
        alvo = el;
        tip.textContent = t;
        tip.style.opacity = '0';
        window.requestAnimationFrame(function () {
            posicionar();
            tip.style.opacity = '1';
            tip.style.transform = 'translateY(0)';
        });
    }

    function esconder() {
        window.clearTimeout(timer);
        timer = null;
        alvo = null;
        if (tip) {
            tip.style.opacity = '0';
            tip.style.transform = 'translateY(2px)';
        }
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest && e.target.closest('[title],[data-bj-tip]');
        if (!el || el === alvo || !naCentral(el)) { return; }
        if (el.tagName.toLowerCase() === 'title') { return; }   // <title> de SVG
        if (el.closest('[data-tip-proprio]')) { return; }
        window.clearTimeout(timer);
        texto(el);
        timer = window.setTimeout(function () { mostrar(el); }, ATRASO_MS);
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!alvo && !timer) { return; }
        var para = e.relatedTarget;
        if (alvo && para && alvo.contains(para)) { return; }
        var el = e.target.closest && e.target.closest('[data-bj-tip]');
        if (el && para && el.contains(para)) { return; }
        esconder();
    }, true);

    ['mousedown', 'keydown', 'wheel', 'scroll'].forEach(function (ev) {
        window.addEventListener(ev, esconder, { capture: true, passive: true });
    });
    document.addEventListener('contextmenu', esconder, true);

    // A Central reescreve title de quem já virou dica (contadores, rótulos de maximizar...): move o novo na hora.
    function vigiar(raiz) {
        if (!window.MutationObserver) { return; }
        new MutationObserver(function (mudancas) {
            mudancas.forEach(function (m) {
                var el = m.target;
                if (!el.hasAttribute('title') || !el.hasAttribute('data-bj-tip')) { return; }
                mover(el);
                if (el === alvo && tip) { tip.textContent = el.getAttribute('data-bj-tip'); posicionar(); }
            });
        }).observe(raiz, { subtree: true, attributes: true, attributeFilter: ['title'] });
    }

    function iniciar() {
        document.querySelectorAll(RAIZES).forEach(function (raiz) {
            if (raiz.parentElement && raiz.parentElement.closest(RAIZES)) { return; }   // já vigiada pela de fora
            vigiar(raiz);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
