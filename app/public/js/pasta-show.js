/* =============================================================================
   pasta-show.js — comportamento do redesenho da tela de pasta.

   Tres coisas, todas puramente visuais:
     1. o indicador que desliza sob a aba ativa;
     2. o drawer do historico do sistema;
     3. os popovers de prioridade e do menu "mais acoes".

   A TROCA de aba continua sendo do Bootstrap (data-bs-toggle="tab"): o
   pasta-arquivos.js chama bootstrap.Tab para voltar a aba Documentos depois do
   upload, e trocar isso por controle proprio quebraria aquele caminho.
   ============================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var pagina = document.querySelector('.ps-page');
        if (!pagina) { return; }

        indicadorDeAbas();
        drawerDeHistorico();
        popovers();
        atalhosDeAba();
        verAnotacoesAnteriores();
        acoesDoMenu();
        acordeaoDoPush();
    });

    /* ── 1. Indicador das abas ────────────────────────────────────────────── */
    function indicadorDeAbas() {
        var trilho = document.querySelector('.ps-abas');
        if (!trilho) { return; }

        var indicador = trilho.querySelector('.ps-abas-ind');
        if (!indicador) { return; }

        // Ha JS: a aba ativa passa a ser marcada pela pilula, nao pelo fundo
        // solido que o CSS usa como degradacao graciosa.
        trilho.classList.remove('ps-abas--sem-js');

        function posicionar(animar) {
            var ativa = trilho.querySelector('.ps-aba.active');
            if (!ativa) { return; }

            if (!animar) { indicador.style.transition = 'none'; }

            indicador.style.opacity   = '1';
            indicador.style.top       = ativa.offsetTop + 'px';
            indicador.style.height    = ativa.offsetHeight + 'px';
            indicador.style.width     = ativa.offsetWidth + 'px';
            indicador.style.transform = 'translate3d(' + ativa.offsetLeft + 'px,0,0)';

            if (!animar) {
                // Forca o reflow antes de devolver a transicao, senao a primeira
                // troca de aba herda o 'none' e a pilula pula em vez de deslizar.
                void indicador.offsetWidth;
                indicador.style.transition = '';
            }
        }

        posicionar(false);

        trilho.addEventListener('shown.bs.tab', function () { posicionar(true); });
        window.addEventListener('resize', function () { posicionar(false); });

        // A pilula e medida em pixels; se a fonte chegar depois da primeira
        // medida, a largura dos botoes muda e o indicador fica torto.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { posicionar(false); });
        }
    }

    /* ── 2. Drawer do historico ───────────────────────────────────────────── */
    function drawerDeHistorico() {
        var drawer  = document.getElementById('psHistorico');
        var overlay = document.getElementById('psHistoricoOverlay');
        var abrir   = document.getElementById('psHistoricoAbrir');
        if (!drawer || !overlay || !abrir) { return; }

        var fechar        = drawer.querySelector('.ps-drawer-fechar');
        var focoAnterior  = null;

        function abrirDrawer() {
            focoAnterior = document.activeElement;
            drawer.classList.add('is-aberto');
            overlay.classList.add('is-aberto');
            drawer.removeAttribute('aria-hidden');
            abrir.setAttribute('aria-expanded', 'true');
            if (fechar) { fechar.focus(); }
        }

        function fecharDrawer() {
            drawer.classList.remove('is-aberto');
            overlay.classList.remove('is-aberto');
            drawer.setAttribute('aria-hidden', 'true');
            abrir.setAttribute('aria-expanded', 'false');
            if (focoAnterior && typeof focoAnterior.focus === 'function') { focoAnterior.focus(); }
        }

        abrir.addEventListener('click', function () {
            if (drawer.classList.contains('is-aberto')) { fecharDrawer(); } else { abrirDrawer(); }
        });
        overlay.addEventListener('click', fecharDrawer);
        if (fechar) { fechar.addEventListener('click', fecharDrawer); }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer.classList.contains('is-aberto')) { fecharDrawer(); }
        });
    }

    /* ── 3. Popovers (prioridade e menu ⋮) ────────────────────────────────── */
    function popovers() {
        var gatilhos = Array.prototype.slice.call(document.querySelectorAll('[data-ps-pop]'));
        if (gatilhos.length === 0) { return; }

        function fecharTodos(exceto) {
            gatilhos.forEach(function (g) {
                var alvo = document.getElementById(g.getAttribute('data-ps-pop'));
                if (!alvo || alvo === exceto) { return; }
                alvo.classList.remove('is-aberto');
                g.setAttribute('aria-expanded', 'false');
            });
        }

        gatilhos.forEach(function (gatilho) {
            var alvo = document.getElementById(gatilho.getAttribute('data-ps-pop'));
            if (!alvo) { return; }

            gatilho.addEventListener('click', function (e) {
                e.stopPropagation();
                var vaiAbrir = !alvo.classList.contains('is-aberto');
                // Abrir um fecha o outro — foi o pedido do desenho, e evita dois
                // paineis sobrepostos no mesmo canto.
                fecharTodos(vaiAbrir ? alvo : null);
                alvo.classList.toggle('is-aberto', vaiAbrir);
                gatilho.setAttribute('aria-expanded', vaiAbrir ? 'true' : 'false');
            });
        });

        document.addEventListener('click', function (e) {
            var dentro = e.target.closest('.ps-pop');
            if (dentro) { return; }
            fecharTodos(null);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharTodos(null); }
        });
    }

    /* ── 4. Atalhos do trilho ("metas", "detalhar", "todos") ──────────────── */
    function atalhosDeAba() {
        document.querySelectorAll('[data-ps-ir-aba]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var gatilho = document.getElementById(link.getAttribute('data-ps-ir-aba'));
                if (gatilho && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(gatilho).show(); }
            });
        });
    }

    /* ── 5. "Ver anotações anteriores (N)" ────────────────────────────────── */
    function verAnotacoesAnteriores() {
        var botao = document.getElementById('psAnotacoesMais');
        if (!botao) { return; }

        botao.addEventListener('click', function () {
            document.querySelectorAll('.ps-anotacao--extra').forEach(function (item) {
                item.classList.remove('d-none');
            });
            botao.remove();
        });
    }

    /* ── 6. Item do menu ⋮ que leva a um controle que ja existe ───────────
       "Vincular processo" e "Excluir pasta" nao passam por aqui: o primeiro abre
       a modal pelos data-attributes do Bootstrap, o segundo e um <form> POST. */
    function acoesDoMenu() {
        // "Trocar responsavel": o controle real e o chip do cabecalho.
        var trocar = document.getElementById('psMenuTrocarResponsavel');
        if (trocar) {
            trocar.addEventListener('click', function () {
                var chip = document.querySelector('.ps-cab-dados .pasta-resp-chip');
                if (chip) { chip.click(); }
            });
        }
    }
    /* ── 7. Acordeao da aba Push Processual ───────────────────────────────
       O teor vem do servidor no primeiro clique — ele nao viaja no HTML da
       pasta: sao textos longos, e a pasta_show ja e a pagina mais pesada do
       sistema. Uma vez carregado fica no DOM, e abrir/fechar nao consulta de
       novo. */
    function acordeaoDoPush() {
        var lista = document.querySelector('.ps-push-lista');
        if (!lista) { return; }

        lista.addEventListener('click', function (e) {
            var cabecalho = e.target.closest('.ps-push-cab');
            if (!cabecalho) { return; }

            var painel = document.getElementById(cabecalho.getAttribute('aria-controls'));
            if (!painel) { return; }

            var abrindo = cabecalho.getAttribute('aria-expanded') !== 'true';
            cabecalho.setAttribute('aria-expanded', abrindo ? 'true' : 'false');
            painel.hidden = !abrindo;

            if (!abrindo || painel.dataset.carregado === '1') { return; }

            painel.innerHTML = '<p class="ps-push-carregando">Carregando o teor…</p>';
            fetch(cabecalho.getAttribute('data-push-url'), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error('falha ' + r.status); }
                    return r.text();
                })
                .then(function (html) {
                    painel.innerHTML = html;
                    painel.dataset.carregado = '1';
                    // Abrir e ler: o servidor marcou como lida, entao a linha
                    // para de se anunciar como nova.
                    var item = cabecalho.closest('.ps-push-item');
                    if (item) { item.classList.remove('ps-push-item--nova'); }
                    var pip = cabecalho.querySelector('.ps-push-pip');
                    if (pip) { pip.remove(); }
                })
                .catch(function () {
                    painel.innerHTML = '<p class="ps-push-sem-teor">Não foi possível carregar o teor. Tente de novo.</p>';
                });
        });
    }

}());
