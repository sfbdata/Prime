/* "Enviar e-mail de teste" (desenho 05 VS 21.10.26, L497-498 e L668-669; F3f). O botão [data-mc-cp-teste] do editor
   abre o modal; "Enviar teste" posta o modelo da seção do clique (mcModeloAtual() da section[data-mc-campanha], contrato
   com a 5d; window.McCampanha.modeloAtual() por compatibilidade) na rota de teste, que grava o teste como campanha e o
   manda só ao Master logado. Depois, "Teste enviado" e o Relatório volta a carregar (a linha nova com o selo "Teste" e o
   KPI "Campanhas").
   A Central de envios existe em dois modos no /master (seção Comercial e seção Indicações, uma escondida): cada raiz .mc
   tem o seu modal e o seu token, e o clique usa os da raiz de onde veio. O _comercial.html.twig carrega este script nos
   dois modos; só a primeira execução liga os ouvintes. */
(function () {
  'use strict';
  if (window.McTeste) { return; }
  window.McTeste = true;

  let atual = null; // { raiz, ok, token, secao, origem } do modal aberto

  function modalDaRaiz(mc) {
    if (!mc) { return null; }
    const raiz = mc.querySelector('[data-mc-tst]');
    const ok = mc.querySelector('[data-mc-tst-ok]');

    return raiz && ok ? { raiz, ok, token: mc.dataset.token || '' } : null;
  }

  function erro(texto) {
    const el = atual.raiz.querySelector('[data-mc-tst-erro]');
    el.textContent = texto || '';
    el.hidden = !texto;
  }

  function abrir(e) {
    const alvo = e && e.target && e.target.closest ? e.target : null;
    const modal = modalDaRaiz(alvo ? alvo.closest('.mc') : document.querySelector('.mc[data-mc]'));
    if (!modal) { return; }
    atual = Object.assign(modal, { secao: alvo ? alvo.closest('[data-mc-campanha]') : null, origem: document.activeElement });
    erro('');
    atual.ok.hidden = true;
    atual.raiz.hidden = false;
    atual.raiz.querySelector('[data-mc-tst-enviar]').focus();
  }

  function fechar() {
    if (!atual) { return; }
    atual.raiz.hidden = true;
    atual.ok.hidden = true;
    if (atual.origem && typeof atual.origem.focus === 'function') { atual.origem.focus(); }
    atual = null;
  }

  function modeloDaSecao() {
    if (atual.secao && typeof atual.secao.mcModeloAtual === 'function') { return atual.secao.mcModeloAtual(); }
    return window.McCampanha && typeof window.McCampanha.modeloAtual === 'function' ? window.McCampanha.modeloAtual() : null;
  }

  function enviar() {
    const modelo = modeloDaSecao();
    if (!modelo) { erro('Abra um modelo no editor antes de enviar o teste.'); return; }
    const aberto = atual;
    const botao = aberto.raiz.querySelector('[data-mc-tst-enviar]');
    botao.disabled = true;
    fetch(aberto.raiz.dataset.url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': aberto.token },
      body: JSON.stringify(modelo),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || 'Não foi possível enviar o teste.')))))
      .then(() => {
        aberto.raiz.hidden = true;
        aberto.ok.querySelector('[data-mc-tst-ok-para]').textContent = aberto.raiz.querySelector('[data-mc-tst-para]').value;
        aberto.ok.hidden = false;
        aberto.ok.querySelector('[data-mc-tst-fechar].mc-tst-enviar').focus();
        document.dispatchEvent(new CustomEvent('mc:relatorio-recarregar'));
      })
      .catch(e => { if (atual === aberto) { erro(e.message); } })
      .then(() => { botao.disabled = false; });
  }

  // Em captura: o editor não posta mais direto no clique do ícone (F3f tirou aquela linha do comercial-campanha.js).
  document.addEventListener('click', e => {
    if (e.target.closest('[data-mc-cp-teste]')) { e.preventDefault(); abrir(e); return; }
    if (e.target.closest('[data-mc-tst-fechar]')) { fechar(); return; }
    if (e.target.closest('[data-mc-tst-enviar]') && atual && !atual.raiz.hidden) { enviar(); }
  }, true);
  document.addEventListener('mc:teste-abrir', abrir);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && atual) { fechar(); } });
})();
