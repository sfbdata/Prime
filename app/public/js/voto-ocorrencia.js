/* BlueJus Voto: contador de caracteres do formulário "Registrar ocorrência" do Portal do Eleitor (máximo de 500). */
(function () {
    'use strict';

    var campo = document.querySelector('[data-vc-ocorrencia-texto]');
    var contador = document.querySelector('[data-vc-ocorrencia-contador]');
    if (!campo || !contador) { return; }

    function atualizar() {
        var maximo = parseInt(campo.getAttribute('maxlength'), 10) || 500;
        var n = campo.value.length;
        contador.textContent = n + '/' + maximo;
        contador.classList.toggle('vcOcorrenciaContadorLimite', n >= maximo);
    }

    campo.addEventListener('input', atualizar);
    atualizar();
})();
