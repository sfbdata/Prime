/* BlueJus Voto: Gestão eleitoral por entidade (F1-4). Sem dependência. A página funciona sem este arquivo (filtros por
   formulário GET e grupos em <details>); ele só acrescenta o conforto do desenho:
   1) trocar "Entidade" ou "Situação" aplica o filtro na hora (o campo de busca aplica no Enter, como o formulário);
   2) os botões "Expandir todas as entidades" e "Recolher todas as entidades" (escondidos sem JavaScript). */
(function () {
    'use strict';

    var form = document.querySelector('[data-voto-filtros]');
    if (form) {
        Array.prototype.forEach.call(form.querySelectorAll('[data-voto-filtro-auto]'), function (campo) {
            campo.addEventListener('change', function () { form.submit(); });
        });
    }

    var botoes = document.querySelectorAll('[data-voto-grupos]');
    Array.prototype.forEach.call(botoes, function (botao) {
        botao.hidden = false;
        botao.addEventListener('click', function () {
            var abrir = botao.getAttribute('data-voto-grupos') === 'abrir';
            Array.prototype.forEach.call(document.querySelectorAll('[data-voto-grupo]'), function (grupo) {
                grupo.open = abrir;
            });
        });
    });
})();
