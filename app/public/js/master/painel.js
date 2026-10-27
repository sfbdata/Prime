/* BlueJus · Painel do Master (desenho docs/design/perfis-1.0/05 - Perfis 1.0.dc.html, aba "Painel do sistema").
   Abas abertas pelo hash como no desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html (linha 481):
   #aba=perm abre Permissões, #aba=rec abre Telas e recursos e #sec=contas abre a seção (é para onde voltam promover e
   remover Master). Vale qualquer aba que exista no HTML ([data-aba]).
   O bloco "Masters do sistema" da aba Permissões é do public/js/master/masters.js; a aba Telas e recursos, do recursos.js.
   Lógica portada do componente do desenho (kpis, gráfico com curva monotônica, mapa, faltam, auditoria, PDF).
   Regra do dono: nada inventado. Dado que não existe aparece como "sem informação" ou no estado vazio do desenho.
   Os dados vêm de /master/dados/* (só leitura, ROLE_SUPER_ADMIN no servidor).
   Planos e limites (spec docs/specs/perfis-1.2-planos-e-limites.md P1 a P6, desenho 1.2 linhas 194-210, 397-410 e 726-735):
   cartões dos planos, modal "Editar plano", itens de limite no menu da conta, modal de limite, faixas de alerta e
   ranking com plano, limite, % e faixa do RetratoDoEspaco da Contribua. As regras moram no servidor.
   Idioma (spec docs/specs/idioma-do-sistema.md, P2a da b5): os textos da moldura e da Visão geral (KPIs, gráfico, mapa,
   estados que faltam, seccionais e janela da OAB) vêm do catálogo da página ({{ catalogo_js('master.js', 'termos') }})
   por BJ_T.t; datas e números por window.BJFormato.
   P2b: as outras seções (Contas e escritórios, Usuários e OABs, Armazenamento, Planos e limites, Segurança e auditoria,
   o PDF) também. O que fica como texto fixo é dado comparado com o servidor (situação da conta, nomes de estado e de
   país) ou a palavra EXCLUIR, que o servidor confere; a lista está no templates-extraidos.json. */
