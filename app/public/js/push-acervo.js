/* Acervo por jurisdição: só dígitos na busca e aviso ao atualizar (desenho 11). */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-pp-acervo]');
    if (!raiz) { return; }
    var form = raiz.querySelector('[data-pp-acervo-form]');
    var campo = raiz.querySelector('[data-pp-acervo-q]');

    campo.addEventListener('input', function () { campo.value = campo.value.replace(/[^\d.\-]/g, ''); });
    form.addEventListener('submit', function () { campo.value = campo.value.replace(/\D/g, ''); });

    raiz.querySelector('[data-pp-acervo-atualizar]').addEventListener('click', function () {
        try { window.sessionStorage.setItem('ppAcervoAtualizado', '1'); } catch (e) { /* sem storage: sem aviso */ }
        window.location.reload();
    });
    try {
        if (window.sessionStorage.getItem('ppAcervoAtualizado') === '1') {
            window.sessionStorage.removeItem('ppAcervoAtualizado');
            if (window.ppToast) { window.ppToast(raiz.getAttribute('data-t-atualizado')); }
        }
    } catch (e) { /* sem storage */ }
}());
