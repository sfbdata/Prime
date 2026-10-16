/* ============================================================================
   Aba Processo da pasta — cartão do processo (desenho 02 - EXPEDIENTES 1.2.3).

   - "Ver todas as informações" / "Mostrar menos": abre e fecha o painel
     `.ps-processo-detalhes` (aria-expanded + aria-controls no botão).
   - ⋮ do cartão: menu `.ps-processo-menu` (fixo, posicionado pelo gatilho), só
     com o que tem função hoje: nota técnica (nota-tecnica.js), alternar o
     painel, ir à aba Push, copiar número, copiar resumo, Compartilhar — Web
     Share onde existe; sem ele, copia o resumo e avisa — e Imprimir resumo, na
     janela de impressão da pasta (pasta-impressao.js). Tornar principal e
     Desvincular também moram no ⋮, mas são forms `.js-ajax-*` do show.html.twig.
   - Botão direito no cartão (PMC-001, desenho `onContextMenu={p.menu}`): abre o
     MESMO ⋮ do cartão (`data-ps-menu-contexto`) no ponto do cursor. Teclado e
     toque seguem pelo botão ⋮, que continua no cartão.
   - "Administrativo sem processo" (interruptor do cabeçalho, `admSPtoggle` do
     desenho): ver o bloco no fim do arquivo.

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

    // Botão direito: o canto do menu no cursor (desenho: gmAbrir com clientX/Y),
    // empurrado para dentro da janela quando não cabe.
    function posicionarNoPonto(menu, x, y) {
        var largura = menu.offsetWidth;
        var altura  = menu.offsetHeight;
        if (x + largura > window.innerWidth - 8) { x = x - largura; }
        if (y + altura > window.innerHeight - 8) { y = y - altura; }
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - largura - 8)) + 'px';
        menu.style.top  = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';
    }

    function abrirMenu(menu, gatilho, ponto) {
        fechar(false);
        // Um menu por vez: fecha os popovers do cabeçalho (pasta-show.js).
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        preparar(menu);
        menu.hidden = false;
        if (ponto) { posicionarNoPonto(menu, ponto.x, ponto.y); } else { posicionar(menu, gatilho); }
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

    /* Compartilhar (EDT-006/PMC-002; desenho procMenu dc L.2928): com Web Share, a folha do
       próprio sistema; sem ele (Firefox e boa parte dos desktops), o item NÃO some — copia o
       resumo e avisa "Resumo copiado para compartilhar", pelo mesmo caminho do "Copiar resumo". */
    function compartilhar(item) {
        if (typeof navigator.share !== 'function') {
            copiar(item);
            return;
        }
        fechar(false);
        navigator.share({
            title: item.getAttribute('data-titulo') || '',
            text: linhasDoResumo(item),
        }).catch(function () { /* cancelado pelo usuário */ });
    }

    /* "Imprimir resumo" (EDT-005; desenho procMenu dc L.2930, `gmImprimir`): as MESMAS
       linhas do "Copiar resumo" viram a tabela do desenho — rótulo em negrito à esquerda,
       valor à direita, partido no primeiro ": " — e vão para a janela de impressão da
       pasta (pasta-impressao.js). Tudo por DOM e textContent: o resumo é dado do cadastro. */
    function imprimirResumo(item) {
        fechar(false);
        if (!window.PastaImpressao) { return; }
        var tabela = document.createElement('table');
        linhasDoResumo(item).split('\n').forEach(function (linha) {
            if (linha === '') { return; }
            var corte  = linha.indexOf(': ');
            var tr     = document.createElement('tr');
            var rotulo = document.createElement('td');
            var forte  = document.createElement('strong');
            var valor  = document.createElement('td');
            forte.textContent = corte === -1 ? linha : linha.slice(0, corte);
            valor.textContent = corte === -1 ? '' : linha.slice(corte + 2);
            rotulo.appendChild(forte);
            tr.appendChild(rotulo);
            tr.appendChild(valor);
            tabela.appendChild(tr);
        });
        // C6-EDT-06: o Baixar PDF leva só o id do cartão; o servidor remonta as linhas.
        var cartao = item.closest('[data-processo-id]');
        window.PastaImpressao.conteudo({
            titulo: item.getAttribute('data-titulo') || '',
            corpo: tabela,
            pdf: cartao ? { secao: 'processo', processo: cartao.getAttribute('data-processo-id') } : null,
        });
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
        if (item.classList.contains('js-proc-imprimir'))       { imprimirResumo(item); return; }
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

    /* ── Botão direito no cartão (PMC-001) ────────────────────────────────
       O cartão diz qual é o seu ⋮ em `data-ps-menu-contexto`; o menu é o mesmo
       elemento, com os mesmos itens — nada é duplicado aqui. Links, botões,
       campos e o próprio menu ficam com o menu nativo do navegador (abrir em
       nova aba, colar…), e também quem selecionou texto para copiar. Sem
       coordenada (tecla de menu), o menu abre sob o ⋮, como no clique. */
    document.addEventListener('contextmenu', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }
        // Botão direito em outro lugar fecha o menu aberto, como o clique fora.
        if (aberto && !aberto.menu.contains(alvo)) { fechar(false); }

        // PZ-CTX-08: botão direito sobre o próprio ⋮ (cabeçalho ou cartão) abre o mesmo menu do clique,
        // sob o ⋮ — o dc liga os dois eventos ao mesmo handler (L.1680 `procHdMenu`).
        var gatilhoProprio = alvo.closest('.js-proc-menu-gatilho');
        if (gatilhoProprio) {
            var menuProprio = document.getElementById(gatilhoProprio.getAttribute('aria-controls') || '');
            if (!menuProprio) { return; }
            e.preventDefault();
            abrirMenu(menuProprio, gatilhoProprio, null);
            return;
        }

        var cartao = alvo.closest('article.ps-processo[data-ps-menu-contexto]');
        if (!cartao) { return; }
        if (alvo.closest('a, button, input, textarea, select, [contenteditable="true"], .ps-processo-menu')) { return; }
        var selecao = window.getSelection ? String(window.getSelection()) : '';
        if (selecao.trim() !== '') { return; }

        var menu    = document.getElementById(cartao.getAttribute('data-ps-menu-contexto'));
        var gatilho = cartao.querySelector('.js-proc-menu-gatilho');
        if (!menu || !gatilho) { return; }

        e.preventDefault();
        var temPonto = e.clientX > 0 || e.clientY > 0;
        abrirMenu(menu, gatilho, temPonto ? { x: e.clientX, y: e.clientY } : null);
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

    /* ── "Administrativo sem processo" sem recarregar ───────────────────────
       O form `.js-pasta-administrativa` funciona sozinho (POST + CSRF + recarga).
       Com JS: confirmação do `data-confirmar` (só existe quando há processo
       vinculado e o pedido é ligar), o interruptor vira NA HORA, o POST vai por
       XHR com o mesmo FormData (token incluso) e, no sucesso, o cartão inteiro é
       trocado pelo `html` da resposta via `window.mpSwapProcessos` (show.html.twig).
       Esse HTML é o parcial `_processos_vinculados` renderizado pelo Twig no
       servidor, com autoescape — nenhum dado do usuário é montado em string aqui.
       Se o servidor recusar (403/404/422, sessão expirada, rede), o interruptor
       volta ao estado anterior e a mensagem do servidor (`erro`) aparece. */
    function pintarInterruptor(botao, ligado) {
        botao.classList.toggle('is-ligado', ligado);
        botao.setAttribute('aria-checked', ligado ? 'true' : 'false');
    }

    function falhaAdministrativa(form, botao, ligadoAntes, mensagem) {
        pintarInterruptor(botao, ligadoAntes);
        botao.disabled = false;
        botao.removeAttribute('aria-busy');
        delete form.dataset.enviando;
        window.alert(mensagem || 'Não foi possível alterar "Administrativo sem processo". Tente de novo.');
        botao.focus();
    }

    /* E7: a pergunta sai na janela da tela (pasta-dialogos.js `confirmarUIPs`), AZUL e com o título do
       desenho ("Marcar como administrativo", dc L.6878 `admSPtoggle`); sem ela, o confirm do navegador. */
    function perguntarAdministrativa(texto) {
        if (window.confirmarUIPs) {
            return window.confirmarUIPs('Marcar como administrativo\n' + texto.replace(/\s*\n\s*/g, ' '), false);
        }

        return Promise.resolve(window.confirm(texto));
    }

    document.addEventListener('submit', async function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form.js-pasta-administrativa')) { return; }
        // Sem fetch, segue o caminho sem JS (POST + recarga).
        if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') { return; }

        e.preventDefault();
        if (form.dataset.enviando) { return; }

        var confirmar = form.getAttribute('data-confirmar');
        if (confirmar) {
            // Enquanto pergunta, outro clique não abre uma segunda janela nem envia.
            form.dataset.enviando = '1';
            var sim = await perguntarAdministrativa(confirmar);
            delete form.dataset.enviando;
            if (!sim || !document.contains(form)) { return; }
        }

        var botao = form.querySelector('button[role="switch"]');
        if (!botao) { form.submit(); return; }

        var ligadoAntes = botao.getAttribute('aria-checked') === 'true';
        var corpo = new FormData(form);
        form.dataset.enviando = '1';
        pintarInterruptor(botao, !ligadoAntes);
        botao.disabled = true;
        botao.setAttribute('aria-busy', 'true');

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: corpo,
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (dados) {
                return { ok: resp.ok, dados: dados || {} };
            });
        }, function () {
            // Só a falha de REDE desfaz aqui: um erro depois da troca não pode
            // "desligar" na tela o que o servidor já gravou.
            falhaAdministrativa(form, botao, ligadoAntes, 'Erro de comunicação. A marcação pode não ter sido salva — recarregue a página.');
            return null;
        }).then(function (r) {
            if (!r) { return; }
            if (!r.ok || r.dados.sucesso !== true || typeof r.dados.html !== 'string') {
                falhaAdministrativa(form, botao, ligadoAntes, typeof r.dados.erro === 'string' ? r.dados.erro : '');
                return;
            }
            if (typeof window.mpSwapProcessos !== 'function') {
                // Gravou, mas a tela não sabe trocar o cartão: recarrega para mostrar o estado real.
                window.location.reload();
                return;
            }
            window.mpSwapProcessos(r.dados.html);
            var novo = document.querySelector('form.js-pasta-administrativa button[role="switch"]');
            if (novo) { novo.focus(); }
        });
    });
}());
