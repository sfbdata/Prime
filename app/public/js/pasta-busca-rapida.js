/*
 * Busca rápida de pastas (Ctrl + K), em toda página logada (layout/_globais/busca_rapida.html.twig). Desenho:
 * docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html, janela L405-421, teclado onBuscaG
 * L6446-6454, lógica bgVals L6455-6482.
 *
 * Ctrl/Cmd + K abre e fecha (em qualquer foco, como no desenho), com uma única exceção, para não quebrar o
 * "inserir link" do Kanban: com a janela fechada e o foco dentro de um .ql-editor cujo Quill tem o botão .ql-link
 * na barra (descrição e comentário do cartão, kanban/board.html.twig), o Ctrl + K fica com o Quill. O editor rico
 * da casa (editor-rico.js, sem botão de link) e os demais campos abrem a busca normalmente. Exceção pendente de
 * confirmação do dono.
 * Esc fecha, ↑ ↓ navegam, Enter abre (o item selecionado, ou o item que estiver com o foco), o mouse
 * seleciona, clique no fundo fecha. Tab circula só dentro da janela, e ao fechar o foco volta para onde estava. Os resultados vêm de GET
 * expediente_busca_rapida?q= (até 8), com espera de 200 ms depois da última tecla e a requisição anterior
 * cancelada (AbortController). O DOM é montado com createElement + textContent: nenhum dado passa por innerHTML.
 *
 * Pasta trancada, para quem não é administrador do escritório (`abreTrancada: false`), não abre: aparece o aviso
 * de sigilo da Lista do Expediente (expediente-lista.js, abrirOuAvisar/mostrarSigilo), com "Pedir acesso" na
 * mesma rota e com o mesmo token. O aviso some quando a seleção muda.
 */
