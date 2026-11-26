/* BlueJus Voto: passo 1 do formulário da assembleia. Escolher a categoria (cenário) troca o rótulo do nome da entidade,
   o aviso e o peso do voto sugerido, como o `aplicaCen` do desenho 18. Os textos vêm dos data-* dos botões; o servidor
   grava só o valor do cenário. Sem JavaScript o formulário funciona do mesmo jeito (o servidor mostra o escolhido). */
(function () {
    'use strict';

    var grupo = document.querySelector('[data-voto-cenarios]');
    if (!grupo) {
        return;
    }
    var form = grupo.closest('form');
    var pesoMexido = false;
    var pesoCampo = form.querySelector('[data-voto-cenario-peso]');
    if (pesoCampo) {
        /* Só o clique da pessoa no peso conta (o script muda o valor sem disparar "change"): depois disso a categoria não o troca mais. */
        pesoCampo.addEventListener('change', function () {
            pesoMexido = true;
        });
    }

    grupo.addEventListener('change', function (evento) {
        var radio = evento.target;
        if (!radio || radio.name !== 'cenario') {
            return;
        }
        grupo.querySelectorAll('.vCat').forEach(function (botao) {
            botao.classList.toggle('vCatOn', botao.contains(radio));
        });
        var entidade = form.querySelector('[data-voto-cenario-entidade]');
        var ajuda = form.querySelector('[data-voto-cenario-ajuda]');
        var peso = form.querySelector('[data-voto-cenario-peso]');
        if (entidade) {
            entidade.textContent = radio.getAttribute('data-entidade');
        }
        if (ajuda) {
            ajuda.textContent = radio.getAttribute('data-ajuda');
        }
        if (peso && !pesoMexido) {
            peso.value = radio.getAttribute('data-peso');
        }
    });
}());
