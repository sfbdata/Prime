/* BlueJus · Painel do Master, aba "Voto" (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b).
   - Carrega na primeira vez que a aba abre (clique na aba ou #aba=voto no endereço), como a aba da I.A.
   - Liberação por escritório: uma linha por escritório ativo, com a chave (.mp-rec-item, role="switch"). Clicar grava
     na hora, sem botão Salvar.
   - POST JSON com X-CSRF-Token; a resposta traz a grade nova e a aba se redesenha. Recusa: a mensagem do servidor, e a
     grade volta ao último estado gravado.
   - Textos no catálogo (master.js.voto.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar.
   Nenhuma regra mora só aqui: o servidor confere o Master, o token e o escritório. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-voto]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elMsg = raiz.querySelector('[data-mp-voto-msg]');
  const elLinhas = raiz.querySelector('[data-mp-voto-linhas]');
  const elContagem = raiz.querySelector('[data-mp-voto-contagem]');
  // O catálogo e o BJ_T entram depois deste arquivo (fim da página): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  let estado = null;
  let carregado = false;
  let ocupado = false;

  function avisar(texto, erro) {
    elMsg.textContent = texto || '';
    elMsg.classList.toggle('is-erro', !!erro);
    elMsg.hidden = !texto;
  }

  function travar(sim) {
    ocupado = sim;
    raiz.querySelectorAll('[data-mp-voto-liberado]').forEach(b => { b.disabled = sim; });
  }

  function el(tag, classe, texto) {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function chave(e) {
    const b = el('button', 'mp-rec-item mp-voto-chave' + (e.liberado ? '' : ' is-off'));
    b.type = 'button';
    b.setAttribute('role', 'switch');
    b.setAttribute('aria-checked', e.liberado ? 'true' : 'false');
    b.setAttribute('aria-label', T('master.js.voto.chave_rotulo', { nome: e.nome }));
    b.dataset.mpVotoLiberado = '';
    b.appendChild(el('span', 'mp-rec-item-st', e.liberado ? T('master.js.voto.liberado') : T('master.js.voto.bloqueado')));
    b.appendChild(el('span', 'mp-rec-chave')).appendChild(el('span'));
    b.addEventListener('click', () => alternar(e));
    return b;
  }

  function linha(e) {
    const lin = el('div', 'mp-voto-lin mp-lin');
    lin.dataset.mpVotoLinha = String(e.tenantId);
    lin.appendChild(el('div', 'mp-voto-nome mp-hv', e.nome));
    lin.appendChild(el('div', 'mp-voto-celula')).appendChild(chave(e));
    return lin;
  }

  function desenhar(d) {
    estado = d;
    elContagem.textContent = T('master.js.voto.contagem', { n: d.escritorios.length });
    elLinhas.textContent = '';
    if (!d.escritorios.length) elLinhas.appendChild(el('div', 'mp-carregando', T('master.js.voto.vazio')));
    d.escritorios.forEach(e => elLinhas.appendChild(linha(e)));
  }

  // A grade é redesenhada depois de cada gravação: devolve o foco à chave do mesmo escritório, para o teclado e o
  // leitor de tela não caírem no começo da página.
  function focar(tenantId) {
    const b = elLinhas.querySelector('[data-mp-voto-linha="' + tenantId + '"] [data-mp-voto-liberado]');
    if (b) b.focus();
  }

  function carregar() {
    carregado = true;
    fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); })
      .catch(() => { carregado = false; avisar(T('master.js.voto.erro_carregar'), true); });
  }

  function alternar(e) {
    if (ocupado) return;
    const liberado = !e.liberado;
    travar(true);
    avisar('');
    fetch(raiz.dataset.urlEscritorio.replace(/\/0$/, '/' + e.tenantId), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify({ liberado }),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.voto.erro_salvar'))))))
      .then(d => {
        desenhar(d);
        focar(e.tenantId);
        avisar(liberado ? T('master.js.voto.liberou', { nome: e.nome }) : T('master.js.voto.bloqueou', { nome: e.nome }));
      })
      .catch(erro => { if (estado) { desenhar(estado); focar(e.tenantId); } avisar(erro.message || T('master.js.voto.erro_salvar'), true); })
      .finally(() => travar(false));
  }

  const aba = document.querySelector('.mp-aba[data-aba="voto"]');
  if (aba) aba.addEventListener('click', () => { if (!carregado) carregar(); });
  // Já aberta pelo endereço: espera o fim da página, onde entram o catálogo e o BJ_T.
  if (/[#&]aba=voto\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
  window.addEventListener('hashchange', () => { if (/[#&]aba=voto\b/.test(location.hash) && !carregado) carregar(); });
})();
