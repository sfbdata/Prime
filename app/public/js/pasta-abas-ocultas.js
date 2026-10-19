/* ============================================================================
   Ocultar aba da pasta (spec pasta-ocultar-aba §5). Marcação: templates/pasta/_abas_ocultas.html.twig
   e os `data-pao-*` das abas em templates/pasta/_cabecalho.html.twig. Desenho [EXP]:
   "02 - EXPEDIENTES 1.2.3.dc.html", `abaMenu` L.4409-4419, modal L.262-279 (`abaVisVals` L.4420-4427).

   Botão direito (ou Shift+F10 / tecla de menu) numa aba:
     - quem configura (Master ou primário): menu "<RÓTULO> · oculta para N" com Ocultar para toda a
       equipe, Ocultar para… e, havendo regra, Mostrar para todos. Na aba Push o menu é o do link
       externo (#pushLinkMenu, pasta-push-link.js): os mesmos itens entram no TOPO dele, uma vez, com
       `data-ppl-externo` (o ponto de extensão daquele script: o menu só fecha, a ação é daqui).
     - quem não configura: na Push, só o menu do link; nas outras, o aviso do desenho (L.4411).

   Contrato JSON (App\Pasta\Controller\PastaAbaOcultaController), URLs com `__ABA__` no `#paoRaiz`:
     GET  equipe        {aba, rotulo, total, pessoas: [{id, nome, funcao, oculta}]}
     POST ocultar-todos | ocultar-para (usuarios[]) | mostrar    {sucesso, ..., mensagem} ou {erro}
   Depois de salvar, a página recarrega (o cabeçalho é do servidor) e o aviso sai na volta.

   Segurança: dado do servidor só entra por textContent e por nós clonados do <template>.
   ============================================================================ */
