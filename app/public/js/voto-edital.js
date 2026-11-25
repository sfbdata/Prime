/* BlueJus Voto: edital em PDF da assembleia. Sem dependência.
   O item de menu com data-voto-edital-escolher="#form" abre o seletor de arquivo do formulário escondido (que tem um
   <input type="file" data-voto-edital-arquivo>); ao escolher o PDF, o formulário é enviado. O menu de três pontos se
   fecha ao clicar. O servidor confere tipo, tamanho e permissão de novo. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var botao = ev.target.closest('[data-voto-edital-escolher]');
        if (!botao) { return; }
        var form = document.querySelector(botao.getAttribute('data-voto-edital-escolher'));
        var campo = form ? form.querySelector('[data-voto-edital-arquivo]') : null;
        var menu = botao.closest('[data-voto-menu]');
        if (menu) {
            var caixa = menu.querySelector('.vKm');
            var gatilho = menu.querySelector('[data-voto-menu-botao]');
            if (caixa) { caixa.hidden = true; }
            if (gatilho) { gatilho.setAttribute('aria-expanded', 'false'); }
        }
        if (campo) { campo.click(); }
    });

    document.addEventListener('change', function (ev) {
        var campo = ev.target.closest('[data-voto-edital-arquivo]');
        if (campo && campo.files && campo.files.length > 0) {
            campo.form.submit();
        }
    });
}());
