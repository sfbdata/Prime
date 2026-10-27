/* BlueJus · Painel do Master, seção "Desempenho" (#sec=desemp)
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L271-280 e lógica `desemp`
   L939-961; spec docs/specs/master-desempenho.md, PR 3: leitura; PR 4: gravação).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash; trocar o período
     (24 horas, 7 ou 30 dias) pede os dados de novo ao servidor.
   - O servidor decide o tom de cada indicador (ok, atencao, critico, neutro, semdados); aqui só se escolhe a cor e o
     texto. Nenhuma regra de limiar mora neste arquivo.
   - Gravar (PR 4): abrir e resolver incidente, registrar backup ou teste e limpar os eventos. Uma janela só, no estilo da
     "Lançar custo" do desenho (L268), no lugar dos prompt() e confirm() do protótipo; POST JSON com X-CSRF-Token e,
     ao gravar, recarrega os números. O servidor valida de novo e devolve `erro` em 422.
   - Mensagem de erro vem do navegador de qualquer usuário: tudo entra por escape (esc), nunca como HTML.
   - Textos no catálogo (master.desempenho.js.*, entregue pelo parcial com catalogo_js), lidos na hora de usar;
     datas e números por window.BJFormato. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-dpm]');
  if (!raiz) return;

  const URL_DADOS = raiz.dataset.urlDados;
  const URL_INCIDENTE = raiz.dataset.urlIncidente;
  const URL_RESOLVER = raiz.dataset.urlResolver;
  const URL_BACKUP = raiz.dataset.urlBackup;
  const URL_LIMPAR = raiz.dataset.urlLimpar;
  const TOKEN = raiz.dataset.token || '';
  const $ = sel => raiz.querySelector(sel);
  const elAviso = $('[data-dpm-aviso]');
  const elKpis = $('[data-dpm-kpis]');
  const botoes = Array.from(raiz.querySelectorAll('[data-dpm-periodo]'));
  // Aspas pelo código (\x22 e \x27): um literal de aspas aqui desalinha o detector de texto fixo (SemTextoFixoNosExtraidos).
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const F = () => window.BJFormato;
  const SEM_DADOS = 'semdados';
  let periodo = 7;
  let carregado = false;
  let pedido = 0;

  function avisar(texto) {
    elAviso.textContent = texto || '';
    elAviso.hidden = !texto;
  }

  function cartao(chave, valor, detalhe, tom) {
    return '<div class="dpm-kpi"><span class="dpm-kpi-rotulo">' + esc(T('master.desempenho.js.kpi.' + chave)) + '</span>'
      + '<b class="dpm-kpi-valor dpm-tom-' + tom + '">' + esc(valor === null ? T('master.desempenho.js.sem_dados') : valor) + '</b>'
      + '<span class="dpm-kpi-detalhe">' + esc(detalhe) + '</span></div>';
  }

  function memoriaDoNavegador() {
    const m = window.performance && window.performance.memory;
    return m ? T('master.desempenho.js.navegador_mb', { n: F().numero(Math.round(m.usedJSHeapSize / 1048576)) }) : '';
  }

  function textoDoBackup(b) {
    if (b.horas === null) return null;
    return b.horas < 1 ? T('master.desempenho.js.backup_menos_1h') : T('master.desempenho.js.backup_ha_h', { n: F().numero(b.horas) });
  }

  function desenharKpis(i) {
    const sem = (x) => x.tom === SEM_DADOS;
    const itens = [
      cartao('disponibilidade', sem(i.disponibilidade) ? null : i.disponibilidade.percentual.toLocaleString(F().idioma(), { maximumFractionDigits: 1 }) + '%', T('master.desempenho.js.verificacoes', { n: F().numero(i.disponibilidade.verificacoes) }), i.disponibilidade.tom),
      cartao('p95', sem(i.p95) ? null : T('master.desempenho.js.segundos', { n: F().numero(i.p95.ms / 1000, 2) }), T('master.desempenho.js.carregamentos', { n: F().numero(i.p95.medidos) }), i.p95.tom),
      cartao('erros', F().numero(i.erros.total), T('master.desempenho.js.erros_de_tela'), i.erros.tom),
      cartao('logins', F().numero(i.loginsFalhos.falhas), T('master.desempenho.js.tentativas', { n: F().numero(i.loginsFalhos.total) }), i.loginsFalhos.tom),
      cartao('uploads', F().numero(i.uploadsFalhos.falhas), T('master.desempenho.js.envios', { n: F().numero(i.uploadsFalhos.total) }), i.uploadsFalhos.tom),
      cartao('backup', textoDoBackup(i.ultimoBackup), i.ultimoBackup.em ? F().dataHora(i.ultimoBackup.em) : T('master.desempenho.js.nenhum_registrado'), i.ultimoBackup.tom),
      cartao('restauracao', i.restauracao.ok === null ? null : T(i.restauracao.ok ? 'master.desempenho.js.aprovado' : 'master.desempenho.js.falhou'), i.restauracao.em ? F().dataHora(i.restauracao.em) : T('master.desempenho.js.nenhum_registrado'), i.restauracao.tom),
      cartao('servidor', null, [T('master.desempenho.js.aguarda_api'), memoriaDoNavegador()].filter(Boolean).join(' · '), i.servidor.tom),
    ];
    elKpis.innerHTML = itens.join('');
    elKpis.removeAttribute('aria-busy');
  }

  function desenharIncidentes(d) {
    $('[data-dpm-inc-abertos]').textContent = T('master.desempenho.js.em_aberto', { n: F().numero(d.incidentesAbertos) });
    $('[data-dpm-inc-vazio]').hidden = d.incidentes.length > 0;
    $('[data-dpm-inc]').innerHTML = d.incidentes.map(x => '<div class="dpm-inc">'
      + '<span class="dpm-grav dpm-grav-' + esc(x.gravidade) + '">' + esc(T('master.desempenho.js.gravidade.' + x.gravidade)) + '</span>'
      + '<span class="dpm-inc-corpo"><b class="dpm-inc-titulo">' + esc(x.titulo) + '</b>'
      + '<span class="dpm-inc-meta">' + esc(T('master.desempenho.js.categoria.' + x.categoria)) + ' · ' + esc(F().dataHora(x.abertoEm)) + ' · '
      + '<span class="' + (x.aberto ? 'dpm-tom-critico' : 'dpm-tom-ok') + '">' + esc(x.aberto ? T('master.desempenho.js.em_aberto_status') : T('master.desempenho.js.resolvido_em', { data: F().dataHora(x.resolvidoEm) })) + '</span></span></span>'
      + (x.aberto ? '<button type="button" class="dpm-resolver" data-dpm-resolver="' + esc(x.id) + '" data-dpm-titulo="' + esc(x.titulo) + '">' + esc(T('master.desempenho.js.resolver')) + '</button>' : '')
      + '</div>').join('');
  }

  function desenharBackups(d) {
    $('[data-dpm-bk-vazio]').hidden = d.backups.length > 0;
    $('[data-dpm-bk]').innerHTML = d.backups.map(b => '<div class="dpm-bk">'
      + '<b class="dpm-bk-tipo">' + esc(T('master.desempenho.js.tipo.' + b.tipo)) + '</b><span class="dpm-bk-em">' + esc(F().dataHora(b.em)) + '</span>'
      + '<b class="' + (b.ok ? 'dpm-tom-ok' : 'dpm-tom-critico') + '">' + esc(T(b.ok ? 'master.desempenho.js.concluido' : 'master.desempenho.js.falhou')) + '</b>'
      + '<span class="dpm-bk-obs">' + esc(b.observacao || '') + '</span></div>').join('');
  }

  function desenharIntegracoes(d) {
    $('[data-dpm-int]').innerHTML = d.integracoes.map(n => {
      const tom = n.conectada ? 'dpm-tom-ok' : 'dpm-tom-atencao';
      return '<div class="dpm-int"><i class="bi ' + (n.conectada ? 'bi-check-circle-fill' : 'bi-plug') + ' ' + tom + '" aria-hidden="true"></i>'
        + '<span class="dpm-int-rotulo">' + esc(T('master.desempenho.js.integracao.' + n.chave)) + '</span>'
        + '<b class="dpm-int-estado ' + tom + '">' + esc(T(n.conectada ? 'master.desempenho.js.conectada' : 'master.desempenho.js.nao_configurada')) + '</b></div>';
    }).join('');
  }

  function desenharErros(d) {
    $('[data-dpm-erros-vazio]').hidden = d.erros.length > 0;
    $('[data-dpm-erros]').innerHTML = d.erros.map(e => '<div class="dpm-erro">'
      + '<span class="dpm-erro-quando">' + esc(F().dataHora(e.em)) + ' · ' + esc(e.pagina || T('master.desempenho.js.sem_pagina')) + '</span>'
      + '<div class="dpm-erro-msg">' + esc(e.mensagem || '') + '</div></div>').join('');
  }

  function marcarPeriodo() {
    botoes.forEach(b => b.setAttribute('aria-pressed', String(Number(b.dataset.dpmPeriodo) === periodo)));
  }

  async function carregar() {
    const meu = ++pedido;
    marcarPeriodo();
    elKpis.setAttribute('aria-busy', 'true');
    try {
      const r = await fetch(URL_DADOS + '?periodo=' + periodo, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!r.ok) throw new Error(String(r.status));
      const d = await r.json();
      if (meu !== pedido) return;
      avisar('');
      desenharKpis(d.indicadores);
      desenharIncidentes(d);
      desenharBackups(d);
      desenharIntegracoes(d);
      desenharErros(d);
    } catch (e) {
      if (meu === pedido) avisar(T('master.desempenho.js.erro_carregar'));
    }
  }

  function abrir() {
    carregado = true;
    carregar();
  }

  botoes.forEach(b => b.addEventListener('click', () => {
    const novo = Number(b.dataset.dpmPeriodo);
    if (novo === periodo) return;
    periodo = novo;
    carregar();
  }));

  /* ---------- Gravação (PR 4) ---------- */
  const dlg = {
    fundo: $('[data-dpm-fundo]'), janela: $('[data-dpm-janela]'), form: $('[data-dpm-form]'), ic: $('[data-dpm-dlg-ic]'),
    titulo: $('[data-dpm-dlg-titulo]'), texto: $('[data-dpm-dlg-texto]'), erro: $('[data-dpm-dlg-erro]'),
    confirmar: $('[data-dpm-confirmar]'), confirmarTxt: $('[data-dpm-confirmar-txt]'),
    campos: Array.from(raiz.querySelectorAll('[data-dpm-campo]')), entradas: Array.from(raiz.querySelectorAll('[data-dpm-in]')),
  };
  // Que campos cada janela mostra, e o texto do cabeçalho (ícone bi-*, igual ao desenho: a "Lançar custo" usa o do cartão).
  const JANELAS = {
    incidente: { ic: 'bi-exclamation-octagon', campos: ['titulo', 'categoria', 'gravidade'], salvar: 'master.desempenho.js.dlg.salvar' },
    resolver: { ic: 'bi-check2-circle', campos: ['solucao'], salvar: 'master.desempenho.js.dlg.resolver' },
    backup: { ic: 'bi-hdd-network', campos: ['ok', 'observacao'], salvar: 'master.desempenho.js.dlg.salvar' },
    restauracao: { ic: 'bi-arrow-counterclockwise', campos: ['ok', 'observacao'], salvar: 'master.desempenho.js.dlg.salvar' },
    limpar: { ic: 'bi-eraser', campos: [], salvar: 'master.desempenho.js.dlg.limpar' },
  };
  let aberta = null;
  let ocupado = false;

  const entrada = nome => dlg.entradas.find(e => e.dataset.dpmIn === nome);

  function mostrarErro(texto) {
    dlg.erro.textContent = texto || '';
    dlg.erro.hidden = !texto;
  }

  function abrirJanela(qual, titulo, texto, extra) {
    const j = JANELAS[qual];
    aberta = Object.assign({ qual }, extra || {});
    dlg.ic.className = 'bi dpm-dlg-ic ' + j.ic;
    dlg.titulo.textContent = titulo;
    dlg.texto.textContent = texto || '';
    dlg.texto.hidden = !texto;
    dlg.confirmarTxt.textContent = T(j.salvar);
    dlg.campos.forEach(c => { c.hidden = !j.campos.includes(c.dataset.dpmCampo); });
    dlg.entradas.forEach(e => { if (e.tagName === 'INPUT') e.value = ''; });
    mostrarErro('');
    dlg.fundo.hidden = false;
    dlg.janela.hidden = false;
    const primeiro = dlg.entradas.find(e => !e.closest('[data-dpm-campo]').hidden);
    (primeiro || dlg.confirmar).focus();
  }

  function fecharJanela() {
    aberta = null;
    dlg.fundo.hidden = true;
    dlg.janela.hidden = true;
  }

  function corpoDaJanela() {
    switch (aberta.qual) {
      case 'incidente': return { titulo: entrada('titulo').value.trim(), categoria: entrada('categoria').value, gravidade: entrada('gravidade').value };
      case 'resolver': return { solucao: entrada('solucao').value.trim() };
      case 'backup':
      case 'restauracao': return { tipo: aberta.qual, ok: entrada('ok').value === '1', observacao: entrada('observacao').value.trim() };
      default: return {};
    }
  }

  function urlDaJanela() {
    switch (aberta.qual) {
      case 'incidente': return URL_INCIDENTE;
      case 'resolver': return URL_RESOLVER.replace(/\/0\//, '/' + aberta.id + '/');
      case 'limpar': return URL_LIMPAR;
      default: return URL_BACKUP;
    }
  }

  async function gravar(e) {
    e.preventDefault();
    if (ocupado || !aberta) return;
    const corpo = corpoDaJanela();
    if (aberta.qual === 'incidente' && !corpo.titulo) { mostrarErro(T('master.desempenho.js.erro_titulo')); return; }
    ocupado = true;
    dlg.confirmar.disabled = true;
    try {
      const r = await fetch(urlDaJanela(), {
        method: 'POST', credentials: 'same-origin', body: JSON.stringify(corpo),
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      });
      if (!r.ok) {
        const d = await r.json().catch(() => ({}));
        mostrarErro(d.erro || T('master.desempenho.js.erro_salvar'));
        return;
      }
      fecharJanela();
      carregar();
    } catch (err) {
      mostrarErro(T('master.desempenho.js.erro_salvar'));
    } finally {
      ocupado = false;
      dlg.confirmar.disabled = false;
    }
  }

  dlg.form.addEventListener('submit', gravar);
  $('[data-dpm-cancelar]').addEventListener('click', fecharJanela);
  dlg.fundo.addEventListener('click', fecharJanela);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && aberta) fecharJanela(); });
  $('[data-dpm-abrir-incidente]').addEventListener('click', () => abrirJanela('incidente', T('master.desempenho.js.dlg.incidente')));
  $('[data-dpm-limpar]').addEventListener('click', () => abrirJanela('limpar', T('master.desempenho.js.dlg.limpar_titulo'), T('master.desempenho.js.dlg.limpar_texto')));
  raiz.querySelectorAll('[data-dpm-registrar]').forEach(b => b.addEventListener('click', () => {
    const tipo = b.dataset.dpmRegistrar;
    abrirJanela(tipo, T(tipo === 'backup' ? 'master.desempenho.js.dlg.backup' : 'master.desempenho.js.dlg.restauracao'));
  }));
  $('[data-dpm-inc]').addEventListener('click', e => {
    const b = e.target.closest('[data-dpm-resolver]');
    if (b) abrirJanela('resolver', T('master.desempenho.js.dlg.resolver_titulo'), b.dataset.dpmTitulo, { id: b.dataset.dpmResolver });
  });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'desemp' && !carregado) abrir(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=desemp\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) abrir(); });
    else abrir();
  }
})();
