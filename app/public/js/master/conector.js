/* BlueJus · Painel do Master, aba "Configurações da I.A.": o conector de I.A. por escritório
   (spec docs/specs/mcp-remoto-oauth.md, 14.a), só leitura.
   - Carrega na primeira vez que a aba abre (clique na aba ou #aba=ia no endereço), como o ia.js.
   - Uma linha por escritório ativo: o "ligado" e o aceite são do administrador do escritório e aqui só aparecem; das
     conexões ativas, só a contagem. O Master não libera escritório por escritório (decisão do Samuel, 19/11).
   - Textos no catálogo (conector.js.*, entregue pelo parcial com catalogo_js), lidos por BJ_T.t na hora de usar.
   Nenhuma regra mora só aqui: o servidor confere o Master. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-conector]');
  if (!raiz) return;

  const elMsg = raiz.querySelector('[data-mp-conector-msg]');
  const elLinhas = raiz.querySelector('[data-mp-conector-linhas]');
  // O catálogo e o BJ_T entram depois deste arquivo (fim da página): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  let carregado = false;

  function avisar(texto, erro) {
    elMsg.textContent = texto || '';
    elMsg.classList.toggle('is-erro', !!erro);
    elMsg.hidden = !texto;
  }

  function el(tag, classe, texto) {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function textoDoAceite(e) {
    if (!e.aceiteEm) return T('conector.js.master.sem_aceite');
    const data = window.BJFormato.data(e.aceiteEm + 'T12:00:00');
    return e.aceiteVigente ? data : data + ' (' + T('conector.js.master.aceite_antigo') + ')';
  }

  function linha(e) {
    const lin = el('div', 'mp-ia-lin mp-lin');
    lin.dataset.mpConectorLinha = String(e.tenantId);
    lin.appendChild(el('div', 'mp-ia-nome mp-hv', e.nome));
    lin.appendChild(el('div', 'mp-ia-celula mp-ia-aceite mp-hv', e.ligado ? T('conector.js.master.ligado') : T('conector.js.master.desligado')));
    lin.appendChild(el('div', 'mp-ia-celula mp-ia-aceite mp-hv', textoDoAceite(e)));
    lin.appendChild(el('div', 'mp-ia-celula mp-ia-num mp-hv', T('conector.js.master.contagem', { n: e.conexoesAtivas })));
    return lin;
  }

  function desenhar(d) {
    elLinhas.textContent = '';
    if (!d.escritorios.length) elLinhas.appendChild(el('div', 'mp-carregando', T('conector.js.master.vazio')));
    d.escritorios.forEach(e => elLinhas.appendChild(linha(e)));
  }

  function carregar() {
    carregado = true;
    fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); })
      .catch(() => { carregado = false; avisar(T('conector.js.master.erro_carregar'), true); });
  }

  const aba = document.querySelector('.mp-aba[data-aba="ia"]');
  if (aba) aba.addEventListener('click', () => { if (!carregado) carregar(); });
  // Já aberta pelo endereço: espera o fim da página, onde entram o BJ_T e o BJFormato.
  if (/[#&]aba=ia\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
  window.addEventListener('hashchange', () => { if (/[#&]aba=ia\b/.test(location.hash) && !carregado) carregar(); });
})();
