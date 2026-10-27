/* BlueJus · Painel do Master, seção "Recompensas" (#sec=recomp), F1 a F3
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L306-338 e lógica L854-964;
   spec docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Regras do programa: cada mudança grava as seis regras de uma vez (o servidor recusa o que estiver fora do intervalo
     e a tela volta ao último valor gravado). A recompensa vai como o Master digitou ("10,00"); o servidor lê.
   - Lista de Indicadores: busca e filtros só no navegador, sobre o que o servidor calculou (nada é recalculado aqui).
   - Menu ⋮ (ou botão direito): "Condição individual", "Lançar crédito ou estorno", "Ver extrato deste indicador",
     "Baixar extrato (CSV)" e "Usar a regra geral". Dar baixa, caixa e Fechamento do mês entram nas fatias seguintes.
   - Antifraude (F3): a situação vem do servidor (Bloqueado, Em análise, Alerta, Liberado...); o title mostra os motivos
     agregados (sem nome nem e-mail do indicado). O menu ganha "Revisar suspeitas e aprovar" (só com suspeita, confirma
     com a quantidade e os motivos), "Bloquear/Desbloquear recompensa" e "Apagar da lista" (digitar APAGAR; o servidor
     confere a palavra de novo). Nada de regra mora aqui.
   - Extrato (F2): os 60 mais recentes vêm do servidor (filtrados por indicador quando há chip); o lançamento leva um
     UUID gerado ao abrir o diálogo, então duplo clique ou reenvio não lança duas vezes (o servidor garante).
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
  const elExtLista = $('[data-mrc-ext-lista]');
  const elExtVazio = $('[data-mrc-ext-vazio]');
  const elExtChip = $('[data-mrc-ext-chip]');
  const campos = Array.from(raiz.querySelectorAll('[data-mrc-regra]'));
  const menu = { fundo: $('[data-mrc-menu-fundo]'), caixa: $('[data-mrc-menu]'), titulo: $('[data-mrc-menu-titulo]') };
  const dlg = {
    fundo: $('[data-mrc-dlg-fundo]'), posicao: $('[data-mrc-dlg]'), nome: $('[data-mrc-dlg-nome]'), sub: $('[data-mrc-dlg-sub]'),
    erro: $('[data-mrc-dlg-erro]'), rec: $('[data-mrc-dlg-rec]'), mensal: $('[data-mrc-dlg-mensal]'), salvar: $('[data-mrc-dlg-salvar]'),
    dicaValor: $('[data-mrc-dlg-dica-valor]'), campos: Array.from(raiz.querySelectorAll('[data-mrc-dlg-campo]')),
  };
  const apg = {
    fundo: $('[data-mrc-apg-fundo]'), posicao: $('[data-mrc-apg]'), nome: $('[data-mrc-apg-nome]'), sub: $('[data-mrc-apg-sub]'),
    palavra: $('[data-mrc-apg-palavra]'), erro: $('[data-mrc-apg-erro]'), enviar: $('[data-mrc-apg-enviar]'),
  };
  const lan = {
    fundo: $('[data-mrc-lan-fundo]'), posicao: $('[data-mrc-lan]'), nome: $('[data-mrc-lan-nome]'), sub: $('[data-mrc-lan-sub]'),
    valor: $('[data-mrc-lan-valor]'), obs: $('[data-mrc-lan-obs]'), erro: $('[data-mrc-lan-erro]'), enviar: $('[data-mrc-lan-enviar]'),
  };
  const bai = {
    fundo: $('[data-mrc-bai-fundo]'), posicao: $('[data-mrc-bai]'), nome: $('[data-mrc-bai-nome]'), sub: $('[data-mrc-bai-sub]'),
    valor: $('[data-mrc-bai-valor]'), dica: $('[data-mrc-bai-dica]'), formas: $('[data-mrc-bai-formas]'), arquivo: $('[data-mrc-bai-arquivo]'),
    anexoTxt: $('[data-mrc-bai-anexo-txt]'), anexoOk: $('[data-mrc-bai-anexo-ok]'), obs: $('[data-mrc-bai-obs]'),
    erro: $('[data-mrc-bai-erro]'), enviar: $('[data-mrc-bai-enviar]'),
  };
  const rcb = {
    fundo: $('[data-mrc-rcb-fundo]'), posicao: $('[data-mrc-rcb]'), indicador: $('[data-mrc-rcb-indicador]'), sub: $('[data-mrc-rcb-sub]'),
    nome: $('[data-mrc-rcb-nome]'), documento: $('[data-mrc-rcb-documento]'), pix: $('[data-mrc-rcb-pix]'),
    erro: $('[data-mrc-rcb-erro]'), enviar: $('[data-mrc-rcb-enviar]'),
    sugestao: $('[data-mrc-rcb-sugestao]'), sugestaoValor: $('[data-mrc-rcb-sugestao-valor]'), sugestaoUsar: $('[data-mrc-rcb-sugestao-usar]'),
  };
  const FORMAS = ['pix', 'transferencia', 'desconto_na_mensalidade', 'credito_de_espaco'];
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
    pagar: u => u.liberado && u.saldoCentavos > 0 && !u.bloqueado,
    lib: u => u.liberado,
    nao: u => !u.liberado,
    priv: u => u.condicao !== null,
  };
  let estado = null;
  let carregado = false;
  let ocupado = false;
  let filtro = 'todos';
  let alvo = null;
  let extratoDe = null;
  let chave = '';
  let forma = 'pix';

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

  // "mesmo domínio de e-mail: 2 · mesmo CNPJ: 1": só motivos agregados, nunca quem é a conta.
  function dicaDasSuspeitas(u) {
    return Object.entries(u.motivosDeSuspeita).map(([m, n]) => T('master.js.recompensas.motivo.' + m) + ': ' + num(n)).join(' · ');
  }

  function situacao(u) {
    if (u.situacao === 'bloqueado') return { txt: T('master.js.recompensas.sit.bloqueado'), ok: false, alerta: true, dica: '' };
    if (u.situacao === 'analise') {
      const txt = T(u.suspeitas > 1 ? 'master.js.recompensas.sit.analise_varias' : 'master.js.recompensas.sit.analise_uma', { n: num(u.suspeitas) });
      return { txt, ok: false, alerta: true, dica: dicaDasSuspeitas(u) };
    }
    if (u.situacao === 'alerta') return { txt: T('master.js.recompensas.sit.alerta', { n: num(u.emSeteDias) }), ok: false, alerta: true, dica: T('master.js.recompensas.sit_dica_alerta') };
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
      + '<span class="mrc-sit' + (s.ok ? ' mrc-sit-ok' : '') + (s.alerta ? ' mrc-sit-alerta' : '') + '" title="' + esc(s.dica) + '">' + esc(s.txt) + '</span>'
      + '<span class="mrc-acoes">' + (REGRAS_DE_FILTRO.pagar(u) ? '<button type="button" class="mrc-pagar" data-acao="baixa">' + esc(T('master.js.recompensas.pagar')) + '</button>' : '')
      + '<button type="button" class="mrc-ajustar" data-acao="condicao" title="' + esc(T('master.js.recompensas.ajustar_dica')) + '"><i class="bi bi-star" aria-hidden="true"></i> ' + esc(T('master.js.recompensas.ajustar')) + '</button>'
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
    if (corpo instanceof FormData) opcoes.body = corpo;
    else if (corpo) { opcoes.headers['Content-Type'] = 'application/json'; opcoes.body = JSON.stringify(corpo); }
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

  const urlDoUsuario = (base, id) => base.replace('/usuarios/0/', '/usuarios/' + id + '/');
  const urlDaCondicao = id => urlDoUsuario(raiz.dataset.urlCondicao, id);

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
    menu.caixa.querySelector('[data-mrc-menu-acao="aprovar"]').hidden = u.suspeitas === 0;
    menu.caixa.querySelector('[data-mrc-menu-txt="bloquear"]').textContent = T(u.bloqueado ? 'master.js.recompensas.menu_desbloquear' : 'master.js.recompensas.menu_bloquear');
    menu.caixa.querySelector('[data-mrc-menu-bloqueio-ic]').className = 'bi ' + (u.bloqueado ? 'bi-unlock' : 'bi-slash-circle');
    menu.fundo.hidden = false;
    menu.caixa.hidden = false;
    menu.caixa.style.left = Math.max(8, Math.min(x, window.innerWidth - 270)) + 'px';
    menu.caixa.style.top = Math.max(8, Math.min(y, window.innerHeight - 420)) + 'px';
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

  /* Extrato (F2) */
  const DATA_HORA = d => window.BJFormato.dataHora(d);

  const urlDoLancamento = id => raiz.dataset.urlComprovante.replace('/lancamentos/0/', '/lancamentos/' + id + '/');

  function linhaDoExtrato(e, ix) {
    const pago = e.tipo === 'pago';
    const estorno = !pago && e.valorCentavos < 0;
    const valor = (estorno ? '-' : '') + moeda(Math.abs(e.valorCentavos));
    const anexo = e.temComprovante
      ? '<a class="mrc-extrato-comprovante" href="' + esc(urlDoLancamento(e.id)) + '" target="_blank" rel="noopener" title="' + esc(T('master.js.recompensas.comprovante')) + '" aria-label="' + esc(T('master.js.recompensas.comprovante')) + '"><i class="bi bi-paperclip" aria-hidden="true"></i></a>'
      : '';
    return '<div class="mrc-extrato-linha' + (ix % 2 ? ' mrc-extrato-linha-zebra' : '') + '"' + (e.observacao ? ' title="' + esc(e.observacao) + '"' : '') + '>'
      + '<span class="mrc-extrato-data">' + esc(DATA_HORA(e.em)) + '</span>'
      + '<b title="' + esc(e.email || '') + '">' + esc(e.nome || T('master.js.recompensas.extrato_removido')) + '</b>'
      + '<span class="mrc-extrato-tipo' + (estorno ? ' mrc-extrato-tipo-estorno' : '') + '">' + esc(T(pago ? 'master.js.recompensas.tipo.pago' : estorno ? 'master.js.recompensas.tipo.estorno' : 'master.js.recompensas.tipo.credito')) + '</span>'
      + '<b>' + esc(valor) + '</b>'
      + '<span>' + (e.forma ? esc(T('master.js.recompensas.forma.' + e.forma)) : '') + anexo + '</span></div>';
  }

  function desenharExtrato(lista) {
    elExtVazio.hidden = lista.length > 0;
    elExtLista.innerHTML = lista.map(linhaDoExtrato).join('');
    const u = extratoDe === null ? null : indicador(extratoDe);
    elExtChip.hidden = extratoDe === null;
    if (extratoDe !== null) elExtChip.querySelector('[data-mrc-ext-nome]').textContent = u ? u.nome : String(extratoDe);
  }

  function carregarExtrato() {
    const url = raiz.dataset.urlExtrato + (extratoDe === null ? '' : '?usuario=' + extratoDe);
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => desenharExtrato(d.extrato))
      .catch(() => avisar(T('master.js.recompensas.extrato_erro')));
  }

  function filtrarExtrato(id) {
    extratoDe = id;
    fecharMenu();
    carregarExtrato();
  }

  function baixarCsv(id) {
    fecharMenu();
    window.location.assign(raiz.dataset.urlCsv + '?usuario=' + id);
  }

  /* Janela "Lançar crédito ou estorno" */
  function novaChave() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return Array.from(window.crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  }

  // Só para a mensagem de sucesso: o servidor já leu e validou o valor digitado ("1.000,50", "10", "-5,5").
  function centavosDoTexto(texto) {
    const t = texto.replace(/^-/, '');
    const n = t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t.replace(/^(\d{1,3})((\.\d{3})+)$/, (_, a, b) => a + b.replace(/\./g, ''));
    return Math.round(parseFloat(n) * 100);
  }

  function avisarNoLancamento(texto) {
    lan.erro.textContent = texto || '';
    lan.erro.hidden = !texto;
  }

  function abrirLancamento(id) {
    const u = indicador(id);
    if (!u) return;
    alvo = id;
    chave = novaChave();
    lan.nome.textContent = u.nome;
    lan.sub.textContent = [u.escritorio, u.email].filter(Boolean).join(' · ');
    lan.valor.value = '';
    lan.obs.value = '';
    avisarNoLancamento('');
    fecharMenu();
    lan.fundo.hidden = false;
    lan.posicao.hidden = false;
    lan.valor.focus();
  }

  function fecharLancamento() {
    lan.fundo.hidden = true;
    lan.posicao.hidden = true;
  }

  function lancar() {
    const u = indicador(alvo);
    if (!u || ocupado) return;
    const valor = lan.valor.value.trim();
    if (!/\d/.test(valor)) { avisarNoLancamento(T('master.js.recompensas.erro_valor')); return; }
    ocupado = true;
    lan.enviar.disabled = true;
    avisarNoLancamento('');
    pedir(urlDoUsuario(raiz.dataset.urlLancamento, u.userId), 'POST', { valor, observacao: lan.obs.value.trim(), chave })
      .then(d => {
        fecharLancamento();
        desenhar(d);
        carregarExtrato();
        avisar(T(valor.startsWith('-') ? 'master.js.recompensas.lancado_estorno' : 'master.js.recompensas.lancado_credito', { valor: moeda(centavosDoTexto(valor)), nome: u.nome }), true);
      })
      .catch(e => avisarNoLancamento(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; lan.enviar.disabled = false; });
  }

  /* Janela "Dar baixa" (F4): registra um pagamento feito fora do sistema */
  function avisarNaBaixa(texto) {
    bai.erro.textContent = texto || '';
    bai.erro.hidden = !texto;
  }

  function desenharFormas() {
    bai.formas.innerHTML = FORMAS.map(f => '<button type="button" role="radio" class="mrc-bai-forma" data-forma="' + f + '" aria-checked="' + (f === forma ? 'true' : 'false') + '">' + esc(T('master.js.recompensas.forma.' + f)) + '</button>').join('');
  }

  function desenharAnexo() {
    const f = bai.arquivo.files && bai.arquivo.files[0];
    bai.anexoTxt.textContent = f ? f.name : T('master.js.recompensas.baixa_anexar');
    bai.anexoOk.hidden = !f;
  }

  function copiarEmail(id) {
    const u = indicador(id);
    fecharMenu();
    if (u && u.email && navigator.clipboard) navigator.clipboard.writeText(u.email);
    avisar(T(u && u.email ? 'master.js.recompensas.email_copiado' : 'master.js.recompensas.sem_email'), !!(u && u.email));
  }

  function abrirBaixa(id) {
    const u = indicador(id);
    if (!u) return;
    alvo = id;
    chave = novaChave();
    forma = 'pix';
    bai.nome.textContent = u.nome;
    bai.sub.textContent = [u.escritorio, u.email].filter(Boolean).join(' · ');
    bai.valor.value = campoValor(u.saldoCentavos);
    bai.dica.textContent = T('master.js.recompensas.baixa_dica', { saldo: moeda(u.saldoCentavos), pix: (u.recebimento && u.recebimento.chavePix) || T('master.js.recompensas.baixa_sem_pix') });
    bai.obs.value = '';
    bai.arquivo.value = '';
    desenharFormas();
    desenharAnexo();
    avisarNaBaixa('');
    fecharMenu();
    bai.fundo.hidden = false;
    bai.posicao.hidden = false;
    bai.valor.focus();
  }

  function fecharBaixa() {
    bai.fundo.hidden = true;
    bai.posicao.hidden = true;
  }

  function darBaixa() {
    const u = indicador(alvo);
    if (!u || ocupado) return;
    const valor = bai.valor.value.trim();
    if (!/\d/.test(valor) || valor.startsWith('-')) { avisarNaBaixa(T('master.js.recompensas.baixa_erro_valor')); return; }
    const corpo = new FormData();
    corpo.append('valor', valor);
    corpo.append('forma', forma);
    corpo.append('observacao', bai.obs.value.trim());
    corpo.append('chave', chave);
    const arquivo = bai.arquivo.files && bai.arquivo.files[0];
    if (arquivo) corpo.append('comprovante', arquivo);
    ocupado = true;
    bai.enviar.disabled = true;
    avisarNaBaixa('');
    pedir(urlDoUsuario(raiz.dataset.urlBaixa, u.userId), 'POST', corpo)
      .then(d => {
        fecharBaixa();
        desenhar(d);
        carregarExtrato();
        avisar(T('master.js.recompensas.baixa_ok', { valor: moeda(centavosDoTexto(valor)), nome: u.nome }), true);
      })
      .catch(e => avisarNaBaixa(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; bai.enviar.disabled = false; });
  }

  /* Janela "Dados para pagamento" (F4): nome, CPF ou CNPJ e chave PIX de quem recebe */
  function avisarNoRecebimento(texto) {
    rcb.erro.textContent = texto || '';
    rcb.erro.hidden = !texto;
  }

  function abrirRecebimento(id) {
    const u = indicador(id);
    if (!u) return;
    alvo = id;
    rcb.indicador.textContent = u.nome;
    rcb.sub.textContent = [u.escritorio, u.email].filter(Boolean).join(' · ');
    rcb.nome.value = u.nome;
    rcb.documento.value = '';
    rcb.pix.value = '';
    rcb.sugestao.hidden = true;
    avisarNoRecebimento('');
    fecharMenu();
    rcb.fundo.hidden = false;
    rcb.posicao.hidden = false;
    rcb.nome.focus();
    // O documento completo só vem sob demanda: a lista traz só o mascarado.
    fetch(urlDoUsuario(raiz.dataset.urlRecebimento, id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => {
        if (alvo !== id) return;
        if (d.nome) {
          rcb.nome.value = d.nome;
          rcb.documento.value = d.documento || '';
          rcb.pix.value = d.chavePix || '';
        }
        // A chave que a própria pessoa informou no perfil só é sugerida (com "Usar") se a do Master está vazia.
        if (d.chavePixDoPerfil && !rcb.pix.value.trim()) {
          rcb.sugestaoValor.textContent = d.chavePixDoPerfil;
          rcb.sugestao.hidden = false;
        }
      })
      .catch(() => avisarNoRecebimento(T('master.js.recompensas.recebimento_erro_carregar')));
  }

  function fecharRecebimento() {
    rcb.fundo.hidden = true;
    rcb.posicao.hidden = true;
  }

  function salvarRecebimento() {
    const u = indicador(alvo);
    if (!u || ocupado) return;
    ocupado = true;
    rcb.enviar.disabled = true;
    avisarNoRecebimento('');
    pedir(urlDoUsuario(raiz.dataset.urlRecebimento, u.userId), 'PUT', { nome: rcb.nome.value.trim(), documento: rcb.documento.value.trim(), chavePix: rcb.pix.value.trim() })
      .then(d => {
        fecharRecebimento();
        desenhar(d);
        avisar(T('master.js.recompensas.recebimento_ok', { nome: u.nome }), true);
      })
      .catch(e => avisarNoRecebimento(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; rcb.enviar.disabled = false; });
  }

  /* Antifraude (F3): revisar suspeitas, bloquear e apagar da lista */
  function aprovarSuspeitas(id) {
    const u = indicador(id);
    fecharMenu();
    if (!u || ocupado) return;
    const texto = [T('master.js.recompensas.aprovar_titulo', { nome: u.nome, n: num(u.suspeitas) }), dicaDasSuspeitas(u), T('master.js.recompensas.aprovar_pergunta')].join('\n\n');
    if (!window.confirm(texto)) return;
    ocupado = true;
    avisar('');
    pedir(urlDoUsuario(raiz.dataset.urlAprovar, id), 'POST', { vistas: u.suspeitas })
      .then(d => { desenhar(d); avisar(T('master.js.recompensas.aprovadas', { nome: u.nome }), true); })
      .catch(e => avisar(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; });
  }

  function alternarBloqueio(id) {
    const u = indicador(id);
    fecharMenu();
    if (!u || ocupado) return;
    ocupado = true;
    avisar('');
    pedir(urlDoUsuario(raiz.dataset.urlBloqueio, id), 'POST', { bloqueado: !u.bloqueado })
      .then(d => { desenhar(d); avisar(T(u.bloqueado ? 'master.js.recompensas.desbloqueada' : 'master.js.recompensas.bloqueada', { nome: u.nome }), true); })
      .catch(e => avisar(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; });
  }

  function avisarNoApagar(texto) {
    apg.erro.textContent = texto || '';
    apg.erro.hidden = !texto;
  }

  function abrirApagar(id) {
    const u = indicador(id);
    if (!u) return;
    alvo = id;
    apg.nome.textContent = u.nome;
    apg.sub.textContent = [u.escritorio, u.email].filter(Boolean).join(' · ');
    apg.palavra.value = '';
    avisarNoApagar('');
    fecharMenu();
    apg.fundo.hidden = false;
    apg.posicao.hidden = false;
    apg.palavra.focus();
  }

  function fecharApagar() {
    apg.fundo.hidden = true;
    apg.posicao.hidden = true;
  }

  function apagar() {
    const u = indicador(alvo);
    if (!u || ocupado) return;
    const palavra = apg.palavra.value.trim();
    if (palavra.toUpperCase() !== 'APAGAR') { avisarNoApagar(T('master.js.recompensas.erro_apagar')); return; }
    ocupado = true;
    apg.enviar.disabled = true;
    avisarNoApagar('');
    pedir(urlDoUsuario(raiz.dataset.urlApagar, u.userId), 'POST', { confirmacao: palavra })
      .then(d => { fecharApagar(); desenhar(d); avisar(T('master.js.recompensas.apagado', { nome: u.nome }), true); })
      .catch(e => avisarNoApagar(e.message || T('master.js.recompensas.erro_salvar')))
      .finally(() => { ocupado = false; apg.enviar.disabled = false; });
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
    if (b.dataset.acao === 'baixa') { abrirBaixa(id); return; }
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
  menu.caixa.querySelector('[data-mrc-menu-acao="lancamento"]').addEventListener('click', () => abrirLancamento(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="extrato"]').addEventListener('click', () => filtrarExtrato(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="csv"]').addEventListener('click', () => baixarCsv(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="geral"]').addEventListener('click', voltarParaAGeral);
  menu.caixa.querySelector('[data-mrc-menu-acao="aprovar"]').addEventListener('click', () => aprovarSuspeitas(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="bloquear"]').addEventListener('click', () => alternarBloqueio(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="apagar"]').addEventListener('click', () => abrirApagar(alvo));
  apg.fundo.addEventListener('click', fecharApagar);
  raiz.querySelectorAll('[data-mrc-apg-fechar]').forEach(b => b.addEventListener('click', fecharApagar));
  apg.enviar.addEventListener('click', apagar);
  apg.palavra.addEventListener('keydown', e => { if (e.key === 'Enter') apagar(); });
  dlg.fundo.addEventListener('click', fecharJanela);
  raiz.querySelectorAll('[data-mrc-dlg-fechar]').forEach(b => b.addEventListener('click', fecharJanela));
  lan.fundo.addEventListener('click', fecharLancamento);
  raiz.querySelectorAll('[data-mrc-lan-fechar]').forEach(b => b.addEventListener('click', fecharLancamento));
  lan.enviar.addEventListener('click', lancar);
  menu.caixa.querySelector('[data-mrc-menu-acao="baixa"]').addEventListener('click', () => abrirBaixa(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="recebimento"]').addEventListener('click', () => abrirRecebimento(alvo));
  menu.caixa.querySelector('[data-mrc-menu-acao="copiar"]').addEventListener('click', () => copiarEmail(alvo));
  bai.fundo.addEventListener('click', fecharBaixa);
  raiz.querySelectorAll('[data-mrc-bai-fechar]').forEach(b => b.addEventListener('click', fecharBaixa));
  bai.enviar.addEventListener('click', darBaixa);
  bai.arquivo.addEventListener('change', desenharAnexo);
  bai.formas.addEventListener('click', e => {
    const b = e.target.closest('[data-forma]');
    if (!b) return;
    forma = b.dataset.forma;
    desenharFormas();
  });
  rcb.fundo.addEventListener('click', fecharRecebimento);
  raiz.querySelectorAll('[data-mrc-rcb-fechar]').forEach(b => b.addEventListener('click', fecharRecebimento));
  rcb.enviar.addEventListener('click', salvarRecebimento);
  rcb.sugestaoUsar.addEventListener('click', () => {
    rcb.pix.value = rcb.sugestaoValor.textContent;
    rcb.sugestao.hidden = true;
    rcb.pix.focus();
  });
  raiz.querySelector('[data-mrc-ext-limpar]').addEventListener('click', () => filtrarExtrato(null));
  dlg.rec.addEventListener('click', () => alternarRecorrente(dlg.rec.getAttribute('aria-checked') !== 'true'));
  dlg.salvar.addEventListener('click', salvarCondicao);
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    fecharMenu();
    fecharJanela();
    fecharLancamento();
    fecharBaixa();
    fecharRecebimento();
    fecharApagar();
  });

  // Textos fixos do menu vêm do catálogo na hora de abrir a seção (o BJ_T só existe no fim da página).
  function textosDoMenu() {
    menu.caixa.querySelector('[data-mrc-menu-txt="condicao"]').textContent = T('master.js.recompensas.menu_condicao');
    menu.caixa.querySelector('[data-mrc-menu-txt="geral"]').textContent = T('master.js.recompensas.menu_geral');
    menu.caixa.querySelector('[data-mrc-menu-txt="baixa"]').textContent = T('master.js.recompensas.menu_baixa');
    menu.caixa.querySelector('[data-mrc-menu-txt="recebimento"]').textContent = T('master.js.recompensas.menu_recebimento');
    menu.caixa.querySelector('[data-mrc-menu-txt="copiar"]').textContent = T('master.js.recompensas.menu_copiar');
    menu.caixa.querySelector('[data-mrc-menu-txt="lancamento"]').textContent = T('master.js.recompensas.menu_lancamento');
    menu.caixa.querySelector('[data-mrc-menu-txt="extrato"]').textContent = T('master.js.recompensas.menu_extrato');
    menu.caixa.querySelector('[data-mrc-menu-txt="csv"]').textContent = T('master.js.recompensas.menu_csv');
    menu.caixa.querySelector('[data-mrc-menu-txt="aprovar"]').textContent = T('master.js.recompensas.menu_aprovar');
    menu.caixa.querySelector('[data-mrc-menu-txt="apagar"]').textContent = T('master.js.recompensas.menu_apagar');
    elExtChip.querySelector('[data-mrc-ext-limpar]').title = T('master.js.recompensas.extrato_todos');
  }

  function abrir() {
    textosDoMenu();
    carregar();
    carregarExtrato();
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
