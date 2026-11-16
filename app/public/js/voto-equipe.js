/* Perfis (desenho 18, H236): trocar a votação no campo "Votação" abre o grupo dela. Sem JavaScript, o botão Abrir faz o mesmo. */
(function () {
    'use strict';
    document.querySelectorAll('[data-voto-equipe-votacao] select').forEach(function (campo) {
        campo.addEventListener('change', function () {
            campo.form.submit();
        });
    });
})();
