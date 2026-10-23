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

    /* ── Arrastar janelinhas (PAS-023; README do pacote, "Janelinhas arrastáveis") ──
       Utilitário único: o arraste que nasceu na janela "Detalhes do cliente"
       (pasta-clientes.js) saiu de lá para cá e vale também para o modal Editar dados
       e para as tarjas dos menus. Fica no `window` na carga do script (antes do
       DOMContentLoaded): o pasta-clientes.js é `defer` e roda depois deste.
         ligar({ cabecalho, janela(cab), posicionar(janela, x, y)?, aceita(evento, cab)? })
       `aceita` (PAS-023r) restringe a alça quando o seletor sozinho não basta (o menu sem
       tarja só arrasta pela faixa do X e pelo título). Com `posicionar`, a janela é
       movida pelo dono (left/top). Sem ele, o utilitário desloca a janela pela
       propriedade CSS `translate` — que não briga com o `transform` do Bootstrap no modal nem com o do próprio X — e `zerar`
       a devolve à âncora. Botão, link e campo no cabeçalho continuam clicáveis. */
    window.PsArrastar = (function () {
        var NAO_ARRASTA = 'button, a, input, textarea, select, label, [contenteditable="true"]';

        function zerar(janela) {
            if (!janela) { return; }
            delete janela.dataset.psArrasteX;
            delete janela.dataset.psArrasteY;
            janela.style.translate = '';
        }

        function ligar(opcoes) {
            document.addEventListener('pointerdown', function (e) {
                var cab = e.target.closest ? e.target.closest(opcoes.cabecalho) : null;
                if (!cab || e.target.closest(NAO_ARRASTA)) { return; }
                if (e.button !== undefined && e.button !== 0) { return; }
                if (opcoes.aceita && !opcoes.aceita(e, cab)) { return; }
                var janela = opcoes.janela(cab);
                if (!janela) { return; }
                e.preventDefault();

                var x0 = e.clientX;
                var y0 = e.clientY;
                var base = opcoes.posicionar
                    ? [janela.offsetLeft, janela.offsetTop]
                    : [parseFloat(janela.dataset.psArrasteX) || 0, parseFloat(janela.dataset.psArrasteY) || 0];
                var andou = false;
                janela.classList.add('is-arrastando');

                function mover(ev) {
                    var x = base[0] + ev.clientX - x0;
                    var y = base[1] + ev.clientY - y0;
                    if (Math.abs(ev.clientX - x0) + Math.abs(ev.clientY - y0) > 3) { andou = true; }
                    if (opcoes.posicionar) { opcoes.posicionar(janela, x, y); return; }
                    janela.dataset.psArrasteX = String(x);
                    janela.dataset.psArrasteY = String(y);
                    janela.style.translate = x + 'px ' + y + 'px';
                }
                // PAS-022b: `pointercancel` (o navegador tomou o gesto: rolagem, toque
                // interrompido) solta como o `pointerup` — senão o `pointermove` fica
                // ligado e a janela segue o dedo/cursor no próximo movimento.
                function soltar() {
                    window.removeEventListener('pointermove', mover);
                    window.removeEventListener('pointerup', soltar);
                    window.removeEventListener('pointercancel', soltar);
                    janela.classList.remove('is-arrastando');
                    if (!andou) { return; }
                    // Soltar fora do cabeçalho gera um clique no ancestral comum, que
                    // os menus leem como "clique fora" e fechariam: engole só esse.
                    var engolir = function (ev) { ev.stopPropagation(); ev.preventDefault(); };
                    window.addEventListener('click', engolir, { capture: true, once: true });
                    setTimeout(function () { window.removeEventListener('click', engolir, { capture: true }); }, 0);
                }
                window.addEventListener('pointermove', mover);
                window.addEventListener('pointerup', soltar);
                window.addEventListener('pointercancel', soltar);
            });
        }

        return { ligar: ligar, zerar: zerar };
    }());

    document.addEventListener('DOMContentLoaded', function () {
        var pagina = document.querySelector('.ps-page');
        if (!pagina) { return; }

        indicadorDeAbas();
        drawerDeHistorico();
        popovers();
        janelasSemPopover();
        arrasteDasJanelas();
        atalhosDeAba();
        verAnotacoesAnteriores();
        acoesDoMenu();
        acordeaoDoPush();
        abaDoFragmento();
        mensagemDoFragmento();
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

        /* Modo compacto (desenho 1.2.3): se os sete rotulos nao cabem no trilho,
           as abas inativas ficam so com o icone e o selo — o `title` continua
           dizendo o nome. Mede-se SEM a classe, senao o trilho ja compacto "cabe"
           e nunca sairia do compacto ao alargar a janela. */
        function ajustarCompacto() {
            trilho.classList.remove('ps-abas--compactas');
            if (trilho.scrollWidth > trilho.clientWidth + 1) {
                trilho.classList.add('ps-abas--compactas');
            }
        }

        function posicionar(animar) {
            ajustarCompacto();
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
        // Outros gatilhos (o "Ver histórico do sistema" da aba Detalhes): mesmo drawer.
        document.querySelectorAll('[data-ps-historico]').forEach(function (gatilho) {
            gatilho.addEventListener('click', abrirDrawer);
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

            botaoFechar(alvo, function () {
                alvo.classList.remove('is-aberto');
                gatilho.setAttribute('aria-expanded', 'false');
                gatilho.focus();
            });

            gatilho.addEventListener('click', function (e) {
                e.stopPropagation();
                var vaiAbrir = !alvo.classList.contains('is-aberto');
                // Abrir um fecha o outro — foi o pedido do desenho, e evita dois
                // paineis sobrepostos no mesmo canto.
                fecharTodos(vaiAbrir ? alvo : null);
                // Menu arrastado volta a abrir junto do gatilho (PAS-023).
                if (vaiAbrir) { window.PsArrastar.zerar(alvo); }
                alvo.classList.toggle('is-aberto', vaiAbrir);
                gatilho.setAttribute('aria-expanded', vaiAbrir ? 'true' : 'false');
            });
        });

        document.addEventListener('click', function (e) {
            var dentro = e.target.closest('.ps-pop');
            // Clique num ITEM do menu fecha o menu — a acao do item segue o seu
            // curso (modal, drawer, POST). Clique no corpo do painel, nao. O
            // "Copiar link" e a excecao: fica aberto o tempo de mostrar "Link
            // copiado" e fecha sozinho (ver acoesDoMenu). "Acompanhar
            // alteracoes" tambem: o desenho (D:4493) alterna sem fechar o menu.
            if (dentro && (!e.target.closest('.ps-pop-item') || e.target.closest('.js-copiar-link, .js-pasta-acompanhar'))) { return; }
            fecharTodos(null);
        });
        document.addEventListener('ps:fechar-popovers', function () { fecharTodos(null); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharTodos(null); }
        });
    }

    /* X de fechar em toda janelinha (PAS-022; README do pacote, "X de fechar em toda
       janelinha", e `bluejus-central.js`): canto superior direito, branco sobre a tarja
       azul quando o menu tem uma, cinza no fundo claro quando não tem. Esc e clique fora
       continuam fechando. `data-sem-x` no painel desliga; quem já tem o próprio X
       (`.ps-pop-x`) não ganha outro. */
    function botaoFechar(painel, fechar) {
        if (painel.hasAttribute('data-sem-x') || painel.querySelector('.ps-pop-x')) { return; }

        var tarja = painel.querySelector(':scope > .ps-pop-tarja');
        // PAS-022r: a faixa da busca do menu do responsável é a tarja dele (mesmo azul).
        if (!tarja) { tarja = painel.querySelector(':scope > .pasta-resp-busca-wrap'); }
        var botao = document.createElement('button');
        botao.type      = 'button';
        botao.className = 'ps-pop-x' + (tarja ? ' ps-pop-x--tarja' : '');
        botao.title     = 'Fechar (Esc)';
        botao.setAttribute('aria-label', 'Fechar');
        botao.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">'
            + '<path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        botao.addEventListener('click', function (e) {
            // Sem propagar: o clique no corpo do painel não fecha (ver popovers), e o
            // X não é um item com ação própria.
            e.preventDefault();
            e.stopPropagation();
            fechar();
        });

        (tarja || painel).appendChild(botao);
    }

    /* PAS-022b: religar o X nos popovers que chegaram DEPOIS do carregamento (as linhas
       das Metas trocadas pelo XHR do pasta-metas.js nascem sem ele, porque `popovers()`
       liga cada `data-ps-pop` uma vez só). Mesmo X e mesmo fechar de `popovers()`; o
       `botaoFechar` não duplica o X de quem já tem. Fica no `window` na carga do script:
       o pasta-metas.js é carregado antes deste e só chama depois da troca. */
    window.PsPopovers = {
        religar: function (raiz) {
            Array.prototype.forEach.call((raiz || document).querySelectorAll('[data-ps-pop]'), function (gatilho) {
                var alvo = document.getElementById(gatilho.getAttribute('data-ps-pop'));
                if (!alvo) { return; }
                botaoFechar(alvo, function () {
                    alvo.classList.remove('is-aberto');
                    gatilho.setAttribute('aria-expanded', 'false');
                    gatilho.focus();
                });
            });
        }
    };

    /* PAS-022r / PAS-023r: as janelinhas que não são `.ps-pop` — o menu do Registro
       (#psRegMenu), o ⋮ da observação de Detalhes (.ps-obs-menu, que também nasce pelo JS
       quando a observação é criada), a lista do responsável (#pastaRespMenu), o drawer do
       Histórico e o painel da Timeline. Cada uma abre e fecha pelo script dela (atributo
       `hidden` ou `aria-hidden`); daqui só se observa a ABERTURA: volta à âncora se tinha sido
       arrastada e, nos três menus, garante o X. O X fecha como o Esc: o script de cada menu
       já fecha no Esc e devolve o foco ao gatilho (é a regra do desenho, `bluejus-central.js`,
       "X de fechar em toda janelinha": "senão envia Esc"). O drawer e a Timeline já têm o
       próprio X. */
    var JANELAS_OBSERVADAS = '#psRegMenu, .ps-obs-menu, #pastaRespMenu, #psHistorico, #psTimeline';
    var MENUS_COM_X = '#psRegMenu, .ps-obs-menu, #pastaRespMenu';

    function fecharComoEsc() {
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    }

    function janelasSemPopover() {
        document.querySelectorAll(MENUS_COM_X).forEach(function (menu) { botaoFechar(menu, fecharComoEsc); });
        if (!window.MutationObserver) { return; }

        new MutationObserver(function (mudancas) {
            mudancas.forEach(function (m) {
                var janela = m.target;
                if (janela.nodeType !== 1 || !janela.matches(JANELAS_OBSERVADAS)) { return; }
                // Abriu = o atributo existia (valor antigo não nulo) e saiu; fechou = o contrário.
                var abriu = m.oldValue !== null && !janela.hasAttribute(m.attributeName);
                var fechou = m.oldValue === null && janela.hasAttribute(m.attributeName);
                if (!abriu && !fechou) { return; }
                // Zera nos dois: aberta, volta à origem; fechando, o deslocamento do arraste não
                // se soma ao transform da saída (drawer e Timeline sairiam com parte à vista).
                window.PsArrastar.zerar(janela);
                if (abriu && janela.matches(MENUS_COM_X)) { botaoFechar(janela, fecharComoEsc); }
            });
        }).observe(document.body, {
            subtree: true,
            attributes: true,
            attributeOldValue: true,
            attributeFilter: ['hidden', 'aria-hidden']
        });
    }

    /* Onde o arraste vale nesta tela (PAS-023): a tarja azul dos menus do
       cabeçalho e o cabeçalho do modal Editar dados. O modal volta ao centro
       quando fecha. PAS-023r: também os menus sem tarja, o do Registro, o da
       observação, a lista do responsável, o Histórico e a Timeline (abaixo). */
    function arrasteDasJanelas() {
        window.PsArrastar.ligar({
            cabecalho: '.ps-pop.is-aberto > .ps-pop-tarja',
            janela: function (cab) { return cab.closest('.ps-pop'); }
        });

        // Menu sem tarja (prioridade, ⋮ do prazo, os das Metas): a alça é o 1º filho quando
        // ele é o título de seção, como o desenho (bluejus-central.js: só o 1º filho arrasta).
        // O fundo do painel não arrasta. Os itens continuam itens (botão não arrasta).
        window.PsArrastar.ligar({
            cabecalho: '.ps-pop.is-aberto',
            aceita: function (e, painel) {
                if (painel.querySelector(':scope > .ps-pop-tarja')) { return false; }
                var primeiro = painel.firstElementChild;
                return e.target === primeiro && primeiro.classList.contains('ps-pop-secao');
            },
            janela: function (painel) { return painel; }
        });

        // Menus do Registro e da observação: o título (autor · data) é a alça.
        window.PsArrastar.ligar({
            cabecalho: '#psRegMenu:not([hidden]) > .ps-processo-menu-titulo, .ps-obs-menu:not([hidden]) > .ps-processo-menu-titulo',
            janela: function (cab) { return cab.parentElement; }
        });

        // Lista do responsável: a faixa da busca (o campo continua campo).
        window.PsArrastar.ligar({
            cabecalho: '#pastaRespMenu:not([hidden]) > .pasta-resp-busca-wrap',
            janela: function (cab) { return cab.parentElement; }
        });

        // Drawer do Histórico e painel da Timeline: o cabeçalho de cada um.
        window.PsArrastar.ligar({
            cabecalho: '.ps-drawer.is-aberto > .ps-drawer-cab, .ps-tl.is-aberto > .ps-tl-cab',
            janela: function (cab) { return cab.parentElement; }
        });

        var modal = document.getElementById('modalEditarPasta');
        if (!modal) { return; }
        window.PsArrastar.ligar({
            cabecalho: '#modalEditarPasta .ps-modal-pasta-cab',
            janela: function (cab) { return cab.closest('.modal-dialog'); }
        });
        modal.addEventListener('hidden.bs.modal', function () {
            window.PsArrastar.zerar(modal.querySelector('.modal-dialog'));
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
                var chip = document.querySelector('.ps-cab-resp .pasta-resp-chip');
                if (chip) { chip.click(); }
            });
        }

        // "Copiar link": real — a URL da pasta vai para a area de transferencia.
        // Desenho (`mCopiar`): o rotulo vira "Link copiado", o icone troca de
        // bi-link para bi-check2 e o menu fecha em 900 ms. Na falha o icone
        // fica o bi-link: o check diria que copiou.
        var copiar = document.querySelector('.js-copiar-link');
        if (copiar) {
            var rotuloCopiar   = copiar.querySelector('.js-copiar-link-rotulo');
            var iconeCopiar    = copiar.querySelector('i.bi');
            var originalCopiar = rotuloCopiar ? rotuloCopiar.textContent : '';
            var esperaCopiar   = null;

            copiar.addEventListener('click', function () {
                var link = copiar.getAttribute('data-link') || window.location.href;

                function avisar(texto, copiou) {
                    if (rotuloCopiar) { rotuloCopiar.textContent = texto; }
                    if (iconeCopiar && copiou) { iconeCopiar.classList.replace('bi-link', 'bi-check2'); }
                    clearTimeout(esperaCopiar);
                    esperaCopiar = setTimeout(function () {
                        if (rotuloCopiar) { rotuloCopiar.textContent = originalCopiar; }
                        if (iconeCopiar) { iconeCopiar.classList.replace('bi-check2', 'bi-link'); }
                        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
                    }, 900);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(link).then(
                        function () { avisar('Link copiado', true); },
                        function () { avisar('Não foi possível copiar', false); }
                    );
                } else {
                    avisar('Não foi possível copiar', false);
                }
            });
        }

        // Atalhos do menu ⋮ (desenho): com o menu ABERTO, E edita e H abre o historico.
        var menu = document.getElementById('psMenuAcoes');
        if (menu) {
            document.addEventListener('keydown', function (e) {
                if (!menu.classList.contains('is-aberto')) { return; }
                if (e.ctrlKey || e.metaKey || e.altKey) { return; }
                var alvo = e.target;
                if (alvo && (/INPUT|TEXTAREA|SELECT/.test(alvo.tagName) || alvo.isContentEditable)) { return; }
                var tecla = (e.key || '').toLowerCase();
                if (tecla !== 'e' && tecla !== 'h') { return; }
                var item = menu.querySelector('[data-ps-atalho="' + tecla + '"]');
                if (item) { e.preventDefault(); item.click(); }
            });
        }

        // Etiquetas do cabecalho: clicar numa abre o mesmo modal do "+" (desenho).
        // O "+" e quem carrega os data-* que o modal le; as etiquetas so o acionam.
        document.querySelectorAll('.js-etiqueta-abrir').forEach(function (etiqueta) {
            etiqueta.addEventListener('click', function () {
                var mais = document.querySelector('.ps-cab-identidade .ps-etiqueta-add');
                if (mais) { mais.click(); }
            });
        });

        // Chip "N vinculado(s)" do cabecalho: mostra/esconde os outros processos.
        document.querySelectorAll('[data-ps-toggle]').forEach(function (botao) {
            var alvo = document.getElementById(botao.getAttribute('data-ps-toggle'));
            if (!alvo) { return; }
            botao.addEventListener('click', function () {
                var abrir = alvo.hidden;
                alvo.hidden = !abrir;
                botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            });
        });
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

    /* ── 8. A aba que o fragmento da URL pedir ────────────────────────────
       `/pasta/223#push` abre na aba Push Processual. E o que faz o "Voltar"
       da publicacao devolver o usuario onde ele estava, e nao na aba Dados.
       Roda por ultimo: o indicador ja mediu a pilula e reage ao shown.bs.tab.
       Fragmento que nao seja de aba (ex.: #partes, ancora dentro de Dados) nao
       encontra gatilho e passa reto. */
    function abaDoFragmento() {
        var hash = window.location.hash;
        if (!hash || hash.length < 2 || !window.bootstrap) { return; }

        var gatilho = document.getElementById(hash.slice(1) + '-tab');
        if (gatilho) { bootstrap.Tab.getOrCreateInstance(gatilho).show(); }
    }

    /* ── 9. Âncora de um registro (`#pasta-msg-<id>`) ─────────────────────
       É o destino da notificação de resposta (EnviarMensagemPastaUseCase). O
       registro pode estar entre os "anteriores" (`.ps-anotacao--extra`, d-none,
       ou dentro de uma conversa escondida): sem abrir o bloco, o navegador não
       tem para onde rolar. Abre pelo PRÓPRIO botão "Ver anotações anteriores"
       (mesmo efeito do clique), garante a aba Dados e rola até o cartão. */
    function mensagemDoFragmento() {
        var hash = window.location.hash;
        if (!/^#pasta-msg-\d+$/.test(hash)) { return; }

        var alvo = document.getElementById(hash.slice(1));
        if (!alvo) { return; }

        var escondido = alvo.classList.contains('ps-anotacao--extra')
            || !!alvo.closest('.ps-anotacao--extra');
        if (escondido) {
            var botao = document.getElementById('psAnotacoesMais');
            if (botao) {
                botao.click();
            } else {
                document.querySelectorAll('.ps-anotacao--extra').forEach(function (item) {
                    item.classList.remove('d-none');
                });
            }
        }

        var gatilhoDados = document.getElementById('dados-tab');
        var painel = alvo.closest('.tab-pane');
        if (gatilhoDados && painel && !painel.classList.contains('active') && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(gatilhoDados).show();
        }

        // Depois do reflow (o bloco acabou de aparecer e a aba pode ter trocado).
        window.requestAnimationFrame(function () {
            alvo.scrollIntoView({ block: 'center' });
        });
    }

}());
