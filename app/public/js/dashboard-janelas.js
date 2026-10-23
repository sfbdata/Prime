/**
 * Janelas flutuantes do Dashboard (DASH-020) — componente reutilizável.
 *
 * Desenho (README do handoff, "Janelas: arrastar e abrir várias" e "Arrastar janelas"):
 *  - várias abertas ao mesmo tempo; cada nova abre deslocada 18px da anterior;
 *  - abrir de novo a MESMA chave só traz a janela para a frente (não duplica);
 *  - arrasta pelo cabeçalho (`[data-janela-alca]`, cursor de mão, ícone bi-grip-horizontal);
 *    o arraste parte da posição VISÍVEL (getBoundingClientRect), não do clique original;
 *    limites: topo de 0 até a altura da tela − 56px (o cabeçalho fica sempre à vista) e, na
 *    horizontal, pelo menos 96px dentro da tela;
 *  - clicar numa janela a traz para a frente;
 *  - fecha pelo X; Esc fecha a que estiver na frente; não fecha com clique fora nem ao rolar.
 *
 * Acessibilidade: cada janela é um `role="dialog"` NÃO modal (`aria-modal="false"`), rotulado
 * pelo título (`aria-labelledby`). Ao abrir, o foco vai para a janela; ao fechar, volta para
 * quem a abriu (`origem`, ou o elemento que tinha o foco) — se ele ainda estiver na página.
 *
 * Segurança: nada aqui monta HTML a partir de string. Título, subtítulo e o conteúdo montado por quem chama
 * entram por createElement/textContent — dado do servidor nunca vira HTML.
 *
 * Uso:
 *   var j = window.DbJanelas.abrir({
 *       chave: 'hist:pastas', titulo: 'Pastas criadas', subtitulo: 'Histórico mensal',
 *       icone: 'bi-folder-plus', classe: 'db-hist db-hist--pastas', largura: 330, altura: 420,
 *       x: ev.clientX, y: ev.clientY, origem: elemento,
 *       montar: function (corpo, janela) { ... }   // só na 1ª abertura da chave
 *   });
 *   j.corpo, j.elemento, j.fechar(), j.frente()
 */
