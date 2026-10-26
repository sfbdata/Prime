/* BlueJus · Painel do Master, seção "Recompensas" (#sec=recomp), F1
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L306-338 e lógica L854-964;
   spec docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Regras do programa: cada mudança grava as seis regras de uma vez (o servidor recusa o que estiver fora do intervalo
     e a tela volta ao último valor gravado). A recompensa vai como o Master digitou ("10,00"); o servidor lê.
   - Lista de Indicadores: busca e filtros só no navegador, sobre o que o servidor calculou (nada é recalculado aqui).
   - Menu ⋮ (ou botão direito): só "Condição individual" e "Voltar à regra geral". Dar baixa, caixa, extrato e
     Fechamento do mês entram nas fatias seguintes.
   - Textos no catálogo (master.js.recompensas.*, entregue pelo painel com catalogo_js), lidos na hora de usar; números
     por window.BJFormato. Nenhuma regra mora só aqui. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mrc]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const $ = sel => raiz.querySelector(sel);
  const elAviso = $('[data-mrc-aviso]');
  const elKpis = $('[data-mrc-kpis]');
  const elAtivo = $('[data-mrc-ativo]');
  const elPasta = $('[data-mrc-pasta]');
  const elPriv = $('[data-mrc-priv]');
  const elBusca = $('[data-mrc-busca]');
  const elFiltros = $('[data-mrc-filtros]');
  const elLista = $('[data-mrc-lista]');
  const elVazio = $('[data-mrc-vazio]');
  const campos = Array.from(raiz.querySelectorAll('[data-mrc-regra]'));
  const menu = { fundo: $('[data-mrc-menu-fundo]'), caixa: $('[data-mrc-menu]'), titulo: $('[data-mrc-menu-titulo]') };
  const dlg = {
    fundo: $('[data-mrc-dlg-fundo]'), posicao: $('[data-mrc-dlg]'), nome: $('[data-mrc-dlg-nome]'), sub: $('[data-mrc-dlg-sub]'),
    erro: $('[data-mrc-dlg-erro]'), rec: $('[data-mrc-dlg-rec]'), mensal: $('[data-mrc-dlg-mensal]'), salvar: $('[data-mrc-dlg-salvar]'),
    dicaValor: $('[data-mrc-dlg-dica-valor]'), campos: Array.from(raiz.querySelectorAll('[data-mrc-dlg-campo]')),
  };
  // Aspas pelo código (\x22 e \x27): um literal de aspas aqui desalinha o detector de texto fixo (SemTextoFixoNosExtraidos).
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const num = n => window.BJFormato.numero(n);
  const decimal = c => (c / 100).toLocaleString(window.BJFormato.idioma(), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const moeda = c => 'R$ ' + decimal(c);
  // Valor para o campo de texto, no formato que o servidor lê ("1000,50"): sem milhar, com vírgula.
  const campoValor = c => (c == null ? '' : Math.floor(c / 100) + ',' + String(c % 100).padStart(2, '0'));
  const FILTROS = ['todos', 'pagar', 'lib', 'nao', 'priv'];
  const REGRAS_DE_FILTRO = {
    todos: () => true,
    pagar: u => u.liberado && u.saldoCentavos > 0,
    lib: u => u.liberado,
    nao: u => !u.liberado,
    priv: u => u.condicao !== null,
  };
  let estado = null;
  let carregado = false;
  let ocupado = false;
  let filtro = 'todos';
  let alvo = null;

  function avisar(texto, ok) {
    elAviso.textContent = texto || '';
    elAviso.hidden = !texto;
    elAviso.classList.toggle('mrc-aviso-ok', !!ok);
  }

  function travar(sim) {
    ocupado = sim;
    elAtivo.disabled = sim;
    elPasta.disabled = sim;
    campos.forEach(c => { c.disabled = sim; });
  }

  function desenharKpis(k) {
    const itens = [
      [T('master.js.recompensas.kpi.indicadores'), num(k.indicadores)],
      [T('master.js.recompensas.kpi.contas'), num(k.contasAbertas)],
      [T('master.js.recompensas.kpi.liberados'), num(k.liberados)],
      [T('master.js.recompensas.kpi.a_pagar'), moeda(k.aPagarCentavos)],
      [T('master.js.recompensas.kpi.ja_pago'), moeda(k.jaPagoCentavos)],
    ];
    elKpis.innerHTML = itens.map(([r, v]) => '<div class="mrc-kpi"><div class="mrc-kpi-rotulo">' + esc(r) + '</div><b class="mrc-kpi-valor">' + esc(v) + '</b></div>').join('');
    elKpis.removeAttribute('aria-busy');
  }

  function desenharRegras(r) {
    elAtivo.setAttribute('aria-checked', r.ativo ? 'true' : 'false');
    $('[data-mrc-ativo-txt]').textContent = r.ativo ? T('master.js.recompensas.ativo') : T('master.js.recompensas.pausado');
    campos.forEach(c => { c.value = c.dataset.mrcRegra === 'valor' ? campoValor(r.valorCentavos) : String(r[c.dataset.mrcRegra]); });
    elPasta.setAttribute('aria-checked', r.exigePasta ? 'true' : 'false');
    elPasta.querySelector('.bi').className = 'bi ' + (r.exigePasta ? 'bi-check-square-fill' : 'bi-square');
    elPriv.hidden = r.comCondicaoIndividual === 0;
    elPriv.textContent = T('master.js.recompensas.priv_n', { n: num(r.comCondicaoIndividual) });
    elPriv.prepend(Object.assign(document.createElement('i'), { className: 'bi bi-star-fill' }), ' ');
  }

  function desenharFiltros() {
    elFiltros.innerHTML = FILTROS.map(f => '<button type="button" class="mrc-filtro" data-filtro="' + f + '" aria-pressed="' + (f === filtro) + '">' + esc(T('master.js.recompensas.filtro.' + f)) + '</button>').join('');
  }

  function situacao(u) {
    if (u.situacao === 'liberado') return { txt: T('master.js.recompensas.sit.liberado'), ok: true, dica: '' };
    if (u.situacao === 'carencia') {
      const dias = u.condicao && u.condicao.carenciaDias !== null ? u.condicao.carenciaDias : estado.regras.carenciaDias;
      return { txt: T('master.js.recompensas.sit.carencia', { n: num(u.emCarencia) }), ok: false, dica: T('master.js.recompensas.sit_dica_carencia', { n: num(u.emCarencia), dias: num(dias) }) };
    }
    return { txt: T('master.js.recompensas.sit.andamento'), ok: false, dica: '' };
  }

  function dicaDaCondicao(u) {
    const c = u.condicao;
    if (!c) return T('master.js.recompensas.dica_geral');
    if (c.recorrente) return T('master.js.recompensas.dica_recorrente', { valor: moeda(c.mensalCentavos || 0), motivo: c.motivo || '' });
    return c.motivo ? T('master.js.recompensas.dica_priv_motivo', { motivo: c.motivo }) : T('master.js.recompensas.dica_priv');
  }

  function celulaPorConta(u) {
    const c = u.condicao;
    const mensal = c && c.recorrente ? ' ' + T('master.js.recompensas.por_mes', { valor: moeda(c.mensalCentavos || 0) }) : '';
    return '<span title="' + esc(dicaDaCondicao(u)) + '">' + esc(moeda(u.valorCentavos) + (c ? ' ★' : '') + mensal) + '</span>';
  }

  function linha(u, ix) {
    const s = situacao(u);
    const pct = Math.min(100, Math.round(u.ativos / Math.max(1, u.minEscritorios) * 100));
    return '<div class="mrc-linha' + (ix % 2 ? ' mrc-linha-zebra' : '') + '" data-uid="' + u.userId + '" title="' + esc(T('master.js.recompensas.linha_dica')) + '">'
      + '<span class="mrc-usuario"><b class="mrc-nome">' + esc(u.nome) + '</b><span class="mrc-sub">' + esc([u.escritorio, u.email].filter(Boolean).join(' · ')) + '</span></span>'
      + '<span>' + num(u.envios) + '</span><span>' + num(u.contas) + '</span>'
      + '<span><span class="mrc-ativos">' + num(u.ativos) + '/' + num(u.minEscritorios) + '</span><span class="mrc-prog' + (u.liberado ? ' mrc-prog-ok' : '') + '"><span data-w="' + pct + '"></span></span></span>'
      + celulaPorConta(u)
      + '<b class="mrc-saldo">' + esc(moeda(u.saldoCentavos)) + '</b><span>' + esc(moeda(u.pagoCentavos)) + '</span>'
      + '<span class="mrc-sit' + (s.ok ? ' mrc-sit-ok' : '') + '" title="' + esc(s.dica) + '">' + esc(s.txt) + '</span>'
      + '<span class="mrc-acoes"><button type="button" class="mrc-ajustar" data-acao="condicao" title="' + esc(T('master.js.recompensas.ajustar_dica')) + '"><i class="bi bi-star" aria-hidden="true"></i> ' + esc(T('master.js.recompensas.ajustar')) + '</button>'
      + '<button type="button" class="mrc-mais" data-acao="menu" title="' + esc(T('master.js.recompensas.mais_opcoes')) + '" aria-label="' + esc(T('master.js.recompensas.mais_opcoes')) + '" aria-haspopup="menu"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button></span>'
      + '</div>';
  }

  function desenharLista() {
    const q = elBusca.value.trim().toLowerCase();
    const visiveis = estado.indicadores.filter(u => REGRAS_DE_FILTRO[filtro](u) && (!q || [u.nome, u.email, u.escritorio].join(' ').toLowerCase().includes(q)));
    elVazio.hidden = visiveis.length > 0;
    elLista.innerHTML = visiveis.map(linha).join('');
    elLista.querySelectorAll('[data-w]').forEach(el => { el.style.width = el.dataset.w + '%'; });
  }

  function desenhar(d) {
    estado = d;
    desenharKpis(d.kpis);
    desenharRegras(d.regras);
    desenharFiltros();
    desenharLista();
  }

  function pedir(url, metodo, corpo) {
    const opcoes = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': TOKEN } };
    if (corpo) { opcoes.headers['Content-Type'] = 'application/json'; opcoes.body = JSON.stringify(corpo); }
    return fetch(url, opcoes)
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.recompensas.erro_salvar'))))));
  }

  function carregar() {
    carregado = true;
    travar(true);
    return fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); travar(false); })
      .catch(() => { carregado = false; avisar(T('master.js.recompensas.erro_carregar')); });
  }

  function gravarRegras(mudanca) {
    if (!estado || ocupado) return;
    const corpo = Object.assign({
      ativo: estado.regras.ativo, exigePasta: estado.regras.exigePasta,
    }, mudanca);
    campos.forEach(c => { corpo[c.dataset.mrcRegra] = c.value.trim(); });
    travar(true);
    avisar('');
    pedir(raiz.dataset.urlRegras, 'POST', corpo)
      .then(d => { desenhar(d); avisar(T('master.js.recompensas.regras_salvas'), true); })
      .catch(e => { avisar(e.message || T('master.js.recompensas.erro_salvar')); desenhar(estado); })
      .finally(() => travar(false));
  }

  function urlDaCondicao(id) {
    return raiz.dataset.urlCondicao.replace('/usuarios/0/', '/usuarios/' + id + '/');
  }

  function indicador(id) {
    return estado && estado.indicadores.find(u => u.userId === id);
  }

  /* Menu ⋮ */
  function fecharMenu() {
    menu.fundo.hidden = true;
    menu.caixa.hidden = true;
  }

  function abrirMenu(id, x, y) {
    const u = indicador(id);
    if (!u) return;
    alvo = id;
    menu.titulo.textContent = u.nome;
    menu.fundo.hidden = false;
    menu.caixa.hidden = false;
    menu.caixa.style.left = Math.max(8, Math.min(x, window.innerWidth - 270)) + 'px';
    menu.caixa.style.top = Math.max(8, Math.min(y, window.innerHeight - 140)) + 'px';
  }

  /* Janela "Condição individual do indicador" */
  const campoDlg = nome => dlg.campos.find(c => c.dataset.mrcDlgCampo === nome);

  function alternarRecorrente(sim) {
    dlg.rec.setAttribute('aria-checked', sim ? 'true' : 'false');
    dlg.rec.querySelector('.bi').className = 'bi ' + (sim ? 'bi-check-square-fill' : 'bi-square');
    dlg.mensal.hidden = !sim;
  }

  function avisarNaJanela(texto) {
    dlg.erro.textContent = texto || '';
    dlg.erro.hidden = !texto;
  }

  function abrirJanela(id) {
    const u = indicador(id);
    if (!u) return;
    const c = u.condicao || { valorCentavos: null, minEscritorios: null, carenciaDias: null, recorrente: false, mensalCentavos: null, motivo: null };
    alvo = id;
    dlg.nome.textContent = u.nome;
    dlg.sub.textContent = [u.escritorio, u.email].filter(Boolean).join(' · ');
    dlg.dicaValor.textContent = T('master.js.recompensas.vazio_geral', { valor: moeda(estado.regras.valorCentavos) });
    campoDlg('valor').value = campoValor(c.valorCentavos);
    campoDlg('minEscritorios').value = c.minEscritorios === null ? '' : String(c.minEscritorios);
    campoDlg('carenciaDias').value = c.carenciaDias === null ? '' : String(c.carenciaDias);
    campoDlg('minEscritorios').placeholder = String(estado.regras.minEscritorios);
    campoDlg('carenciaDias').placeholder = String(estado.regras.carenciaDias);
    campoDlg('mensal').value = campoValor(c.mensalCentavos);
    campoDlg('motivo').value = c.motivo || '';
    alternarRecorrente(c.recorrente);
    avisarNaJanela('');
    fecharMenu();
    dlg.fundo.hidden = false;
    dlg.posicao.hidden = false;
    campoDlg('valor').focus();
  }

  function fecharJanela() {
    dlg.fundo.hidden = true;
    dlg.posicao.hidden = true;
  }

  function salvarCondicao() {
    const u = indicador(alvo);
    if (!u || ocupado) return;
    const recorrente = dlg.rec.getAttribute('aria-checked') === 'true';
    const corpo = {
      valor: campoDlg('valor').value.trim(), minEscritorios: campoDlg('minEscritorios').value.trim(),
      carenciaDias: campoDlg('carenciaDias').value.trim(), recorrente, mensal: recorrente ? campoDlg('mensal').value.trim() : '',
      motivo: campoDlg('motivo').value.trim(),
    };
    ocupado = true;
    dlg.salvar.disabled = true;
    avisarNaJanela('');
    pedir(urlDaCondicao(u.userId), 'POST', corpo)
      .then(d => { fecharJanela(); desenhar(d); avisar(T('master.js.recompensas.salvo', { nome: u.nome }), true); })
      .catch(e => avisarNaJanela(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; dlg.salvar.disabled = false; });
  }

  function voltarParaAGeral() {
    const u = indicador(alvo);
    fecharMenu();
    if (!u || ocupado) return;
    ocupado = true;
    avisar('');
    pedir(urlDaCondicao(u.userId), 'DELETE')
      .then(d => { desenhar(d); avisar(T('master.js.recompensas.voltou', { nome: u.nome }), true); })
      .catch(e => avisar(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; });
  }

  const idDaLinha = el => { const l = el.closest('[data-uid]'); return l ? Number(l.dataset.uid) : null; };

  elAtivo.addEventListener('click', () => { if (estado) gravarRegras({ ativo: !estado.regras.ativo }); });
  elPasta.addEventListener('click', () => { if (estado) gravarRegras({ exigePasta: !estado.regras.exigePasta }); });
  campos.forEach(c => c.addEventListener('change', () => gravarRegras({})));
  elPriv.addEventListener('click', () => { filtro = 'priv'; desenharFiltros(); desenharLista(); });
  elBusca.addEventListener('input', () => { if (estado) desenharLista(); });
  elFiltros.addEventListener('click', e => {
    const b = e.target.closest('[data-filtro]');
    if (!b || !estado) return;
    filtro = b.dataset.filtro;
    desenharFiltros();
    desenharLista();
  });
  elLista.addEventListener('click', e => {
    const b = e.target.closest('[data-acao]');
    const id = b ? idDaLinha(b) : null;
    if (id === null) return;
    if (b.dataset.acao === 'condicao') { abrirJanela(id); return; }
    const r = b.getBoundingClientRect();
    abrirMenu(id, r.right - 260, r.bottom + 4);
  });
  elLista.addEventListener('contextmenu', e => {
    const id = idDaLinha(e.target);
    if (id === null) return;
    e.preventDefault();
    abrirMenu(id, e.clientX, e.clientY);
  });
  menu.fundo.addEventListener('click', fecharMenu);
  menu.fundo.addEventListener('contextmenu', e => { e.preventDefault(); fecharMenu(); });
  menu.caixa.querySelector('[data-mrc-menu-acao="condicao"]').addEventListener('click', () => abrirJanela(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="geral"]').addEventListener('click', voltarParaAGeral);
  dlg.fundo.addEventListener('click', fecharJanela);
  raiz.querySelectorAll('[data-mrc-dlg-fechar]').forEach(b => b.addEventListener('click', fecharJanela));
  dlg.rec.addEventListener('click', () => alternarRecorrente(dlg.rec.getAttribute('aria-checked') !== 'true'));
  dlg.salvar.addEventListener('click', salvarCondicao);
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    fecharMenu();
    fecharJanela();
  });

  // Textos fixos do menu vêm do catálogo na hora de abrir a seção (o BJ_T só existe no fim da página).
  function textosDoMenu() {
    menu.caixa.querySelector('[data-mrc-menu-txt="condicao"]').textContent = T('master.js.recompensas.menu_condicao');
    menu.caixa.querySelector('[data-mrc-menu-txt="geral"]').textContent = T('master.js.recompensas.menu_geral');
  }

  function abrir() {
    textosDoMenu();
    carregar();
  }

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'recomp' && !carregado) abrir(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=recomp\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) abrir(); });
    else abrir();
  }
})();
