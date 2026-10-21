/* "Enviar e-mail de teste" (desenho 05 VS 21.10.26, L497-498 e L668-669; F3f). O botão [data-mc-cp-teste] do editor
   abre o modal; "Enviar teste" posta o modelo atual (window.McCampanha.modeloAtual(), do comercial-campanha.js) na rota
   de teste, que grava o teste como campanha e o manda só ao Master logado. Depois, "Teste enviado" e o Relatório volta a
   carregar (a linha nova com o selo "Teste" e o KPI "Campanhas"). */
(function () {
  'use strict';
  const raiz = document.querySelector('[data-mc-tst]');
  const ok = document.querySelector('[data-mc-tst-ok]');
  const caixa = document.querySelector('[data-mc]') || document.querySelector('[data-token]');
  if (!raiz || !ok) { return; }
  const TOKEN = (caixa && caixa.dataset.token) || '';
  const elErro = raiz.querySelector('[data-mc-tst-erro]');
  const elEnviar = raiz.querySelector('[data-mc-tst-enviar]');

  function mostrarErro(texto) {
    elErro.textContent = texto || '';
    elErro.hidden = !texto;
  }

  function abrir() {
    mostrarErro('');
    ok.hidden = true;
    raiz.hidden = false;
    elEnviar.focus();
  }

  function fechar() {
    raiz.hidden = true;
    ok.hidden = true;
  }

  function enviar() {
    const modelo = window.McCampanha && typeof window.McCampanha.modeloAtual === 'function' ? window.McCampanha.modeloAtual() : null;
    if (!modelo) { mostrarErro('Abra um modelo no editor antes de enviar o teste.'); return; }
    elEnviar.disabled = true;
    fetch(raiz.dataset.url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(modelo),
    })
      .then(r => r.json().then(j => (r.ok ? j : Promise.reject(new Error(j.erro || 'Não foi possível enviar o teste.')))))
      .then(() => {
        raiz.hidden = true;
        ok.querySelector('[data-mc-tst-ok-para]').textContent = raiz.querySelector('[data-mc-tst-para]').value;
        ok.hidden = false;
        document.dispatchEvent(new CustomEvent('mc:relatorio-recarregar'));
      })
      .catch(e => mostrarErro(e.message))
      .then(() => { elEnviar.disabled = false; });
  }

  // Em captura: o editor não posta mais direto no clique do ícone (F3f tirou aquela linha do comercial-campanha.js).
  document.addEventListener('click', e => {
    if (e.target.closest('[data-mc-cp-teste]')) { e.preventDefault(); abrir(); return; }
    if (e.target.closest('[data-mc-tst-fechar]')) { fechar(); return; }
    if (e.target.closest('[data-mc-tst-enviar]') && !raiz.hidden) { enviar(); }
  }, true);
  document.addEventListener('mc:teste-abrir', abrir);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && (!raiz.hidden || !ok.hidden)) { fechar(); } });
})();