(function () {
    'use strict';

    var BASE_Z    = 1036;   // acima do conteúdo e do cabeçalho fixo; abaixo do modal (1050+)
    var DESLOCA   = 18;     // cada nova janela abre 18px deslocada da anterior
    var MARGEM    = 10;     // folga da borda na abertura
    var TOPO_MIN  = 0;
    var ALCA_VIS  = 56;     // o cabeçalho nunca sai de baixo da tela
    var LADO_VIS  = 96;     // pelo menos 96px da janela dentro da tela, na horizontal

    var abertas = [];       // da mais ao fundo para a da frente
    var contador = 0;

    function el(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = String(texto); }

        return n;
    }

    function porChave(chave) {
        for (var i = 0; i < abertas.length; i++) {
            if (abertas[i].chave === chave) { return abertas[i]; }
        }

        return null;
    }

    function reordenar() {
        abertas.forEach(function (j, i) {
            j.elemento.style.zIndex = String(BASE_Z + i);
            j.elemento.classList.toggle('is-frente', i === abertas.length - 1);
        });
    }

    function frente(j) {
        var i = abertas.indexOf(j);
        if (i === -1 || i === abertas.length - 1) { return; }
        abertas.splice(i, 1);
        abertas.push(j);
        reordenar();
    }

    function limitar(x, y, largura) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;

        return {
            x: Math.max(-largura + LADO_VIS, Math.min(vw - LADO_VIS, x)),
            y: Math.max(TOPO_MIN, Math.min(vh - ALCA_VIS, y))
        };
    }

    function posicionar(j, x, y) {
        j.elemento.style.left = Math.round(x) + 'px';
        j.elemento.style.top  = Math.round(y) + 'px';
    }

    /** Primeira posição: junto ao cursor, deslocada pelas já abertas, inteira dentro da tela. */
    function posicaoInicial(j, x, y, alturaPrevista) {
        var r  = j.elemento.getBoundingClientRect();
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var n  = abertas.length - 1;
        var px = (typeof x === 'number' ? x : vw / 2 - r.width / 2) + n * DESLOCA + 6;
        var py = (typeof y === 'number' ? y : vh / 3) + n * DESLOCA + 6;

        posicionar(j,
            Math.max(MARGEM, Math.min(px, vw - r.width - MARGEM)),
            Math.max(MARGEM, Math.min(py, vh - Math.max(r.height, alturaPrevista || 0) - MARGEM)));
    }

    function focavel(alvo) {
        return !!(alvo && alvo.isConnected && typeof alvo.focus === 'function' && alvo !== document.body);
    }

    function fechar(j) {
        var i = abertas.indexOf(j);
        if (i === -1) { return; }
        var tinhaFoco = j.elemento.contains(document.activeElement);
        abertas.splice(i, 1);
        j.elemento.remove();
        reordenar();
        if (typeof j.aoFechar === 'function') { j.aoFechar(j); }

        if (!tinhaFoco && document.activeElement && document.activeElement !== document.body) {
            return; // o foco já está em outro lugar: não roubar
        }
        // Retorno do foco: para quem abriu; senão, para a janela que ficou na frente.
        if (focavel(j.voltarPara)) {
            j.voltarPara.focus({ preventScroll: true });
            if (document.activeElement === j.voltarPara) { return; }
        }
        var topo = abertas[abertas.length - 1];
        if (topo) { topo.elemento.focus({ preventScroll: true }); }
    }

    function ligarArraste(j, alca) {
        alca.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button !== 0) { return; }
            if (e.target.closest('button, a, input, select, textarea')) { return; }
            e.preventDefault();
            frente(j);

            var rc = j.elemento.getBoundingClientRect();
            var sx = e.clientX;
            var sy = e.clientY;
            var ox = rc.left;
            var oy = rc.top;
            var larg = rc.width;
            j.elemento.classList.add('is-arrastando');

            function mover(ev) {
                var p = limitar(ox + ev.clientX - sx, oy + ev.clientY - sy, larg);
                posicionar(j, p.x, p.y);
            }
            function soltar() {
                window.removeEventListener('pointermove', mover);
                window.removeEventListener('pointerup', soltar);
                window.removeEventListener('pointercancel', soltar);
                j.elemento.classList.remove('is-arrastando');
            }
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
            window.addEventListener('pointercancel', soltar);
        });
    }

    function abrir(opcoes) {
        var o = opcoes || {};
        var chave = String(o.chave || ('janela-' + (++contador)));
        var ja = porChave(chave);
        if (ja) {
            frente(ja);
            ja.elemento.focus({ preventScroll: true });

            return ja;
        }

        var id = 'db-janela-' + (++contador);
        var caixa = el('div', 'db-janela' + (o.classe ? ' ' + o.classe : ''));
        caixa.setAttribute('role', 'dialog');
        caixa.setAttribute('aria-modal', 'false');
        caixa.setAttribute('aria-labelledby', id + '-titulo');
        caixa.setAttribute('tabindex', '-1');
        caixa.setAttribute('data-janela-chave', chave);
        if (o.largura) { caixa.style.width = o.largura + 'px'; }

        var cab = el('div', 'db-janela-cab');
        cab.setAttribute('data-janela-alca', '');
        cab.title = window.BJ_T.t('dashboard.js.janelas.arraste_para_mover');
        if (o.icone) {
            var ic = el('span', 'db-janela-icone');
            ic.setAttribute('aria-hidden', 'true');
            ic.appendChild(el('i', 'bi ' + o.icone));
            cab.appendChild(ic);
        }
        var textos = el('div', 'db-janela-textos');
        var titulo = el('div', 'db-janela-titulo', o.titulo || '');
        titulo.id = id + '-titulo';
        textos.appendChild(titulo);
        if (o.subtitulo) {
            textos.appendChild(el('div', 'db-janela-sub', o.subtitulo));
        }
        cab.appendChild(textos);
        var grip = el('i', 'bi bi-grip-horizontal db-janela-grip');
        grip.setAttribute('aria-hidden', 'true');
        cab.appendChild(grip);
        var x = el('button', 'db-janela-fechar');
        x.type = 'button';
        x.setAttribute('aria-label', window.BJ_T.t('dashboard.js.janelas.fechar'));
        x.title = window.BJ_T.t('dashboard.js.janelas.fechar');
        var xi = el('i', 'bi bi-x-lg');
        xi.setAttribute('aria-hidden', 'true');
        x.appendChild(xi);
        cab.appendChild(x);
        caixa.appendChild(cab);

        var corpo = el('div', 'db-janela-corpo');
        caixa.appendChild(corpo);

        var j = {
            chave: chave,
            elemento: caixa,
            corpo: corpo,
            titulo: titulo,
            voltarPara: o.origem || document.activeElement,
            aoFechar: o.aoFechar,
            fechar: function () { fechar(j); },
            frente: function () { frente(j); }
        };

        x.addEventListener('click', function () { fechar(j); });
        // Clique (ou foco por teclado) em qualquer ponto da janela a traz para a frente.
        caixa.addEventListener('pointerdown', function () { frente(j); });
        caixa.addEventListener('focusin', function () { frente(j); });
        ligarArraste(j, cab);

        abertas.push(j);
        document.body.appendChild(caixa);
        reordenar();
        if (typeof o.montar === 'function') { o.montar(corpo, j); }
        posicaoInicial(j, o.x, o.y, o.altura);
        caixa.focus({ preventScroll: true });

        return j;
    }

    /** Campo de texto ou lista aberta FORA das janelas tem o próprio Esc: não competir com ele. */
    function escDeOutroComponente(e) {
        if (e.defaultPrevented) { return true; }
        var ativo = document.activeElement;
        var dentroDeJanela = ativo && ativo.closest && ativo.closest('.db-janela');
        if (dentroDeJanela) { return false; }
        if (ativo && ativo.matches && ativo.matches('input, textarea, select, [contenteditable="true"]')) { return true; }
        var expandidos = document.querySelectorAll('[aria-expanded="true"]');
        for (var i = 0; i < expandidos.length; i++) {
            if (!expandidos[i].closest('.db-janela')) { return true; }
        }

        return false;
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || abertas.length === 0 || escDeOutroComponente(e)) { return; }
        e.preventDefault();
        fechar(abertas[abertas.length - 1]);
    });

    // Tela redimensionada: nenhuma janela fica perdida fora dela.
    window.addEventListener('resize', function () {
        abertas.forEach(function (j) {
            var r = j.elemento.getBoundingClientRect();
            var p = limitar(r.left, r.top, r.width);
            posicionar(j, p.x, p.y);
        });
    });

    window.DbJanelas = {
        abrir: abrir,
        fechar: function (chave) { var j = porChave(String(chave)); if (j) { fechar(j); } },
        aberta: function (chave) { return porChave(String(chave)) !== null; },
        quantas: function () { return abertas.length; },
        // utilitário para quem monta o conteúdo sem montar HTML por string
        el: el
    };
})();
