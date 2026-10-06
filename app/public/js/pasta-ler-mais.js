/*
 * "continuar lendo (N parágrafos)" / "mostrar menos" — textos longos da pasta.
 *
 * Desenho 1.2.3 ("02 - EXPEDIENTES 1.2.3.dc.html", `toggleLabel` ~l.6479, botão ~l.2348):
 * texto com MAIS de 3 parágrafos mostra só os 3 primeiros e um botão
 * "continuar lendo (N parágrafos)", em que N = total − 3; aberto, o botão vira
 * "mostrar menos". Até 3 parágrafos, nada muda.
 *
 * Só EXIBIÇÃO: o texto não é reescrito. Os blocos excedentes ganham a classe
 * `ps-lermais-oculto` (ou, no texto legado, ficam dentro de um <span> com ela) e o
 * CSS os esconde enquanto o contêiner não estiver `ps-lermais--aberto`.
 *
 * Opt-in pelo contêiner da LISTA: `data-ler-mais="<seletor do texto>"`. O script
 * observa a lista (MutationObserver), então o cartão montado em JS depois de
 * enviar, e o texto devolvido à tela depois de editar, ganham o mesmo
 * comportamento sem que o JS da tela precise chamar nada. Idempotente: o texto já
 * tratado leva `data-ler-mais-pronto` e não é tratado de novo.
 *
 * O que conta como parágrafo (vazios não contam — o `<p><br></p>` do editor é
 * espaço, não parágrafo):
 *  - editor rico: cada bloco filho do `.ql-editor` (p, h*, blockquote, pre…);
 *    numa lista (ul/ol), cada <li>;
 *  - legado em texto puro (sai do `texto_rico` como texto + <br>): cada linha.
 */
(function () {
    'use strict';

    var VISIVEIS = 3;
    var BLOCOS = /^(P|DIV|H[1-6]|BLOCKQUOTE|PRE|UL|OL|TABLE|HR|FIGURE)$/;

    function temConteudo(nos) {
        for (var i = 0; i < nos.length; i++) {
            var no = nos[i];
            if (no.nodeType === Node.TEXT_NODE && no.textContent.replace(/[\s ]+/g, '') !== '') { return true; }
            if (no.nodeType === Node.ELEMENT_NODE) {
                if (no.textContent.replace(/[\s ]+/g, '') !== '') { return true; }
                if (no.matches('img, iframe, video') || no.querySelector('img, iframe, video')) { return true; }
            }
        }
        return false;
    }

    /* Quebra o conteúdo em unidades na ordem em que aparecem. Cada unidade é
       `{ el }` (bloco que recebe a classe) ou `{ nos }` (trecho de linha legado,
       que é embrulhado num <span> se tiver de sumir). */
    function unidadesDe(raiz) {
        var unidades = [];
        var linha = [];

        function fecharLinha() {
            if (linha.length) { unidades.push({ nos: linha }); }
            linha = [];
        }

        Array.prototype.slice.call(raiz.childNodes).forEach(function (no) {
            if (no.nodeType === Node.ELEMENT_NODE && BLOCOS.test(no.tagName)) {
                fecharLinha();
                if (no.tagName === 'UL' || no.tagName === 'OL') {
                    Array.prototype.slice.call(no.children).forEach(function (li) {
                        unidades.push({ el: li, lista: no });
                    });
                } else {
                    unidades.push({ el: no });
                }
                return;
            }
            if (no.nodeType === Node.ELEMENT_NODE && no.tagName === 'BR') {
                // O <br> abre a linha seguinte: some junto com ela.
                fecharLinha();
                linha.push(no);
                return;
            }
            linha.push(no);
        });
        fecharLinha();

        unidades.forEach(function (u) { u.cheia = temConteudo(u.el ? [u.el] : u.nos); });

        return unidades;
    }

    function ocultar(u) {
        if (u.el) {
            u.el.classList.add('ps-lermais-oculto');
            return;
        }
        var span = document.createElement('span');
        span.className = 'ps-lermais-oculto';
        u.nos[0].parentNode.insertBefore(span, u.nos[0]);
        u.nos.forEach(function (no) { span.appendChild(no); });
    }

    function rotulo(ocultos, aberto) {
        return aberto ? 'mostrar menos' : 'continuar lendo (' + ocultos + (ocultos === 1 ? ' parágrafo)' : ' parágrafos)');
    }

    function preparar(texto) {
        if (texto.hasAttribute('data-ler-mais-pronto')) { return; }

        var raiz = texto.querySelector('.ql-editor') || texto;
        var unidades = unidadesDe(raiz);
        var cheias = unidades.filter(function (u) { return u.cheia; }).length;

        // Ainda vazio (o cartão novo nasce vazio e recebe o HTML logo depois): não marca,
        // para que a próxima mutação o trate.
        if (cheias === 0) { return; }
        texto.setAttribute('data-ler-mais-pronto', '');
        if (cheias <= VISIVEIS) { return; }

        // Esconde tudo DEPOIS do 3º parágrafo cheio — inclusive os vazios entre ele e o
        // 4º, que senão deixariam um buraco antes do botão.
        var vistas = 0;
        var listas = [];
        unidades.forEach(function (u) {
            if (vistas >= VISIVEIS) {
                ocultar(u);
                if (u.lista && listas.indexOf(u.lista) === -1) { listas.push(u.lista); }
                return;
            }
            if (u.cheia) { vistas++; }
        });
        // Lista inteira escondida: some o <ul>/<ol> também (senão sobra a margem dele).
        listas.forEach(function (lista) {
            var todos = Array.prototype.every.call(lista.children, function (li) {
                return li.classList.contains('ps-lermais-oculto');
            });
            if (todos) { lista.classList.add('ps-lermais-oculto'); }
        });

        var ocultos = cheias - VISIVEIS;
        texto.classList.add('ps-lermais');

        var botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'ps-lermais-botao';
        botao.setAttribute('aria-expanded', 'false');
        botao.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i><span></span>';
        botao.lastChild.textContent = rotulo(ocultos, false);
        botao.addEventListener('click', function () {
            var aberto = !texto.classList.contains('ps-lermais--aberto');
            texto.classList.toggle('ps-lermais--aberto', aberto);
            botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            botao.firstChild.className = aberto ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
            botao.lastChild.textContent = rotulo(ocultos, aberto);
        });
        texto.appendChild(botao);
    }

    function tratarLista(lista) {
        var seletor = lista.getAttribute('data-ler-mais') || '.ps-anotacao-texto';
        lista.querySelectorAll(seletor).forEach(preparar);
    }

    function iniciar() {
        document.querySelectorAll('[data-ler-mais]').forEach(function (lista) {
            if (lista.hasAttribute('data-ler-mais-observado')) { return; }
            lista.setAttribute('data-ler-mais-observado', '');
            tratarLista(lista);
            new MutationObserver(function () { tratarLista(lista); })
                .observe(lista, { childList: true, subtree: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
