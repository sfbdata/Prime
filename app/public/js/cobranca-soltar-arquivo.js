/* Área "Arraste o arquivo ou clique para escolher" (desenho Carteira 1B, modal Adicionar documento, L457-461).
   O <input type="file"> continua sendo o campo do formulário (escondido dentro do <label>): clicar abre o seletor,
   e soltar um arquivo na área põe o arquivo nele. Mostra o nome escolhido no lugar da dica. */
(function () {
  'use strict';

  function mostrarEscolha(area, input) {
    const nome = area.querySelector('[data-soltar-nome]');
    if (!nome) return;
    const arquivo = input.files && input.files[0];
    nome.textContent = arquivo ? arquivo.name : '';
    nome.hidden = !arquivo;
    area.classList.toggle('tem-arquivo', !!arquivo);
  }

  document.querySelectorAll('[data-soltar-arquivo]').forEach(function (area) {
    const input = area.querySelector('input[type="file"]');
    if (!input) return;
    input.addEventListener('change', function () { mostrarEscolha(area, input); });
    ['dragenter', 'dragover'].forEach(function (ev) {
      area.addEventListener(ev, function (e) { e.preventDefault(); area.classList.add('arrastando'); });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      area.addEventListener(ev, function () { area.classList.remove('arrastando'); });
    });
    area.addEventListener('drop', function (e) {
      e.preventDefault();
      area.classList.remove('arrastando');
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      input.files = e.dataTransfer.files;
      mostrarEscolha(area, input);
    });
  });
})();
