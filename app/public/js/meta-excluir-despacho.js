/*
 * Exclusão da meta com despacho registrado (decisão do dono, 23/10), na aba Metas da pasta: o "Excluir" do ⋮
 * (`form.ps-meta-excluir[data-meta-despacho]`, que só existe para o Master e o primário com a opção) não envia direto.
 * Abre a janela #psMetaExcluirDespacho (o `.mp-modal` da devolução): o aviso, a caixa "Entendo que o registro oficial
 * será apagado" e o botão que só habilita com ela marcada. Confirmado, o form vai com `confirmar_despacho=1`; sem isso,
 * o servidor recusa (422). Delegado no documento: a aba é renderizada de novo por XHR (pasta-metas.js).
 */
(function () {
    'use strict';

    var formAlvo = null;

    function janela() { return document.getElementById('psMetaExcluirDespacho'); }

    function fechar() {
        var j = janela();
        if (j) { j.hidden = true; }
        formAlvo = null;
    }

    function abrir(form) {
        var j = janela();
        if (!j) { return; }
        formAlvo = form;
        var ciente = j.querySelector('[data-meta-despacho-ciente]');
        var ok = j.querySelector('[data-meta-despacho-ok]');
        ciente.checked = false;
        ok.disabled = true;
        j.hidden = false;
        ciente.focus();
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form.ps-meta-excluir[data-meta-despacho]')) { return; }
        if (form.dataset.metaDespachoConfirmado === '1') { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        abrir(form);
    }, true);

    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('#psMetaExcluirDespacho [data-meta-despacho-ciente]')) { return; }
        janela().querySelector('[data-meta-despacho-ok]').disabled = !e.target.checked;
    });

    document.addEventListener('click', function (e) {
        var alvo = e.target.closest ? e.target.closest('#psMetaExcluirDespacho [data-mp-fechar], #psMetaExcluirDespacho [data-meta-despacho-ok]') : null;
        if (!alvo) { return; }
        if (alvo.hasAttribute('data-mp-fechar')) { fechar(); return; }
        var ciente = janela().querySelector('[data-meta-despacho-ciente]');
        if (!formAlvo || !ciente.checked) { return; }
        var form = formAlvo;
        form.querySelector('input[name="confirmar_despacho"]').value = '1';
        form.dataset.metaDespachoConfirmado = '1';
        fechar();
        form.submit();
    });

    document.addEventListener('keydown', function (e) {
        var j = janela();
        if (e.key === 'Escape' && j && !j.hidden) { fechar(); }
    });
})();