(function () {
    'use strict';

    var raiz = document.getElementById('paoRaiz');
    if (!raiz) { return; }

    var CONFIGURA = raiz.getAttribute('data-pode-configurar') === '1';
    var AVISO_SO_PRIMARIO = 'Só o usuário primário pode ocultar abas';
    var ERRO_GERAL = 'Não foi possível falar com o servidor. Tente de novo.';
    var CHAVE_AVISO = 'pao-aviso';

    var menu = document.getElementById('abaOcultaMenu');
    var modal = document.getElementById('paoModal');
    var menuPush = document.getElementById('pushLinkMenu');

    var estado = { aba: null, gatilho: null, selecionados: {}, pessoas: [], modalAba: null, gatilhoModal: null };

    function avisar(texto, tipo) {
        if (typeof window.avisarPs === 'function') { window.avisarPs(texto, tipo); }
    }

    function q(seletor, dentro) { return (dentro || document).querySelector(seletor); }

    function url(chave, aba) {
        return String(raiz.getAttribute('data-url-' + chave) || '').replace('__ABA__', encodeURIComponent(aba));
    }

    function rotuloDa(aba) {
        var botao = q('[data-pao-aba="' + aba + '"]');
        return botao ? botao.getAttribute('data-pao-rotulo') : aba;
    }

    /* ── Aviso que sobrevive ao recarregar ── */
    function avisoGuardado() {
        var texto = null;
        try { texto = window.sessionStorage.getItem(CHAVE_AVISO); window.sessionStorage.removeItem(CHAVE_AVISO); } catch (e) { texto = null; }
        if (texto) { avisar(texto); }
    }

    function salvarERecarregar(resposta) {
        try { window.sessionStorage.setItem(CHAVE_AVISO, resposta.mensagem || ''); } catch (e) { /* sem storage: só recarrega */ }
        window.location.reload();
    }

    function postar(chave, aba, campos) {
        var corpo = new FormData();
        corpo.append('_token', raiz.getAttribute('data-csrf') || '');
        (campos || []).forEach(function (par) { corpo.append(par[0], par[1]); });

        return fetch(url(chave, aba), {
            method: 'POST', body: corpo, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(lerJson);
    }

    function lerJson(r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
            if (!r.ok) { throw new Error(j.erro || j.mensagem || ERRO_GERAL); }
            return j;
        });
    }

    function executar(chave, aba, campos) {
        return postar(chave, aba, campos).then(salvarERecarregar, function (e) { avisar(e.message || ERRO_GERAL, 'erro'); });
    }

    /* ── Menu do botão direito ([EXP] `gm` L.289-297, posição `gmVals` L.4367) ── */
    function itensDoMenu() {
        return Array.prototype.filter.call(menu.querySelectorAll('.ppl-menu-item'), function (b) { return !b.hidden; });
    }

    function posicionar(x, y) {
        menu.style.left = '0px';
        menu.style.top = '0px';
        var largura = menu.offsetWidth;
        var altura = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(x - largura, window.innerWidth - largura - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';
    }

    function abrirMenu(aba, x, y, peloTeclado) {
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        estado.aba = aba.getAttribute('data-pao-aba');
        estado.gatilho = aba;
        q('#abaOcultaMenuTitulo', menu).textContent = aba.getAttribute('data-pao-rotulo') + (aba.getAttribute('data-pao-sufixo') || '');
        q('.js-pao-mostrar', menu).hidden = aba.getAttribute('data-pao-regra') !== '1';
        menu.hidden = false;
        posicionar(x, y);
        if (peloTeclado) { (itensDoMenu()[0] || q('.js-pao-menu-fechar', menu)).focus(); }
    }

    function fecharMenu(devolverFoco) {
        if (!menu || menu.hidden) { return; }
        menu.hidden = true;
        if (devolverFoco && estado.gatilho) { estado.gatilho.focus(); }
    }

    function abaDoAlvo(alvo) {
        var aba = alvo && alvo.closest ? alvo.closest('[data-pao-aba]') : null;
        // A aba Push tem o menu do link externo (pasta-push-link.js); os itens de ocultar já estão nele.
        return aba && !aba.hasAttribute('data-ppl-menu') ? aba : null;
    }

    function pedirMenu(aba, e, peloTeclado) {
        e.preventDefault();
        // Aviso neutro, como o `gmAviso` do desenho (L.4411, ícone de check): é informação, não falha.
        if (!CONFIGURA || !menu) { avisar(AVISO_SO_PRIMARIO); return; }
        if (peloTeclado || (e.clientX === 0 && e.clientY === 0)) {
            var r = aba.getBoundingClientRect();
            abrirMenu(aba, r.right, r.bottom + 4, true);
            return;
        }
        abrirMenu(aba, e.clientX, e.clientY, false);
    }

    document.addEventListener('contextmenu', function (e) {
        var aba = abaDoAlvo(e.target);
        if (aba) { pedirMenu(aba, e, false); }
    });

    document.addEventListener('keydown', function (e) {
        var aba = abaDoAlvo(e.target);
        if (aba && (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10'))) { pedirMenu(aba, e, true); return; }
        if (!menu || menu.hidden) { return; }
        if (e.key === 'Escape') { e.preventDefault(); fecharMenu(true); return; }
        if (!menu.contains(document.activeElement)) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var itens = itensDoMenu();
            var i = itens.indexOf(document.activeElement);
            var prox = e.key === 'ArrowDown' ? (i + 1) % itens.length : (i <= 0 ? itens.length - 1 : i - 1);
            itens[prox].focus();
        } else if (e.key === 'Tab') {
            fecharMenu(false);
        }
    });

    if (menu) {
        document.addEventListener('mousedown', function (e) {
            if (!menu.hidden && !menu.contains(e.target)) { fecharMenu(false); }
        });
        window.addEventListener('resize', function () { fecharMenu(false); });
        window.addEventListener('scroll', function () { fecharMenu(false); }, true);
    }

    /* ── Itens no topo do menu da aba Push (uma vez, na carga) ── */
    function comporMenuDoPush() {
        var abaPush = q('[data-pao-aba="push"]');
        if (!CONFIGURA || !menu || !menuPush || !abaPush) { return; }

        var cab = q('.ppl-menu-cab', menuPush);
        var depois = cab ? cab.nextSibling : menuPush.firstChild;
        Array.prototype.forEach.call(menu.querySelectorAll('.ppl-menu-item'), function (item) {
            var copia = item.cloneNode(true);
            copia.setAttribute('data-ppl-externo', '');
            copia.setAttribute('data-pao-alvo', 'push');
            copia.hidden = item.classList.contains('js-pao-mostrar') && abaPush.getAttribute('data-pao-regra') !== '1';
            menuPush.insertBefore(copia, depois);
        });

        var titulo = q('.ppl-menu-titulo', menuPush);
        if (titulo) { titulo.textContent = abaPush.getAttribute('data-pao-rotulo') + (abaPush.getAttribute('data-pao-sufixo') || ''); }
    }

    /* ── Ações dos itens (dos dois menus) ── */
    document.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.js-pao-ocultar-todos, .js-pao-ocultar-para, .js-pao-mostrar, .js-pao-menu-fechar') : null;
        if (!item || !CONFIGURA) { return; }

        var noPush = item.hasAttribute('data-pao-alvo');
        var aba = noPush ? 'push' : estado.aba;
        var gatilho = noPush ? q('[data-pao-aba="push"]') : estado.gatilho;
        fecharMenu(item.classList.contains('js-pao-menu-fechar'));
        if (!aba || item.classList.contains('js-pao-menu-fechar')) { return; }

        if (item.classList.contains('js-pao-ocultar-todos')) { executar('ocultar-todos', aba); return; }
        if (item.classList.contains('js-pao-mostrar')) { executar('mostrar', aba); return; }
        abrirModal(aba, gatilho);
    });

    /* ── Modal "Ocultar aba" ([EXP] L.262-279, `abaVisVals` L.4420-4427) ── */
    function quantosMarcados() {
        return estado.pessoas.filter(function (p) { return estado.selecionados[p.id]; }).length;
    }

    function desenharMarca(botao, marca, estadoMarca) {
        // [EXP] L.4423-4425: quadrado cheio, traço (alguns) ou vazio.
        marca.className = 'bi pao-marca ' + (estadoMarca === 'true' ? 'bi-check-square-fill' : estadoMarca === 'mixed' ? 'bi-dash-square-fill' : 'bi-square');
        botao.setAttribute('aria-checked', estadoMarca);
    }

    function desenharModal() {
        var total = estado.pessoas.length;
        var n = quantosMarcados();
        var todos = q('.js-pao-todos', modal);
        q('.js-pao-todos-texto', modal).textContent = 'Toda a equipe (' + total + ')';
        desenharMarca(todos, q('.js-pao-todos-marca', modal), total > 0 && n === total ? 'true' : n > 0 ? 'mixed' : 'false');
        todos.disabled = total === 0;

        Array.prototype.forEach.call(modal.querySelectorAll('.pao-linha'), function (linha) {
            var marcado = !!estado.selecionados[linha.getAttribute('data-id')];
            desenharMarca(linha, q('.pao-marca', linha), marcado ? 'true' : 'false');
            q('.pao-selo', linha).hidden = !marcado;
        });
    }

    function montarLinhas(pessoas) {
        var lista = q('.js-pao-lista', modal);
        var modelo = document.getElementById('paoLinhaModelo');
        Array.prototype.forEach.call(lista.querySelectorAll('.pao-linha'), function (l) { l.remove(); });
        pessoas.forEach(function (p) {
            var linha = modelo.content.firstElementChild.cloneNode(true);
            linha.setAttribute('data-id', String(p.id));
            q('.pao-linha-nome', linha).textContent = p.nome;
            q('.pao-linha-funcao', linha).textContent = p.funcao || '';
            lista.appendChild(linha);
        });
        q('.js-pao-vazio', modal).hidden = pessoas.length > 0;
    }

    function abrirModal(aba, gatilho) {
        if (!modal) { return; }
        estado.modalAba = aba;
        estado.gatilhoModal = gatilho;
        estado.pessoas = [];
        estado.selecionados = {};
        q('#paoTitulo', modal).textContent = 'Ocultar aba ' + rotuloDa(aba);
        q('.js-pao-carregando', modal).hidden = false;
        q('.js-pao-vazio', modal).hidden = true;
        q('.js-pao-salvar', modal).disabled = true;
        montarLinhas([]);
        desenharModal();
        modal.hidden = false;
        q('.js-pao-fechar', modal).focus();

        fetch(url('equipe', aba), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(lerJson)
            .then(function (dados) {
                if (modal.hidden || estado.modalAba !== aba) { return; }
                estado.pessoas = Array.isArray(dados.pessoas) ? dados.pessoas : [];
                estado.pessoas.forEach(function (p) { if (p.oculta) { estado.selecionados[p.id] = true; } });
                q('.js-pao-carregando', modal).hidden = true;
                montarLinhas(estado.pessoas);
                desenharModal();
                q('.js-pao-salvar', modal).disabled = false;
            }, function (e) {
                fecharModal();
                avisar(e.message || ERRO_GERAL, 'erro');
            });
    }

    function fecharModal() {
        if (!modal || modal.hidden) { return; }
        modal.hidden = true;
        if (estado.gatilhoModal) { estado.gatilhoModal.focus(); }
    }

    function salvarModal() {
        var botao = q('.js-pao-salvar', modal);
        var ids = estado.pessoas.filter(function (p) { return estado.selecionados[p.id]; }).map(function (p) { return ['usuarios[]', String(p.id)]; });
        botao.disabled = true;
        postar('ocultar-para', estado.modalAba, ids).then(salvarERecarregar, function (e) {
            botao.disabled = false;
            avisar(e.message || ERRO_GERAL, 'erro');
        });
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('.js-pao-fechar')) { fecharModal(); return; }
            if (e.target.closest('.js-pao-salvar')) { salvarModal(); return; }
            if (e.target.closest('.js-pao-todos')) {
                // [EXP] L.4424: todos marcados → nenhum; senão → todos.
                var todos = estado.pessoas.length > 0 && quantosMarcados() === estado.pessoas.length;
                estado.selecionados = {};
                if (!todos) { estado.pessoas.forEach(function (p) { estado.selecionados[p.id] = true; }); }
                desenharModal();
                return;
            }
            var linha = e.target.closest('.pao-linha');
            if (linha) {
                var id = linha.getAttribute('data-id');
                if (estado.selecionados[id]) { delete estado.selecionados[id]; } else { estado.selecionados[id] = true; }
                desenharModal();
            }
        });
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); fecharModal(); }
        });
    }

    comporMenuDoPush();
    avisoGuardado();
})();
