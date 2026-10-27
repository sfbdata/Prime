/*
 * Peticionar (desenho 07, L35-39): seletor do processo no cabeçalho. Só leitura: abre a lista dos
 * processos da pasta (principal e apensados) e marca o escolhido no botão. O destino por peça
 * (que grava) é outra fatia; aqui a escolha vale só para esta visita e fica lembrada no navegador.
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
    var pasta = (location.pathname.match(/\/pasta\/(\d+)/) || [])[1] || '';
    var chave = 'bj-pet-proc-' + pasta;

    function abrir(aberto) {
        menu.hidden = !aberto;
        fundo.hidden = !aberto;
        botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    }

    function marcar(item) {
        itens.forEach(function (i) { i.classList.toggle('petic-proc-item--atual', i === item); });
        texto.textContent = item.dataset.texto;
    }

    try {
        var salvo = localStorage.getItem(chave);
        if (salvo) {
            itens.forEach(function (i) { if (i.dataset.numero === salvo) marcar(i); });
        }
    } catch (e) { /* sem armazenamento: segue com o principal */ }

    botao.addEventListener('click', function () { abrir(menu.hidden); });
    fundo.addEventListener('click', function () { abrir(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') abrir(false); });
    itens.forEach(function (item) {
        item.addEventListener('click', function () {
            marcar(item);
            try { localStorage.setItem(chave, item.dataset.numero); } catch (e) { /* ignora */ }
            abrir(false);
        });
    });
})();
