/* Aba "Diário da OAB" do Push: modal de registro e texto expansível da linha (desenho 11). */
(function () {
    'use strict';
    var modal = document.querySelector('[data-pp-dr]');
    var abrir = document.querySelector('[data-pp-dr-abrir]');

    if (modal && abrir) {
        var fechar = function () { modal.hidden = true; };
        abrir.addEventListener('click', function () {
            modal.hidden = false;
            modal.querySelector('input[name="data"]').focus();
        });
        modal.querySelectorAll('[data-pp-dr-fechar]').forEach(function (b) { b.addEventListener('click', fechar); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { fechar(); } });
    }

    document.querySelectorAll('[data-pp-diario-linha]').forEach(function (linha) {
        linha.addEventListener('click', function (e) {
            if (e.target.closest('input, a, button')) { return; }
            var texto = linha.parentNode.querySelector('[data-pp-diario-texto]');
            texto.hidden = !texto.hidden;
        });
    });
}());
