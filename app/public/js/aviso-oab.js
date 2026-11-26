/* BlueJus · aviso "Confira o número" da OAB no topo das telas (layout/_globais/aviso_oab.html.twig).
   O servidor já mostra o aviso uma vez por sessão; o × só o esconde nesta tela. */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    const botao = e.target.closest('[data-fechar-aviso-oab]');
    if (!botao) return;
    const aviso = botao.closest('[data-aviso-oab]');
    if (aviso) aviso.hidden = true;
  });
})();
