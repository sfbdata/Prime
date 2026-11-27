/* BlueJus · Painel do Master, seção "Indicações do BlueJus" (#sec=ind)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, marcação L234-242 e lógica
   L683-689; spec docs/specs/indicar-bluejus.md).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Cabeçalho: "N enviadas · N hoje · N viraram conta" (enviadas, últimas 24h e viraram conta do servidor).
   - Tabela "Quem indica" (pedido do dono, 25/11): GET quem-indica com `q` (mín. 2 letras) e `pagina`; só o Master.
   - A chave pausa ou libera os convites de todos; os três campos gravam ao mudar (onChange do dc), sempre os três.
   - POST JSON com X-CSRF-Token; a resposta traz o estado novo e a tela se redesenha. Recusa: a mensagem do servidor
     aparece e o campo volta ao último valor gravado.
   - Textos no catálogo (master.js.indicacoes.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de
     usar; números por window.BJFormato.
   Nenhuma regra mora só aqui: o servidor recusa limite menor que 1 e valor que não seja inteiro. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mi]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elCaixa = raiz.querySelector('[data-mi-caixa]');
  const elResumo = raiz.querySelector('[data-mi-resumo]');
  const elChave = raiz.querySelector('[data-mi-pausa]');
  const elChaveTxt = raiz.querySelector('[data-mi-pausa-txt]');
  const elMsg = raiz.querySelector('[data-mi-msg]');
  const campos = Array.from(raiz.querySelectorAll('[data-mi-limite]'));
  const num = n => window.BJFormato.numero(n);
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  let estado = null;
  let carregado = false;
  let ocupado = false;

  // Tabela "Quem indica": a busca (nome ou escritório, mínimo 2 letras) e a página vão ao servidor.
  const BUSCA_MINIMO = 2;
  const elQuemBusca = raiz.querySelector('[data-mi-quem-busca]');
  const elQuemLista = raiz.querySelector('[data-mi-quem-lista]');
  const elQuemVazio = raiz.querySelector('[data-mi-quem-vazio]');
  const elQuemPag = raiz.querySelector('[data-mi-quem-pag]');
  const elQuemPos = raiz.querySelector('[data-mi-quem-pos]');
  const elQuemAnt = raiz.querySelector('[data-mi-quem-ant]');
  const elQuemProx = raiz.querySelector('[data-mi-quem-prox]');
  let quemPagina = 1;
  let quemSeq = 0;

  function celula(texto, classe) {
    const s = document.createElement('span');
    if (classe) s.className = classe;
    s.textContent = texto;
    if (texto) s.title = texto;
    return s;
  }

  function desenharQuem(d) {
    elQuemLista.replaceChildren();
    d.itens.forEach(i => {
      const linha = document.createElement('div');
      linha.className = 'mi-quem-linha';
      linha.setAttribute('role', 'row');
      linha.append(
        celula(i.nome), celula(i.escritorio || '-'), celula(num(i.enviadas), 'mi-dir'), celula(num(i.viraramConta), 'mi-dir'),
        celula(i.ultimoEnvio ? window.BJFormato.dataHora(i.ultimoEnvio) : '-'),
      );
      elQuemLista.append(linha);
    });
    elQuemVazio.hidden = d.itens.length > 0;
    const paginas = Math.max(1, Math.ceil(d.total / d.porPagina));
    elQuemPag.hidden = paginas <= 1;
    elQuemPos.textContent = T('master.js.indicacoes.quem_pagina', { pagina: num(d.pagina), total: num(paginas) });
    elQuemAnt.disabled = d.pagina <= 1;
    elQuemProx.disabled = d.pagina >= paginas;
    quemPagina = d.pagina;
  }

  function carregarQuem(pagina) {
    const seq = ++quemSeq;
    const termo = window.BJBusca.termo(elQuemBusca.value, BUSCA_MINIMO);
    const url = raiz.dataset.urlQuem + '?pagina=' + encodeURIComponent(String(pagina)) + (termo ? '&q=' + encodeURIComponent(elQuemBusca.value.trim()) : '');
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { if (seq === quemSeq) desenharQuem(d); })
      .catch(() => { if (seq === quemSeq) avisar(T('master.js.indicacoes.erro_carregar')); });
  }

  const buscarQuem = window.BJBusca.atrasar(() => carregarQuem(1), 300);
  elQuemBusca.addEventListener('input', buscarQuem);
  elQuemAnt.addEventListener('click', () => carregarQuem(quemPagina - 1));
  elQuemProx.addEventListener('click', () => carregarQuem(quemPagina + 1));

  function avisar(texto) {
    elMsg.textContent = texto || '';
    elMsg.hidden = !texto;
  }

  function travar(sim) {
    ocupado = sim;
    elChave.disabled = sim;
    campos.forEach(c => { c.disabled = sim; });
  }

  function desenhar(d) {
    estado = d;
    elResumo.textContent = T('master.js.indicacoes.resumo', { enviadas: num(d.enviadas), hoje: num(d.hoje), conta: num(d.viraramConta) });
    const pausado = !!d.limites.pausadoParaTodos;
    // B1: ligada = convites liberados (o servidor continua guardando "pausado para todos"; só a leitura da chave inverte).
    elChave.setAttribute('aria-checked', pausado ? 'false' : 'true');
    elChave.setAttribute('aria-label', T('master.js.indicacoes.liberados'));
    elChaveTxt.textContent = pausado ? T('master.js.indicacoes.pausados') : T('master.js.indicacoes.liberados');
    campos.forEach(c => { c.value = String(d.limites[c.dataset.miLimite]); });
    elCaixa.removeAttribute('aria-busy');
  }

  function carregar() {
    carregado = true;
    travar(true);
    return fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); travar(false); carregarQuem(1); })
      .catch(() => { carregado = false; avisar(T('master.js.indicacoes.erro_carregar')); });
  }

  function postar(url, corpo) {
    if (ocupado) return;
    travar(true);
    avisar('');
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.indicacoes.erro_salvar'))))))
      .then(d => desenhar(d))
      .catch(e => { avisar(e.message || T('master.js.indicacoes.erro_salvar')); if (estado) desenhar(estado); })
      .finally(() => travar(false));
  }

  elChave.addEventListener('click', () => {
    if (!estado) return;
    postar(raiz.dataset.urlPausa, { pausar: !estado.limites.pausadoParaTodos });
  });

  campos.forEach(c => c.addEventListener('change', () => {
    if (!estado) return;
    const corpo = {};
    campos.forEach(x => { corpo[x.dataset.miLimite] = x.value.trim(); });
    postar(raiz.dataset.urlLimites, corpo);
  }));

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'ind' && !carregado) carregar(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=ind\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
})();
