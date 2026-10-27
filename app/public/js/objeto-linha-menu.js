/**
 * Menu da linha da obrigação (aba Dívida do objeto) — desenho 1A de 19/10 (`menuAbrir`/`omVals`).
 *
 * O `⋯` da linha e o botão direito em qualquer ponto dela abrem o MESMO menu: o elemento cujo `id`
 * está no `aria-controls` do botão e no `data-obr-menu` da linha. O menu nasce no HTML (Twig),
 * `hidden`; este script só abre, posiciona, fecha e despacha os itens.
 *
 * Cada item reusa a ação que já existe na linha, sem implementação paralela:
 *   · receber  → clica o botão Receber da linha (o modal de pagamento lê o `relatedTarget` dele);
 *   · marcar   → clica a checkbox da linha (a barra de seleção escuta o `change` dela);
 *   · memoria  → abre o chevron da linha (só abre, como no desenho; não alterna);
 *   · copiar   → área de transferência + aviso "Copiado".
 * Editar e Excluir não passam por aqui: são os gatilhos REAIS dos modais (`data-bs-toggle="modal"`),
 * e o Bootstrap os trata pelo próprio clique. Este script só fecha o menu depois.
 *
 * Texto só por textContent: vem de `data-obr-menu-dados` (JSON), nunca como HTML.
 */
