/*
 * Peticionar (desenho 07, L35-39): seletor do processo no cabeçalho. Abre a lista dos processos da
 * pasta (principal e apensados) e mostra no botão o DESTINO DA PEÇA selecionada (sem destino, o
 * principal). Escolher um processo grava o destino na peça selecionada; sem peça selecionada ou
 * sem permissão de editar, a escolha vale só para a tela.
 */
(function () {
    'use strict';
    var raiz = document.getElementById('peticProc');
    if (!raiz) return;
    var botao = document.getElementById('peticProcBtn');
    var menu = document.getElementById('peticProcMenu');
    var fundo = document.getElementById('peticProcFundo');
    var texto = document.getElementById('peticProcTxt');
    var itens = raiz.querySelectorAll('.petic-proc-item');
    var lista = document.getElementById('timelineList');

    function abrir(aberto) {
        menu.hidden = !aberto;
        fundo.hidden = !aberto;
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    }

    function marcar(item) {
        itens.forEach(function (i) { i.classList.toggle('petic-proc-item--atual', i === item); });
        texto.textContent = item.dataset.texto;
    }

    function itemDoVinculo(id) {
        var achado = null;
        itens.forEach(function (i) { if (id && i.dataset.vinculo === String(id)) achado = i; });
        return achado || itens[0] || null;
    }

    function aoSelecionar(linha) {
        var item = itemDoVinculo(linha && linha.dataset.destino);
        if (item) marcar(item);
    }

    function gravar(linha, item) {
        var vinculo = item === itens[0] ? '' : item.dataset.vinculo;
        var fd = new FormData();
        fd.append('_token', raiz.dataset.csrf);
        fd.append('vinculo', vinculo);
        fetch(raiz.dataset.urlDestino.replace('__ID__', linha.dataset.docId), {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (resp) {
            if (!resp.ok) throw new Error('destino');
            linha.dataset.destino = vinculo;
        }).catch(function () {
            aoSelecionar(linha);
            if (window.avisarErroPs) window.avisarErroPs(botao.dataset.erro || '');
        });
    }

    botao.addEventListener('click', function () { abrir(menu.hidden); });
    fundo.addEventListener('click', function () { abrir(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') abrir(false); });
    itens.forEach(function (item) {
        item.addEventListener('click', function () {
            var linha = document.querySelector('.petic-tl-row.ativo');
            marcar(item);
            if (linha && lista && lista.dataset.podeEditar === '1' && raiz.dataset.csrf) gravar(linha, item);
            abrir(false);
        });
    });

    window.PeticionarProcesso = { aoSelecionar: aoSelecionar };
    var atual = document.querySelector('.petic-tl-row.ativo');
    if (atual) aoSelecionar(atual);
})();
