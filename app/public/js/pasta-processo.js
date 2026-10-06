/* ============================================================================
   Aba Processo da pasta — cartão do processo (desenho 02 - EXPEDIENTES 1.2.3).

   - "Ver todas as informações" / "Mostrar menos": abre e fecha o painel
     `.ps-processo-detalhes` (aria-expanded + aria-controls no botão).
   - ⋮ do cartão: menu `.ps-processo-menu` (fixo, posicionado pelo gatilho), só
     com o que tem função hoje: nota técnica (nota-tecnica.js), alternar o
     painel, ir à aba Push, copiar número, copiar resumo e Compartilhar — este
     só aparece onde o navegador tem `navigator.share`.

   Tudo por delegação no `document`: o parcial `_processos_vinculados` volta por
   XHR (vincular / desvincular / principal) e o cartão novo já nasce funcionando.
   ============================================================================ */
(function () {
    'use strict';

    if (window.__psProcessoCartao) { return; }
    window.__psProcessoCartao = true;

    var aberto = null; // { menu, gatilho }

    /* ── "Ver todas as informações" ─────────────────────────────────────── */
    function alternar(botao) {
        var painel = document.getElementById(botao.getAttribute('aria-controls') || '');
        if (!painel) { return; }

        var abrir = botao.getAttribute('aria-expanded') !== 'true';
        painel.hidden = !abrir;
        botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');

        var rotulo = botao.querySelector('span');
        var icone  = botao.querySelector('i');
        if (rotulo) { rotulo.textContent = abrir ? 'Mostrar menos' : 'Ver todas as informações'; }
        if (icone)  { icone.className = 'bi ' + (abrir ? 'bi-chevron-up' : 'bi-chevron-down'); }
    }

    /* ── Menu ⋮ ─────────────────────────────────────────────────────────── */
    function fechar(devolverFoco) {
        if (!aberto) { return; }
        aberto.menu.hidden = true;
        aberto.gatilho.setAttribute('aria-expanded', 'false');
        if (devolverFoco) { aberto.gatilho.focus(); }
        aberto = null;
    }

    function posicionar(menu, gatilho) {
        var r       = gatilho.getBoundingClientRect();
        var largura = menu.offsetWidth;
        var altura  = menu.offsetHeight;
        // Alinhado pela borda direita do ⋮, logo abaixo dele (desenho: gmAbrir).
        var x = r.right - largura;
        var y = r.bottom + 4;
        if (y + altura > window.innerHeight - 8) { y = Math.max(8, r.top - altura - 4); }
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - largura - 8)) + 'px';
        menu.style.top  = Math.max(8, y) + 'px';
    }

    function preparar(menu) {
        // Compartilhar só onde existe a função do navegador.
        var compartilhar = menu.querySelector('.js-proc-compartilhar');
        if (compartilhar) { compartilhar.hidden = typeof navigator.share !== 'function'; }

        // O item de alternar acompanha o estado do botão do cartão.
        var item = menu.querySelector('.js-proc-alternar-menu');
        if (item) {
            var botao = document.querySelector(item.getAttribute('data-botao') || '');
            var exp   = !!botao && botao.getAttribute('aria-expanded') === 'true';
            var span  = item.querySelector('span');
            var icone = item.querySelector('i');
            if (span)  { span.textContent = exp ? 'Mostrar menos' : 'Ver todas as informações'; }
            if (icone) { icone.className = 'bi ' + (exp ? 'bi-chevron-up' : 'bi-card-list'); }
        }
    }

    function abrirMenu(menu, gatilho) {
        fechar(false);
        // Um menu por vez: fecha os popovers do cabeçalho (pasta-show.js).
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        preparar(menu);
        menu.hidden = false;
        posicionar(menu, gatilho);
        gatilho.setAttribute('aria-expanded', 'true');
        aberto = { menu: menu, gatilho: gatilho };
        var primeiro = menu.querySelector('.ps-processo-menu-item:not([hidden])');
        if (primeiro) { primeiro.focus(); }
    }

    function linhasDoResumo(el) {
        try {
            var linhas = JSON.parse(el.getAttribute('data-resumo') || '[]');
            return Array.isArray(linhas) ? linhas.join('\n') : '';
        } catch (e) {
            return '';
        }
    }

    function copiar(item) {
        var texto  = item.hasAttribute('data-resumo') ? linhasDoResumo(item) : (item.getAttribute('data-copiar') || '');
        var rotulo = item.querySelector('span');
        var original = rotulo ? rotulo.textContent : '';
        var menuDaVez = aberto ? aberto.menu : null;

        // Como o "Copiar link" do cabeçalho: o rótulo vira o aviso por um instante
        // e o menu fecha depois, se ainda for o mesmo.
        function avisar(msg) {
            if (rotulo) { rotulo.textContent = msg; }
            setTimeout(function () {
                if (rotulo) { rotulo.textContent = original; }
                if (aberto && aberto.menu === menuDaVez) { fechar(false); }
            }, 1200);
        }

        if (texto && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(
                function () { avisar(item.getAttribute('data-aviso') || 'Copiado'); },
                function () { avisar('Não foi possível copiar'); }
            );
        } else {
            avisar('Não foi possível copiar');
        }
    }

    function compartilhar(item) {
        fechar(false);
        if (typeof navigator.share !== 'function') { return; }
        navigator.share({
            title: item.getAttribute('data-titulo') || '',
            text: linhasDoResumo(item),
        }).catch(function () { /* cancelado pelo usuário */ });
    }

    function irParaAba(item) {
        fechar(false);
        var gatilho = document.getElementById(item.getAttribute('data-aba') || '');
        if (gatilho && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(gatilho).show(); }
    }

    // Clique FORA fecha, em captura (os gatilhos do cabeçalho param a propagação).
    document.addEventListener('click', function (e) {
        if (!aberto) { return; }
        if (aberto.menu.contains(e.target) || aberto.gatilho.contains(e.target)) { return; }
        fechar(false);
    }, true);

    document.addEventListener('click', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }

        var botaoMais = alvo.closest('.js-proc-alternar');
        if (botaoMais) { alternar(botaoMais); return; }

        var gatilho = alvo.closest('.js-proc-menu-gatilho');
        if (gatilho) {
            var menu = document.getElementById(gatilho.getAttribute('aria-controls') || '');
            if (!menu) { return; }
            if (aberto && aberto.menu === menu) { fechar(false); } else { abrirMenu(menu, gatilho); }
            return;
        }

        var item = alvo.closest('.ps-processo-menu-item');
        if (!item) { return; }

        if (item.classList.contains('js-proc-copiar'))         { copiar(item); return; }
        if (item.classList.contains('js-proc-compartilhar'))   { compartilhar(item); return; }
        if (item.classList.contains('js-proc-ver-movimentacoes')) { irParaAba(item); return; }
        if (item.classList.contains('js-proc-alternar-menu')) {
            var botao = document.querySelector(item.getAttribute('data-botao') || '');
            fechar(false);
            if (botao) { alternar(botao); }
            return;
        }
        // "Adicionar nota técnica": quem abre o compositor é o nota-tecnica.js
        // (`.js-nota-nova` + `data-notas`); aqui só fecha o menu.
        fechar(false);
    });

    document.addEventListener('keydown', function (e) {
        if (aberto && e.key === 'Escape') { fechar(true); }
    });
    window.addEventListener('resize', function () { fechar(false); });
    window.addEventListener('scroll', function () { fechar(false); }, true);
    document.addEventListener('ps:fechar-popovers', function () {
        // Disparado pelos outros menus da tela; o próprio abrirMenu dispara antes
        // de registrar `aberto`, então não se fecha a si mesmo.
        fechar(false);
    });
}());
