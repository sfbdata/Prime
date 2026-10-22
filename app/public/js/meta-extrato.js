/* BlueJus · Extrato de tramitação da meta (desenho 04B de 22/10, L150). Template: tarefa/extrato.html.twig.
   A folha abre já pedindo a impressão, como o `w.print()` do desenho; o botão "Imprimir" repete o pedido. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var botao = document.querySelector('[data-imprimir]');
    if (botao) botao.addEventListener('click', function () { window.print(); });
  });

  window.addEventListener('load', function () { window.print(); });
})();