(() => {
  'use strict';
  const raiz = document.getElementById('master-painel');
  if (!raiz) return;

  const URLS = JSON.parse(raiz.dataset.urls || '{}');
  const elSecs = document.getElementById('mp-secs');
  const elSecao = document.getElementById('mp-secao');

  // Aspas pelo código (\x22 e \x27): um literal de aspas aqui desalinha o detector de texto fixo (SemTextoFixoNosExtraidos)
  // e esconde dele o resto do arquivo.
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const num = n => window.BJFormato.numero(n);
  // Decimal sem separador de milhar (o GB e o eixo do gráfico eram montados à mão: "1234,5"), no idioma da página.
  const decimal = (v, min, max) => Number(v).toLocaleString(window.BJFormato.idioma(), { minimumFractionDigits: min, maximumFractionDigits: max, useGrouping: false });
  const GB = 1073741824;
  const gb = bytes => { const v = (bytes || 0) / GB; if (v > 0 && v < 0.1) return T('master.js.gb_menos', { n: decimal(0.1, 1, 1) }); return decimal(v, 1, 1) + ' GB'; };
  const pct = (n, d) => (d > 0 ? Math.round(n / d * 100) : 0) + '%';
  const dataHora = iso => window.BJFormato.dataHora(iso);
  const QUEM = raiz.dataset.quem || T('termos.master');
  const agora = () => dataHora(new Date().toISOString());

  const S = { aba: 'painel', sec: 'visao', grMet: 'pastas', grVis: 'mes', grMod: 'acum', grSel: null, mapaModo: 'br', mapaQuem: 'esc', mapaUsu: 'geral', mapaInd: 'env',
    faltamOn: false, faltamCid: false, busca: '', fSit: '', menu: null, menuPos: null, audTipo: '', audConta: null, aviso: null, fx: null, fxMsg: null };
  let CONVENIO = {}; // sigla => estado do convênio (do painel Convênio OAB), mais novo que o das contas carregadas
  const D = { visao: null, contas: null, oab: null, audit: null, planos: null };
  const ERRO = {};

  const SECS = [['visao', T('master.js.secao.visao'), 'bi-speedometer2'], ['contas', T('master.js.secao.contas'), 'bi-buildings'], ['oab', T('master.js.secao.oab'), 'bi-person-vcard'],
    ['arm', T('master.js.secao.arm'), 'bi-hdd-stack'], ['planos', T('master.js.secao.planos'), 'bi-sliders'], ['custos', T('master.js.secao.custos'), 'bi-cash-coin'],
    ['desemp', T('master.js.secao.desemp'), 'bi-activity'], ['audit', T('master.js.secao.audit'), 'bi-journal-check'], ['config', T('master.js.secao.config'), 'bi-gear']];
  // Seções de outras frentes (desenho 1.2): entram só quando o parcial delas existe e trouxe conteúdo.
  const EXTERNAS = {};
  raiz.querySelectorAll('[data-sec-externa]').forEach(el => { if (el.innerHTML.trim()) EXTERNAS[el.dataset.secExterna] = el; });
  if (EXTERNAS.com) SECS.push(['com', T('master.js.secao.com'), 'bi-megaphone']);
  if (EXTERNAS.ind) SECS.push(['ind', T('master.js.secao.ind'), 'bi-share']);
  if (EXTERNAS.recomp) SECS.push(['recomp', T('master.js.secao.recomp'), 'bi-gift']);
  if (EXTERNAS.notif) SECS.push(['notif', T('master.js.secao.notif'), 'bi-bell']);
  const VAZIAS = {
    custos: ['bi-cash-coin', T('master.js.secao.custos'), T('master.js.vazia.custos.texto'), [T('master.js.vazia.custos.armazenamento'), T('master.js.vazia.custos.banco'), T('master.js.vazia.custos.hospedagem'), T('master.js.vazia.custos.trafego'), T('master.js.vazia.custos.backups'), T('master.js.vazia.custos.apis_ia'), T('master.js.vazia.custos.notificacoes'), T('master.js.vazia.custos.receita'), T('master.js.vazia.custos.custo_conta'), T('master.js.vazia.custos.custo_gb'), T('master.js.vazia.custos.margem'), T('master.js.vazia.custos.projecao')]],
    desemp: ['bi-activity', T('master.js.vazia.desemp.titulo'), T('master.js.vazia.desemp.texto'), [T('master.js.vazia.desemp.disponibilidade'), T('master.js.vazia.desemp.tempo_resposta'), T('master.js.vazia.desemp.erros'), T('master.js.vazia.desemp.falhas_upload'), T('master.js.vazia.desemp.falhas_login'), T('master.js.vazia.desemp.ultimo_backup'), T('master.js.vazia.desemp.teste_restauracao'), T('master.js.vazia.desemp.cpu_memoria'), T('master.js.vazia.desemp.integracoes'), T('master.js.vazia.desemp.incidentes')]],
    config: ['bi-gear', T('master.js.secao.config'), T('master.js.vazia.config.texto'), [T('master.js.vazia.config.faixas'), T('master.js.vazia.config.periodo_teste'), T('master.js.vazia.config.excedente'), T('master.js.vazia.config.excecoes'), T('master.js.vazia.config.autenticacao'), T('master.js.vazia.config.retencao')]]
  };
  const NM = { RO: 'Rondônia', AC: 'Acre', AM: 'Amazonas', RR: 'Roraima', PA: 'Pará', AP: 'Amapá', TO: 'Tocantins', MA: 'Maranhão', PI: 'Piauí', CE: 'Ceará', RN: 'Rio Grande do Norte', PB: 'Paraíba', PE: 'Pernambuco', AL: 'Alagoas', SE: 'Sergipe', BA: 'Bahia', MG: 'Minas Gerais', ES: 'Espírito Santo', RJ: 'Rio de Janeiro', SP: 'São Paulo', PR: 'Paraná', SC: 'Santa Catarina', RS: 'Rio Grande do Sul', MS: 'Mato Grosso do Sul', MT: 'Mato Grosso', GO: 'Goiás', DF: 'Distrito Federal' };
  // A situação gravada vem do servidor em português (ATIVA, SUSPENSA, QUARENTENA): é dado, comparado aqui e no filtro.
  // O rótulo da tela sai do catálogo (SIT_ROTULO); situação desconhecida aparece como veio.
  const ATIVA = 'Ativa', SUSPENSA = 'Suspensa', QUARENTENA = 'Em quarentena';
  // Pedido de exclusão pendente (o Tenant segue ativo): a tabela mostra "Suspensa a pedido", não "Ativa" (desvio pelo
  // dado: o protótipo mostrava Ativa). Só o rótulo muda; filtro e menu seguem a situação gravada da conta.
  const A_PEDIDO = 'a_pedido';
  const ST = { [ATIVA]: '#1e7b45', [SUSPENSA]: '#a3232b', [A_PEDIDO]: '#a3232b', [QUARENTENA]: '#5b7383' };
  const SIT_ROTULO = { [ATIVA]: T('master.js.situacao.ativa'), [SUSPENSA]: T('master.js.situacao.suspensa'), [A_PEDIDO]: T('master.js.situacao.a_pedido'), [QUARENTENA]: T('master.js.situacao.quarentena') };
  const rotuloDaSituacao = sit => SIT_ROTULO[sit] || sit;
  const situacaoVisivel = c => (c.pedidoPendente && c.situacao === ATIVA ? A_PEDIDO : c.situacao);
  // Data "Y-m-d" do servidor no formato do idioma, lida como data local (new Date('Y-m-d') seria meia-noite UTC: dia anterior aqui).
  const dataBr = ymd => { const p = String(ymd || '').split('-'); return p.length === 3 ? window.BJFormato.data(new Date(+p[0], +p[1] - 1, +p[2])) : ''; };
  // Faixa do RetratoDoEspaco (Contribua) com o rótulo e a cor da função faixa() do desenho (linha 593).
  const FAIXA = { livre: [T('master.js.faixa.livre'), '#1e7b45'], atencao: [T('master.js.faixa.atencao'), '#c08a12'], alerta: [T('master.js.faixa.alerta'), '#c0392b'], critico: [T('master.js.faixa.critico'), '#a3232b'], bloqueio: [T('master.js.faixa.bloqueio'), '#7a1b22'] };
  const ORDEM_FAIXA = { livre: 0, atencao: 1, alerta: 2, critico: 3, bloqueio: 4 };
  const faixaDe = c => FAIXA[c.espaco.faixa] || FAIXA.livre;
  // "Perto ou acima da franquia" conta pela faixa de Alerta (desenho, linha 697: FX[1]).
  const pertoDaFranquia = c => c.espaco.percentual !== null && ORDEM_FAIXA[c.espaco.faixa] >= ORDEM_FAIXA.alerta;

  /* ---------- carga ---------- */
  const carregar = (chave, url) => {
    if (D[chave] || ERRO[chave] === 'carregando') return;
    ERRO[chave] = 'carregando';
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(j => { D[chave] = j; ERRO[chave] = null; renderSecs(); if (deps(S.sec).includes(chave)) renderSecao(); })
      .catch(() => { ERRO[chave] = 'falhou'; if (deps(S.sec).includes(chave)) renderSecao(); });
  };
  // Armazenamento e Planos leem as contas (plano, limite, faixa) e o catálogo com as faixas de alerta.
  const precisa = { visao: ['visao'], contas: ['contas'], arm: ['contas', 'planos'], planos: ['planos', 'contas'], oab: ['oab'], audit: ['audit'] };
  const deps = sec => precisa[sec] || [];
  const urlDe = { visao: URLS.visao, contas: URLS.contas, oab: URLS.oab, audit: URLS.auditoria, planos: URLS.planos };
  const aguardando = chave => (ERRO[chave] === 'falhou'
    ? '<div class="mp-card mp-carregando">' + T('master.js.erro_secao') + '</div>'
    : '<div class="mp-carregando">' + T('master.js.carregando') + '</div>');

  /* ---------- abas ---------- */
  function mostrarAba() {
    raiz.querySelectorAll('[data-aba]').forEach(x => { const on = x.dataset.aba === S.aba; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    raiz.querySelectorAll('[data-painel-aba]').forEach(p => { p.hidden = p.dataset.painelAba !== S.aba; });
  }
  raiz.querySelectorAll('[data-aba]').forEach(b => b.addEventListener('click', () => { S.aba = b.dataset.aba; mostrarAba(); }));

  // Aba e seção pelo hash (desenho 1.2): aba=(\w+) e sec=(\w+). Valor desconhecido é ignorado (no carregamento, fica no padrão).
  // Vale no carregamento e no hashchange (itens "Exclusivo do Master" do menu do usuário com a página já aberta).
  // Devolve true quando a seção mudou; o painel não escreve no hash, então não há laço.
  const doHash = chave => (location.hash.match(new RegExp(chave + '=(\\w+)')) || [])[1];
  function aplicarAncora() {
    if (raiz.querySelector('[data-aba="' + doHash('aba') + '"]')) S.aba = doHash('aba');
    mostrarAba();
    const sec = doHash('sec');
    if (!SECS.some(([k]) => k === sec) || sec === S.sec) return false;
    S.sec = sec;
    return true;
  }
  aplicarAncora();
  window.addEventListener('hashchange', () => { if (aplicarAncora()) irPara(S.sec); });

  /* ---------- seções ---------- */
  function renderSecs() {
    // Selo desde a abertura (desenho 1.2, linha 678; spec P10): o total vem com a Visão geral; aberta a trilha, vale a lista.
    // Recarga pendente (D.visao ou D.audit zerados depois de uma ação) mantém o último número conhecido.
    const nAud = S.nAud = D.audit ? D.audit.registros.length : D.visao ? D.visao.registrosDeAuditoria || 0 : S.nAud || 0;
    elSecs.innerHTML = SECS.map(([k, rot, icone]) => '<button type="button" class="mp-sec' + (S.sec === k ? ' on' : '') + '" data-sec="' + k + '"><i class="bi ' + icone + '"></i>' + esc(rot)
      + (k === 'audit' && nAud > 0 ? '<span class="mp-sec-n">' + num(nAud) + '</span>' : '') + '</button>').join('');
  }
  elSecs.addEventListener('click', e => { const b = e.target.closest('[data-sec]'); if (b) irPara(b.dataset.sec); });
  function irPara(sec) { S.sec = sec; S.menu = null; S.menuPos = null; S.aviso = null; S.fxMsg = null; renderSecs(); renderSecao(); }

  function renderSecao() {
    const sec = S.sec, falta = deps(sec).filter(d => !D[d]);
    Object.keys(EXTERNAS).forEach(k => { EXTERNAS[k].hidden = k !== sec; });
    elSecao.hidden = !!EXTERNAS[sec];
    raiz.dispatchEvent(new CustomEvent('mp:secao', { detail: { sec } }));
    if (EXTERNAS[sec]) return;
    if (falta.length) { elSecao.innerHTML = aguardando(falta.find(d => ERRO[d] === 'falhou') || falta[0]); falta.forEach(d => carregar(d, urlDe[d])); return; }
    if (VAZIAS[sec]) { elSecao.innerHTML = vazia(VAZIAS[sec]); return; }
    if (sec === 'visao') return montarVisao();
    if (sec === 'contas') return montarContas();
    if (sec === 'oab') return montarOab();
    if (sec === 'arm') return montarArm();
    if (sec === 'planos') return montarPlanos();
    if (sec === 'audit') return montarAudit();
  }

  const vazia = ([icone, titulo, texto, itens]) => '<div class="mp-card mp-vazia"><span class="mp-vazia-ico"><i class="bi ' + icone + '"></i></span><b>' + esc(titulo) + '</b>'
    + '<span class="mp-vazia-texto">' + esc(texto) + '</span><div class="mp-vazia-itens">' + itens.map(i => '<span>' + esc(i) + '</span>').join('') + '</div>'
    + '<span class="mp-vazia-aviso">' + T('master.js.vazia.aviso') + '</span></div>';

  /* Metas de divulgação: o módulo metas-divulgacao.js cuida do bloco; aqui só entram a base do mapa (usuários por
     estado e por cidade, geral) e as URLs. */
  function renderMetas() {
    const el = document.getElementById('mp-metas');
    if (!el || !window.BJMetasDivulgacao) return;
    const g = D.visao.mapa.pessoas.geral || {};
    window.BJMetasDivulgacao.montar(el, {
      url: URLS.metasDivulgacao, token: URLS.metasToken, urlPopulacao: URLS.populacaoIbge,
      urlMapa: URLS.mapaBrasil + '?quem=pes&usu=geral', usuarios: { A: g.A || {}, C: g.C || {} },
    });
  }

  /* ================= VISÃO GERAL ================= */
  function montarVisao() {
    elSecao.innerHTML = '<div class="mp-kpis" id="mp-kpis"></div>'
      + '<div class="mp-card mp-gr" id="mp-gr"></div>'
      + '<div class="mp-card mp-mapa"><div class="mp-mapa-topo" id="mp-mapa-topo"></div>'
      + '<div class="mp-mapa-corpo"><div class="mp-uf-tab" id="mp-uf-tab"></div><iframe class="mp-mapa-iframe" id="mp-mapa-iframe" title=""></iframe></div>'
      + '<div class="mp-escala"><span>' + T('master.js.mapa.escala_menos') + '</span>' + ['#eef2f5', '#cfe3f1', '#4f8fbd', '#0a4f7a'].map(c => '<i style="--cor:' + c + '"></i>').join('')
      + '<span>' + T('master.js.mapa.escala_mais') + '</span><span class="mp-espaco"></span><span>' + T('master.js.mapa.malha') + '</span></div></div>'
      + '<div id="mp-metas"></div>'
      + '<div class="mp-card mp-idades" id="mp-idades"></div>';
    renderKpis(); renderGrafico(); renderMapa(); renderIdades(); renderMetas();
  }

  function renderKpis() {
    const k = D.visao.kpis;
    const kpis = [
      { rot: T('master.js.visao.kpi.escritorios'), n: num(k.escritorios), desc: T('master.js.visao.kpi.escritorios_desc', { ativas: num(k.ativas), suspensas: num(k.suspensas) }), icone: 'bi-buildings', cor: '#0a6fa0', bg: '#e5f1f7', ir: 'contas' },
      { rot: T('master.js.visao.kpi.primarios'), n: num(k.primarios), desc: T('master.js.visao.kpi.primarios_desc'), icone: 'bi-person-badge', cor: '#0a6fa0', bg: '#e5f1f7', ir: 'contas' },
      { rot: T('master.js.visao.kpi.secundarios'), n: num(k.secundarios), desc: T('master.js.visao.kpi.secundarios_desc'), icone: 'bi-people', cor: '#3b5bb8', bg: '#e9edf8', ir: 'contas' },
      { rot: T('master.js.visao.kpi.oab'), n: num(k.oabUnicas), desc: T('master.js.visao.kpi.oab_desc', { registros: num(k.oabRegistros), duplicados: num(k.oabRegistros - k.oabUnicas) }), icone: 'bi-person-vcard', cor: '#7a3e8f', bg: '#f3e9f7', ir: 'oab' },
      { rot: T('master.js.visao.kpi.pastas'), n: num(k.pastas), desc: T('master.js.visao.kpi.pastas_desc'), icone: 'bi-folder2-open', cor: '#1e7b45', bg: '#e6f4ec', ir: 'contas' },
      { rot: T('master.js.visao.kpi.documentos'), n: num(k.documentos), desc: T('master.js.visao.kpi.documentos_desc'), icone: 'bi-file-earmark-text', cor: '#1e7b45', bg: '#e6f4ec', ir: 'contas' },
      kpiArmazenamento(k, D.visao.armazenamento),
      { rot: T('master.js.visao.kpi.receita'), n: T('master.js.sem_informacao'), texto: true, desc: T('master.js.visao.kpi.receita_desc'), icone: 'bi-cash-coin', cor: '#7d909d', bg: '#eef2f5', ir: 'custos' }
    ];
    document.getElementById('mp-kpis').innerHTML = kpis.map(x => '<button type="button" class="mp-kpi" data-ir="' + x.ir + '" style="--cor:' + x.cor + ';--bg:' + x.bg + '">'
      + '<span class="mp-kpi-rot">' + esc(x.rot) + '</span>'
      + '<span class="mp-kpi-n' + (x.texto ? ' mp-kpi-n-texto' : '') + '" title="' + esc(x.n) + '" style="--nlen:' + Math.max(3, String(x.n).length) + '">' + esc(x.n) + '</span><span class="mp-kpi-desc">' + esc(x.desc) + '</span>'
      + (x.pct ? '<div class="mp-barra-6 mp-kpi-barra"><div style="--w:' + x.pct + '"></div></div>' : '')
      + '<span class="mp-kpi-base"></span><span class="mp-kpi-brilho"></span></button>').join('');
  }

  // KPI ARMAZENAMENTO (desenho 1.2, linha 688; spec P9): cota = soma dos limites das contas com limite, cor e barra pela faixa.
  function kpiArmazenamento(k, a) {
    const base = { rot: T('master.js.visao.kpi.armazenamento'), n: gb(k.bytes), icone: 'bi-hdd-stack', bg: '#fbf1dc', ir: 'arm' };
    if (!a) return Object.assign(base, { desc: T('master.js.visao.kpi.arm_sem_dado'), cor: '#c08a12' });
    // Número, descrição e barra somam só as contas com limite, como no desenho (a cota é delas); o uso das contas
    // sem limite vem no sufixo. Sem nenhuma conta com limite, fica o texto antigo, com o uso total.
    const sem = a.contasSemLimite > 0 ? ' · ' + T('master.js.visao.kpi.arm_sem_limite', { gb: gb(a.bytesSemLimite), n: num(a.contasSemLimite) }) : '';
    return Object.assign(base, { n: gb(a.bytesUsados), desc: T('master.js.visao.kpi.arm_desc', { cota: num(a.cotaGb), livre: gb(a.bytesLivres) }) + sem, cor: (FAIXA[a.faixa] || FAIXA.livre)[1], pct: a.percentual + '%' });
  }

  /* ---------- Crescimento (gráfico SVG do desenho) ---------- */
  const MES = [T('master.js.visao.grafico.mes.jan'), T('master.js.visao.grafico.mes.fev'), T('master.js.visao.grafico.mes.mar'), T('master.js.visao.grafico.mes.abr'), T('master.js.visao.grafico.mes.mai'), T('master.js.visao.grafico.mes.jun'),
    T('master.js.visao.grafico.mes.jul'), T('master.js.visao.grafico.mes.ago'), T('master.js.visao.grafico.mes.set'), T('master.js.visao.grafico.mes.out'), T('master.js.visao.grafico.mes.nov'), T('master.js.visao.grafico.mes.dez')];
  const CRESC = () => D.visao.crescimento;
  const ANOS = () => Object.keys(CRESC()).map(Number).sort((a, b) => a - b);
  const corDoAno = ano => { const anos = ANOS(), i = anos.length - 1 - anos.indexOf(ano); return ['#0a6fa0', '#7d909d', '#c08a12', '#7a3e8f', '#1e7b45', '#3b5bb8'][i % 6]; };
  const curva = P => { if (!P.length) return ''; if (P.length === 1) return 'M' + P[0].x + ' ' + P[0].y; const k = P.length, dx = [], m = [], t = []; for (let i = 0; i < k - 1; i++) { dx[i] = P[i + 1].x - P[i].x; m[i] = (P[i + 1].y - P[i].y) / dx[i]; } t[0] = m[0]; t[k - 1] = m[k - 2]; for (let i = 1; i < k - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : 3 * (dx[i - 1] + dx[i]) / ((2 * dx[i] + dx[i - 1]) / m[i - 1] + (dx[i] + 2 * dx[i - 1]) / m[i]); let d = 'M' + P[0].x.toFixed(1) + ' ' + P[0].y.toFixed(1); for (let i = 0; i < k - 1; i++) { const h = dx[i] / 3; d += ' C' + (P[i].x + h).toFixed(1) + ' ' + (P[i].y + t[i] * h).toFixed(1) + ' ' + (P[i + 1].x - h).toFixed(1) + ' ' + (P[i + 1].y - t[i + 1] * h).toFixed(1) + ' ' + P[i + 1].x.toFixed(1) + ' ' + P[i + 1].y.toFixed(1); } return d; };

  function renderGrafico() {
    const C = CRESC(), anos = ANOS();
    if (!S.grSel) S.grSel = anos.slice(-2);
    const vis = S.grVis, mod = S.grMod, sel = S.grSel.slice().sort((a, b) => a - b);
    const fmt = v => (S.grMet === 'gb' ? decimal(Math.round(v * 10) / 10, 0, 1) : num(Math.round(v)));
    const serie = a => { const v = C[a][S.grMet]; if (mod === 'acum') return v.slice(); const ant = C[a - 1] ? C[a - 1][S.grMet].filter(x => x !== null).slice(-1)[0] || 0 : 0; return v.map((x, i) => (x === null ? null : Math.max(0, Math.round((x - (i ? v[i - 1] : ant)) * 10) / 10))); };
    let rotulos, grupos;
    if (vis === 'mes') { rotulos = MES.slice(); grupos = sel.map(a => ({ cor: corDoAno(a), vals: serie(a).map((v, i) => ({ v, tip: MES[i] + '/' + a })) })); }
    else if (vis === 'todos') { const pts = [].concat(...sel.map(a => serie(a).map((v, i) => ({ v, tip: MES[i] + '/' + String(a).slice(2) })).filter(p => p.v !== null))); rotulos = pts.map((p, i) => (i % 2 ? '' : p.tip)); grupos = [{ cor: '#0a6fa0', vals: pts }]; }
    else { rotulos = sel.map(String); grupos = [{ cor: '#0a6fa0', vals: sel.map(a => { const v = serie(a).filter(x => x !== null); return { v: mod === 'acum' ? v[v.length - 1] : v.reduce((p, q) => p + q, 0), tip: String(a) }; }) }]; }
    const n = rotulos.length, X = i => (n > 1 ? 60 + i * (688 / (n - 1)) : 404);
    const all = [].concat(...grupos.map(g => g.vals.map(p => p.v).filter(v => v !== null))), mx = Math.max(1, ...all) * 1.12, Y = v => 200 - v / mx * 180;
    const grade = [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: Y(mx * f), topPct: (Y(mx * f) / 230 * 100) + '%', v: fmt(mx * f) }));
    const series = grupos.map(g => { const pts = g.vals.map((p, i) => (p.v === null ? null : { x: X(i), y: Y(p.v), tip: p.tip + ': ' + fmt(p.v) })).filter(Boolean), d = curva(pts); return { cor: g.cor, pts, d, area: pts.length > 1 ? d + ' L' + pts[pts.length - 1].x + ' 200 L' + pts[0].x + ' 200 Z' : '' }; });
    const tit = { contas: T('master.js.visao.grafico.tit_contas'), usuarios: T('master.js.visao.grafico.tit_usuarios'), pastas: T('master.js.visao.grafico.tit_pastas'), gb: T('master.js.visao.grafico.tit_gb') }[S.grMet];
    const seg = (lista, atual, attr) => '<div class="mp-seg">' + lista.map(([k, rot]) => '<button type="button" class="' + (atual === k ? 'on' : '') + '" ' + attr + '="' + k + '">' + esc(rot) + '</button>').join('') + '</div>';
    const todos = sel.length === anos.length;
    document.getElementById('mp-gr').innerHTML = '<div class="mp-gr-topo"><b>' + T('master.js.visao.grafico.titulo') + ' <span>' + esc(tit) + '</span></b>'
      + '<select class="mp-select" data-gr-met>' + [['contas', T('master.js.visao.grafico.met_contas')], ['usuarios', T('master.js.visao.grafico.met_usuarios')], ['pastas', T('master.js.visao.grafico.met_pastas')], ['gb', T('master.js.visao.grafico.met_gb')]].map(([k, r]) => '<option value="' + k + '"' + (S.grMet === k ? ' selected' : '') + '>' + r + '</option>').join('') + '</select>'
      + seg([['mes', T('master.js.visao.grafico.por_mes')], ['ano', T('master.js.visao.grafico.por_ano')], ['todos', T('master.js.visao.grafico.continua')]], vis, 'data-gr-vis') + seg([['acum', T('master.js.visao.grafico.acumulado')], ['novo', T('master.js.visao.grafico.novos')]], mod, 'data-gr-mod')
      + '<div class="mp-anos"><button type="button" class="mp-todos-anos' + (todos ? ' on' : '') + '" data-gr-todos>' + T('master.js.visao.grafico.todos_anos') + '</button>'
      + anos.map(a => { const on = S.grSel.includes(a), cor = corDoAno(a); return '<button type="button" class="mp-ano" data-gr-ano="' + a + '" style="--linha:' + cor + ';--bg:' + (on ? '#fff' : '#f3f6f8') + ';--borda:' + (on ? cor : '#e2e9ee') + ';--cor:' + (on ? '#12242f' : '#98a9b4') + '"><span class="mp-ano-linha"></span>' + a + '</button>'; }).join('') + '</div></div>'
      + '<div class="mp-gr-area">' + grade.map(g => '<span class="mp-gr-y" style="top:' + g.topPct + '">' + esc(g.v) + '</span>').join('')
      + rotulos.map((r, i) => '<span class="mp-gr-x" style="left:' + (X(i) / 760 * 100) + '%">' + esc(r) + '</span>').join('')
      + '<svg viewBox="0 0 760 230" aria-hidden="false">' + grade.map(g => '<line x1="44" x2="748" y1="' + g.y + '" y2="' + g.y + '" stroke="#eef2f5"></line>').join('')
      + series.map(s => '<path class="mp-gr-area-fill" d="' + s.area + '" fill="' + s.cor + '"></path><path class="mp-gr-linha" d="' + s.d + '" stroke="' + s.cor + '"></path>'
        + s.pts.map(p => '<g><circle class="mp-gr-ponto" cx="' + p.x + '" cy="' + p.y + '" r="2.6" stroke="' + s.cor + '"></circle><circle class="mp-gr-alvo" cx="' + p.x + '" cy="' + p.y + '" r="11" fill="' + s.cor + '"><title>' + esc(p.tip) + '</title></circle></g>').join('')).join('')
      + '</svg></div>';
  }

  /* ---------- Onde estamos atuando ---------- */
  const REG_BR = [[T('master.js.mapa.regiao.norte'), ['RO', 'AC', 'AM', 'RR', 'PA', 'AP', 'TO']], [T('master.js.mapa.regiao.nordeste'), ['MA', 'PI', 'CE', 'RN', 'PB', 'PE', 'AL', 'SE', 'BA']], [T('master.js.mapa.regiao.centro_oeste'), ['MS', 'MT', 'GO', 'DF']], [T('master.js.mapa.regiao.sudeste'), ['MG', 'ES', 'RJ', 'SP']], [T('master.js.mapa.regiao.sul'), ['PR', 'SC', 'RS']]];
  // O nome do país é dado, não texto da tela: o servidor manda o nome em português (M.paises) e o painel compara com ele.
  const REG_MUNDO = [[T('master.js.mapa.regiao.america_sul'), ['Argentina', 'Bolívia', 'Chile', 'Colômbia', 'Equador', 'Paraguai', 'Peru', 'Uruguai', 'Venezuela']], [T('master.js.mapa.regiao.america_norte'), ['Canadá', 'México', 'Estados Unidos']], [T('master.js.mapa.regiao.europa'), ['Portugal', 'Espanha', 'Itália', 'França', 'Alemanha', 'Reino Unido', 'Suíça', 'Países Baixos', 'Bélgica', 'Irlanda']], [T('master.js.mapa.regiao.lusofonia'), ['Angola', 'Moçambique', 'Cabo Verde', 'Guiné-Bissau', 'São Tomé e Príncipe', 'Timor-Leste']]];
  // As 27 capitais (desenho 05 L879, `CAP`): nome em português (dado, como o dos estados), comparado sem acento e sem
  // caixa com as cidades que já têm usuário no mapa (G.C, a mesma base do MapaDeAtuacao).
  const CAP = { AC: 'Rio Branco', AL: 'Maceió', AP: 'Macapá', AM: 'Manaus', BA: 'Salvador', CE: 'Fortaleza', DF: 'Brasília', ES: 'Vitória', GO: 'Goiânia', MA: 'São Luís', MT: 'Cuiabá', MS: 'Campo Grande', MG: 'Belo Horizonte', PA: 'Belém', PB: 'João Pessoa', PR: 'Curitiba', PE: 'Recife', PI: 'Teresina', RJ: 'Rio de Janeiro', RN: 'Natal', RS: 'Porto Alegre', RO: 'Porto Velho', RR: 'Boa Vista', SC: 'Florianópolis', SP: 'São Paulo', SE: 'Aracaju', TO: 'Palmas' };
  const semAcento = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const temCidade = (C, uf, cidade) => Object.keys(C[uf] || {}).some(k => semAcento(k) === semAcento(cidade));
  const seg = (lista, atual, attr, comIcone) => '<div class="mp-seg mp-seg-28">' + lista.map(([k, rot, ic]) => '<button type="button" class="' + (atual === k ? 'on' : '') + '" ' + attr + '="' + k + '">' + (comIcone ? '<i class="bi ' + ic + '"></i>' : '') + esc(rot) + '</button>').join('') + '</div>';
  const ehModoOab = () => S.mapaModo !== 'mundo' && S.mapaQuem === 'oab';
  // Modo Indicações (desenho 05 VS 25.10.26, L116 e L1160): só no Brasil; sub-modo env (enviadas), ok (contas) ou pago (R$).
  const ehModoInd = () => S.mapaModo !== 'mundo' && S.mapaQuem === 'ind';

  // Modo OAB (desenho L898): o número da UF é o de inscrições na seccional (GET das seccionais); as cidades com usuário
  // são as das pessoas, a base do mapa (modo Capitais dos que faltam).
  function geo() {
    const M = D.visao.mapa;
    if (S.mapaQuem === 'esc') return { A: M.escritorios.A || {}, C: M.escritorios.C || {}, tend: {}, semEnd: M.escritorios.semEnd || 0, semLoc: 0 };
    if (ehModoInd()) { const i = (M.indicacoes || {})[S.mapaInd] || {}; return { A: i.A || {}, C: i.C || {}, tend: {}, semEnd: 0, semLoc: 0 }; }
    if (ehModoOab()) return { A: secInscricoes(), C: (M.pessoas.geral || {}).C || {}, tend: {}, semEnd: 0, semLoc: 0 };
    const g = M.pessoas[S.mapaUsu] || {};
    return { A: g.A || {}, C: g.C || {}, tend: g.tend || {}, semEnd: 0, semLoc: g.semLoc || 0 };
  }

  function renderMapa() {
    const M = D.visao.mapa, G = geo(), ATUA = G.A, br = S.mapaModo !== 'mundo', ehPes = S.mapaQuem === 'pes', ehOab = ehModoOab();
    const exterior = M.exterior || 0, paisesExt = M.paises || [];
    const totBr = Object.values(ATUA).reduce((a, b) => a + b, 0), ufTotal = totBr + exterior;
    document.getElementById('mp-mapa-topo').innerHTML = mapaTopo(br, ehPes, ufTotal);
    const usuTit = ehModoInd() ? T('master.js.mapa.col_ind_' + S.mapaInd) : ehOab ? T('master.js.mapa.inscricoes') : S.mapaQuem === 'esc' ? T('master.js.mapa.escritorios') : S.mapaUsu === 'prim' ? T('master.js.mapa.primarios') : T('master.js.mapa.pessoas');

    document.getElementById('mp-uf-tab').innerHTML = '<div class="mp-uf-cab"><span>' + (br ? T('master.js.mapa.estado') : T('master.js.mapa.pais')) + '</span><span>' + usuTit + '</span><span></span></div>'
      + (br ? linhasDoBrasil(G) : linhasDoMundo(totBr, paisesExt))
      + (ehOab ? blocoSeccionais() : '')
      + (br ? '<div class="mp-uf-outros"><span><i class="bi bi-globe-americas"></i>' + T('master.js.mapa.outros_paises') + '</span><b>' + num(exterior) + '</b></div>' : '')
      + (S.mapaQuem === 'esc' && G.semEnd > 0 ? '<div class="mp-uf-semend"><span><i class="bi bi-geo"></i>' + T('master.js.mapa.sem_endereco') + '</span><b>' + num(G.semEnd) + '</b></div>' : '')
      + (ehPes && G.semLoc > 0 ? '<div class="mp-uf-semend" title="' + esc(T('master.js.mapa.sem_localizacao_dica')) + '"><span><i class="bi bi-geo"></i>' + T('master.js.mapa.sem_localizacao') + '</span><b>' + num(G.semLoc) + '</b></div>' : '')
      + '<div class="mp-uf-total"><span>' + T('master.js.mapa.total_geral') + '</span><span>' + num(ufTotal) + '</span></div>'
      + avisoHtml() + faltamHtml(faltamGrupos(br, G, paisesExt), br);

    // iframe: só troca o endereço quando a visão muda (evita recarregar o mapa a cada clique). O iframe busca as
    // contagens no servidor e as valida; a query string só escolhe a visão (quem=oab no modo OAB do Brasil).
    const quem = ehOab ? 'oab' : ehModoInd() ? 'ind' : S.mapaQuem === 'esc' ? 'esc' : 'pes';
    const src = (br ? URLS.mapaBrasil : URLS.mapaMundo) + '?quem=' + quem + '&usu=' + (S.mapaUsu === 'prim' ? 'prim' : 'geral') + (quem === 'ind' ? '&sub=' + S.mapaInd : ''), fr = document.getElementById('mp-mapa-iframe');
    fr.style.setProperty('--ratio', br ? '1.75' : '2.05');
    fr.title = br ? T('master.js.mapa.iframe_brasil') : T('master.js.mapa.iframe_mundo');
    if (fr.dataset.src !== src) { fr.dataset.src = src; fr.src = src; }
  }

  // Título, subtítulo e seletores (desenho L114 e L875): "quem" com Pessoas, Escritórios e OAB, só no Brasil.
  function mapaTopo(br, ehPes, ufTotal) {
    const sub = !br ? T('master.js.mapa.por_pais') : ehModoOab() ? T('master.js.mapa.por_seccional') : ehModoInd() ? T('master.js.mapa.por_estado_ind') : T('master.js.mapa.por_estado');
    return '<b>' + T('master.js.mapa.titulo') + '</b><span class="mp-mapa-sub">' + sub + ' · ' + T('master.js.mapa.total', { n: num(ufTotal) }) + '</span>'
      + (br ? seg([['pes', T('master.js.mapa.pessoas'), 'bi-people'], ['esc', T('master.js.mapa.escritorios'), 'bi-buildings'], ['oab', T('master.js.mapa.oab'), 'bi-bank'], ['ind', T('master.js.mapa.indicacoes'), 'bi-share']], S.mapaQuem, 'data-mapa-quem', true) : '')
      + (ehModoInd() ? seg([['env', T('master.js.mapa.ind_env')], ['ok', T('master.js.mapa.ind_ok')], ['pago', T('master.js.mapa.ind_pago')]], S.mapaInd, 'data-mapa-ind', false) : '')
      + (ehPes && br ? seg([['geral', T('master.js.mapa.geral')], ['prim', T('master.js.mapa.so_primarios')]], S.mapaUsu, 'data-mapa-usu', false) : '')
      + seg([['br', T('master.js.mapa.brasil'), 'bi-geo-alt-fill'], ['mundo', T('master.js.mapa.mundo'), 'bi-globe-americas']], S.mapaModo, 'data-mapa-modo', true);
  }

  function linhasDoMundo(totBr, paisesExt) {
    const P = [['076', 'Brasil', totBr]].concat(paisesExt), mx = Math.max(1, ...P.map(p => p[2]));
    return P.sort((a, b) => b[2] - a[2]).map(p => '<div class="mp-uf-lin"><span class="mp-uf-barra" style="--w:' + pct(p[2], mx) + '"></span><span class="mp-uf-nome">' + esc(p[1]) + '</span><b>' + num(p[2]) + '</b><i class="mp-uf-seta"></i></div>').join('');
  }

  // Linha da UF (desenho L118 e L898, `u.ir`/`u.cur`): no modo OAB o nome é "OAB/<UF> · <Estado>" e o clique abre o
  // cartão da sede; nos outros modos a linha não tem ação. Tendência só nas Pessoas.
  function linhasDoBrasil(G) {
    const ehOab = ehModoOab(), semTend = S.mapaQuem !== 'pes', maxAt = Math.max(1, ...Object.values(G.A));
    return Object.entries(G.A).sort((a, b) => b[1] - a[1]).map(([uf, n]) => {
      const t = semTend ? { ag: 0, an: 0 } : (G.tend[uf] || { ag: 0, an: 0 }), dl = t.ag - t.an;
      const ic = dl > 0 ? 'bi-arrow-up-right' : dl < 0 ? 'bi-arrow-down-right' : 'bi-arrow-right', cor = dl > 0 ? '#1e7b45' : dl < 0 ? '#a3232b' : '#9aabb7';
      const tit = semTend ? T('master.js.mapa.tendencia_so_pessoas') : T('master.js.mapa.tendencia', { ag: (t.ag >= 0 ? '+' : '') + t.ag, an: (t.an >= 0 ? '+' : '') + t.an });
      const acao = ehOab ? ' is-oab" role="button" tabindex="0" data-sec-linha="' + esc(uf) + '"' : '"';
      const nome = ehOab ? T('master.js.seccionais.linha', { uf, estado: NM[uf] || uf }) : (NM[uf] || uf);
      return '<div class="mp-uf-lin' + acao + '><span class="mp-uf-barra" style="--w:' + pct(n, maxAt) + '"></span><span class="mp-uf-nome">' + esc(nome) + '</span><b>' + num(n) + '</b><i class="bi ' + ic + ' mp-uf-seta" style="--cor:' + cor + '" title="' + esc(tit) + '"></i></div>';
    }).join('');
  }

  // Os que faltam (desenho L877-882): no Brasil, Estados (sem usuário na UF) ou Capitais (sem usuário na capital);
  // no Mundo, os países-alvo.
  function faltamGrupos(br, G, paisesExt) {
    if (!br) {
      const tem = new Set(['Brasil'].concat(paisesExt.map(p => p[1])));
      const grupos = REG_MUNDO.map(([rot, ps]) => { const itens = ps.filter(p => !tem.has(p)).sort((a, b) => a.localeCompare(b)).map(p => ({ uf: null, cidade: '', r: p, sub: '' })); return { rot, n: itens.length, itens }; }).filter(g => g.n);
      const tot = REG_MUNDO.reduce((a, g) => a + g[1].length, 0);
      return { grupos, tot, meta: T('master.js.faltam.meta_paises', { n: tot }) };
    }
    secGarantir();
    const cid = S.faltamCid, item = cid ? (u => ({ uf: u, cidade: CAP[u], r: CAP[u], sub: u })) : (u => ({ uf: u, cidade: '', r: NM[u], sub: '' }));
    const falta = cid ? (u => !temCidade(G.C, u, CAP[u])) : (u => !G.A[u]);
    const grupos = REG_BR.map(([rot, ufs]) => { const itens = ufs.filter(falta).map(item).sort((a, b) => a.r.localeCompare(b.r)); return { rot, n: itens.length, itens }; }).filter(g => g.n);
    return { grupos, tot: 27, meta: cid ? T('master.js.faltam.meta_capitais', { n: 27 }) : T('master.js.faltam.meta_estados', { n: 27 }) };
  }

  // Botão, seletor Estados/Capitais (só no Brasil, L134 e L888), cobertura, barra e a lista por região.
  function faltamHtml(F, br) {
    const on = S.faltamOn, n = F.grupos.reduce((a, g) => a + g.n, 0), feitos = F.tot - n, p = Math.round(feitos / F.tot * 100);
    const modos = br ? '<div class="mp-faltam-modos">' + [['', T('master.js.faltam.modo_estados')], ['1', T('master.js.faltam.modo_capitais')]].map(([k, r]) => '<button type="button" class="' + (!!S.faltamCid === !!k ? 'on' : '') + '" data-faltam-modo="' + k + '">' + esc(r) + '</button>').join('') + '</div>' : '';
    return '<button type="button" class="mp-faltam-btn' + (on ? ' on' : '') + '" data-faltam title="' + esc(T('master.js.faltam.dica')) + '"><i class="bi bi-bullseye"></i><span>' + (on ? T('master.js.faltam.ocultar') : (br ? T('master.js.faltam.estados') : T('master.js.faltam.paises'))) + '</span><span class="mp-faltam-n">' + n + '</span><i class="bi ' + (on ? 'bi-chevron-up' : 'bi-chevron-down') + '"></i></button>'
      + (on ? '<div class="mp-faltam">' + modos + '<div class="mp-faltam-cob">' + T('master.js.faltam.cobertura') + ' <b>' + T('master.js.faltam.cobertura_valor', { feitos, total: F.tot, pct: p }) + '</b> · ' + T('master.js.faltam.faltam', { n, meta: F.meta }) + '</div>'
        + '<div class="mp-faltam-barra"><div style="--w:' + p + '%"></div></div><div class="mp-faltam-lista">'
        + F.grupos.map(g => '<div class="mp-faltam-grupo">' + esc(g.rot) + ' · ' + g.n + '</div>' + g.itens.map(faltamItem).join('')).join('')
        + '</div><div class="mp-faltam-rod">' + T('master.js.faltam.rodape') + '</div></div>' : '');
  }

  /* ---------- Seccionais da OAB (desenho 05, Perfis 1.2, VS 21.10.26, L116-127 e L898-905; spec master-seccionais-oab) ----------
     Bloco "SECCIONAIS VISÍVEIS" embaixo das UFs, só no modo OAB do Brasil. Tudo vem de /master/dados/seccionais (o
     mesmo GET do iframe): sede, inscrições, visibilidade (compartilhada entre os Masters) e a marca da divulgação.
     Mostrar ou ocultar e "Mostrar todas" gravam no servidor e avisam o iframe. */
  const SEC_ERRO_SALVAR = T('master.js.seccionais.erro_salvar');
  const SEC_CFOAB = 'https://www.oab.org.br/institucionalconselhofederal/asiseccional';
  const SEC = { dados: null, estado: null, aberta: null };
  const secDe = uf => (SEC.dados ? SEC.dados.seccionais.find(s => s.uf === uf) : null);
  const secCor = c => (/^#[0-9a-f]{6}$/i.test(c || '') ? c : null);
  const secSite = s => (/^https?:\/\/\S+$/i.test(s || '') ? s : null);
  const secEstado = s => NM[s.uf] || s.nome;
  const secInscritos = n => (n === 1 ? T('master.js.seccionais.inscricao_um') : T('master.js.seccionais.inscricoes', { n: num(n) }));
  function secInscricoes() {
    if (!SEC.dados) { secGarantir(); return {}; }
    const A = {};
    SEC.dados.seccionais.forEach(s => { if (s.inscricoes > 0) A[s.uf] = s.inscricoes; });
    return A;
  }

  function carregarSeccionais() {
    if (SEC.estado === 'carregando') return;
    SEC.estado = 'carregando';
    fetch(URLS.seccionais, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(j => { SEC.dados = j; SEC.estado = null; })
      .catch(() => { SEC.estado = 'falhou'; })
      .finally(() => { if (S.sec === 'visao' && D.visao && document.getElementById('mp-uf-tab')) renderMapa(); if (OAB.uf) renderOab(); });
  }
  // Os estados que faltam e o modo OAB leem daqui também.
  function secGarantir() { if (!SEC.dados && SEC.estado !== 'falhou') carregarSeccionais(); }
  // O iframe avisa depois de registrar uma divulgação (mesmo padrão do __bjFontesAuto do mapa-dados.js).
  window.__bjSeccionaisRecarregar = () => { SEC.estado = null; carregarSeccionais(); };
  // E o painel avisa o iframe (mesma origem) quando a visibilidade muda: ele relê as seccionais.
  function secAvisarMapa() {
    try { const w = document.getElementById('mp-mapa-iframe').contentWindow; if (w && typeof w.__bjSeccionaisAtualizar === 'function') w.__bjSeccionaisAtualizar(); } catch (e) { /* iframe ainda sem a página */ }
  }

  function blocoSeccionais() {
    if (!SEC.dados) {
      secGarantir();
      return '<div class="mp-sec-oab" data-sec-oab><span class="mp-sec-oab-nota">' + (SEC.estado === 'falhou' ? T('master.js.seccionais.erro_carga') : T('master.js.seccionais.carregando')) + '</span></div>';
    }
    const todas = SEC.dados.seccionais, algumaOculta = todas.some(s => !s.visivel);
    return '<div class="mp-sec-oab" data-sec-oab><div class="mp-sec-oab-cab"><b>' + T('master.js.seccionais.titulo') + '</b><button type="button" data-sec-todas>' + (algumaOculta ? T('master.js.seccionais.mostrar_todas') : T('master.js.seccionais.todas_visiveis')) + '</button></div>'
      + '<div class="mp-sec-oab-chips">' + todas.map(secChip).join('') + '</div>'
      + (secDe(SEC.aberta) ? secCartao(secDe(SEC.aberta)) : '')
      + '<div class="mp-sec-oab-nota">' + esc(T('master.js.seccionais.nota')) + '</div></div>';
  }

  // Chip (L902): tooltip do desenho; o ponto aparece só com status e cor, na cor da marca.
  function secChip(s) {
    const m = s.marca && secCor(s.marca.cor) ? s.marca : null, base = { uf: s.uf, estado: secEstado(s) };
    const tit = m ? T('master.js.seccionais.chip_titulo_marca', Object.assign(base, { status: m.rotulo, data: dataBr(m.data) })) : T('master.js.seccionais.chip_titulo', base);
    return '<button type="button" class="mp-sec-chip' + (s.visivel ? '' : ' is-oculta') + (SEC.aberta === s.uf ? ' is-aberta' : '') + '" data-sec-uf="' + esc(s.uf) + '" title="' + esc(tit) + '">' + esc(s.uf)
      + (m ? '<span class="mp-sec-ponto" style="--cor:' + m.cor + '"></span>' : '') + '</button>';
  }

  // Cartão da sede (L903): título, inscrições, endereço, telefone, site, CFOAB e "Editar sede".
  function secCartao(s) {
    const site = secSite(s.site);
    return '<div class="mp-sec-card" data-sec-card><div class="mp-sec-card-cab"><i class="bi bi-bank"></i><span>' + esc(T('master.js.seccionais.cartao_titulo', { uf: s.uf, estado: secEstado(s) })) + '</span><button type="button" data-sec-fechar title="' + esc(T('master.js.fechar')) + '" aria-label="' + esc(T('master.js.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
      + '<div class="mp-sec-card-corpo"><span><i class="bi bi-people"></i>' + esc(secInscritos(s.inscricoes)) + '</span>'
      + '<span><i class="bi bi-geo-alt"></i>' + esc(s.endereco || T('master.js.seccionais.sem_endereco')) + '</span>'
      + (s.telefone ? '<span><i class="bi bi-telephone"></i>' + esc(s.telefone) + '</span>' : '')
      + (site ? '<a href="' + esc(site) + '" target="_blank" rel="noopener"><i class="bi bi-globe2"></i>' + T('master.js.seccionais.site') + '</a>' : '')
      + '<div class="mp-sec-card-acoes"><a href="' + SEC_CFOAB + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>' + T('master.js.seccionais.cfoab') + '</a>'
      + '<button type="button" data-sec-editar><i class="bi bi-pencil"></i> ' + T('master.js.seccionais.editar_sede') + '</button></div></div></div>';
  }

  // Botão direito no chip (x.tgCtx): a tela muda na hora e o servidor grava o estado pedido (idempotente); na recusa,
  // volta ao anterior e avisa.
  function secAlternar(uf) {
    const s = secDe(uf); if (!s) return;
    const antes = s.visivel;
    s.visivel = !antes; renderMapa();
    secPostar(SEC.dados.urlVisibilidade.replace('/XX/', '/' + uf + '/'), { visivel: s.visivel }, secAvisarMapa, erro => { s.visivel = antes; renderMapa(); avisarNoMapa(erro || SEC_ERRO_SALVAR); });
  }

  // "Mostrar todas" / "Todas visíveis" (secTodas): o clique sempre limpa o filtro.
  function secTodas() {
    SEC.dados.seccionais.forEach(s => { s.visivel = true; }); renderMapa();
    secPostar(SEC.dados.urlMostrarTodas, {}, secAvisarMapa, erro => { avisarNoMapa(erro || SEC_ERRO_SALVAR); window.__bjSeccionaisRecarregar(); });
  }

  // "Editar sede" no molde das janelas de limite e plano. Os rótulos são os do desenho: os do cartão (L904) ou os da
  // janela da OAB (L890).
  const SEDE_ROTULOS = {
    cartao: ['master.js.seccionais.campo_endereco', 'master.js.seccionais.campo_telefone', 'master.js.seccionais.campo_site'],
    janela: ['master.js.janela_oab.campo_endereco_sede', 'master.js.janela_oab.campo_telefone', 'master.js.janela_oab.campo_site']
  };
  function secAbrirEdicao(s, origem) {
    const host = document.getElementById('mp-modal'); if (!host) return;
    const [rEnd, rTel, rSite] = SEDE_ROTULOS[origem].map(k => esc(T(k, { uf: s.uf })));
    host.innerHTML = caixa('bi-bank', T('master.js.seccionais.editar_sede'), '<b class="mp-lim-conta" data-sede="' + esc(s.uf) + '">' + esc(T('master.js.seccionais.cartao_titulo', { uf: s.uf, estado: secEstado(s) })) + '</b>'
      + campo(rEnd, 'type="text" maxlength="255" data-sede-endereco value="' + esc(s.endereco || '') + '"')
      + campo(rTel, 'type="text" maxlength="40" data-sede-telefone value="' + esc(s.telefone || '') + '"')
      + campo(rSite, 'type="url" maxlength="255" placeholder="https://" data-sede-site value="' + esc(s.site || '') + '"')
      + '<span class="mp-lim-msg" data-lim-msg></span>', T('master.js.seccionais.salvar_sede'));
    secAtualizarEdicao(null);
    host.querySelector('[data-sede-endereco]').focus();
  }

  function secAtualizarEdicao(erro) {
    const host = document.getElementById('mp-modal'), site = host.querySelector('[data-sede-site]').value.trim(), ok = site === '' || /^https?:\/\/\S+$/i.test(site);
    const msg = host.querySelector('[data-lim-msg]');
    msg.textContent = erro || (ok ? T('master.js.seccionais.sede_ajuda') : T('master.js.seccionais.site_invalido'));
    msg.style.setProperty('--cor', erro || !ok ? '#a3232b' : '#4a6373');
    host.querySelector('[data-lim-ok]').disabled = !ok;
  }

  function secSalvarEdicao() {
    const host = document.getElementById('mp-modal'), uf = host.querySelector('[data-sede]').dataset.sede, v = sel => host.querySelector(sel).value;
    host.querySelector('[data-lim-ok]').disabled = true;
    secPostar(SEC.dados.urlSede.replace('/XX/', '/' + uf + '/'), { endereco: v('[data-sede-endereco]'), telefone: v('[data-sede-telefone]'), site: v('[data-sede-site]') }, () => {
      fecharModal(); window.__bjSeccionaisRecarregar();
    }, erro => { if (host.querySelector('[data-sede]')) secAtualizarEdicao(erro || SEC_ERRO_SALVAR); });
  }

  // POST JSON com o token no header (o controller lê X-CSRF-Token), com o mesmo critério do postar() do painel.
  function secPostar(url, corpo, aoDarCerto, aoFalhar) {
    fetch(url, { method: 'POST', body: JSON.stringify(corpo), credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': SEC.dados.token } })
      .then(r => r.json().catch(() => null).then(j => ({ r, j })))
      .then(({ r, j }) => { const json = !r.redirected && j !== null && typeof j === 'object'; if (json && r.ok && j.mensagem) return aoDarCerto(j); aoFalhar(json && !r.ok ? j.erro : null); })
      .catch(() => aoFalhar(null));
  }

  // Eventos do bloco: ouvintes próprios (não passam pelos da seção nem pelos das janelas de limite e plano).
  elSecao.addEventListener('click', e => {
    if (!e.target.closest('[data-sec-oab]')) return;
    const chip = e.target.closest('[data-sec-uf]');
    if (chip) { SEC.aberta = SEC.aberta === chip.dataset.secUf ? null : chip.dataset.secUf; return renderMapa(); }
    if (e.target.closest('[data-sec-fechar]')) { SEC.aberta = null; return renderMapa(); }
    if (e.target.closest('[data-sec-todas]')) return secTodas();
    if (e.target.closest('[data-sec-editar]') && secDe(SEC.aberta)) return secAbrirEdicao(secDe(SEC.aberta), 'cartao');
  });
  elSecao.addEventListener('contextmenu', e => {
    const chip = e.target.closest('[data-sec-oab] [data-sec-uf]'); if (!chip) return;
    e.preventDefault(); secAlternar(chip.dataset.secUf);
  });
  (host => {
    if (!host) return;
    host.addEventListener('click', e => { if (host.querySelector('[data-sede]') && e.target.closest('[data-lim-ok]')) secSalvarEdicao(); });
    host.addEventListener('input', () => { if (host.querySelector('[data-sede]')) secAtualizarEdicao(null); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && host.querySelector('[data-sede]')) fecharModal(); });
  })(document.getElementById('mp-modal'));

  /* ---------- Janela da OAB (desenho 05, Perfis 1.2, VS 21.10.26, L140 e L481-495, lógica L879-896;
     spec master-oab-estados-que-faltam) ----------
     O estado ou a capital que falta (L140) abre, só no Brasil, a janela da OAB do estado ou da cidade: a sede (o MESMO
     "Editar sede" do cartão, com os rótulos da janela), as subseções por cidade (adicionar e remover) e a nota do estado
     ou da cidade. Tudo vem do GET das seccionais; cada escrita é um POST com o token no header e recarrega as
     seccionais. No Mundo o item não tem ação (L885). */
  const OAB_MAPS = 'https://www.google.com/maps/search/?api=1&query=';
  const OAB_BUSCA = 'https://www.google.com/search?q=';
  const OAB_FUSO = 'America/Sao_Paulo';
  const OAB = { uf: null, cidade: '', rascunho: null, form: null, msg: null, enviando: false };
  const oabNotaDe = (sec, cidade) => (cidade ? (sec.notasCidades || {})[cidade] : sec.nota) || null;
  // "Nota salva em dd/mm/aaaa, hh:mm" no fuso de Brasília (L896).
  const oabDataHora = iso => new Intl.DateTimeFormat('pt-BR', { timeZone: OAB_FUSO, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

  // Item da lista dos que faltam (L140): ponto, nome, ícone de nota, subtítulo (vazio no estado, a UF na capital) e
  // seta. No Mundo, sem ação, sem mão e sem tooltip.
  function faltamItem(f) {
    const corpo = '<span class="mp-faltam-ponto"></span><span class="mp-faltam-nome">' + esc(f.r) + '</span>';
    if (!f.uf) return '<div class="mp-faltam-uf">' + corpo + '<span class="mp-faltam-sub"></span><i class="bi bi-chevron-right"></i></div>';
    const sec = secDe(f.uf), nota = sec && oabNotaDe(sec, f.cidade);
    return '<div class="mp-faltam-uf is-oab" role="button" tabindex="0" data-oab-uf="' + esc(f.uf) + '" data-oab-cidade="' + esc(f.cidade) + '" title="' + esc(T('master.js.janela_oab.dica')) + '">' + corpo
      + (nota ? '<i class="bi bi-sticky-fill" title="' + esc(T('master.js.janela_oab.tem_nota')) + '"></i>' : '')
      + '<span class="mp-faltam-sub">' + esc(f.sub) + '</span><i class="bi bi-chevron-right"></i></div>';
  }

  // Host próprio da janela, dentro do #master-painel (o "Editar sede" e a confirmação sobem por cima, ver o CSS).
  let elOab = null;
  function oabHost() {
    if (elOab) return elOab;
    elOab = document.createElement('div');
    elOab.className = 'mp-oab-host';
    raiz.appendChild(elOab);
    elOab.addEventListener('click', oabClique);
    elOab.addEventListener('input', oabDigitou);
    return elOab;
  }

  // O nome vem preenchido com "Subseção de <cidade>" (L894) e acompanha a cidade até a pessoa mexer nele.
  function oabDigitou(e) {
    if (e.target.matches('[data-oab-nota]')) OAB.rascunho = e.target.value;
    const chave = e.target.dataset.oabForm; if (!chave || !OAB.form) return;
    OAB.form[chave] = e.target.value;
    if (chave === 'nome') OAB.form.nomeAuto = false;
    if (chave !== 'cidade') return;
    const cidade = e.target.value.trim(), nome = elOab.querySelector('[data-oab-form="nome"]'), rotulo = elOab.querySelector('[data-oab-nome-rotulo]');
    if (rotulo) rotulo.textContent = T('master.js.janela_oab.campo_nome', { cidade: cidade || '…' });
    if (OAB.form.nomeAuto && nome) { OAB.form.nome = cidade ? T('master.js.janela_oab.nome_padrao', { cidade }) : ''; nome.value = OAB.form.nome; }
  }

  // Carga das seccionais falhada antes: o clique tenta de novo (se falhar outra vez, o renderOab avisa).
  function abrirOab(uf, cidade) {
    Object.assign(OAB, { uf, cidade: cidade || '', rascunho: null, form: null, msg: null, enviando: false });
    if (SEC.estado === 'falhou') SEC.estado = null;
    secGarantir();
    renderOab();
  }

  function fecharOab() { OAB.uf = null; renderOab(); }

  // Sem os dados ainda: espera a carga (o finally do carregarSeccionais chama de novo). Carga falhou: não abre e
  // mostra o aviso de erro do painel.
  function renderOab() {
    const host = oabHost(), sec = OAB.uf ? secDe(OAB.uf) : null;
    if (OAB.uf && !sec && SEC.estado === 'falhou') { OAB.uf = null; avisarNoMapa(T('master.js.seccionais.erro_carga')); }
    raiz.classList.toggle('mp-oab-aberta', !!sec);
    host.innerHTML = sec ? oabJanela(sec) : '';
  }

  // L482-484 e L889: título (estado, ou "<Cidade> · <UF>") e subtítulo do desenho; rodapé com "Fechar" e "Salvar nota".
  function oabJanela(sec) {
    const tit = OAB.cidade ? T('master.js.janela_oab.titulo_cidade', { cidade: OAB.cidade, uf: sec.uf }) : secEstado(sec);
    const sub = OAB.cidade ? T('master.js.janela_oab.sub_cidade', { uf: sec.uf }) : T('master.js.janela_oab.sub_estado', { uf: sec.uf });
    return '<div class="mp-oab-fundo" data-oab-fechar></div><div class="mp-oab-centro"><div class="mp-oab" role="dialog" aria-modal="true" aria-labelledby="mpOabTitulo">'
      + '<div class="mp-oab-cab"><i class="bi bi-bank"></i><span class="mp-oab-cab-txt"><b id="mpOabTitulo">' + esc(tit) + '</b><span>' + esc(sub) + '</span></span>'
      + '<button type="button" data-oab-fechar title="' + esc(T('master.js.fechar')) + '" aria-label="' + esc(T('master.js.fechar')) + '"><i class="bi bi-x-lg"></i></button></div>'
      + '<div class="mp-oab-corpo">' + oabSede(sec) + oabSubsecoes(sec) + oabNota(sec)
      + (OAB.msg ? '<span class="mp-oab-msg' + (OAB.msg.ok ? ' is-ok' : '') + '" role="status">' + esc(OAB.msg.texto) + '</span>' : '') + '</div>'
      + '<div class="mp-oab-rodape"><button type="button" class="mp-oab-fechar" data-oab-fechar>' + T('master.js.fechar') + '</button>'
      + '<button type="button" class="mp-oab-salvar" data-oab-nota-salvar' + (OAB.enviando ? ' disabled' : '') + '><i class="bi bi-check2"></i> ' + T('master.js.janela_oab.salvar_nota') + '</button></div></div></div>';
  }

  // L486 e L889-890: SECCIONAL · SEDE com "Editar", endereço ("Endereço ainda não cadastrado" em cinza), telefone,
  // "Ver no mapa" e "Site oficial".
  function oabSede(sec) {
    const site = secSite(sec.site), mapa = sec.endereco ? OAB_MAPS + encodeURIComponent(sec.endereco) : null;
    return '<div class="mp-oab-sede"><div class="mp-oab-tit"><b>' + T('master.js.janela_oab.sede') + '</b><button type="button" class="mp-oab-editar" data-oab-editar><i class="bi bi-pencil"></i> ' + T('master.js.janela_oab.editar') + '</button></div>'
      + '<div class="mp-oab-end' + (sec.endereco ? '' : ' is-vazio') + '">' + esc(sec.endereco || T('master.js.janela_oab.sem_endereco_sede')) + '</div>'
      + (sec.telefone ? '<div class="mp-oab-tel"><i class="bi bi-telephone"></i> ' + esc(sec.telefone) + '</div>' : '')
      + '<div class="mp-oab-links">' + (mapa ? '<a href="' + esc(mapa) + '" target="_blank" rel="noopener"><i class="bi bi-geo-alt"></i> ' + T('master.js.janela_oab.ver_mapa') + '</a>' : '')
      + (site ? '<a href="' + esc(site) + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> ' + T('master.js.janela_oab.site_oficial') + '</a>' : '') + '</div></div>';
  }

  // L487-489 e L893-895: SUBSEÇÕES. Na janela do estado, as de todas as cidades da UF; na da cidade, só as dela. Vazio:
  // o texto do desenho com a busca do endereço oficial no Google.
  function oabSubsecoes(sec) {
    const lista = OAB.cidade ? sec.subsecoes.filter(x => semAcento(x.cidade) === semAcento(OAB.cidade)) : sec.subsecoes;
    const busca = OAB.cidade ? T('master.js.janela_oab.busca_cidade', { cidade: OAB.cidade, estado: secEstado(sec) }) : T('master.js.janela_oab.busca_estado', { estado: secEstado(sec) });
    return '<div class="mp-oab-subs"><div class="mp-oab-tit"><b>' + T('master.js.janela_oab.subsecoes_titulo') + '</b><button type="button" class="mp-oab-add" data-oab-sub-add><i class="bi bi-plus-lg"></i> ' + T('master.js.janela_oab.adicionar_endereco') + '</button></div>'
      + (OAB.form ? oabFormulario() : '')
      + (lista.length ? '' : '<div class="mp-oab-subs-vazio">' + T('master.js.janela_oab.subs_vazio', { link: '<a href="' + esc(OAB_BUSCA + encodeURIComponent(busca)) + '" target="_blank" rel="noopener">' + T('master.js.janela_oab.pesquisar') + '</a>' }) + '</div>')
      + lista.map(x => '<div class="mp-oab-sub"><i class="bi bi-geo-alt"></i><span><b>' + esc(x.nome) + '</b><span>' + esc(x.endereco) + '</span>'
        + (x.telefone ? '<span class="mp-oab-sub-tel">' + esc(x.telefone) + '</span>' : '') + '</span>'
        + '<button type="button" title="' + esc(T('master.js.janela_oab.remover')) + '" aria-label="' + esc(T('master.js.janela_oab.remover')) + '" data-oab-sub-rm="' + esc(x.id) + '"><i class="bi bi-trash3"></i></button></div>').join('') + '</div>';
  }

  // "Adicionar endereço" (L894): os campos dos prompts do desenho num formulário na própria janela. A cidade só é
  // pedida na janela do estado; na da cidade, vem dela.
  const oabCampo = (rotulo, chave, max, extra) => '<label class="mp-oab-campo"><span' + (extra || '') + '>' + esc(rotulo) + '</span><input type="text" maxlength="' + max + '" data-oab-form="' + chave + '" value="' + esc(OAB.form[chave]) + '"></label>';
  function oabFormulario() {
    const cidade = OAB.cidade || OAB.form.cidade.trim();
    return '<div class="mp-oab-form">' + (OAB.cidade ? '' : oabCampo(T('master.js.janela_oab.campo_cidade'), 'cidade', 120))
      + oabCampo(T('master.js.janela_oab.campo_nome', { cidade: cidade || '…' }), 'nome', 120, ' data-oab-nome-rotulo')
      + oabCampo(T('master.js.janela_oab.campo_endereco_completo'), 'endereco', 255) + oabCampo(T('master.js.janela_oab.campo_telefone'), 'telefone', 40)
      + '<div class="mp-oab-form-acoes"><button type="button" class="mp-oab-form-cancelar" data-oab-form-cancelar>' + T('master.js.cancelar') + '</button>'
      + '<button type="button" class="mp-oab-form-ok" data-oab-form-ok' + (OAB.enviando ? ' disabled' : '') + '>' + T('master.js.janela_oab.adicionar') + '</button></div></div>';
  }

  // L491 e L896: NOTA EXPLICATIVA do estado ou da cidade e "Nota salva em dd/mm/aaaa, hh:mm".
  function oabNota(sec) {
    const nota = oabNotaDe(sec, OAB.cidade), texto = OAB.rascunho !== null ? OAB.rascunho : (nota ? nota.texto : '');
    return '<label class="mp-oab-nota">' + T('master.js.janela_oab.nota') + '<textarea rows="3" maxlength="1000" data-oab-nota placeholder="' + esc(T('master.js.janela_oab.exemplo')) + '">' + esc(texto) + '</textarea>'
      + '<span>' + (nota ? esc(T('master.js.janela_oab.nota_salva', { data: oabDataHora(nota.atualizadaEm) })) : '') + '</span></label>';
  }

  function oabClique(e) {
    const t = e.target, sec = secDe(OAB.uf);
    if (t.closest('[data-oab-fechar]')) return fecharOab();
    if (!sec) return;
    if (t.closest('[data-oab-editar]')) return secAbrirEdicao(sec, 'janela');
    if (t.closest('[data-oab-sub-add]')) return oabAbrirFormulario();
    if (t.closest('[data-oab-form-cancelar]')) { OAB.form = null; return renderOab(); }
    if (t.closest('[data-oab-form-ok]')) return oabEnviar(SEC.dados.urlSubsecoes.replace('/XX/', '/' + OAB.uf + '/'), oabCorpoDaSubsecao(), () => { OAB.form = null; }, false);
    const rm = t.closest('[data-oab-sub-rm]'); if (rm) return oabPedirRemocao(sec, +rm.dataset.oabSubRm);
    if (t.closest('[data-oab-nota-salvar]')) return oabEnviar(SEC.dados.urlNota.replace('/XX/', '/' + OAB.uf + '/'), { texto: elOab.querySelector('[data-oab-nota]').value, cidade: OAB.cidade }, () => {}, true);
  }

  function oabAbrirFormulario() {
    OAB.form = OAB.form || { cidade: '', nome: OAB.cidade ? T('master.js.janela_oab.nome_padrao', { cidade: OAB.cidade }) : '', endereco: '', telefone: '', nomeAuto: true };
    OAB.msg = null; renderOab();
    elOab.querySelector(OAB.cidade ? '[data-oab-form="nome"]' : '[data-oab-form="cidade"]').focus();
  }

  const oabCorpoDaSubsecao = () => ({ cidade: OAB.cidade || OAB.form.cidade, nome: OAB.form.nome, endereco: OAB.form.endereco, telefone: OAB.form.telefone });

  // "Remover" pede a confirmação do desenho, "Remover este endereço?", na janela de confirmação do painel (BJMasterConf).
  function oabPedirRemocao(sec, id) {
    const escolhida = sec.subsecoes.find(v => v.id === id); if (!escolhida || !window.BJMasterConf) return;
    const url = SEC.dados.urlSubsecaoRemover.replace('/XX/', '/' + sec.uf + '/').replace('/0/', '/' + id + '/');
    window.BJMasterConf.abrir({ titulo: T('master.js.janela_oab.remover_titulo'), icone: 'bi-trash3', cor: '#a3232b', botao: T('master.js.janela_oab.remover'),
      texto: T('master.js.janela_oab.remover_texto', { nome: escolhida.nome }),
      aoConfirmar: () => { window.BJMasterConf.fechar(); oabEnviar(url, {}, () => {}, false); } });
  }

  // Um envio por vez; o sucesso mostra a mensagem do servidor (ou fecha a janela, no "Salvar nota", L896) e recarrega
  // as seccionais (a janela e a lista dos que faltam acompanham).
  function oabEnviar(url, corpo, aoDarCerto, fechar) {
    if (OAB.enviando) return;
    OAB.enviando = true; OAB.msg = null; renderOab();
    secPostar(url, corpo, j => {
      OAB.enviando = false; aoDarCerto(); OAB.msg = { ok: true, texto: j.mensagem };
      if (fechar) fecharOab(); else renderOab();
      window.__bjSeccionaisRecarregar();
    }, erro => { OAB.enviando = false; OAB.msg = { ok: false, texto: erro || SEC_ERRO_SALVAR }; renderOab(); });
  }

  // Aviso do painel (avisoHtml), embaixo do total da tabela de UFs.
  function avisarNoMapa(msg) {
    S.aviso = msg ? { ok: false, msg } : null;
    if (S.sec === 'visao' && D.visao && document.getElementById('mp-uf-tab')) renderMapa();
  }

  // Item dos que faltam: abre a janela do estado ou da cidade. Linha da UF no modo OAB: abre o cartão da sede.
  function mapaPeloAlvo(alvo) {
    if (S.aviso) avisarNoMapa(null);
    if (alvo.hasAttribute('data-sec-linha')) { SEC.aberta = alvo.dataset.secLinha; return renderMapa(); }
    abrirOab(alvo.dataset.oabUf, alvo.dataset.oabCidade);
  }
  elSecao.addEventListener('click', e => { const alvo = e.target.closest('[data-oab-uf], [data-sec-linha]'); if (alvo) mapaPeloAlvo(alvo); });
  elSecao.addEventListener('keydown', e => {
    const alvo = (e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-oab-uf], [data-sec-linha]');
    if (alvo) { e.preventDefault(); mapaPeloAlvo(alvo); }
  });
  // Esc fecha a janela só quando nada está aberto por cima (Editar sede ou a confirmação): captura, antes dos outros.
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !OAB.uf) return;
    const modal = document.getElementById('mp-modal'), conf = raiz.querySelector('[data-mp-conf]');
    if ((modal && modal.innerHTML !== '') || (conf && !conf.hidden)) return;
    fecharOab();
  }, true);

  function renderIdades() {
    const I = D.visao.idades, F = I.faixas, tot = F.reduce((a, x) => a + x.n, 0), mx = Math.max(1, ...F.map(x => x.n));
    document.getElementById('mp-idades').innerHTML = '<div class="mp-idades-topo"><b>' + T('master.js.visao.idades.titulo') + '</b><span>' + T('master.js.visao.idades.media', { valor: I.media === null ? T('master.js.sem_informacao') : T('master.js.visao.idades.anos', { n: I.media }) }) + '</span></div>'
      + '<div class="mp-idades-lista">' + F.map(x => '<div class="mp-idade"><span>' + esc(x.rot) + '</span><div class="mp-idade-trilho"><div style="--w:' + pct(x.n, mx) + '"></div></div><b>' + num(x.n) + '</b><span>' + pct(x.n, tot) + '</span></div>').join('') + '</div>'
      + '<span class="mp-idades-nota">' + T('master.js.visao.idades.nota', { n: num(I.semData) })
      + (I.menores > 0 ? ' ' + T('master.js.visao.idades.menores', { n: num(I.menores) }) : '') + '</span>';
  }

  /* ================= CONTAS E ESCRITÓRIOS ================= */
  const acessoRelativo = iso => {
    if (!iso) return T('master.js.contas.sem_registro');
    const d = new Date(iso), h = new Date(); d.setHours(0, 0, 0, 0); h.setHours(0, 0, 0, 0);
    const dias = Math.round((h - d) / 864e5);
    return dias <= 0 ? T('master.js.contas.hoje') : dias === 1 ? T('master.js.contas.ontem') : T('master.js.contas.ha_dias', { n: dias });
  };

  function montarContas() {
    // O bloco "Pedidos de exclusão de conta" (desenho 1.2, linha 148) vem antes da tabela. Escondido (hidden,
    // sem pedidos), ele não ocupa lugar nem abre o espaçamento da seção.
    elSecao.innerHTML = '<div id="mp-pedidos-exclusao" hidden></div><div id="mp-convenio"></div><div class="mp-card mp-contas"><div class="mp-cab-cinza"><b>' + T('master.js.secao.contas') + ' <span id="mp-contas-n"></span></b>'
      + '<a class="mp-btn-cinza" href="' + esc(URLS.convites) + '"><i class="bi bi-envelope-paper"></i>' + T('master.js.contas.convites') + '</a>'
      + '<select class="mp-select mp-select-34" data-f-sit><option value="">' + T('master.js.contas.todas_situacoes') + '</option>'
      + [ATIVA, SUSPENSA].map(v => '<option value="' + v + '"' + (S.fSit === v ? ' selected' : '') + '>' + rotuloDaSituacao(v) + '</option>').join('')
      // C4b: as contas excluídas só aparecem nesta opção, com a data da purga.
      + '<option value="' + QUARENTENA + '"' + (S.fSit === QUARENTENA ? ' selected' : '') + '>' + rotuloDaSituacao(QUARENTENA) + '</option></select>'
      + '<label class="mp-busca"><i class="bi bi-search"></i><input data-busca value="' + esc(S.busca) + '" placeholder="' + esc(T('master.js.contas.busca')) + '" aria-label="' + esc(T('master.js.contas.busca')) + '"></label></div>'
      + '<div id="mp-contas-aviso"></div><div class="mp-rolagem"><div class="mp-contas-grade"><div class="mp-contas-cab">'
      + ['conta', 'primario_plano', 'usuarios', 'pastas', 'docs', 'armazenamento', 'dias30', 'previsao', 'situacao'].map(k => '<div>' + T('master.js.contas.cab.' + k) + '</div>').join('') + '<div></div></div>'
      + '<div id="mp-contas-lista"></div></div></div></div>'
      // O menu da conta mora FORA do card e da rolagem (overflow), em posição fixa na janela: nunca é cortado.
      + '<div id="mp-menu-conta"></div>';
    renderPedidosDeExclusao();
    renderContasLista();
    renderConvenio();
  }

  // Convênio OAB por estado (desenho 05 VS 25.10.26, L175-178): o módulo convenio-oab.js cuida do painel; cada gravação
  // devolve os 27 estados e o rótulo "Convênio OAB/UF" da linha de cada conta se atualiza.
  function renderConvenio() {
    const el = document.getElementById('mp-convenio');
    if (!el || !window.BJConvenioOab) return;
    window.BJConvenioOab.montar(el, {
      url: URLS.convenios, token: URLS.conveniosToken,
      aoMudar: estados => { CONVENIO = Object.fromEntries(estados.map(x => [x.uf, x])); renderContasLista(); },
    });
  }

  // Rótulo do convênio sob o plano da conta (desenho L194): verde (grátis), azul (valor diferenciado) ou vermelho
  // (sem convênio). Conta sem estado válido não tem rótulo.
  function rotuloDoConvenio(c) {
    const v = c.uf && (CONVENIO[c.uf] || c.convenio);
    if (!v) return '';
    const p = { uf: c.uf, valor: 'R$ ' + window.BJFormato.numero(v.valor, 2) };
    const [cor, texto] = v.modo === 'dif' ? ['#0a6fa0', T('master.js.convenio.rotulo_conta_dif', p)]
      : v.modo === 'sem' ? ['#a3232b', T('master.js.convenio.rotulo_conta_sem', p)] : ['#1e7b45', T('master.js.convenio.rotulo_conta', p)];
    return '<span class="mp-conta-conv" style="color:' + cor + '">' + esc(texto) + '</span>';
  }

  // Pedidos de exclusão de conta (desenho 1.2, linhas 148 e 679), de /master/dados/contas (`pedidosExclusao`).
  // Só aparece com pedido pendente. Reativar e Excluir abrem as MESMAS confirmações do menu da conta (regra do
  // dono: a exclusão é a quarentena, então o confirm/prompt do desenho, que diz que não dá para desfazer, não entra).
  // Os UseCases decidem o pedido junto; aqui a linha só sai da lista depois da resposta de sucesso.
  const pedidosDeExclusao = () => (D.contas && D.contas.pedidosExclusao) || [];
  const quemPediu = p => (p.pedidoPorNome
    ? p.pedidoPorNome + (p.pedidoPorEmail ? ' (' + p.pedidoPorEmail + ')' : '')
    : (p.pedidoPorEmail || T('master.js.pedidos.autor_ausente')));
  function renderPedidosDeExclusao() {
    const host = document.getElementById('mp-pedidos-exclusao'); if (!host) return;
    const peds = pedidosDeExclusao();
    host.hidden = peds.length === 0;
    host.innerHTML = peds.length === 0 ? '' : '<div class="mp-pedidos" id="pedidos-exclusao" data-mp-pedidos>'
      + '<div class="mp-pedidos-cab"><i class="bi bi-trash3"></i><b>' + T('master.js.pedidos.titulo', { n: num(peds.length) }) + '</b><span>' + T('master.js.pedidos.sub') + '</span></div>'
      + peds.map(p => '<div class="mp-pedido" data-mp-pedido="' + p.contaId + '"><span class="mp-pedido-id"><b>' + esc(p.escritorio) + '</b>'
        + '<span>' + esc(quemPediu(p)) + ' · ' + esc(dataHora(p.criadoEm)) + ' · ' + esc(p.motivo) + '</span></span>'
        + '<button type="button" class="mp-pedido-reativar" data-pedido-op="reativar" data-conta-id="' + p.contaId + '">' + T('master.js.pedidos.reativar') + '</button>'
        + '<button type="button" class="mp-pedido-excluir" data-pedido-op="excluir" data-conta-id="' + p.contaId + '">' + T('master.js.pedidos.excluir') + '</button></div>').join('')
      + '</div>';
  }
  // Conta do pedido fora da lista (não deveria acontecer): monta o mínimo para a confirmação com o token do pedido.
  const contaDoPedido = id => {
    const p = pedidosDeExclusao().find(x => x.contaId === id);
    return p ? { id: p.contaId, nome: p.escritorio, token: p.token, situacao: ATIVA, purgaEm: null } : null;
  };

  // Menu da conta (desenho 1.2, linha 735), na ordem: Editar limite de armazenamento, Definir limite de usuários,
  // Estatísticas, Histórico, Suspender OU Reativar (conforme a situação) e Excluir conta (oculto na conta do Master, C5).
  // Conta em quarentena (C4b): só Estatísticas, Histórico e Reativar conta (não há o que limitar até a purga).
  const opMenu = (op, classe, icone, rotulo, c) => '<button type="button" class="mp-menu-op' + classe + '" data-op="' + op + '" data-conta-id="' + c.id + '"><i class="bi ' + icone + '"></i>' + rotulo + '</button>';
  const menuDaConta = c => '<div class="mp-menu" data-parar><div class="mp-menu-cab">' + esc(c.nome) + '</div>'
    + (c.situacao !== QUARENTENA ? opMenu('limArm', '', 'bi-hdd', T('master.js.menu.lim_arm'), c) + opMenu('limUsu', '', 'bi-people', T('master.js.menu.lim_usu'), c) : '')
    + opMenu('estat', '', 'bi-bar-chart', T('master.js.menu.estat'), c)
    + opMenu('hist', '', 'bi-clock-history', T('master.js.menu.hist'), c)
    + (c.situacao === ATIVA
      ? opMenu('suspender', ' mp-menu-sep mp-menu-suspender', 'bi-pause-circle', T('master.js.menu.suspender'), c)
      : opMenu('reativar', ' mp-menu-sep mp-menu-reativar', 'bi-play-circle', T('master.js.menu.reativar'), c))
    + (c.situacao !== QUARENTENA && !c.ehDoMaster ? opMenu('excluir', ' mp-menu-excluir', 'bi-trash3', T('master.js.menu.excluir'), c) : '')
    + '</div>';

  // Menu em position: fixed, nas coordenadas da janela: as do clique (botão direito) ou as do botão ⋮.
  // Não cabe embaixo: abre para cima. Não cabe à direita: encosta na borda. Largura 260px (desenho).
  const LARGURA_MENU = 260, MARGEM_MENU = 8;
  function abrirMenuDaConta(id, pos) { S.menu = id; S.menuPos = pos; renderMenuDaConta(); }
  function fecharMenuDaConta() {
    if (S.menu === null) return;
    S.menu = null; S.menuPos = null;
    const host = document.getElementById('mp-menu-conta'); if (host) host.innerHTML = '';
  }
  function renderMenuDaConta() {
    const host = document.getElementById('mp-menu-conta'); if (!host) return;
    const c = S.menu === null || !S.menuPos || !D.contas ? null : D.contas.contas.find(x => x.id === S.menu);
    if (!c) { host.innerHTML = ''; return; }
    host.innerHTML = menuDaConta(c);
    const el = host.firstElementChild, p = S.menuPos, alto = el.offsetHeight;
    let top = p.y;
    if (top + alto > window.innerHeight - MARGEM_MENU) top = Math.max(MARGEM_MENU, p.yAcima - alto);
    let left = p.alinharDireita ? p.x - LARGURA_MENU : p.x;
    left = Math.max(MARGEM_MENU, Math.min(left, window.innerWidth - LARGURA_MENU - MARGEM_MENU));
    el.style.top = top + 'px';
    el.style.left = left + 'px';
  }
  // Do botão ⋮: abre logo abaixo dele, com a borda direita alinhada à do botão; para cima, acima dele.
  const posDoBotao = b => { const r = b.getBoundingClientRect(); return { x: r.right, y: r.bottom + 4, yAcima: r.top - 4, alinharDireita: true }; };
  // Do botão direito: abre no ponto do clique, para a direita e para baixo (ou para cima).
  const posDoClique = e => ({ x: e.clientX, y: e.clientY, yAcima: e.clientY, alinharDireita: false });

  // Confirmação do desenho (linha 624), textos exatos. O servidor revalida tudo (CSRF por conta, Master, regras C2, C3 e C5).
  const CONF_CONTA = {
    suspender: c => ({ titulo: T('master.js.conf.suspender.titulo'), icone: 'bi-pause-circle', cor: '#c08a12', botao: T('master.js.conf.suspender.botao'), texto: T('master.js.conf.suspender.texto', { nome: c.nome }) }),
    reativar: c => ({ titulo: T('master.js.conf.reativar.titulo'), icone: 'bi-play-circle', cor: '#1e7b45', botao: T('master.js.conf.reativar.botao'), texto: T('master.js.conf.reativar.texto', { nome: c.nome }) }),
    // C4: texto e botão aprovados pelo dono, com a palavra EXCLUIR para liberar o botão. Os dias vêm do servidor
    // (`tenant_carencia_purga_dias`, no JSON de /master/dados/contas): com 365, o texto é o aprovado, letra a letra.
    // A palavra EXCLUIR não se traduz: o ExcluirContaPeloMasterUseCase confere exatamente ela.
    excluir: c => ({ titulo: T('master.js.conf.excluir.titulo'), icone: 'bi-exclamation-octagon', cor: '#a3232b', botao: T('master.js.conf.excluir.botao'), palavra: 'EXCLUIR', texto: T('master.js.conf.excluir.texto', { nome: c.nome, dias: D.contas.carenciaPurgaDias, papel: T('termos.master') }) })
  };
  const URL_ACAO = { suspender: () => URLS.contaSuspender, reativar: () => URLS.contaReativar, excluir: () => URLS.contaExcluir };

  function pedirAcaoNaConta(tipo, id) {
    const c = D.contas.contas.find(x => x.id === id) || contaDoPedido(id);
    if (!c || !window.BJMasterConf) return;
    window.BJMasterConf.abrir(Object.assign(CONF_CONTA[tipo](c), { aoConfirmar: digitado => enviarAcaoNaConta(tipo, c, digitado) }));
  }

  function enviarAcaoNaConta(tipo, c, digitado) {
    const corpo = new FormData();
    corpo.append('_token', c.token || '');
    if (tipo === 'excluir') corpo.append('confirmacao', digitado || '');
    const url = URL_ACAO[tipo]().replace('/0/', '/' + c.id + '/');
    fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.json().catch(() => null).then(j => ({ r, j })))
      .then(({ r, j }) => {
        // Só é sucesso a resposta JSON direta do servidor com a conta: redirecionamento (sessão caída, login),
        // corpo que não é JSON ou JSON sem `conta` contam como erro, nunca verde.
        const json = !r.redirected && j !== null && typeof j === 'object';
        if (json && r.ok && j.conta) {
          // Excluída vira "Em quarentena": sai da lista padrão e passa a aparecer só nessa opção do filtro.
          c.situacao = j.conta.situacao; c.purgaEm = j.conta.purgaEm || null;
          // Reativar e excluir decidem o pedido de exclusão da conta (no servidor, na mesma transação): sai do bloco.
          // Suspender não decide (o pedido continua pendente).
          if (tipo !== 'suspender') { D.contas.pedidosExclusao = pedidosDeExclusao().filter(p => p.contaId !== c.id); c.pedidoPendente = false; }
          // KPIs (suspensas) e trilha mudaram: recarregam quando a seção for aberta de novo.
          D.visao = null; D.audit = null; ERRO.visao = null; ERRO.audit = null;
          return avisar(true, j.mensagem);
        }
        avisar(false, json && !r.ok ? j.erro : null);
      })
      .catch(() => avisar(false));
  }

  function avisar(ok, msg) {
    if (window.BJMasterConf) window.BJMasterConf.fechar();
    S.aviso = { ok, msg: msg || (ok ? T('master.js.contas.aviso_ok') : T('master.js.erro_acao')) };
    renderSecs();
    if (S.sec === 'contas') { renderPedidosDeExclusao(); renderContasLista(); }
    if (S.sec === 'arm') renderRanking();
  }

  // Armazenamento da linha (desenho 1.2, linhas 166 e 733): "<uso> de <limite>", %, barra e "<faixa> · livre <livre>".
  // Em quarentena o uso não é medido: só o plano e os limites fazem sentido. Sem limite: o uso e "sem limite".
  const livreDe = c => Math.max(0, c.espaco.limiteGb * GB - c.bytes);
  // Bônus de espaço vigente (origem e validade): o limite mostrado já o inclui.
  function bonusDaConta(c) {
    if (!c.espaco.bonusAte) return '';
    const origem = c.espaco.bonusOrigem === 'indicacao' ? T('master.js.contas.arm_bonus_indicacao') : c.espaco.bonusOrigem;
    return '<span data-mp-bonus>' + esc(T('master.js.contas.arm_bonus', { gb: num(c.espaco.bonusGb), origem, data: c.espaco.bonusAte })) + '</span>';
  }
  function celulaArm(c) {
    if (c.purgaEm) return '<div class="mp-conta-arm mp-hv" style="--cor:#5b7383"><span><span>' + T('master.js.contas.arm_nao_medido') + '</span><b></b></span><span>' + T('master.js.contas.arm_quarentena') + '</span></div>';
    if (c.espaco.semLimite) return '<div class="mp-conta-arm mp-hv" style="--cor:#4a6373"><span><span>' + esc(T('master.js.contas.arm_em_uso', { gb: gb(c.bytes) })) + '</span><b></b></span><span>' + T('master.js.sem_limite') + '</span></div>';
    const [rotulo, cor] = faixaDe(c), p = c.espaco.percentual;
    return '<div class="mp-conta-arm mp-hv" style="--cor:' + cor + '"><span><span>' + esc(T('master.js.contas.arm_de', { gb: gb(c.bytes), limite: num(c.espaco.limiteGb) })) + '</span><b>' + p + '%</b></span>'
      + '<div class="mp-barra-6"><div style="--w:' + Math.min(100, p) + '%"></div></div><span>' + esc(T('master.js.contas.arm_livre', { faixa: rotulo, livre: gb(livreDe(c)) })) + '</span>' + bonusDaConta(c) + '</div>';
  }
  // Previsão (desenho, linha 733): meses até o limite no ritmo dos últimos 30 dias. Plural: convenção ("≈ 1 mês").
  function previsao(c) {
    if (c.espaco.semLimite || !(c.bytesRecentes > 0)) return '<div class="mp-conta-prev mp-hv">' + T('master.js.contas.sem_previsao') + '</div>';
    const mes = (c.espaco.limiteGb * GB - c.bytes) / c.bytesRecentes, n = Math.round(mes);
    return '<div class="mp-conta-prev mp-hv" style="--cor:' + (mes < 3 ? '#a3232b' : '#4a6373') + '">' + (mes < 1 ? T('master.js.contas.prev_menos') : T(n === 1 ? 'master.js.contas.prev_mes' : 'master.js.contas.prev_meses', { n })) + '</div>';
  }

  // Busca da lista (desenho L752): nome e primário por trecho; o número só EXATO (n === +q). Só dígitos, com ou
  // sem "nº"/"n°" e zeros à esquerda antes, casa a conta daquele número: "123" e "nº 000123" acham a Nº 000123,
  // "12" não acha a 000120 e "000" não acha todas.
  function casaNaBusca(c, b) {
    const q = b.replace(/^n\s*[º°o]?\s*/, '');
    if (/^\d+$/.test(q) && Number(q) === Number(c.numero)) return true;

    return (c.nome + ' ' + (c.titular || '')).toLowerCase().includes(b);
  }

  function renderContasLista() {
    const b = S.busca.toLowerCase().trim();
    const contas = D.contas.contas.filter(c => (S.fSit ? c.situacao === S.fSit : c.situacao !== QUARENTENA) && (!b || casaNaBusca(c, b)));
    document.getElementById('mp-contas-n').textContent = T('master.js.contas.contagem', { n: num(contas.length) });
    // Resultado de suspender/reativar: uma linha acima da tabela, até trocar de seção.
    document.getElementById('mp-contas-aviso').innerHTML = avisoHtml();
    document.getElementById('mp-contas-lista').innerHTML = contas.length ? contas.map(c => {
      const sit = situacaoVisivel(c), cor = ST[sit] || '#4a6373';
      return '<div class="mp-conta mp-lin" data-conta="' + c.id + '">'
        + '<div class="mp-conta-nome mp-hv"><span class="mp-conta-titulo"><span class="mp-conta-nro" title="' + esc(T('master.js.contas.numero_dica')) + '">' + esc(T('master.js.contas.numero', { numero: c.numero })) + '</span><b title="' + esc(c.nome) + '">' + esc(c.nome) + '</b></span><span>' + esc(T('master.js.contas.desde', { desde: c.desde, acesso: acessoRelativo(c.ultimoAcesso) })) + '</span></div>'
        + '<div class="mp-conta-prim mp-hv"><span>' + esc(c.titular || T('master.js.contas.titular_ausente')) + '</span><span>' + esc(c.espaco.planoNome) + '</span>' + rotuloDoConvenio(c) + '</div>'
        + '<div class="mp-conta-usu mp-hv"><b>' + num(c.usuarios) + '</b><span> / ' + (c.espaco.limiteUsuarios === null ? T('master.js.sem_limite') : num(c.espaco.limiteUsuarios)) + '</span></div>'
        + '<div class="mp-conta-num mp-hv">' + num(c.pastas) + '</div><div class="mp-conta-num mp-hv">' + num(c.documentos) + '</div>'
        + celulaArm(c)
        + '<div class="mp-conta-cresc mp-hv">' + (c.bytesRecentes > 0 ? '+' + esc(gb(c.bytesRecentes)) : T('master.js.contas.estavel')) + '</div>'
        + (c.purgaEm ? '<div class="mp-conta-prev mp-hv">' + T('master.js.contas.sem_previsao') + '</div>' : previsao(c))
        + '<div class="mp-conta-st mp-hv">' + (c.purgaEm
          // Quarentena: a situação e, abaixo, a data da purga em duas linhas curtas que não quebram (coluna de 100px).
          ? '<div class="mp-conta-st-bloco"><span class="mp-conta-sit" style="--cor:' + cor + '"><i></i>' + esc(rotuloDaSituacao(c.situacao)) + '</span>'
            + '<span class="mp-conta-purga" title="' + esc(T('master.js.contas.purga_dica')) + '">' + T('master.js.contas.purga') + '<br>' + esc(dataBr(c.purgaEm)) + '</span></div>'
          : '<span style="--cor:' + cor + '"><i></i>' + esc(rotuloDaSituacao(sit)) + '</span>') + '</div>'
        + '<div class="mp-conta-acoes mp-hv"><button type="button" class="mp-btn-icone" data-menu="' + c.id + '" title="' + esc(T('master.js.contas.acoes')) + '" aria-label="' + esc(T('master.js.contas.acoes')) + '"><i class="bi bi-three-dots-vertical"></i></button></div>'
        + '</div>';
    }).join('') : '<div class="mp-vazio-linha">' + T('master.js.contas.nenhuma') + '</div>';
    renderMenuDaConta();
  }

  /* ================= USUÁRIOS E OABs ================= */
  function montarOab() {
    const O = D.oab, ufs = Object.entries(O.ufs).sort((a, b) => b[1] - a[1]), maxUf = Math.max(1, ...ufs.map(u => u[1]));
    const kpis = [
      { k: 'registros', n: O.registros, cor: '#0a6fa0' },
      { k: 'unicas', n: O.unicas, cor: '#1e7b45' },
      { k: 'duplicadas', n: O.duplicadas, cor: '#c08a12' },
      { k: 'nao_validadas', n: O.naoValidadas, cor: '#a3232b' },
      { k: 'ufs', n: ufs.length, cor: '#7a3e8f' }
    ].map(k => Object.assign(k, { rot: T('master.js.usuarios_oab.kpi.' + k.k), desc: T('master.js.usuarios_oab.kpi.' + k.k + '_desc') }));
    const P = {
      incompleto: p => ['bi-exclamation-circle', '#a3232b', p.inscricao ? T('master.js.usuarios_oab.pend.sem_uf', { conta: p.conta, inscricao: p.inscricao }) : T('master.js.usuarios_oab.pend.sem_numero', { conta: p.conta }), T('master.js.usuarios_oab.pend.tag_incompleto')],
      nao_validada: p => ['bi-hourglass', '#c08a12', T('master.js.usuarios_oab.pend.aguardando', { conta: p.conta, normalizada: p.normalizada }), T('master.js.usuarios_oab.pend.tag_nao_validada')],
      divergente: p => ['bi-hourglass', '#c08a12', T('master.js.usuarios_oab.pend.divergente', { conta: p.conta, normalizada: p.normalizada }), T('master.js.usuarios_oab.pend.tag_nao_validada')],
      formatacao: p => ['bi-arrow-repeat', '#0a6fa0', T('master.js.usuarios_oab.pend.formatacao', { conta: p.conta, inscricao: p.inscricao, normalizada: p.normalizada }), T('master.js.usuarios_oab.pend.tag_formatacao')]
    };
    elSecao.innerHTML = '<div class="mp-oab-kpis">' + kpis.map(k => '<div class="mp-card mp-oab-kpi" style="--cor:' + k.cor + '"><span>' + k.rot + '</span><span>' + num(k.n) + '</span><span>' + k.desc + '</span></div>').join('') + '</div>'
      + '<div class="mp-oab-duas"><div class="mp-card mp-oab-dist"><b>' + T('master.js.usuarios_oab.distribuicao') + '</b><div class="mp-oab-ufs">'
      + (ufs.length ? ufs.map(([uf, n]) => '<div class="mp-oab-uf"><b>' + esc(uf) + '</b><div class="mp-barra-8"><div style="--w:' + pct(n, maxUf) + ';--cor:#0a6fa0"></div></div><span>' + num(n) + '</span></div>').join('') : '<span class="mp-nota">' + T('master.js.usuarios_oab.sem_inscricao') + '</span>')
      + '</div></div><div class="mp-card mp-oab-pend"><div class="mp-cab-simples"><b>' + T('master.js.usuarios_oab.pendencias') + '</b></div>'
      + (O.pendencias.length ? O.pendencias.map(p => { const [ic, cor, txt, tag] = (P[p.tipo] || P.nao_validada)(p); return '<div class="mp-pend" style="--cor:' + cor + '"><i class="bi ' + ic + '"></i><span>' + esc(txt) + '</span><span>' + tag + '</span></div>'; }).join('') : '<div class="mp-vazio-linha">' + T('master.js.usuarios_oab.sem_pendencia') + '</div>')
      + '</div></div>'
      + '<span class="mp-nota">' + T('master.js.usuarios_oab.nota') + ' <a href="' + esc(URLS.revisaoOab) + '">' + T('master.js.usuarios_oab.revisao') + '</a></span>';
  }

  /* ================= ARMAZENAMENTO ================= */
  // Faixas de alerta (desenho 1.2, linhas 195-198 e 695): chips com a cor do desenho. A chave do bloqueio, o aviso ao
  // lado dela e o botão de salvar são convenção (spec P6). Ordem crescente, de 1 a 100: a Contribua revalida.
  const CHIPS = [['atencao', T('master.js.faixa.atencao'), '#8a5a12', '#fbf1dc'], ['alerta', T('master.js.faixa.alerta'), '#a3232b', '#fbe7e7'], ['critico', T('master.js.faixa.critico'), '#7a1b22', '#f6dcdc'], ['bloqueio', T('master.js.faixa.chip_bloqueio'), '#fff', '#7a1b22']];
  const AVISO_BLOQUEIO = T('master.js.arm.aviso_bloqueio');
  const ERRO_FAIXAS = T('master.js.arm.erro_faixas');
  const fxDoServidor = () => Object.assign({}, D.planos.faixas, { bloqueioAtivo: D.planos.bloqueioAtivo });
  const fxValidas = f => { const v = CHIPS.map(([k]) => f[k]); return v.every(Number.isInteger) && v[0] > 0 && v[0] < v[1] && v[1] < v[2] && v[2] < v[3] && v[3] <= 100; };
  const fxMudou = () => { const o = fxDoServidor(); return CHIPS.some(([k]) => S.fx[k] !== o[k]) || S.fx.bloqueioAtivo !== o.bloqueioAtivo; };

  // Faixa de duplicidade (desenho 05 de 27/10, dupKpis): cobrado x físico x economizado x arquivos. Os números vêm do
  // servidor (D.visao.duplicidade); aqui só formata, como o desenho (KB sem casas, MB com 1, GB com 2).
  function faixaDeDuplicidade() {
    const d = D.visao.duplicidade || {}, cob = d.cobrado || 0, fis = d.fisico || 0, vin = d.vinculos || 0, arq = d.arquivos || 0;
    const fB = b => b < 1048576 ? decimal(b / 1024, 0, 0) + ' KB' : b < 1073741824 ? decimal(b / 1048576, 1, 1) + ' MB' : decimal(b / 1073741824, 2, 2) + ' GB';
    const kpis = [
      ['cobrado', fB(cob), T('master.js.arm.dup.cobrado_desc'), '#12242f'],
      ['fisico', fB(fis), T('master.js.arm.dup.fisico_desc'), '#12242f'],
      ['virtual', fB(Math.max(0, cob - fis)), cob ? T('master.js.arm.dup.virtual_desc', { pct: Math.round((1 - fis / cob) * 100) }) : T('master.js.arm.dup.virtual_vazio'), '#1e7b45'],
      ['arquivos', String(vin), T('master.js.arm.dup.arquivos_desc', { reais: arq, virtuais: Math.max(0, vin - arq) }), '#12242f']
    ];
    return '<div class="mp-dup">' + kpis.map(([k, v, desc, cor]) => '<div class="mp-dup-kpi" title="' + esc(T('master.js.arm.dup.' + k + '_dica')) + '"><span class="mp-dup-rot">'
      + esc(T('master.js.arm.dup.' + k)) + '</span><b style="color:' + cor + '">' + esc(v) + '</b><span class="mp-dup-desc">' + esc(desc) + '</span></div>').join('') + '</div>';
  }

  function montarArm() {
    if (!S.fx) S.fx = fxDoServidor();
    elSecao.innerHTML = faixaDeDuplicidade() + '<div class="mp-card mp-faixas"><b class="mp-faixas-tit">' + T('master.js.arm.faixas_titulo') + ' <span>' + T('master.js.arm.configuraveis') + '</span></b>'
      + '<div class="mp-faixas-chips">' + CHIPS.map(([k, rot, cor, bg]) => '<label class="mp-faixa-chip" style="--cor:' + cor + ';--bg:' + bg + '"><span>' + T('master.js.arm.a_partir', { faixa: rot }) + '</span>'
        + '<input type="number" min="1" max="100" step="1" data-faixa="' + k + '" value="' + esc(S.fx[k]) + '" aria-label="' + esc(T('master.js.arm.a_partir', { faixa: rot })) + '">%</label>').join('') + '</div>'
      + '<span class="mp-faixas-nota">' + T('master.js.arm.faixas_nota') + '</span>'
      + '<div class="mp-faixas-bloqueio"><label class="mp-chave"><input type="checkbox" data-faixa-bloqueio' + (S.fx.bloqueioAtivo ? ' checked' : '') + '><span>' + T('master.js.arm.bloquear', { faixa: T('master.js.faixa.chip_bloqueio') }) + '</span></label>'
      + '<span class="mp-faixas-aviso"><i class="bi bi-exclamation-triangle"></i>' + esc(AVISO_BLOQUEIO) + '</span></div>'
      + '<div class="mp-faixas-rodape"><span class="mp-faixas-msg" data-faixas-msg></span><button type="button" class="mp-btn-azul" data-faixas-salvar>' + T('master.js.arm.salvar_faixas') + '</button></div></div>'
      + '<div class="mp-card mp-ranking"><div class="mp-cab-simples"><b>' + T('master.js.arm.ranking') + '</b></div><div id="mp-arm-aviso"></div><div id="mp-ranking-lista"></div></div>'
      + '<span class="mp-nota">' + T('master.js.arm.nota') + '</span>';
    atualizarFaixas();
    renderRanking();
  }

  function atualizarFaixas() {
    const msg = elSecao.querySelector('[data-faixas-msg]'), btn = elSecao.querySelector('[data-faixas-salvar]'); if (!msg || !btn) return;
    const valida = fxValidas(S.fx), erro = !valida ? ERRO_FAIXAS : S.fxMsg && !S.fxMsg.ok ? S.fxMsg.texto : null;
    msg.textContent = erro || (S.fxMsg && S.fxMsg.ok ? S.fxMsg.texto : '');
    msg.style.setProperty('--cor', erro ? '#a3232b' : '#1e7b45');
    btn.disabled = !valida || !fxMudou() || !!S.fxEnviando;
  }

  // Ranking (desenho, linhas 200-201 e 696): por % da cota, decrescente; sem limite no fim, pelo uso. "Editar limite" abre o modal.
  function renderRanking() {
    const el = document.getElementById('mp-ranking-lista'); if (!el) return;
    const chave = c => (c.espaco.percentual === null ? -1 : c.bytes / (c.espaco.limiteGb * GB));
    const contas = D.contas.contas.filter(c => c.situacao !== QUARENTENA).sort((a, b) => chave(b) - chave(a) || b.bytes - a.bytes);
    document.getElementById('mp-arm-aviso').innerHTML = avisoHtml();
    el.innerHTML = contas.length ? contas.map(c => {
      const sem = c.espaco.percentual === null, [rotulo, cor] = sem ? [T('master.js.sem_limite'), '#4a6373'] : faixaDe(c), p = sem ? 0 : c.bytes / (c.espaco.limiteGb * GB) * 100;
      return '<div class="mp-rank" style="--cor:' + cor + '"><span title="' + esc(c.nome) + '">' + esc(c.nome) + '</span><div class="mp-barra-8"><div style="--w:' + Math.min(100, p) + '%"></div></div>'
        + '<b>' + (sem ? '' : c.espaco.percentual + '%') + '</b><span>' + esc(rotulo) + '</span><button type="button" class="mp-rank-btn" data-lim-arm="' + c.id + '">' + T('master.js.arm.editar_limite') + '</button></div>';
    }).join('') : '<div class="mp-vazio-linha">' + T('master.js.arm.nenhuma') + '</div>';
  }

  function salvarFaixas() {
    if (!fxValidas(S.fx) || !fxMudou() || S.fxEnviando) return;
    const corpo = new FormData();
    corpo.append('_token', D.planos.tokenFaixas || '');
    CHIPS.forEach(([k]) => corpo.append(k, String(S.fx[k])));
    corpo.append('bloqueioAtivo', S.fx.bloqueioAtivo ? '1' : '0');
    S.fxEnviando = true; atualizarFaixas();
    postar(URLS.faixas, corpo, j => {
      // O catálogo, as faixas e as contas (a faixa de cada uma muda) recarregam; a trilha também.
      S.fxEnviando = false; S.fx = null; S.fxMsg = { ok: true, texto: j.mensagem }; D.planos = null; D.contas = null; ERRO.planos = null; ERRO.contas = null; recarregarVisaoETrilha();
      if (S.sec === 'arm') renderSecao();
    }, erro => { S.fxEnviando = false; S.fxMsg = { ok: false, texto: erro }; atualizarFaixas(); });
  }

  /* ================= PLANOS E LIMITES ================= */
  // Cartões (desenho 1.2, linhas 205-210 e 697). Cores do desenho; o 4º plano (Avançado) é convenção, o desenho tem 3.
  const COR_PLANO = { free: '#c08a12', essencial: '#0a6fa0', profissional: '#1e7b45', avancado: '#5b3f9e' };
  const reais = cent => 'R$ ' + (cent % 100 === 0 ? num(cent / 100) : window.BJFormato.numero(cent / 100, 2));
  const nomeDoPlano = p => (p.codigo === 'free' ? T('master.js.planos.plano_free', { nome: p.nome }) : p.nome);
  const TAG_PLANO = { free: 'master.js.planos.tag_free', profissional: 'master.js.planos.tag_escolhido' };

  function montarPlanos() {
    const ativas = D.contas.contas.filter(c => c.situacao !== QUARENTENA);
    elSecao.innerHTML = '<div id="mp-planos-aviso"></div>'
      + '<div class="mp-convenio-faixa"><img src="' + esc(URLS.logoOab) + '" alt="OAB"><div><b>' + T('master.js.planos.faixa_titulo') + '</b><span>' + T('master.js.planos.faixa_texto') + '</span></div></div>'
      + '<div class="mp-planos-rotulo">' + T('master.js.planos.rotulo') + '</div><div class="mp-planos">' + D.planos.planos.map(p => {
      const acima = ativas.filter(c => c.espaco.planoCodigo === p.codigo && pertoDaFranquia(c)).length;
      return '<div class="mp-card mp-plano" style="--cor:' + (COR_PLANO[p.codigo] || '#4a6373') + '"><div class="mp-plano-topo"><b>' + esc(nomeDoPlano(p)) + '</b>'
        + (TAG_PLANO[p.codigo] ? '<span class="mp-plano-tag">' + T(TAG_PLANO[p.codigo]) + '</span>' : '')
        + (p.ativo ? '' : '<span class="mp-plano-inativo">' + T('master.js.planos.inativo') + '</span>')
        + '<button type="button" class="mp-rank-btn mp-plano-editar" data-plano-editar="' + esc(p.codigo) + '" title="' + esc(T('master.js.planos.ajustar_dica')) + '"><i class="bi bi-pencil"></i> ' + T('master.js.planos.ajustar') + '</button></div>'
        + '<span class="mp-plano-preco">' + esc(reais(p.precoCentavos)) + '<span> ' + T('master.js.planos.por_mes') + '</span></span>'
        + '<span class="mp-plano-cap">' + T('master.js.planos.cap', { gb: num(p.cotaGb) }) + '</span>'
        + '<span class="mp-plano-recursos"><i class="bi bi-check2"></i> ' + T('master.js.planos.recursos') + '</span>'
        + '<span class="mp-plano-contas">' + T('master.js.planos.contas', { n: num(p.escritorios), acima: num(acima) }) + '</span></div>';
    }).join('') + '</div>'
      + '<span class="mp-nota">' + T('master.js.planos.nota') + '</span>';
    document.getElementById('mp-planos-aviso').innerHTML = avisoHtml();
  }

  /* ---------- modal (limite e plano), no host #mp-modal do template ---------- */
  const elModal = document.getElementById('mp-modal');
  let modal = null;
  const fecharModal = () => { modal = null; if (elModal) elModal.innerHTML = ''; };
  const caixa = (icone, titulo, corpo, botao) => '<div class="mp-lim-fundo" data-lim-fundo><div class="mp-lim" role="dialog" aria-modal="true" aria-labelledby="mpLimTitulo">'
    + '<div class="mp-lim-cab"><i class="bi ' + icone + '"></i><span id="mpLimTitulo">' + esc(titulo) + '</span></div><div class="mp-lim-corpo">' + corpo + '</div>'
    + '<div class="mp-lim-rodape"><button type="button" class="mp-lim-cancelar" data-lim-cancelar>' + T('master.js.cancelar') + '</button><button type="button" class="mp-lim-ok" data-lim-ok disabled>' + esc(botao) + '</button></div></div></div>';
  const campo = (rotulo, attrs) => '<label class="mp-lim-campo"><span>' + rotulo + '</span><input ' + attrs + '></label>';
  const quadros = itens => '<div class="mp-lim-info">' + itens.map(([rot, val]) => '<div><div>' + rot + '</div><div>' + esc(val) + '</div></div>').join('') + '</div>';

  // Modal de limite (desenho 1.2, linhas 397-410 e 726-732): o campo vem com o limite atual e a justificativa vazia.
  // "Sem limite" (spec P4, contrato semLimite) só no armazenamento: libera o espaço e os usuários da conta.
  function abrirLimite(tipo, id) {
    const c = D.contas && D.contas.contas.find(x => x.id === id); if (!c || !elModal) return;
    const arm = tipo === 'arm', atual = arm ? (c.espaco.limiteGb === null ? null : c.espaco.limiteGb - (c.espaco.bonusGb || 0)) : c.espaco.limiteUsuarios;
    modal = { tipo: 'lim', lim: tipo, c, atual, erro: null, enviando: false };
    const sem = T('master.js.sem_limite');
    const info = arm
      ? [[T('master.js.limite.em_uso'), gb(c.bytes)], [T('master.js.limite.limite_atual'), atual === null ? sem : num(atual) + ' GB'], [T('master.js.limite.livre'), atual === null ? sem : gb(livreDe(c))]]
      : [[T('master.js.limite.usuarios'), num(c.usuarios)], [T('master.js.limite.limite_atual'), atual === null ? sem : num(atual)], [T('master.js.limite.vagas'), atual === null ? sem : num(Math.max(0, atual - c.usuarios))]];
    elModal.innerHTML = caixa(arm ? 'bi-hdd' : 'bi-people', arm ? T('master.js.menu.lim_arm') : T('master.js.menu.lim_usu'),
      '<b class="mp-lim-conta">' + esc(c.nome) + '</b>' + quadros(info)
      + campo(arm ? T('master.js.limite.novo_arm') : T('master.js.limite.novo_usu'), 'type="number" min="1" step="1" data-lim-novo value="' + (atual === null ? '' : atual) + '"')
      + (arm ? '<label class="mp-lim-sem"><input type="checkbox" data-lim-sem' + (c.espaco.semLimite ? ' checked' : '') + '>' + T('master.js.limite.sem_limite') + '</label>' : '')
      + '<span class="mp-lim-msg" data-lim-msg></span>'
      + (!arm && c.espaco.semLimite ? '<span class="mp-lim-nota">' + T('master.js.limite.nota_sem_limite') + '</span>' : '')
      + campo(T('master.js.limite.justificativa'), 'type="text" maxlength="500" data-lim-just placeholder="' + esc(T('master.js.limite.justificativa_dica')) + '"'), T('master.js.limite.confirmar'));
    atualizarLimite();
    elModal.querySelector('[data-lim-novo]').focus();
  }

  // Regras do desenho (linhas 622-623 e 729-731): valor <= 0, abaixo do uso ou justificativa vazia (só espaços contam
  // como vazia) bloqueiam o botão. Repetir o limite atual é permitido ("Diferença: +0 GB.").
  function estadoDoLimite() {
    const m = modal, arm = m.lim === 'arm', bruto = elModal.querySelector('[data-lim-novo]').value, novo = +bruto || 0;
    const sem = arm && elModal.querySelector('[data-lim-sem]').checked, just = elModal.querySelector('[data-lim-just]').value;
    const abaixo = !sem && (arm ? novo * GB < m.c.bytes : novo < m.c.usuarios);
    const inteiro = Number.isInteger(novo);
    const bloq = (!sem && (novo <= 0 || abaixo || !inteiro)) || !just.trim() || m.enviando;
    // O número do campo vai como digitado (sem separador de milhar), como sempre foi.
    let msg;
    if (sem) msg = T('master.js.limite.msg_sem');
    else if (abaixo) msg = arm ? T('master.js.limite.msg_abaixo_arm', { gb: gb(m.c.bytes) }) : T('master.js.limite.msg_abaixo_usu');
    else if (!inteiro) msg = T('master.js.limite.msg_inteiro');
    else if (m.atual === null) msg = T(arm ? 'master.js.limite.msg_de_sem_arm' : 'master.js.limite.msg_de_sem_usu', { n: novo });
    else { const d = novo - m.atual; msg = T(arm ? 'master.js.limite.msg_diferenca_arm' : 'master.js.limite.msg_diferenca_usu', { d: (d >= 0 ? '+' : '') + d }); }
    return { novo, sem, just, abaixo, bloq, msg };
  }

  function atualizarLimite() {
    const e = estadoDoLimite(), entrada = elModal.querySelector('[data-lim-novo]'), msg = elModal.querySelector('[data-lim-msg]');
    entrada.disabled = e.sem;
    entrada.style.setProperty('--borda', e.abaixo ? '#a3232b' : '#d6e0e7');
    msg.textContent = modal.erro || e.msg;
    msg.style.setProperty('--cor', modal.erro || e.abaixo ? '#a3232b' : '#4a6373');
    elModal.querySelector('[data-lim-ok]').disabled = e.bloq;
  }

  function salvarLimite() {
    const e = estadoDoLimite(), m = modal; if (e.bloq) return;
    const corpo = new FormData();
    corpo.append('_token', m.c.token || '');
    corpo.append('valor', e.sem ? '' : String(e.novo));
    corpo.append('semLimite', e.sem ? '1' : '0');
    corpo.append('justificativa', e.just);
    m.enviando = true; m.erro = null; atualizarLimite();
    const url = (m.lim === 'arm' ? URLS.contaLimiteArm : URLS.contaLimiteUsu).replace('/0/', '/' + m.c.id + '/');
    postar(url, corpo, j => {
      if (!j.conta || !j.conta.espaco) return falhaNoModal(null);
      m.c.espaco = j.conta.espaco;
      // KPI, selo e trilha recarregam; os cartões de plano ("perto ou acima da franquia") leem a conta já trocada.
      recarregarVisaoETrilha();
      fecharModal();
      S.aviso = { ok: true, msg: j.mensagem };
      renderSecs(); renderSecao();
    }, falhaNoModal);
  }

  // Recusa do servidor (\DomainException da Contribua, com o texto do desenho) aparece no próprio modal.
  function falhaNoModal(erro) {
    if (!modal) return;
    modal.enviando = false; modal.erro = erro || T('master.js.erro_acao');
    if (modal.tipo === 'lim') atualizarLimite(); else atualizarPlano();
  }

  // Editar plano (spec P2; o desenho não tem este modal: convenção, no mesmo molde do modal de limite).
  // O código não muda. Free: continua grátis e ativo (a Contribua recusa com a mensagem dela).
  function abrirPlano(codigo) {
    const p = D.planos && D.planos.planos.find(x => x.codigo === codigo); if (!p || !elModal) return;
    modal = { tipo: 'plano', p, erro: null, enviando: false };
    const preco = decimal(p.precoCentavos / 100, p.precoCentavos % 100 === 0 ? 0 : 2, 2);
    elModal.innerHTML = caixa('bi-sliders', T('master.js.planos.editar'), '<b class="mp-lim-conta">' + esc(nomeDoPlano(p)) + '</b>'
      + campo(T('master.js.campo.nome'), 'type="text" maxlength="60" data-plano-nome value="' + esc(p.nome) + '"')
      + '<div class="mp-lim-duas">' + campo(T('master.js.plano_modal.espaco'), 'type="number" min="1" step="1" data-plano-cota value="' + p.cotaGb + '"')
      + campo(T('master.js.plano_modal.preco'), 'type="text" inputmode="decimal" data-plano-preco value="' + esc(preco) + '"') + '</div>'
      + campo(T('master.js.plano_modal.limite_usuarios'), 'type="number" min="1" step="1" data-plano-usuarios placeholder="' + esc(T('master.js.plano_modal.vazio_sem_limite')) + '" value="' + (p.limiteUsuarios === null ? '' : p.limiteUsuarios) + '"')
      + campo(T('master.js.plano_modal.descricao'), 'type="text" maxlength="120" data-plano-descricao value="' + esc(p.descricao) + '"')
      + '<label class="mp-lim-sem"><input type="checkbox" data-plano-ativo' + (p.ativo ? ' checked' : '') + '>' + T('master.js.plano_modal.ativo') + '</label>'
      + '<span class="mp-lim-msg" data-lim-msg></span>', T('master.js.plano_modal.salvar'));
    atualizarPlano();
    elModal.querySelector('[data-plano-nome]').focus();
  }

  // Preço em reais ("19,90", "19.90" ou "19") para centavos; nulo se não for um valor válido.
  const centavos = v => (/^\d{1,7}([.,]\d{1,2})?$/.test(String(v).trim()) ? Math.round(parseFloat(String(v).trim().replace(',', '.')) * 100) : null);

  function estadoDoPlano() {
    const v = sel => elModal.querySelector(sel);
    const cota = v('[data-plano-cota]').value.trim(), usuarios = v('[data-plano-usuarios]').value.trim(), preco = centavos(v('[data-plano-preco]').value);
    const ok = v('[data-plano-nome]').value.trim() !== '' && /^\d+$/.test(cota) && preco !== null && (usuarios === '' || /^\d+$/.test(usuarios));
    return { ok, cota, usuarios, preco, nome: v('[data-plano-nome]').value, descricao: v('[data-plano-descricao]').value, ativo: v('[data-plano-ativo]').checked };
  }

  function atualizarPlano() {
    const e = estadoDoPlano(), msg = elModal.querySelector('[data-lim-msg]');
    const texto = modal.erro || (e.ok ? T('master.js.plano_modal.msg_ok', { codigo: modal.p.codigo }) : T('master.js.plano_modal.msg_preencha'));
    msg.textContent = texto;
    msg.style.setProperty('--cor', modal.erro ? '#a3232b' : '#4a6373');
    elModal.querySelector('[data-lim-ok]').disabled = !e.ok || modal.enviando;
  }

  function salvarPlano() {
    const e = estadoDoPlano(), m = modal; if (!e.ok || m.enviando) return;
    const corpo = new FormData();
    [['_token', m.p.token || ''], ['nome', e.nome], ['cotaGb', e.cota], ['precoCentavos', String(e.preco)], ['limiteUsuarios', e.usuarios], ['descricao', e.descricao], ['ativo', e.ativo ? '1' : '0']]
      .forEach(([k, val]) => corpo.append(k, val));
    m.enviando = true; m.erro = null; atualizarPlano();
    postar(URLS.planoAlterar.replace(/\/codigo$/, '/' + encodeURIComponent(m.p.codigo)), corpo, j => {
      // Catálogo, contas (nome e limite do plano em cada linha) e trilha recarregam.
      D.planos = null; D.contas = null; ERRO.planos = null; ERRO.contas = null; recarregarVisaoETrilha();
      fecharModal();
      S.aviso = { ok: true, msg: j.mensagem };
      renderSecs(); renderSecao();
    }, falhaNoModal);
  }

  // Depois de salvar limite, plano ou faixas: o KPI ARMAZENAMENTO e o selo da auditoria não podem ficar velhos.
  // A Visão geral volta já (o selo usa S.nAud, o último número, enquanto isso); a trilha, quando for aberta.
  function recarregarVisaoETrilha() {
    D.visao = null; D.audit = null; ERRO.visao = null; ERRO.audit = null;
    carregar('visao', URLS.visao);
  }

  // POST com o mesmo critério do menu da conta: só é sucesso o JSON direto do servidor com `mensagem`.
  function postar(url, corpo, aoDarCerto, aoFalhar) {
    fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.json().catch(() => null).then(j => ({ r, j })))
      .then(({ r, j }) => {
        const json = !r.redirected && j !== null && typeof j === 'object';
        if (json && r.ok && j.mensagem) return aoDarCerto(j);
        aoFalhar(json && !r.ok ? j.erro : null);
      })
      .catch(() => aoFalhar(null));
  }

  const avisoHtml = () => (S.aviso ? '<div class="mp-aviso ' + (S.aviso.ok ? 'is-ok' : 'is-erro') + '" role="status" data-mp-aviso><i class="bi '
    + (S.aviso.ok ? 'bi-check-circle' : 'bi-exclamation-circle') + '"></i>' + esc(S.aviso.msg) + '</div>' : '');

  if (elModal) {
    elModal.addEventListener('click', e => {
      if (e.target.matches('[data-lim-fundo]') || e.target.closest('[data-lim-cancelar]')) return fecharModal();
      if (!e.target.closest('[data-lim-ok]') || !modal) return;
      return modal.tipo === 'lim' ? salvarLimite() : salvarPlano();
    });
    const aoMudar = () => { if (!modal) return; modal.erro = null; return modal.tipo === 'lim' ? atualizarLimite() : atualizarPlano(); };
    elModal.addEventListener('input', aoMudar);
    elModal.addEventListener('change', aoMudar);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal) fecharModal(); });
  }

  /* ================= SEGURANÇA E AUDITORIA ================= */
  // A ação da trilha é dado (vem do servidor em português): o ícone e a cor saem dela, não da tela.
  const AI = a => (/Limite/.test(a) ? ['bi-hdd', '#0a6fa0'] : /reativada|reativado|Exclusão de conta cancelada/i.test(a) ? ['bi-play-circle', '#1e7b45'] : /suspensa|excluída|excluído|removido|retirada|desativado/i.test(a) ? ['bi-slash-circle', '#a3232b'] : /Master|primário|Papel no sistema/i.test(a) ? ['bi-stars', '#7a3e8f'] : /Alerta/.test(a) ? ['bi-exclamation-triangle', '#c08a12'] : /^Plano|^Faixas de alerta/.test(a) ? ['bi-sliders', '#0a6fa0'] : ['bi-journal-check', '#4a6373']);
  // Cabeçalho da trilha, na tela e no PDF.
  const AUD_CAB = () => [T('master.js.audit.cab.data'), T('master.js.audit.cab.responsavel'), T('master.js.audit.cab.acao'), T('master.js.audit.cab.detalhe')];
  const audFiltrada = () => D.audit.registros.filter(r => (S.audConta === null || r.contaId === S.audConta) && (!S.audTipo || r.acao === S.audTipo));

  function montarAudit() {
    const A = D.audit, audF = audFiltrada(), tipos = [...new Set(A.registros.map(r => r.acao))].sort((a, b) => a.localeCompare(b));
    const resumo = [[T('master.js.audit.resumo.limites'), 'bi-hdd', '#0a6fa0', '#e5f1f7', r => /Limite/.test(r.acao)], [T('master.js.audit.resumo.contas'), 'bi-buildings', '#8a5a12', '#fbf1dc', r => /Conta/.test(r.acao)], [T('master.js.audit.resumo.masters'), 'bi-stars', '#7a3e8f', '#f3e9f7', r => /Master|primário/i.test(r.acao)], [T('master.js.audit.resumo.alertas'), 'bi-exclamation-triangle', '#a3232b', '#fbe7e7', r => /Alerta/.test(r.acao)]];
    elSecao.innerHTML = '<div class="mp-card mp-audit"><div class="mp-audit-cab"><b>' + T('master.js.audit.titulo') + ' <span>' + T('master.js.audit.contagem', { n: num(audF.length), meses: A.meses }) + (A.truncado ? ' · ' + T('master.js.audit.truncado', { n: num(A.limite) }) : '') + '</span></b>'
      + '<select class="mp-select" data-aud-tipo><option value="">' + T('master.js.audit.todas_acoes') + '</option>' + tipos.map(t => '<option value="' + esc(t) + '"' + (S.audTipo === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('') + '</select>'
      + '<button type="button" class="mp-btn-cinza" data-pdf><i class="bi bi-file-earmark-pdf"></i>' + T('master.js.audit.pdf') + '</button>'
      + (S.audConta !== null ? '<button type="button" class="mp-btn-link" data-aud-todas>' + T('master.js.audit.todas_contas') + '</button>' : '') + '</div>'
      + '<div class="mp-audit-resumo">' + resumo.map(([rot, ic, cor, bg, f]) => '<span style="--cor:' + cor + ';--bg:' + bg + '"><i class="bi ' + ic + '"></i>' + rot + ' · ' + audF.filter(f).length + '</span>').join('') + '</div>'
      + '<div class="mp-audit-grade mp-audit-titulos">' + AUD_CAB().map(t => '<span>' + t + '</span>').join('') + '</div>'
      + audF.map(r => { const [ic, cor] = AI(r.acao); return '<div class="mp-audit-grade mp-audit-lin mp-lin"><span class="mp-hv">' + esc(dataHora(r.quando)) + '</span><span class="mp-hv">' + esc(r.quem) + '</span>'
        + '<span class="mp-hv"><span style="--cor:' + cor + '"><i class="bi ' + ic + '"></i><b>' + esc(r.acao) + '</b></span><br><span>' + esc(r.conta) + '</span></span><span class="mp-hv">' + esc(r.detalhe) + '</span></div>'; }).join('')
      + (audF.length ? '' : '<div class="mp-vazio-linha">' + T('master.js.audit.vazio') + '</div>')
      + '</div>';
  }

  function gerarPdf() {
    const audF = audFiltrada(), contaNome = S.audConta !== null ? ((D.contas && D.contas.contas.find(c => c.id === S.audConta)) || {}).nome || (audF[0] || {}).conta || '' : '';
    const linhas = audF.map(r => '<tr><td>' + esc(dataHora(r.quando)) + '</td><td>' + esc(r.quem) + '</td><td><b>' + esc(r.acao) + '</b><br><span>' + esc(r.conta) + '</span></td><td>' + esc(r.detalhe) + '</td></tr>').join('');
    const w = window.open('', '_blank'); if (!w) return;
    w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + T('master.js.pdf.titulo_janela') + '</title><style>body{font-family:Arial,sans-serif;color:#12242f;margin:0}header{background:#0a6fa0;color:#fff;padding:18px 28px;display:flex;align-items:center;gap:14px}header img{height:30px}h1{font-size:20px;margin:0}main{padding:22px 28px}p{color:#4a6373;font-size:12px;margin:0 0 14px}table{width:100%;border-collapse:collapse;font-size:12px}th{text-align:left;background:#f5f8fa;border-bottom:2px solid #d6e0e7;padding:8px;font-size:10.5px;letter-spacing:1px}td{border-bottom:1px solid #e6edf2;padding:8px;vertical-align:top}td span{color:#5b7383}footer{margin-top:18px;font-size:10.5px;color:#7d909d}@page{size:A4;margin:12mm}@media print{header{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body>'
      + '<header><img src="' + esc(new URL(URLS.logo, location.href).href) + '" alt="BlueJus"><h1>' + T('master.js.pdf.titulo') + '</h1></header><main><p>'
      + T('master.js.pdf.gerado', { quando: esc(agora()), quem: esc(QUEM), papel: T('termos.master'), n: audF.length })
      + (S.audTipo ? ' · ' + T('master.js.pdf.filtro', { filtro: esc(S.audTipo) }) : '') + (contaNome ? ' · ' + T('master.js.pdf.conta', { conta: esc(contaNome) }) : '') + '</p><table><thead><tr>' + AUD_CAB().map(t => '<th>' + t + '</th>').join('') + '</tr></thead><tbody>' + linhas
      + '</tbody></table><footer>' + T('master.js.pdf.rodape', { papel: T('termos.master') }) + '</footer></main></body></html>');
    w.document.close(); setTimeout(() => w.print(), 400);
  }

  /* ---------- eventos da seção (delegados) ---------- */
  elSecao.addEventListener('click', e => {
    const t = e.target;
    const kpi = t.closest('[data-ir]'); if (kpi) return irPara(kpi.dataset.ir);
    const ped = t.closest('[data-pedido-op]'); if (ped) { e.stopPropagation(); fecharMenuDaConta(); return pedirAcaoNaConta(ped.dataset.pedidoOp, +ped.dataset.contaId); }
    if (t.closest('[data-parar]') && !t.closest('[data-op]')) return e.stopPropagation();
    const vis = t.closest('[data-gr-vis]'); if (vis) { S.grVis = vis.dataset.grVis; return renderGrafico(); }
    const mod = t.closest('[data-gr-mod]'); if (mod) { S.grMod = mod.dataset.grMod; return renderGrafico(); }
    if (t.closest('[data-gr-todos]')) { S.grSel = ANOS(); return renderGrafico(); }
    const ano = t.closest('[data-gr-ano]');
    if (ano) { const a = +ano.dataset.grAno, on = S.grSel.includes(a), sel = on ? S.grSel.filter(x => x !== a) : S.grSel.concat(a); if (sel.length) S.grSel = sel; return renderGrafico(); }
    const quem = t.closest('[data-mapa-quem]'); if (quem) { S.mapaQuem = quem.dataset.mapaQuem; return renderMapa(); }
    const ind = t.closest('[data-mapa-ind]'); if (ind) { S.mapaInd = ind.dataset.mapaInd; return renderMapa(); }
    const usu = t.closest('[data-mapa-usu]'); if (usu) { S.mapaUsu = usu.dataset.mapaUsu; return renderMapa(); }
    const modo = t.closest('[data-mapa-modo]'); if (modo) { S.mapaModo = modo.dataset.mapaModo; return renderMapa(); }
    const fm = t.closest('[data-faltam-modo]'); if (fm) { S.faltamCid = fm.dataset.faltamModo === '1'; return renderMapa(); }
    if (t.closest('[data-faltam]')) { S.faltamOn = !S.faltamOn; return renderMapa(); }
    const menu = t.closest('[data-menu]');
    if (menu) { e.stopPropagation(); const id = +menu.dataset.menu; return S.menu === id ? fecharMenuDaConta() : abrirMenuDaConta(id, posDoBotao(menu)); }
    const op = t.closest('[data-op]');
    if (op) {
      e.stopPropagation(); fecharMenuDaConta();
      if (op.dataset.op === 'estat') return irPara('arm');
      if (op.dataset.op === 'limArm' || op.dataset.op === 'limUsu') return abrirLimite(op.dataset.op === 'limArm' ? 'arm' : 'usu', +op.dataset.contaId);
      if (CONF_CONTA[op.dataset.op]) return pedirAcaoNaConta(op.dataset.op, +op.dataset.contaId);
      S.audConta = +op.dataset.contaId; S.audTipo = ''; return irPara('audit');
    }
    const limArm = t.closest('[data-lim-arm]'); if (limArm) return abrirLimite('arm', +limArm.dataset.limArm);
    const plano = t.closest('[data-plano-editar]'); if (plano) return abrirPlano(plano.dataset.planoEditar);
    if (t.closest('[data-faixas-salvar]')) return salvarFaixas();
    if (t.closest('[data-pdf]')) return gerarPdf();
    if (t.closest('[data-aud-todas]')) { S.audConta = null; return montarAudit(); }
  });
  elSecao.addEventListener('contextmenu', e => {
    const lin = e.target.closest('[data-conta]'); if (!lin) return;
    e.preventDefault(); abrirMenuDaConta(+lin.dataset.conta, posDoClique(e));
  });
  elSecao.addEventListener('change', e => {
    const t = e.target;
    if (t.matches('[data-gr-met]')) { S.grMet = t.value; return renderGrafico(); }
    if (t.matches('[data-f-sit]')) { S.fSit = t.value; fecharMenuDaConta(); return renderContasLista(); }
    if (t.matches('[data-aud-tipo]')) { S.audTipo = t.value; return montarAudit(); }
    if (t.matches('[data-faixa-bloqueio]')) { S.fx.bloqueioAtivo = t.checked; S.fxMsg = null; return atualizarFaixas(); }
  });
  elSecao.addEventListener('input', e => {
    if (e.target.matches('[data-busca]')) { S.busca = e.target.value; fecharMenuDaConta(); renderContasLista(); }
    if (e.target.matches('[data-faixa]')) { S.fx[e.target.dataset.faixa] = +e.target.value || 0; S.fxMsg = null; atualizarFaixas(); }
  });
  // Fecha o menu: clique fora, Esc, rolagem da página OU da tabela (captura: o scroll não borbulha) e janela redimensionada.
  document.addEventListener('click', e => { if (!e.target.closest('[data-parar]')) fecharMenuDaConta(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharMenuDaConta(); });
  document.addEventListener('scroll', fecharMenuDaConta, true);
  window.addEventListener('resize', fecharMenuDaConta);

  renderSecs();
  renderSecao();
  // A trilha só é carregada quando a seção é aberta; o contador vem antes, com a Visão geral (P10).
  carregar('visao', URLS.visao);
})();
