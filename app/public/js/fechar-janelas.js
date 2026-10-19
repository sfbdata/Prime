/* =============================================================================
   fechar-janelas.js: o X de fechar em toda janelinha (protótipo do Designer, bluejus-central.js, bloco "X de fechar
   em toda janelinha"; cobrado pelo desenho 03 VS 18.10.26). Toda janela, menu ou pop-up aberto (role menu, dialog,
   alertdialog ou listbox, ou o cartão de um véu fixo que cobre a tela) que NÃO tenha botão de fechar recebe um X no
   canto superior direito. Fechar usa o botão de fechar da própria janela se houver; senão envia Esc e um clique fora,
   como a pessoa faria. data-sem-x="1" desliga num elemento.

   Diferenças conscientes do protótipo, para o sistema real:
   - Também conta como "já tem X" o fechar do Bootstrap (.btn-close, [data-bs-dismiss], "Close"): sem isso os modais
     ganhariam um segundo X.
   - Observa também classe, estilo e hidden: o Bootstrap abre modal trocando classe, sem nó novo.
   - Sem o último recurso do protótipo (esconder com display:none quando a janela não fecha): no sistema isso
     dessincronizaria componentes que reaproveitam o nó; quem não fecha com Esc nem clique fora fica aberto.
   - Claro ou escuro pelo primeiro fundo de verdade (o protótipo contava cabeçalho transparente como preto).
   - DOM só com createElement/createElementNS (nunca innerHTML).
   ============================================================================= */
(function () {
    'use strict';

    if (window.__bjFecharGlobal) { return; }
    window.__bjFecharGlobal = true;

    var PAPEIS = '[role="menu"],[role="dialog"],[role="alertdialog"],[role="listbox"]';
    var TEM_X = '[aria-label="Fechar"],[aria-label^="Fechar "],[title^="Fechar"],[data-bj-x],.bi-x-lg,.bi-x-circle,.bi-x-circle-fill,'
        + '.btn-close,[data-bs-dismiss],[aria-label="Close"],[aria-label^="Close "]';

    function cobre(el) {
        if (getComputedStyle(el).position !== 'fixed') { return false; }
        var r = el.getBoundingClientRect();
        var d = document.documentElement;

        return r.width >= d.clientWidth - 2 && r.height >= d.clientHeight - 2;
    }

    /* A janela de verdade: o próprio elemento, ou, num véu que cobre a tela, o cartão de dentro. */
    function alvo(el) {
        if (!el || !el.isConnected || el.closest('[data-sem-x]')) { return null; }
        if (cobre(el)) {
            return Array.prototype.find.call(el.children, function (x) {
                var r = x.getBoundingClientRect();
                return r.width > 180 && r.height > 100 && r.width < document.documentElement.clientWidth - 8;
            }) || null;
        }
        var r = el.getBoundingClientRect();

        return r.width > 150 && r.height > 80 ? el : null;
    }

    function fechar(jan) {
        var proprio = Array.prototype.find.call(jan.querySelectorAll('button'), function (b) {
            return b.dataset.bjX !== '1' && /^(Fechar|Cancelar|Voltar)$/i.test((b.getAttribute('aria-label') || b.textContent || '').trim());
        });
        if (proprio) { proprio.click(); return; }
        var op = { key: 'Escape', code: 'Escape', keyCode: 27, bubbles: true, cancelable: true };
        (document.activeElement || document.body).dispatchEvent(new KeyboardEvent('keydown', op));
        document.dispatchEvent(new KeyboardEvent('keydown', op));
        setTimeout(function () {
            if (!jan.isConnected) { return; }
            ['pointerdown', 'mousedown', 'mouseup', 'click'].forEach(function (t) {
                document.body.dispatchEvent(new MouseEvent(t, { bubbles: true, clientX: 1, clientY: 1 }));
            });
        }, 60);
    }

    /* Fundo claro ou escuro, pelo primeiro fundo de verdade de baixo para cima. O protótipo lia só o do anfitrião, e
       um cabeçalho transparente (rgba(0, 0, 0, 0)) contava como preto: o X saía branco sobre branco, invisível. */
    function escuro(host) {
        for (var el = host; el && el !== document.documentElement; el = el.parentElement) {
            var cs = getComputedStyle(el);
            if (/gradient/.test(cs.backgroundImage)) { return true; }
            var m = cs.backgroundColor.match(/rgba?\((\d+), ?(\d+), ?(\d+)(?:, ?([\d.]+))?/);
            if (m !== null && (m[4] === undefined || +m[4] > 0.5)) {
                return (+m[1] * 0.3 + +m[2] * 0.59 + +m[3] * 0.11) < 140;
            }
        }

        return false;
    }

    function botao(jan) {
        var b = document.createElement('button');
        b.type = 'button';
        b.dataset.bjX = '1';
        b.className = 'bj-fechar-x';
        b.setAttribute('aria-label', 'Fechar');
        b.title = 'Fechar (Esc)';
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '12'); svg.setAttribute('height', '12'); svg.setAttribute('viewBox', '0 0 12 12'); svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('d', 'M2 2l8 8M10 2l-8 8'); p.setAttribute('stroke', 'currentColor'); p.setAttribute('stroke-width', '1.8'); p.setAttribute('stroke-linecap', 'round');
        svg.appendChild(p);
        b.appendChild(svg);
        ['pointerdown', 'mousedown'].forEach(function (t) { b.addEventListener(t, function (e) { e.stopPropagation(); }, true); });
        b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fechar(jan); });

        return b;
    }

    function colocar(base) {
        var jan = alvo(base);
        if (!jan || jan.querySelector(TEM_X)) { return; }   // já tem X (o próprio ou um nosso que continua lá)
        var h = jan.firstElementChild;
        var alturaCab = h ? h.getBoundingClientRect().height : 0;
        var usaCab = alturaCab < 140 && alturaCab > 24;
        var host = usaCab ? h : jan;
        if (getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
        var b = botao(jan);
        b.classList.add(usaCab ? 'bj-fechar-x--cab' : 'bj-fechar-x--canto');
        if (escuro(host)) { b.classList.add('bj-fechar-x--escuro'); }
        if (usaCab) {
            var pr = parseFloat(getComputedStyle(h).paddingRight) || 0;
            if (pr < 40) { h.style.paddingRight = (pr + 34) + 'px'; }
        }
        host.appendChild(b);
    }

    var agendado = false;
    function varrer() {
        agendado = false;
        document.querySelectorAll(PAPEIS).forEach(function (el) {
            if (el.parentElement && el.parentElement.closest(PAPEIS)) { return; }   // só a janela de fora
            colocar(el);
        });
        document.querySelectorAll('[style*="position: fixed"],[style*="position:fixed"]').forEach(function (el) {
            if (el.children.length && cobre(el) && !el.closest('[data-sem-x]')) {
                var k = alvo(el);
                if (k && getComputedStyle(k).boxShadow !== 'none') { colocar(el); }
            }
        });
    }
    function agendar() {
        if (agendado) { return; }
        agendado = true;
        requestAnimationFrame(function () { setTimeout(varrer, 30); });
    }

    new MutationObserver(agendar).observe(document.documentElement, {
        childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'],
    });
    setTimeout(varrer, 500);
}());
