/* Relatório de contribuições no Painel do Master (templates/contribua/_relatorio_master.html.twig).
   O cartão vem pronto do servidor; aqui só se decide quando aparece: na seção "Visão geral" do painel.
   O painel (public/js/master/painel.js) redesenha os botões de seção a cada troca; o observador lê qual
   está ligado. Sem nenhum ligado (antes do painel carregar, ou sem JS do painel), o cartão fica visível. */
(() => {
  'use strict';
  const cartao = document.querySelector('[data-cr-relatorio]');
  const secoes = document.getElementById('mp-secs');
  if (!cartao || !secoes) return;

  const mostrar = () => {
    const ligada = secoes.querySelector('.mp-sec.on');
    cartao.hidden = Boolean(ligada) && ligada.dataset.sec !== 'visao';
  };
  new MutationObserver(mostrar).observe(secoes, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
  mostrar();
})();
