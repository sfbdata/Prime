/* BlueJus · Comercial do /master: chave "Confirmação de leitura" da barra de envio (F3g; desenho 05 VS 21.10.26,
   L291 e L656). Alterna a escolha quando o rastreio está liberado e a manda no "Enviar" pelo gancho da F3a
   (`window.McCampanha.extrasDoEnvio`): `rastrearLeitura` só é true com a chave ligada. Desabilitada (rastreio global
   desligado), não muda. */
(function () {
  'use strict';
  const botao = document.querySelector('[data-mc-cp-rastrear]');
  if (!botao) return;
  const texto = botao.querySelector('[data-mc-cp-rastrear-txt]');

  function mostrar(ligada) {
    botao.dataset.mcCpRastrear = ligada ? '1' : '0';
    botao.classList.toggle('is-ligada', ligada);
    botao.setAttribute('aria-pressed', String(ligada));
    if (texto) texto.textContent = ligada ? 'Confirmação de leitura' : 'Sem confirmação de leitura';
    botao.title = ligada ? 'Rastreando leitura (✓✓). Clique para somente enviar' : 'Clique para rastrear a leitura (✓✓)';
  }

  botao.addEventListener('click', () => {
    if (botao.disabled) return;
    mostrar(botao.dataset.mcCpRastrear !== '1');
  });

  window.McCampanha = window.McCampanha || {};
  (window.McCampanha.extrasDoEnvio = window.McCampanha.extrasDoEnvio || []).push(() => ({ rastrearLeitura: !botao.disabled && botao.dataset.mcCpRastrear === '1' }));
})();