(function () {
    'use strict';

    var LARGURA = 240;
    var MARGEM = 8;
    var aberto = null;     // { menu, linha }
    var avisoTimer = null;
    var camada = null;

    /* Camada transparente atrás do menu aberto (desenho L215: `position:fixed;inset:0`). Ela ABSORVE
       o clique e o botão direito fora do menu: só fecham o menu. Sem ela o clique atravessaria para
       o "Receber" de outra linha e o botão direito abriria o menu nativo do navegador. */
    function camadaDeFundo() {
        if (camada) {
            return camada;
        }
        camada = document.createElement('div');
        camada.className = 'cob-obr-menu-camada';
        camada.hidden = true;
        camada.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fechar(false);
        });
        camada.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fechar(false);
        });
        document.body.appendChild(camada);
        return camada;
    }

    function linhaDoMenu(menu) {
        return document.querySelector('[data-obr-menu="' + menu.id + '"]');
    }

    function itens(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('.cob-obr-menu-item'));
    }

    function fechar(devolverFoco) {
        if (!aberto) {
            return;
        }
        var atual = aberto;
        aberto = null;
        atual.menu.hidden = true;
        if (camada) {
            camada.hidden = true;
        }
        atual.menu.style.left = '';
        atual.menu.style.top = '';
        var botao = atual.linha ? atual.linha.querySelector('[data-obr-menu-abrir]') : null;
        if (botao) {
            botao.setAttribute('aria-expanded', 'false');
        }
        if (devolverFoco && botao) {
            botao.focus();
        }
    }

    /* Posição do desenho: a borda DIREITA do menu encosta no ponto (o canto direito do `⋯`, ou o
       cursor no botão direito) e ele nunca sai da janela. */
    function posicionar(menu, x, y) {
        var altura = menu.offsetHeight;
        var esquerda = Math.max(MARGEM, Math.min(x - LARGURA, window.innerWidth - LARGURA - MARGEM));
        var topo = Math.max(MARGEM, Math.min(y, window.innerHeight - altura - MARGEM));
        menu.style.left = esquerda + 'px';
        menu.style.top = topo + 'px';
    }

    function abrir(menu, x, y, pelobotao) {
        if (aberto && aberto.menu === menu) {
            fechar(false);
            return;
        }
        fechar(false);
        var linha = linhaDoMenu(menu);
        aberto = { menu: menu, linha: linha };

        // O estado da checkbox pode ter mudado por fora (clique direto nela): sincroniza ao abrir.
        var marcar = menu.querySelector('[data-obr-menu-acao="marcar"]');
        var check = linha ? linha.querySelector('.jp-check') : null;
        if (marcar && check) {
            marcar.setAttribute('aria-checked', check.checked ? 'true' : 'false');
        }

        camadaDeFundo().hidden = false;
        menu.hidden = false;
        posicionar(menu, x, y);
        var botao = linha ? linha.querySelector('[data-obr-menu-abrir]') : null;
        if (botao) {
            botao.setAttribute('aria-expanded', 'true');
        }
        var primeiro = itens(menu)[0];
        if (primeiro && pelobotao) {
            primeiro.focus();
        }
    }

    function aviso(texto) {
        var caixa = document.getElementById('cobObrAviso');
        if (!caixa) {
            caixa = document.createElement('div');
            caixa.id = 'cobObrAviso';
            caixa.className = 'cob-obr-aviso';
            caixa.setAttribute('role', 'status');
            caixa.setAttribute('aria-live', 'polite');
            caixa.hidden = true;
            document.body.appendChild(caixa);
        }
        caixa.textContent = texto;
        caixa.hidden = false;
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { caixa.hidden = true; }, 2600);
    }

    function dados(menu) {
        try {
            return JSON.parse(menu.getAttribute('data-obr-menu-dados') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function copiar(menu) {
        var d = dados(menu);
        var texto = (d.descricao || '') + ' · vencimento ' + (d.vencimento || '') + ' · total ' + (d.total || '');
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            aviso(BJ_T.t('cobranca_objeto.js.copiar_falhou'));
            return;
        }
        navigator.clipboard.writeText(texto).then(
            function () { aviso(BJ_T.t('cobranca_objeto.js.copiado')); },
            function () { aviso(BJ_T.t('cobranca_objeto.js.copiar_falhou')); }
        );
    }

    function executar(acao, menu, linha) {
        if (acao === 'copiar') {
            copiar(menu);
            return;
        }
        if (!linha) {
            return;
        }
        if (acao === 'receber') {
            var receber = linha.querySelector('[data-acao="receber"]');
            if (receber) { receber.click(); }
        } else if (acao === 'marcar') {
            var check = linha.querySelector('.jp-check');
            if (check) { check.click(); }
        } else if (acao === 'memoria') {
            var chevron = linha.querySelector('.cob-obr-chevron');
            if (chevron && chevron.getAttribute('aria-expanded') !== 'true') { chevron.click(); }
        }
    }

    document.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-obr-menu-abrir]');
        if (botao) {
            var menu = document.getElementById(botao.getAttribute('data-obr-menu-abrir'));
            if (menu) {
                e.preventDefault();
                var r = botao.getBoundingClientRect();
                abrir(menu, r.right, r.bottom + 4, true);
            }
            return;
        }

        var item = aberto ? e.target.closest('.cob-obr-menu-item') : null;
        if (item && aberto && aberto.menu.contains(item)) {
            var atual = aberto;
            fechar(false);
            var acao = item.getAttribute('data-obr-menu-acao');
            if (acao) {
                executar(acao, atual.menu, atual.linha);
            }
            // Sem `data-obr-menu-acao` é Editar/Excluir: o Bootstrap abre o modal pelo próprio clique.
            return;
        }
    });

    document.addEventListener('contextmenu', function (e) {
        if (aberto && aberto.menu.contains(e.target)) {
            // Dentro do próprio menu o botão direito não faz nada (nem abre o nativo por cima dele).
            e.preventDefault();
            return;
        }
        var linha = e.target.closest('[data-obr-menu]');
        if (!linha || e.shiftKey) {
            // Shift + botão direito devolve o menu nativo do navegador. Com o menu aberto, o botão
            // direito fora dele nem chega aqui: cai na camada de fundo, que absorve e fecha.
            return;
        }
        var menu = document.getElementById(linha.getAttribute('data-obr-menu'));
        if (!menu) {
            return;
        }
        e.preventDefault();
        if (aberto && aberto.menu === menu) {
            fechar(false);
        }
        abrir(menu, e.clientX, e.clientY, false);
    });

    document.addEventListener('keydown', function (e) {
        if (!aberto) {
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            fechar(true);
            return;
        }
        // Setas só navegam quando o foco está DENTRO do menu (aberto pelo botão direito o foco fica
        // onde estava, e as setas continuam rolando a página).
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && aberto.menu.contains(document.activeElement)) {
            var lista = itens(aberto.menu);
            if (!lista.length) { return; }
            e.preventDefault();
            var i = lista.indexOf(document.activeElement);
            var proximo = e.key === 'ArrowDown' ? (i + 1) % lista.length : (i <= 0 ? lista.length - 1 : i - 1);
            lista[proximo].focus();
        } else if (e.key === 'Tab') {
            fechar(false);
        }
    });

    // Rolar ou redimensionar desloca a linha sob um menu de posição fixa: fecha, como um menu nativo.
    window.addEventListener('resize', function () { fechar(false); });
    window.addEventListener('scroll', function () { fechar(false); }, true);
})();
