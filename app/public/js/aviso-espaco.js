/* Aviso de espaço (desenho 08, bj-plano.js): aparece 1,5 s depois de abrir a tela, uma vez por dia.
   Espera os modais que vêm antes dele (alerta do ponto, instalar no iPhone): nunca abre por cima de um. */
(function () {
    'use strict';

    const aviso = document.querySelector('[data-aviso-espaco]');
    if (!aviso) {
        return;
    }
    const CHAVE = 'bj-aviso-espaco';
    const hoje = new Date().toLocaleDateString('sv-SE');
    const cheio = aviso.dataset.cheio === '1';
    let fechadoHoje = false;
    try { fechadoHoje = localStorage.getItem(CHAVE) === hoje; } catch (e) { /* sem storage: mostra */ }
    if (!cheio && fechadoHoje) {
        return;
    }

    function mostrar() {
        if (document.querySelector('.modal.show')) {
            document.addEventListener('hidden.bs.modal', mostrar, { once: true });

            return;
        }
        aviso.hidden = false;
    }
    setTimeout(mostrar, 1500);

    const fechar = aviso.querySelector('[data-fechar-aviso-espaco]');
    if (fechar) {
        fechar.addEventListener('click', () => {
            try { localStorage.setItem(CHAVE, hoje); } catch (e) { /* sem storage: só fecha */ }
            aviso.hidden = true;
        });
    }
})();
