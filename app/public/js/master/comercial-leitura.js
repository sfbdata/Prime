/* BlueJus · Comercial do /master: chave "Confirmação de leitura" da barra de envio (F3g; desenho 05 VS 21.10.26,
   L291 e L656). Uma por Central (seção Comercial e modo venda da seção Indicações, F3h). Alterna a escolha quando o
   rastreio está liberado e a manda no "Enviar" da PRÓPRIA Central: cada chave se registra em
   `raiz.mcExtrasDoEnvio`, na `section[data-mc-campanha]` dela (contrato com a 5d), e não no gancho global, para não
   entrar duas vezes. `rastrearLeitura` só é true com a chave ligada e habilitada; desabilitada (rastreio global
   desligado), não muda. */
(function () {
  'use strict';

  function ligar(botao) {
    const texto = botao.querySelector('[data-mc-cp-rastrear-txt]');
    const raiz = botao.closest('[data-mc-campanha]');

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

    if (raiz) {
      raiz.mcExtrasDoEnvio = raiz.mcExtrasDoEnvio || [];
      raiz.mcExtrasDoEnvio.push(() => ({ rastrearLeitura: !botao.disabled && botao.dataset.mcCpRastrear === '1' }));
    }
  }

  /* O script carrega antes da Central de venda existir no DOM: varrer só com a página montada. */
  function iniciar() { document.querySelectorAll('[data-mc-cp-rastrear]').forEach(ligar); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
