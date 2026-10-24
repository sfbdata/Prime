/* BlueJus · Painel do Master, seção "Central de Notificações"
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, template L309-322 e lógica
   nfVals L724-749; spec docs/specs/master-central-de-notificacoes.md).
   - Carrega a caixa na primeira vez que a seção "notif" abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Abas, busca e página vão ao servidor (GET); marcar, abrir e responder são POST JSON com X-CSRF-Token.
   - O texto das mensagens vem de fora (visitante da tela de entrada, autor de sugestão): tudo entra por textContent,
     nunca por innerHTML.
   - Textos no catálogo (master.js.notificacoes.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora
     de usar; datas por window.BJFormato. O rótulo do tipo, o remetente, o assunto e o texto vêm do servidor.
   Nenhuma regra mora só aqui: o estado é por Master no servidor, e o servidor recusa resposta a alerta. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-nf]');
  if (!raiz) return;

  const URL_LISTAR = raiz.dataset.urlListar;
  const URL_MARCAR = raiz.dataset.urlMarcar;
  const URL_ABRIR = raiz.dataset.urlAbrir;
  const URL_RESPONDER = raiz.dataset.urlResponder;
  const TOKEN = raiz.dataset.token || '';
  const $ = sel => raiz.querySelector(sel);
  const elLista = $('[data-nf-lista]');
  const elVazio = $('[data-nf-vazio]');
  const elMsg = $('[data-nf-msg]');
  const elAcoes = $('[data-nf-acoes]');
  const elVer = $('[data-nf-ver]');
  const elBusca = $('[data-nf-busca]');

  // Rótulos e cores de cada tipo: o TP do desenho (L727). As cores moram no master.css (.nf-tag-<tipo>).
  const TIPOS = ['sug', 'prob', 'falta', 'alerta', 'sup', 'fale'];
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const S = { aba: 'rec', q: '', pagina: 1, sel: new Set(), dados: null, ver: null, carregado: false, ocupado: false };

  /* ---------- utilidades ---------- */
  function el(tag, classe, texto) {
    const n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto != null) n.textContent = String(texto);
    return n;
  }
  function icone(classe) { const i = el('i', 'bi ' + classe); i.setAttribute('aria-hidden', 'true'); return i; }
  // Hora se hoje, senão "dd mmm" (desenho, função em).
  function dataCurta(iso) {
    const d = new Date(iso);
    if (isNaN(d)) return '';
    if (d.toDateString() === new Date().toDateString()) return window.BJFormato.hora(d);
    return d.toLocaleDateString(window.BJFormato.idioma(), { day: '2-digit', month: 'short' }).replace('.', '');
  }
  const dataHora = iso => window.BJFormato.dataHora(iso);
  const iniciais = nome => String(nome || '').split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
  const deCompleto = m => m.de + (m.escritorio ? ' · ' + m.escritorio : '');
  const classeDoTipo = tipo => 'nf-tag nf-tag-' + (TIPOS.includes(tipo) ? tipo : 'sug');

  function avisar(texto) { elMsg.textContent = texto; elMsg.hidden = !texto; }

  function postar(url, corpo) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo || {}),
    }).then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.notificacoes.erro_salvar'))))));
  }
  const urlDa = (base, m) => base.replace('xorigemx', encodeURIComponent(m.origem)).replace(/\/0\//, '/' + encodeURIComponent(m.id) + '/');

  /* ---------- caixa ---------- */
  function carregar() {
    const p = new URLSearchParams({ aba: S.aba, q: S.q, pagina: String(S.pagina) });
    return fetch(URL_LISTAR + '?' + p.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { S.carregado = true; S.dados = d; avisar(''); desenhar(); })
      .catch(() => avisar(T('master.js.notificacoes.erro_carregar')));
  }

  function desenhar() {
    const d = S.dados;
    if (!d) return;
    const chaves = new Set(d.itens.map(m => m.chave));
    S.sel.forEach(c => { if (!chaves.has(c)) S.sel.delete(c); });
    desenharAbas(d.abas);
    desenharBarra(d);
    elLista.replaceChildren(...d.itens.map(linha));
    elLista.removeAttribute('aria-busy');
    elVazio.hidden = d.itens.length > 0;
  }

  function desenharAbas(contagens) {
    raiz.querySelectorAll('[data-nf-aba]').forEach(b => {
      const on = b.dataset.nfAba === S.aba;
      b.classList.toggle('on', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      const n = b.querySelector('[data-nf-n]');
      if (n) { const v = contagens[b.dataset.nfAba] || 0; n.textContent = String(v); n.hidden = v <= 0; }
    });
  }

  function desenharBarra(d) {
    const nSel = S.sel.size, nPag = d.itens.length;
    $('[data-nf-sel-ic]').className = 'bi ' + (nSel && nSel === nPag ? 'bi-check-square-fill' : nSel ? 'bi-dash-square' : 'bi-square');
    elAcoes.hidden = nSel === 0;
    $('[data-nf-sel-n]').textContent = String(nSel);
    const arq = S.aba === 'arq';
    $('[data-nf-arq]').dataset.nfLote = arq ? 'desarquivar' : 'arquivar';
    $('[data-nf-arq]').title = arq ? T('master.js.notificacoes.desarquivar') : T('master.js.notificacoes.arquivar');
    $('[data-nf-arq-txt]').textContent = arq ? T('master.js.notificacoes.desarquivar') : T('master.js.notificacoes.arquivar');
    const ini = (d.pagina - 1) * d.porPagina;
    $('[data-nf-pag]').textContent = d.total ? T('master.js.notificacoes.pagina', { ini: ini + 1, fim: Math.min(ini + d.porPagina, d.total), total: d.total }) : T('master.js.notificacoes.pagina_vazia');
    const paginas = Math.ceil(d.total / d.porPagina);
    $('[data-nf-paginas]').hidden = paginas <= 1;
    $('[data-nf-pag-ant]').disabled = d.pagina <= 1;
    $('[data-nf-pag-prox]').disabled = d.pagina >= paginas;
  }

  function linha(m) {
    const sel = S.sel.has(m.chave);
    const l = el('div', 'nf-linha' + (m.lida ? ' nf-lida' : '') + (sel ? ' nf-sel' : ''));
    l.dataset.nfChave = m.chave;
    l.tabIndex = 0;
    const bSel = el('button', 'nf-l-btn');
    bSel.type = 'button'; bSel.dataset.nfSel = ''; bSel.setAttribute('aria-label', T('master.js.notificacoes.selecionar'));
    bSel.append(icone(sel ? 'bi-check-square-fill' : 'bi-square'));
    const bEst = el('button', 'nf-l-btn nf-l-estrela' + (m.estrela ? ' on' : ''));
    bEst.type = 'button'; bEst.dataset.nfEstrela = ''; bEst.setAttribute('aria-label', m.estrela ? T('master.js.notificacoes.tirar_estrela') : T('master.js.notificacoes.marcar_estrela'));
    bEst.append(icone(m.estrela ? 'bi-star-fill' : 'bi-star'));
    const de = el('span', 'nf-l-de', m.de);
    const meio = el('span', 'nf-l-meio');
    const resumo = el('span', 'nf-l-resumo');
    resumo.append(el('b', 'nf-l-ass', m.assunto), el('span', 'nf-l-txt', ' · ' + m.texto));
    meio.append(el('span', classeDoTipo(m.tipo), m.rotulo), resumo);
    l.append(bSel, bEst, de, meio);
    if (m.respondida) { const r = icone('bi-reply-fill nf-l-resp'); r.title = T('master.js.notificacoes.respondida'); l.append(r); }
    l.append(el('span', 'nf-l-data', dataCurta(m.em)));
    return l;
  }

  const itemDa = chave => (S.dados ? S.dados.itens.find(m => m.chave === chave) : null);

  // Resolve true quando o servidor aceitou a marcação; false quando nem houve POST ou ele recusou.
  function marcar(acao, chaves) {
    if (S.ocupado || !chaves.length) return Promise.resolve(false);
    S.ocupado = true;
    return postar(URL_MARCAR, { acao, chaves })
      .then(() => { S.ocupado = false; carregar(); return true; })
      .catch(e => { S.ocupado = false; avisar(e.message); return false; });
  }

  /* ---------- mensagem aberta ---------- */
  function abrir(m) {
    postar(urlDa(URL_ABRIR, m), {})
      .then(d => { S.ver = d; desenharVer(); carregar(); })
      .catch(e => avisar(e.message));
  }

  function desenharVer() {
    const m = S.ver.mensagem;
    $('[data-nf-ver-em]').textContent = dataHora(m.em);
    $('[data-nf-ver-ass]').textContent = m.assunto;
    $('[data-nf-ver-tag]').textContent = m.rotulo;
    $('[data-nf-ver-ini]').textContent = iniciais(m.de);
    $('[data-nf-ver-de]').textContent = deCompleto(m);
    $('[data-nf-ver-txt]').textContent = m.texto;
    // Só o alerta do Indicar o BlueJus leva às Indicações; os outros alertas (consumo, exclusão de conta) não.
    $('[data-nf-ir-ind]').hidden = m.origem !== 'alerta_indicacao';
    $('[data-nf-est-um-ic]').className = 'bi ' + (m.estrela ? 'bi-star-fill' : 'bi-star');
    $('[data-nf-arq-um]').title = m.arquivada ? T('master.js.notificacoes.desarquivar') : T('master.js.notificacoes.arquivar');
    $('[data-nf-arq-um]').setAttribute('aria-label', m.arquivada ? T('master.js.notificacoes.desarquivar') : T('master.js.notificacoes.arquivar'));
    desenharHistorico(S.ver.historico);
    const resp = $('[data-nf-resp]');
    resp.hidden = !m.podeResponder;
    $('[data-nf-resp-para]').textContent = T('master.js.notificacoes.responder_a', { quem: deCompleto(m) });
    $('[data-nf-resp-txt]').placeholder = m.email ? T('master.js.notificacoes.dica_email', { email: m.email }) : T('master.js.notificacoes.dica_central');
    $('[data-nf-resp-msg]').hidden = true;
    elVer.hidden = false;
  }

  function desenharHistorico(historico) {
    const h = $('[data-nf-hist]');
    h.replaceChildren(...historico.map(r => {
      const item = el('div', 'nf-hist-item');
      const corpo = el('span', 'nf-hist-corpo');
      corpo.append(el('b', 'nf-hist-quem', quemRespondeu(r)), el('span', 'nf-hist-em', ' · ' + dataHora(r.em)), el('div', 'nf-hist-txt', r.texto));
      item.append(icone('bi-reply-fill nf-hist-ic'), corpo);
      return item;
    }));
    h.hidden = historico.length === 0;
  }

  // A resposta dada antes pela página do Contribua vem com o canal `contribua`.
  function quemRespondeu(r) {
    if (r.canal === 'contribua') return T('master.js.notificacoes.respondeu_contribua', { nome: r.porNome });
    return r.minha ? T('master.js.notificacoes.voce_respondeu') : T('master.js.notificacoes.respondeu', { nome: r.porNome });
  }

  function fecharVer() { S.ver = null; elVer.hidden = true; $('[data-nf-resp-txt]').value = ''; }

  function responder() {
    const txt = $('[data-nf-resp-txt]');
    const texto = txt.value.trim();
    if (!texto || !S.ver || S.ocupado) return;
    S.ocupado = true;
    const botao = $('[data-nf-enviar]');
    botao.disabled = true;
    postar(urlDa(URL_RESPONDER, S.ver.mensagem), { texto })
      .then(r => {
        S.ver.historico.push(r);
        S.ver.mensagem.respondida = true;
        txt.value = '';
        desenharHistorico(S.ver.historico);
        mostrarNaResposta(r.canal === 'email' ? T('master.js.notificacoes.enviada_email') : T('master.js.notificacoes.enviada_central'));
        carregar();
      })
      .catch(e => mostrarNaResposta(e.message))
      .finally(() => { S.ocupado = false; botao.disabled = false; });
  }
  function mostrarNaResposta(texto) { const m = $('[data-nf-resp-msg]'); m.textContent = texto; m.hidden = false; }

  /* ---------- eventos ---------- */
  elLista.addEventListener('click', e => {
    const l = e.target.closest('[data-nf-chave]');
    if (!l) return;
    const m = itemDa(l.dataset.nfChave);
    if (!m) return;
    if (e.target.closest('[data-nf-sel]')) {
      if (S.sel.has(m.chave)) S.sel.delete(m.chave); else S.sel.add(m.chave);
      desenhar();
      return;
    }
    if (e.target.closest('[data-nf-estrela]')) { marcar(m.estrela ? 'sem_estrela' : 'estrela', [m.chave]); return; }
    abrir(m);
  });
  elLista.addEventListener('keydown', e => {
    if (e.key !== 'Enter' || e.target.closest('button')) return;
    const l = e.target.closest('[data-nf-chave]');
    const m = l && itemDa(l.dataset.nfChave);
    if (m) abrir(m);
  });
  $('[data-nf-sel-todos]').addEventListener('click', () => {
    if (!S.dados) return;
    const todas = S.dados.itens.length > 0 && S.sel.size === S.dados.itens.length;
    S.sel = todas ? new Set() : new Set(S.dados.itens.map(m => m.chave));
    desenhar();
  });
  $('[data-nf-atualizar]').addEventListener('click', () => carregar());
  raiz.querySelectorAll('[data-nf-lote]').forEach(b => b.addEventListener('click', () => {
    const chaves = Array.from(S.sel);
    S.sel = new Set();
    marcar(b.dataset.nfLote, chaves);
  }));
  raiz.querySelectorAll('[data-nf-aba]').forEach(b => b.addEventListener('click', () => {
    S.aba = b.dataset.nfAba; S.pagina = 1; S.sel = new Set(); fecharVer(); carregar();
  }));
  let espera = null;
  elBusca.addEventListener('input', () => {
    clearTimeout(espera);
    espera = setTimeout(() => { S.q = elBusca.value.trim(); S.pagina = 1; S.sel = new Set(); carregar(); }, 300);
  });
  $('[data-nf-pag-ant]').addEventListener('click', () => { if (S.pagina > 1) { S.pagina -= 1; S.sel = new Set(); carregar(); } });
  $('[data-nf-pag-prox]').addEventListener('click', () => { S.pagina += 1; S.sel = new Set(); carregar(); });

  raiz.querySelectorAll('[data-nf-fechar]').forEach(b => b.addEventListener('click', fecharVer));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !elVer.hidden) fecharVer(); });
  $('[data-nf-arq-um]').addEventListener('click', () => {
    if (!S.ver) return;
    const m = S.ver.mensagem;
    fecharVer();
    marcar(m.arquivada ? 'desarquivar' : 'arquivar', [m.chave]);
  });
  $('[data-nf-est-um]').addEventListener('click', () => {
    if (!S.ver) return;
    const m = S.ver.mensagem;
    const nova = !m.estrela;
    marcar(nova ? 'estrela' : 'sem_estrela', [m.chave]).then(ok => {
      if (!ok || !S.ver || S.ver.mensagem !== m) return;
      m.estrela = nova;
      $('[data-nf-est-um-ic]').className = 'bi ' + (nova ? 'bi-star-fill' : 'bi-star');
    });
  });
  // "Abrir Indicações": já no painel, troca a seção pelo botão dela (o painel.js lê o hash só ao carregar).
  $('[data-nf-ir-ind]').addEventListener('click', e => {
    const botao = document.querySelector('#mp-secs [data-sec="ind"]');
    if (!botao) return;
    e.preventDefault();
    fecharVer();
    history.replaceState(null, '', e.currentTarget.getAttribute('href'));
    botao.click();
  });
  $('[data-nf-enviar]').addEventListener('click', responder);

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'notif' && !S.carregado) carregar(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=notif\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!S.carregado) carregar(); });
    else carregar();
  }
})();
