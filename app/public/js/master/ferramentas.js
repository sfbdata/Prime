/* BlueJus · Painel do Master, aba "Ferramentas" (spec docs/specs/multiproduto-contratacao-e-voto.md, §10).
   - Carrega na primeira vez que a aba abre (clique na aba ou #aba=ferramentas no endereço), como a aba do Voto.
   - Uma linha por escritório ativo, com uma chave (.mp-rec-item, role="switch") por ferramenta e uma para "Completo".
     Clicar grava na hora, sem botão Salvar. Ferramenta desligada pelo BlueJus mostra "Desligada pelo BlueJus" (o dono
     não religa; só o Master).
   - POST JSON com X-CSRF-Token; a resposta traz a grade nova e a aba se redesenha. Recusa: a mensagem do servidor, e a
     grade volta ao último estado gravado.
   - Textos no catálogo (master.js.produtos.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar;
     o nome de cada ferramenta vem do servidor.
   Nenhuma regra mora só aqui: o servidor confere o Master, o token, o escritório e o produto. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-fer]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elMsg = raiz.querySelector('[data-mp-fer-msg]');
  const elGrade = raiz.querySelector('[data-mp-fer-grade]');
  const elCab = raiz.querySelector('[data-mp-fer-cab]');
  const elLinhas = raiz.querySelector('[data-mp-fer-linhas]');
  const elContagem = raiz.querySelector('[data-mp-fer-contagem]');
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
    raiz.querySelectorAll('[data-mp-fer-chave]').forEach(b => { b.disabled = sim; });
  }

  function el(tag, classe, texto) {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  // `produto` é o valor da ferramenta ou "completo"; `ligada` e `rotuloDoEstado` já vêm resolvidos.
  function chave(e, produto, nomeDoProduto, ligada, rotuloDoEstado) {
    const b = el('button', 'mp-rec-item mp-fer-chave' + (ligada ? '' : ' is-off'));
    b.type = 'button';
    b.setAttribute('role', 'switch');
    b.setAttribute('aria-checked', ligada ? 'true' : 'false');
    b.setAttribute('aria-label', T('master.js.produtos.chave_rotulo', { produto: nomeDoProduto, nome: e.nome }));
    b.dataset.mpFerChave = produto;
    b.appendChild(el('span', 'mp-rec-item-st', rotuloDoEstado));
    b.appendChild(el('span', 'mp-rec-chave')).appendChild(el('span'));
    b.addEventListener('click', () => alternar(e, produto, nomeDoProduto, !ligada));
    return b;
  }

  function celula(e, produto, nomeDoProduto, ligada, rotuloDoEstado) {
    const c = el('div', 'mp-fer-celula');
    c.appendChild(chave(e, produto, nomeDoProduto, ligada, rotuloDoEstado));
    return c;
  }

  function linha(d, e) {
    const lin = el('div', 'mp-fer-lin mp-lin');
    lin.dataset.mpFerLinha = String(e.tenantId);
    lin.appendChild(el('div', 'mp-fer-nome mp-hv', e.nome));
    lin.appendChild(celula(e, 'completo', T('master.js.produtos.completo'), e.completo, e.completo ? T('master.js.produtos.sim') : T('master.js.produtos.nao')));
    d.ferramentas.forEach(f => {
      const ligada = e.ligadas.includes(f.valor);
      const travada = e.travadas.includes(f.valor);
      const estadoEmTexto = ligada ? T('master.js.produtos.ligada') : (travada ? T('master.js.produtos.travada') : T('master.js.produtos.desligada'));
      lin.appendChild(celula(e, f.valor, f.rotulo, ligada, estadoEmTexto));
    });
    return lin;
  }

  function desenharCabecalho(d) {
    elGrade.style.setProperty('--mp-fer-n', String(d.ferramentas.length + 1));
    elCab.textContent = '';
    elCab.appendChild(el('div', '', T('master.js.produtos.col_nome')));
    elCab.appendChild(el('div', '', T('master.js.produtos.completo')));
    d.ferramentas.forEach(f => elCab.appendChild(el('div', '', f.rotulo)));
  }

  function desenhar(d) {
    estado = d;
    elContagem.textContent = T('master.js.produtos.contagem', { n: d.escritorios.length });
    desenharCabecalho(d);
    elLinhas.textContent = '';
    if (!d.escritorios.length) elLinhas.appendChild(el('div', 'mp-carregando', T('master.js.produtos.vazio')));
    d.escritorios.forEach(e => elLinhas.appendChild(linha(d, e)));
  }

  // A grade é redesenhada depois de cada gravação: devolve o foco à chave da mesma célula, para o teclado e o leitor de
  // tela não caírem no começo da página.
  function focar(tenantId, produto) {
    const b = elLinhas.querySelector('[data-mp-fer-linha="' + tenantId + '"] [data-mp-fer-chave="' + produto + '"]');
    if (b) b.focus();
  }

  function carregar() {
    carregado = true;
    fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); })
      .catch(() => { carregado = false; avisar(T('master.js.produtos.erro_carregar'), true); });
  }

  function alternar(e, produto, nomeDoProduto, ligado) {
    if (ocupado) return;
    travar(true);
    avisar('');
    fetch(raiz.dataset.urlEscritorio.replace(/\/0$/, '/' + e.tenantId), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify({ produto, ligado }),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.produtos.erro_salvar'))))))
      .then(d => {
        desenhar(d);
        focar(e.tenantId, produto);
        avisar(ligado ? T('master.js.produtos.ligou', { produto: nomeDoProduto, nome: e.nome }) : T('master.js.produtos.desligou', { produto: nomeDoProduto, nome: e.nome }));
      })
      .catch(erro => { if (estado) { desenhar(estado); focar(e.tenantId, produto); } avisar(erro.message || T('master.js.produtos.erro_salvar'), true); })
      .finally(() => travar(false));
  }

  const aba = document.querySelector('.mp-aba[data-aba="ferramentas"]');
  if (aba) aba.addEventListener('click', () => { if (!carregado) carregar(); });
  // Já aberta pelo endereço: espera o fim da página, onde entram o catálogo e o BJ_T.
  if (/[#&]aba=ferramentas\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
  window.addEventListener('hashchange', () => { if (/[#&]aba=ferramentas\b/.test(location.hash) && !carregado) carregar(); });
})();
