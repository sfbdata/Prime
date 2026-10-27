/* Aba "Processos da OAB" do Push: seleção em lote e janela de anotações (desenho 11). */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-pp-processos]');
    if (!raiz) { return; }

    var caixas = Array.prototype.slice.call(raiz.querySelectorAll('[data-pp-marca]'));
    var barra = raiz.querySelector('[data-pp-selecao-barra]');
    var contador = raiz.querySelector('[data-pp-selecao-n]');
    var todos = raiz.querySelector('[data-pp-marcar-todos]');

    function marcadas() { return caixas.filter(function (c) { return c.checked; }); }

    function atualizar() {
        var n = marcadas().length;
        if (barra) { barra.hidden = n === 0; }
        if (contador) { contador.textContent = String(n); }
        if (todos) {
            var icone = todos.querySelector('i');
            if (icone) {
                icone.className = 'bi ' + (n === 0 ? 'bi-square' : (n === caixas.length ? 'bi-check-square-fill' : 'bi-dash-square'));
            }
        }
        raiz.querySelectorAll('[data-pp-numeros]').forEach(function (form) {
            form.querySelectorAll('input[name="numeros[]"]').forEach(function (i) { i.remove(); });
            marcadas().forEach(function (c) {
                var campo = document.createElement('input');
                campo.type = 'hidden';
                campo.name = 'numeros[]';
                campo.value = c.value;
                form.appendChild(campo);
            });
        });
    }

    caixas.forEach(function (c) { c.addEventListener('change', atualizar); });
    if (todos) {
        todos.addEventListener('click', function () {
            var marcar = marcadas().length !== caixas.length;
            caixas.forEach(function (c) { c.checked = marcar; });
            atualizar();
        });
    }
    atualizar();

    raiz.querySelectorAll('[data-pp-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-pp-confirmar'))) { e.preventDefault(); }
        });
    });

    var janela = document.querySelector('[data-pp-janela]');
    if (janela) {
        raiz.querySelectorAll('[data-pp-editar]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                var f = janela.querySelector('form');
                f.elements.numero.value = botao.getAttribute('data-numero');
                f.elements.observacao.value = botao.getAttribute('data-observacao') || '';
                f.elements.area.value = botao.getAttribute('data-area') || '';
                f.elements.fase.value = botao.getAttribute('data-fase') || '';
                f.elements.email.value = botao.getAttribute('data-email') || '';
                janela.querySelector('[data-pp-janela-numero]').textContent = botao.getAttribute('data-numero-exibicao');
                janela.hidden = false;
                f.elements.observacao.focus();
            });
        });
        janela.querySelectorAll('[data-pp-fechar]').forEach(function (b) {
            b.addEventListener('click', function () { janela.hidden = true; });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { janela.hidden = true; } });
    }
}());