(function () {
    'use strict';

    var raiz = document.getElementById('pastaBuscaRapida');
    if (!raiz) { return; }

    var janela = raiz.querySelector('.pbr-janela');
    var campo = raiz.querySelector('.pbr-campo');
    var opcoes = raiz.querySelector('.pbr-opcoes');
    var vazio = raiz.querySelector('.pbr-vazio');
    var vazioTexto = raiz.querySelector('.pbr-vazio-texto');
    var sigilo = raiz.querySelector('.pbr-sigilo');
    var sigiloNup = raiz.querySelector('.js-pbr-sigilo-nup');
    var sigiloRetorno = raiz.querySelector('.pbr-sigilo-retorno');
    var pedir = raiz.querySelector('.pbr-sigilo-pedir');
    var url = raiz.getAttribute('data-url');

    var ICONES = { Pasta: 'bi bi-folder2', Cliente: 'bi bi-person', Processo: 'bi bi-bank' };
    var ESPERA_MS = 200;
    var TEXTO_VAZIO = 'Nada encontrado.';
    var TEXTO_SESSAO = 'Sessão expirada. Recarregue a página.';
    var TEXTO_ERRO = 'Não foi possível buscar. Tente de novo.';

    var resultados = [];
    var selecionado = 0;
    var abreTrancada = false;
    var espera = null;
    var controle = null;
    var focoAnterior = null;
    var sigiloPastaId = null;

    function aberta() { return !raiz.hidden; }

    function abrir() {
        focoAnterior = document.activeElement;
        raiz.hidden = false;
        campo.value = '';
        esconderSigilo();
        limpar();
        campo.focus();
        buscar('');
    }

    function fechar() {
        raiz.hidden = true;
        clearTimeout(espera);
        if (controle) { controle.abort(); controle = null; }
        if (focoAnterior && typeof focoAnterior.focus === 'function' && document.contains(focoAnterior)) { focoAnterior.focus(); }
        focoAnterior = null;
    }

    /* Antes de cada nova lista: some a anterior e nenhuma mensagem, até a resposta chegar. */
    function limpar() {
        resultados = [];
        selecionado = 0;
        while (opcoes.firstChild) { opcoes.removeChild(opcoes.firstChild); }
        vazio.hidden = true;
        campo.removeAttribute('aria-activedescendant');
    }

    function agendarBusca() {
        clearTimeout(espera);
        esconderSigilo();
        espera = setTimeout(function () { buscar(campo.value.trim()); }, ESPERA_MS);
    }

    function buscar(termo) {
        if (controle) { controle.abort(); }
        controle = new AbortController();
        fetch(url + '?q=' + encodeURIComponent(termo), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controle.signal
        }).then(function (r) {
            if (r.redirected && new URL(r.url).pathname.indexOf('/login') === 0) { throw new Error('sessao'); }
            if (!r.ok) { throw new Error('http'); }
            return r.json();
        }).then(function (j) {
            if (!j || !Array.isArray(j.resultados)) { throw new Error('json'); }
            resultados = j.resultados.slice(0, 8);
            abreTrancada = j.abreTrancada === true;
            selecionado = 0;
            desenhar(TEXTO_VAZIO);
        }).catch(function (e) {
            if (e && e.name === 'AbortError') { return; }
            resultados = [];
            desenhar(e && e.message === 'sessao' ? TEXTO_SESSAO : TEXTO_ERRO);
        });
    }

    /* `mensagem` só aparece quando não há resultado: "Nada encontrado." ou o erro, no mesmo estilo. */
    function desenhar(mensagem) {
        while (opcoes.firstChild) { opcoes.removeChild(opcoes.firstChild); }
        resultados.forEach(function (r, k) { opcoes.appendChild(item(r, k)); });
        vazioTexto.textContent = mensagem;
        vazio.hidden = resultados.length > 0;
        marcarSelecionado();
    }

    function item(r, k) {
        var botao = document.createElement('button');
        botao.type = 'button';
        botao.className = 'pbr-item';
        botao.id = 'pbrOpcao' + k;
        botao.setAttribute('role', 'option');
        botao.setAttribute('data-indice', String(k));

        var icone = document.createElement('span');
        icone.className = 'pbr-icone';
        var i = document.createElement('i');
        i.className = ICONES[r.grupo] || ICONES.Pasta;
        i.setAttribute('aria-hidden', 'true');
        icone.appendChild(i);

        var textos = document.createElement('span');
        textos.className = 'pbr-textos';
        var titulo = document.createElement('span');
        titulo.className = 'pbr-titulo';
        titulo.textContent = r.titulo || '';
        if (r.trancada) {
            var cadeado = document.createElement('i');
            cadeado.className = 'bi bi-lock pbr-cadeado';
            cadeado.setAttribute('role', 'img');
            cadeado.setAttribute('aria-label', 'Pasta trancada');
            titulo.appendChild(cadeado);
        }
        var sub = document.createElement('span');
        sub.className = 'pbr-subtitulo';
        sub.textContent = r.subtitulo || '';
        textos.appendChild(titulo);
        textos.appendChild(sub);

        var grupo = document.createElement('span');
        grupo.className = 'pbr-grupo';
        grupo.textContent = r.grupo || '';

        botao.appendChild(icone);
        botao.appendChild(textos);
        botao.appendChild(grupo);
        // O mouse seleciona mas não fecha o aviso de sigilo: no caminho até o "Pedir acesso" ele passa por outros itens.
        botao.addEventListener('mouseenter', function () { selecionar(k, true); });
        botao.addEventListener('click', function () { ir(k); });

        return botao;
    }

    function selecionar(k, manterSigilo) {
        if (k !== selecionado && !manterSigilo) { esconderSigilo(); }
        selecionado = k;
        marcarSelecionado();
    }

    function marcarSelecionado() {
        var botoes = opcoes.querySelectorAll('.pbr-item');
        botoes.forEach(function (b, k) {
            var sel = k === selecionado;
            b.classList.toggle('is-selecionado', sel);
            b.setAttribute('aria-selected', sel ? 'true' : 'false');
            if (sel) { b.scrollIntoView({ block: 'nearest' }); }
        });
        if (botoes[selecionado]) { campo.setAttribute('aria-activedescendant', botoes[selecionado].id); }
    }

    function ir(k) {
        var r = resultados[k];
        if (!r || !r.url) { return; }
        if (r.trancada && !abreTrancada) { mostrarSigilo(r); return; }
        fechar();
        window.location.href = r.url;
    }

    function mostrarSigilo(r) {
        sigiloPastaId = r.pastaId;
        sigiloNup.textContent = r.nup || '';
        sigiloRetorno.textContent = '';
        pedir.disabled = false;
        sigilo.hidden = false;
    }

    function esconderSigilo() {
        sigilo.hidden = true;
        sigiloPastaId = null;
    }

    /* "Pedir acesso": a mesma chamada e as mesmas mensagens da Lista do Expediente (expediente-lista.js). */
    function pedirAcesso() {
        if (sigiloPastaId === null) { return; }
        var corpo = new URLSearchParams();
        corpo.append('_token', raiz.getAttribute('data-csrf-pedir'));
        pedir.disabled = true;
        fetch(raiz.getAttribute('data-url-pedir').replace('/0/', '/' + sigiloPastaId + '/'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo.toString()
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
        }).then(function (r) {
            if (!r.ok) { sigiloRetorno.textContent = r.j.erro || 'Não foi possível pedir acesso'; pedir.disabled = false; return; }
            sigiloRetorno.textContent = r.j.avisados > 0 ? 'Pedido de acesso enviado ao administrador do escritório' : 'Nenhum administrador ativo no escritório para receber o pedido';
        }, function () {
            sigiloRetorno.textContent = 'Falha de conexão ao pedir acesso';
            pedir.disabled = false;
        });
    }

    /* Tab e Shift+Tab circulam só entre o campo, os itens e o "Pedir acesso" visível. */
    function prenderFoco(e) {
        var alvos = [campo].concat(Array.prototype.slice.call(opcoes.querySelectorAll('.pbr-item')));
        if (!sigilo.hidden && !pedir.disabled) { alvos.push(pedir); }
        var atual = alvos.indexOf(document.activeElement);
        var proximo = e.shiftKey ? (atual <= 0 ? alvos.length - 1 : atual - 1) : (atual + 1) % alvos.length;
        e.preventDefault();
        alvos[proximo].focus();
        var indice = alvos[proximo].getAttribute('data-indice');
        if (indice !== null) { selecionar(parseInt(indice, 10)); }
    }

    function mover(passo) {
        selecionar(Math.max(0, Math.min(resultados.length - 1, selecionado + passo)));
    }

    /* Foco num Quill que usa Ctrl + K para "inserir link": o tema snow põe a barra (.ql-toolbar) logo antes do
       .ql-container, e só liga o atalho quando a barra tem o botão .ql-link. */
    function quillComLink(alvo) {
        var editor = alvo && alvo.closest ? alvo.closest('.ql-editor') : null;
        var container = editor ? editor.closest('.ql-container') : null;
        var barra = container ? container.previousElementSibling : null;
        return !!(barra && barra.classList.contains('ql-toolbar') && barra.querySelector('.ql-link'));
    }

    document.addEventListener('keydown', function (e) {
        /* Teclado em composição (acento, IME): a tecla ainda não é do usuário. */
        if (e.isComposing || e.keyCode === 229) { return; }
        /* Só Ctrl + K minúsculo, sem Shift nem Alt: Ctrl + Shift + K é atalho do navegador (console). */
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key === 'k') {
            if (!aberta() && quillComLink(e.target)) { return; }
            e.preventDefault();
            if (aberta()) { fechar(); } else { abrir(); }
            return;
        }
        if (!aberta()) { return; }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            fechar();
        } else if (e.key === 'Tab') {
            prenderFoco(e);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            mover(e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Enter') {
            if (e.target === pedir) { return; }
            var noItem = e.target.closest ? e.target.closest('.pbr-item') : null;
            var k = noItem ? parseInt(noItem.getAttribute('data-indice'), 10) : selecionado;
            if (!resultados[k]) { return; }
            e.preventDefault();
            selecionar(k);
            ir(k);
        }
    }, true);

    /* Foco que escapa da janela aberta (clique fora do fundo, leitor de tela) volta para o campo. */
    document.addEventListener('focusin', function (e) {
        if (aberta() && !janela.contains(e.target)) { campo.focus(); }
    });

    campo.addEventListener('input', agendarBusca);
    pedir.addEventListener('click', pedirAcesso);
    raiz.querySelector('.pbr-fundo').addEventListener('click', fechar);
})();
