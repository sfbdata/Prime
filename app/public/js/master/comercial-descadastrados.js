/* BlueJus · Comercial do /master: KPIs e Descadastrados (desenho 05 VS 21.10.26, L245-249, L325, L690-697; spec
   comercial-e-emails §10, itens 9 e 10).
   - KPIs: lê `master_comercial_resumo` quando a seção "com" abre e avisa o cabeçalho da Central pelo evento
     `mc:resumo` ({campanhas, naFila, base, descadastrados}).
   - Descadastrados: abre e fecha pelo evento `mc:descadastrados-abrir` (botão da Central e KPI); lista com busca,
     "Registrar pedido" (prompt do desenho), "Excluir" um por vez (confirm de LGPD do desenho) e CSV.
   As linhas são montadas por DOM, com textContent. */
(function () {
  'use strict';
  const raiz = document.querySelector('[data-mc]');
  const bloco = document.querySelector('[data-mc-dsc]');
  const kpis = document.querySelector('[data-mc-kpis]');
  if (!raiz || !bloco) return;

  const TOKEN = raiz.dataset.token || '';
  const $ = sel => bloco.querySelector(sel);
  const elLinhas = $('[data-mc-dsc-linhas]'), elVazio = $('[data-mc-dsc-vazio]'), elAviso = $('[data-mc-dsc-aviso]');
  const elBusca = $('[data-mc-dsc-busca]'), elCsv = $('[data-mc-dsc-csv]');
  const kpiAbrir = kpis ? kpis.querySelector('[data-mc-kpi-abrir]') : null;
  let busca = '', espera = null, resumoCarregado = false;

  const numero = n => (window.BJFormato ? window.BJFormato.numero(n) : String(n));
  const dataHora = iso => (window.BJFormato ? window.BJFormato.dataHora(iso) : new Date(iso).toLocaleString('pt-BR').slice(0, 16));

  function avisar(texto) {
    elAviso.textContent = texto || '';
    elAviso.hidden = !texto;
  }

  function pedir(url, opcoes) {
    return fetch(url, Object.assign({ headers: { 'Accept': 'application/json' } }, opcoes)).then(r => r.json().catch(() => ({})).then(d => {
      if (!r.ok) throw new Error(d.erro || 'Não foi possível concluir. Tente de novo.');
      return d;
    }));
  }

  function postar(url, corpo) {
    return pedir(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': TOKEN }, body: JSON.stringify(corpo) });
  }

  /* ---------- KPIs ---------- */
  function carregarResumo() {
    resumoCarregado = true;
    return pedir(bloco.dataset.urlResumo).then(r => {
      if (kpis) {
        ['campanhas', 'naFila', 'base', 'descadastrados'].forEach(chave => {
          const valor = kpis.querySelector('[data-mc-kpi="' + chave + '"] [data-mc-kpi-valor]');
          if (valor) valor.textContent = numero(r[chave] || 0);
        });
      }
      document.dispatchEvent(new CustomEvent('mc:resumo', { detail: { campanhas: r.campanhas, naFila: r.naFila, base: r.base, descadastrados: r.descadastrados } }));
    }).catch(() => {});
  }

  /* ---------- Lista ---------- */
  function linha(item) {
    const el = document.createElement('div');
    el.className = 'mc-dsc-linha';
    const email = document.createElement('b'); email.className = 'mc-dsc-email'; email.textContent = item.email; email.title = item.email;
    const em = document.createElement('span'); em.className = 'mc-dsc-em'; em.textContent = dataHora(item.em);
    const motivo = document.createElement('span'); motivo.className = 'mc-dsc-motivo'; motivo.textContent = item.motivo;
    const origem = document.createElement('span'); origem.className = 'mc-dsc-origem'; origem.textContent = item.origem;
    const excluir = document.createElement('button');
    excluir.type = 'button'; excluir.className = 'mc-dsc-excluir'; excluir.textContent = 'Excluir';
    excluir.addEventListener('click', () => reativar(item));
    el.append(email, em, motivo, origem, excluir);

    return el;
  }

  function carregarLista() {
    const itens = [];
    const pagina = n => pedir(bloco.dataset.urlLista + '?' + new URLSearchParams({ q: busca, pagina: String(n) })).then(r => {
      itens.push(...(r.itens || []));
      return itens.length < r.total && (r.itens || []).length ? pagina(n + 1) : itens;
    });

    return pagina(1).then(todos => {
      elLinhas.replaceChildren(...todos.map(linha));
      elVazio.hidden = todos.length > 0;
    }).catch(err => avisar(err.message));
  }

  function recarregar() {
    return Promise.all([carregarLista(), carregarResumo()]);
  }

  function reativar(item) {
    if (!window.confirm('Excluir ' + item.email + ' da lista de descadastrados? Ele volta a poder receber e-mails. Faça isso só se o próprio contato pediu (LGPD).')) return;
    postar(bloco.dataset.urlReativar, { email: item.email, assunto: item.assunto }).then(() => { avisar(''); recarregar(); }).catch(err => avisar(err.message));
  }

  function registrar() {
    const email = String(window.prompt('E-mail que pediu para não receber (por telefone, WhatsApp etc.):', '') || '').trim().toLowerCase();
    if (!email) return;
    postar(bloco.dataset.urlRegistrar, { email }).then(() => { avisar(''); recarregar(); }).catch(err => avisar(err.message));
  }

  /* ---------- Abrir e fechar ---------- */
  function mostrar(aberto) {
    bloco.hidden = !aberto;
    if (kpiAbrir) { kpiAbrir.setAttribute('aria-expanded', String(aberto)); kpiAbrir.classList.toggle('is-aberto', aberto); }
    if (aberto) { avisar(''); carregarLista(); }
  }

  /* O bloco é um só para as duas Centrais (seção Comercial e modo venda, F3h): aberto pelo botão de uma delas, vai
     para logo antes dela (dc L245). O clique é anotado em captura, antes de o JS da Central disparar o evento. */
  let origem = null;
  document.addEventListener('click', e => {
    const gatilho = e.target.closest && e.target.closest('[data-mc-cp-descad], [data-mc-kpi-abrir]');
    if (gatilho) origem = gatilho.closest('.mc[data-modo]');
  }, true);

  function levarPara(secao) {
    const central = secao ? secao.querySelector('[data-mc-campanha]') : null;
    if (central && bloco.nextElementSibling !== central) central.parentNode.insertBefore(bloco, central);
  }

  document.addEventListener('mc:descadastrados-abrir', () => {
    const destino = origem || raiz;
    origem = null;
    const outraSecao = !bloco.hidden && !destino.contains(bloco);
    levarPara(destino);
    mostrar(bloco.hidden || outraSecao);
  });
  if (kpiAbrir) kpiAbrir.addEventListener('click', () => document.dispatchEvent(new CustomEvent('mc:descadastrados-abrir')));
  $('[data-mc-dsc-fechar]').addEventListener('click', () => mostrar(false));
  $('[data-mc-dsc-registrar]').addEventListener('click', registrar);
  elBusca.addEventListener('input', () => {
    clearTimeout(espera);
    espera = setTimeout(() => {
      busca = elBusca.value.trim();
      elCsv.href = bloco.dataset.urlCsv + (busca ? '?' + new URLSearchParams({ q: busca }) : '');
      carregarLista();
    }, 250);
  });

  const painel = document.getElementById('master-painel');
  // O N do botão "Descadastrados" vem do resumo, nas duas seções que têm a Central (com e, no modo venda, ind).
  if (painel) painel.addEventListener('mp:secao', e => { if (e.detail && ['com', 'ind'].includes(e.detail.sec) && !resumoCarregado) carregarResumo(); });
  if (/[#&]sec=(com|ind)\b/.test(location.hash)) carregarResumo();
})();
