/*
 * Janela "Excluir a pasta" do cabeçalho da pasta (18/11/2026, pedido do Samuel, no lugar do prompt() do navegador).
 * O "Excluir pasta" do menu (`form.js-excluir-pasta`) não envia direto: abre a janela #psExcluirPasta (o `.mp-modal`
 * da casa), que pede o número da pasta. O botão vermelho só habilita com o número certo (sem espaços nas pontas e sem
 * diferença de maiúsculas, como o servidor grava). Confirmado, o form segue pelo caminho de sempre, com o CSRF e o
 * `confirmar_nup`; quem confere de verdade é o servidor, que recusa número errado.
 */
(function () {
    'use strict';

    var formAlvo = null;

    function janela() { return document.getElementById('psExcluirPasta'); }

    function normalizar(valor) { return String(valor || '').trim().toUpperCase(); }

    function confere(j) {
        return normalizar(j.querySelector('[data-excluir-pasta-numero]').value) === normalizar(j.dataset.nup);
    }

    function fechar() {
        var j = janela();
        if (j) { j.hidden = true; }
        formAlvo = null;
    }

    function abrir(form) {
        var j = janela();
        if (!j) { return; }
        formAlvo = form;
        var campo = j.querySelector('[data-excluir-pasta-numero]');
        campo.value = '';
        j.querySelector('[data-excluir-pasta-ok]').disabled = true;
        j.hidden = false;
        campo.focus();
    }

    function confirmar() {
        var j = janela();
        if (!j || !formAlvo) { return; }
        if (!confere(j)) {
            if (window.avisarPs) { window.avisarPs(j.dataset.naoConfere); }
            return;
        }
        var form = formAlvo;
        form.elements.confirmar_nup.value = normalizar(j.querySelector('[data-excluir-pasta-numero]').value);
        form.dataset.excluirConfirmado = '1';
        fechar();
        form.submit();
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form.js-excluir-pasta')) { return; }
        if (form.dataset.excluirConfirmado === '1') { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        abrir(form);
    }, true);

    document.addEventListener('input', function (e) {
        if (!e.target.matches || !e.target.matches('#psExcluirPasta [data-excluir-pasta-numero]')) { return; }
        var j = janela();
        j.querySelector('[data-excluir-pasta-ok]').disabled = !confere(j);
    });

    document.addEventListener('click', function (e) {
        var alvo = e.target.closest ? e.target.closest('#psExcluirPasta [data-mp-fechar], #psExcluirPasta [data-excluir-pasta-ok]') : null;
        if (!alvo) { return; }
        if (alvo.hasAttribute('data-mp-fechar')) { fechar(); return; }
        confirmar();
    });

    document.addEventListener('keydown', function (e) {
        var j = janela();
        if (!j || j.hidden) { return; }
        if (e.key === 'Escape') { fechar(); return; }
        if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-excluir-pasta-numero]')) {
            e.preventDefault();
            confirmar();
        }
    });
})();
