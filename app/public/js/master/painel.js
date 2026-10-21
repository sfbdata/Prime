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
   ranking com plano, limite, % e faixa do RetratoDoEspaco da Contribua. As regras moram no servidor. */
(() => {
  'use strict';
  const raiz = document.getElementById('master-painel');
  if (!raiz) return;

  const URLS = JSON.parse(raiz.dataset.urls || '{}');
  const QUEM = raiz.dataset.quem || 'Master';
  const elSecs = document.getElementById('mp-secs');
  const elSecao = document.getElementById('mp-secao');

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const num = n => Number(n || 0).toLocaleString('pt-BR');
  const GB = 1073741824;
  const gb = bytes => { const v = (bytes || 0) / GB; if (v > 0 && v < 0.1) return 'menos de 0,1 GB'; return v.toFixed(1).replace('.', ',') + ' GB'; };
  const pct = (n, d) => (d > 0 ? Math.round(n / d * 100) : 0) + '%';
  const dataHora = iso => { const d = new Date(iso); if (isNaN(d)) return ''; return d.toLocaleDateString('pt-BR') + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const agora = () => dataHora(new Date().toISOString());

  const S = { aba: 'painel', sec: 'visao', grMet: 'pastas', grVis: 'mes', grMod: 'acum', grSel: null, mapaModo: 'br', mapaQuem: 'pes', mapaUsu: 'geral',
    faltamOn: false, busca: '', fSit: '', menu: null, menuPos: null, audTipo: '', audConta: null, aviso: null, fx: null, fxMsg: null };
  const D = { visao: null, contas: null, oab: null, audit: null, planos: null };
  const ERRO = {};

  const SECS = [['visao', 'Visão geral', 'bi-speedometer2'], ['contas', 'Contas e escritórios', 'bi-buildings'], ['oab', 'Usuários e OABs', 'bi-person-vcard'],
    ['arm', 'Armazenamento', 'bi-hdd-stack'], ['planos', 'Planos e limites', 'bi-sliders'], ['custos', 'Custos e infraestrutura', 'bi-cash-coin'],
    ['desemp', 'Desempenho', 'bi-activity'], ['audit', 'Segurança e auditoria', 'bi-journal-check'], ['config', 'Configurações globais', 'bi-gear']];
  // Seções de outras frentes (desenho 1.2): entram só quando o parcial delas existe e trouxe conteúdo.
  const EXTERNAS = {};
  raiz.querySelectorAll('[data-sec-externa]').forEach(el => { if (el.innerHTML.trim()) EXTERNAS[el.dataset.secExterna] = el; });
  if (EXTERNAS.com) SECS.push(['com', 'Comercial e e-mails', 'bi-megaphone']);
  if (EXTERNAS.ind) SECS.push(['ind', 'Indicações do BlueJus', 'bi-share']);
  const VAZIAS = {
    custos: ['bi-cash-coin', 'Custos e infraestrutura', 'Mostra quanto custa manter o BlueJus e o impacto de cada conta, separando custos medidos, importados de fornecedores e estimados. Sem integração financeira, os valores podem ser lançados manualmente, com origem identificada.', ['Armazenamento', 'Banco de dados', 'Hospedagem', 'Tráfego', 'Backups', 'APIs de IA', 'Notificações', 'Receita recorrente', 'Custo por conta', 'Custo por GB', 'Margem por plano', 'Projeção 3, 6 e 12 meses']],
    desemp: ['bi-activity', 'Desempenho e disponibilidade', 'Saúde técnica da plataforma, com histórico filtrável por gravidade, período e categoria. Métricas não coletadas aparecem como indisponíveis, nunca estimadas.', ['Disponibilidade', 'Tempo de resposta', 'Erros', 'Falhas de upload', 'Falhas de login', 'Último backup', 'Teste de restauração', 'CPU e memória', 'Integrações', 'Incidentes']],
    config: ['bi-gear', 'Configurações globais', 'Parâmetros que valem para todas as contas. Cada alteração exige confirmação e fica na auditoria.', ['Faixas de alerta padrão', 'Período de teste', 'Política de excedente', 'Exceções temporárias', 'Autenticação reforçada', 'Retenção de auditoria']]
  };
  const NM = { RO: 'Rondônia', AC: 'Acre', AM: 'Amazonas', RR: 'Roraima', PA: 'Pará', AP: 'Amapá', TO: 'Tocantins', MA: 'Maranhão', PI: 'Piauí', CE: 'Ceará', RN: 'Rio Grande do Norte', PB: 'Paraíba', PE: 'Pernambuco', AL: 'Alagoas', SE: 'Sergipe', BA: 'Bahia', MG: 'Minas Gerais', ES: 'Espírito Santo', RJ: 'Rio de Janeiro', SP: 'São Paulo', PR: 'Paraná', SC: 'Santa Catarina', RS: 'Rio Grande do Sul', MS: 'Mato Grosso do Sul', MT: 'Mato Grosso', GO: 'Goiás', DF: 'Distrito Federal' };
  const ST = { Ativa: '#1e7b45', Suspensa: '#a3232b', 'Suspensa a pedido': '#a3232b', 'Em quarentena': '#5b7383' };
  const QUARENTENA = 'Em quarentena';
  // Pedido de exclusão pendente (o Tenant segue ativo): a tabela mostra "Suspensa a pedido", não "Ativa" (desvio pelo
  // dado: o protótipo mostrava Ativa). Só o rótulo muda; filtro e menu seguem a situação gravada da conta.
  const A_PEDIDO = 'Suspensa a pedido';
  const situacaoVisivel = c => (c.pedidoPendente && c.situacao === 'Ativa' ? A_PEDIDO : c.situacao);
  const dataBr = ymd => { const p = String(ymd || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ''; };
  // Faixa do RetratoDoEspaco (Contribua) com o rótulo e a cor da função faixa() do desenho (linha 593).
  const FAIXA = { livre: ['Normal', '#1e7b45'], atencao: ['Atenção', '#c08a12'], alerta: ['Alerta', '#c0392b'], critico: ['Crítico', '#a3232b'], bloqueio: ['Limite atingido', '#7a1b22'] };
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
    ? '<div class="mp-card mp-carregando">Não foi possível carregar esta seção agora. Recarregue a página.</div>'
    : '<div class="mp-carregando">Carregando…</div>');

  /* ---------- abas ---------- */
  function mostrarAba() {
    raiz.querySelectorAll('[data-aba]').forEach(x => { const on = x.dataset.aba === S.aba; x.classList.toggle('on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    raiz.querySelectorAll('[data-painel-aba]').forEach(p => { p.hidden = p.dataset.painelAba !== S.aba; });
  }
  raiz.querySelectorAll('[data-aba]').forEach(b => b.addEventListener('click', () => { S.aba = b.dataset.aba; mostrarAba(); }));

  // Estado inicial pelo hash (desenho 1.2): aba=(\w+) e sec=(\w+). Valor desconhecido fica no padrão.
  const doHash = chave => (location.hash.match(new RegExp(chave + '=(\\w+)')) || [])[1];
  if (raiz.querySelector('[data-aba="' + doHash('aba') + '"]')) S.aba = doHash('aba');
  if (SECS.some(([k]) => k === doHash('sec'))) S.sec = doHash('sec');
  mostrarAba();

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
    + '<span class="mp-vazia-aviso">DADO AINDA NÃO COLETADO · DEPENDE DE INTEGRAÇÃO NO SERVIDOR</span></div>';

  /* ================= VISÃO GERAL ================= */
  function montarVisao() {
    elSecao.innerHTML = '<div class="mp-kpis" id="mp-kpis"></div>'
      + '<div class="mp-card mp-gr" id="mp-gr"></div>'
      + '<div class="mp-card mp-mapa"><div class="mp-mapa-topo" id="mp-mapa-topo"></div>'
      + '<div class="mp-mapa-corpo"><div class="mp-uf-tab" id="mp-uf-tab"></div><iframe class="mp-mapa-iframe" id="mp-mapa-iframe" title=""></iframe></div>'
      + '<div class="mp-escala"><span>menos</span>' + ['#eef2f5', '#cfe3f1', '#4f8fbd', '#0a4f7a'].map(c => '<i style="--cor:' + c + '"></i>').join('')
      + '<span>mais</span><span class="mp-espaco"></span><span>Malha dos estados: IBGE</span></div></div>'
      + '<div class="mp-card mp-idades" id="mp-idades"></div>';
    renderKpis(); renderGrafico(); renderMapa(); renderIdades();
  }

  function renderKpis() {
    const k = D.visao.kpis;
    const kpis = [
      { rot: 'ESCRITÓRIOS', n: num(k.escritorios), desc: num(k.ativas) + ' ativa · ' + num(k.suspensas) + ' suspensa', icone: 'bi-buildings', cor: '#0a6fa0', bg: '#e5f1f7', ir: 'contas' },
      { rot: 'USUÁRIOS PRIMÁRIOS', n: num(k.primarios), desc: 'quem abriu o escritório e os sócios', icone: 'bi-person-badge', cor: '#0a6fa0', bg: '#e5f1f7', ir: 'contas' },
      { rot: 'USUÁRIOS SECUNDÁRIOS', n: num(k.secundarios), desc: 'convidados em todas as contas', icone: 'bi-people', cor: '#3b5bb8', bg: '#e9edf8', ir: 'contas' },
      { rot: 'OABs ÚNICAS', n: num(k.oabUnicas), desc: num(k.oabRegistros) + ' registros · ' + num(k.oabRegistros - k.oabUnicas) + ' duplicado(s)', icone: 'bi-person-vcard', cor: '#7a3e8f', bg: '#f3e9f7', ir: 'oab' },
      { rot: 'PASTAS', n: num(k.pastas), desc: 'soma de todas as contas', icone: 'bi-folder2-open', cor: '#1e7b45', bg: '#e6f4ec', ir: 'contas' },
      { rot: 'DOCUMENTOS', n: num(k.documentos), desc: 'arquivos armazenados', icone: 'bi-file-earmark-text', cor: '#1e7b45', bg: '#e6f4ec', ir: 'contas' },
      kpiArmazenamento(k, D.visao.armazenamento),
      { rot: 'RECEITA MENSAL', n: 'sem informação', texto: true, desc: 'sem integração financeira ainda', icone: 'bi-cash-coin', cor: '#7d909d', bg: '#eef2f5', ir: 'custos' }
    ];
    document.getElementById('mp-kpis').innerHTML = kpis.map(x => '<button type="button" class="mp-kpi" data-ir="' + x.ir + '" style="--cor:' + x.cor + ';--bg:' + x.bg + '">'
      + '<span class="mp-kpi-ico"><i class="bi ' + x.icone + '"></i></span><span class="mp-kpi-rot">' + esc(x.rot) + '</span>'
      + '<span class="mp-kpi-n' + (x.texto ? ' mp-kpi-n-texto' : '') + '">' + esc(x.n) + '</span><span class="mp-kpi-desc">' + esc(x.desc) + '</span>'
      + (x.pct ? '<div class="mp-barra-6 mp-kpi-barra"><div style="--w:' + x.pct + '"></div></div>' : '')
      + '<span class="mp-kpi-base"></span><span class="mp-kpi-brilho"></span></button>').join('');
  }

  // KPI ARMAZENAMENTO (desenho 1.2, linha 688; spec P9): cota = soma dos limites das contas com limite, cor e barra pela faixa.
  function kpiArmazenamento(k, a) {
    const base = { rot: 'ARMAZENAMENTO', n: gb(k.bytes), icone: 'bi-hdd-stack', bg: '#fbf1dc', ir: 'arm' };
    if (!a) return Object.assign(base, { desc: 'contratado: sem informação · ponto e tarefas não medidos', cor: '#c08a12' });
    // Número, descrição e barra somam só as contas com limite, como no desenho (a cota é delas); o uso das contas
    // sem limite vem no sufixo. Sem nenhuma conta com limite, fica o texto antigo, com o uso total.
    const sem = a.contasSemLimite > 0 ? ' · + ' + gb(a.bytesSemLimite) + ' em ' + num(a.contasSemLimite) + ' conta(s) sem limite' : '';
    return Object.assign(base, { n: gb(a.bytesUsados), desc: 'de ' + num(a.cotaGb) + ' GB contratados · livre ' + gb(a.bytesLivres) + sem, cor: (FAIXA[a.faixa] || FAIXA.livre)[1], pct: a.percentual + '%' });
  }

  /* ---------- Crescimento (gráfico SVG do desenho) ---------- */
  const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const CRESC = () => D.visao.crescimento;
  const ANOS = () => Object.keys(CRESC()).map(Number).sort((a, b) => a - b);
  const corDoAno = ano => { const anos = ANOS(), i = anos.length - 1 - anos.indexOf(ano); return ['#0a6fa0', '#7d909d', '#c08a12', '#7a3e8f', '#1e7b45', '#3b5bb8'][i % 6]; };
  const curva = P => { if (!P.length) return ''; if (P.length === 1) return 'M' + P[0].x + ' ' + P[0].y; const k = P.length, dx = [], m = [], t = []; for (let i = 0; i < k - 1; i++) { dx[i] = P[i + 1].x - P[i].x; m[i] = (P[i + 1].y - P[i].y) / dx[i]; } t[0] = m[0]; t[k - 1] = m[k - 2]; for (let i = 1; i < k - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : 3 * (dx[i - 1] + dx[i]) / ((2 * dx[i] + dx[i - 1]) / m[i - 1] + (dx[i] + 2 * dx[i - 1]) / m[i]); let d = 'M' + P[0].x.toFixed(1) + ' ' + P[0].y.toFixed(1); for (let i = 0; i < k - 1; i++) { const h = dx[i] / 3; d += ' C' + (P[i].x + h).toFixed(1) + ' ' + (P[i].y + t[i] * h).toFixed(1) + ' ' + (P[i + 1].x - h).toFixed(1) + ' ' + (P[i + 1].y - t[i + 1] * h).toFixed(1) + ' ' + P[i + 1].x.toFixed(1) + ' ' + P[i + 1].y.toFixed(1); } return d; };

  function renderGrafico() {
    const C = CRESC(), anos = ANOS();
    if (!S.grSel) S.grSel = anos.slice(-2);
    const vis = S.grVis, mod = S.grMod, sel = S.grSel.slice().sort((a, b) => a - b);
    const fmt = v => (S.grMet === 'gb' ? (Math.round(v * 10) / 10).toString().replace('.', ',') : Math.round(v).toLocaleString('pt-BR'));
    const serie = a => { const v = C[a][S.grMet]; if (mod === 'acum') return v.slice(); const ant = C[a - 1] ? C[a - 1][S.grMet].filter(x => x !== null).slice(-1)[0] || 0 : 0; return v.map((x, i) => (x === null ? null : Math.max(0, Math.round((x - (i ? v[i - 1] : ant)) * 10) / 10))); };
    let rotulos, grupos;
    if (vis === 'mes') { rotulos = MES.slice(); grupos = sel.map(a => ({ cor: corDoAno(a), vals: serie(a).map((v, i) => ({ v, tip: MES[i] + '/' + a })) })); }
    else if (vis === 'todos') { const pts = [].concat(...sel.map(a => serie(a).map((v, i) => ({ v, tip: MES[i] + '/' + String(a).slice(2) })).filter(p => p.v !== null))); rotulos = pts.map((p, i) => (i % 2 ? '' : p.tip)); grupos = [{ cor: '#0a6fa0', vals: pts }]; }
    else { rotulos = sel.map(String); grupos = [{ cor: '#0a6fa0', vals: sel.map(a => { const v = serie(a).filter(x => x !== null); return { v: mod === 'acum' ? v[v.length - 1] : v.reduce((p, q) => p + q, 0), tip: String(a) }; }) }]; }
    const n = rotulos.length, X = i => (n > 1 ? 60 + i * (688 / (n - 1)) : 404);
    const all = [].concat(...grupos.map(g => g.vals.map(p => p.v).filter(v => v !== null))), mx = Math.max(1, ...all) * 1.12, Y = v => 200 - v / mx * 180;
    const grade = [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: Y(mx * f), topPct: (Y(mx * f) / 230 * 100) + '%', v: fmt(mx * f) }));
    const series = grupos.map(g => { const pts = g.vals.map((p, i) => (p.v === null ? null : { x: X(i), y: Y(p.v), tip: p.tip + ': ' + fmt(p.v) })).filter(Boolean), d = curva(pts); return { cor: g.cor, pts, d, area: pts.length > 1 ? d + ' L' + pts[pts.length - 1].x + ' 200 L' + pts[0].x + ' 200 Z' : '' }; });
    const tit = { contas: 'contas cadastradas', usuarios: 'usuários (primários + secundários)', pastas: 'pastas no sistema', gb: 'armazenamento em GB' }[S.grMet];
    const seg = (lista, atual, attr) => '<div class="mp-seg">' + lista.map(([k, rot]) => '<button type="button" class="' + (atual === k ? 'on' : '') + '" ' + attr + '="' + k + '">' + esc(rot) + '</button>').join('') + '</div>';
    const todos = sel.length === anos.length;
    document.getElementById('mp-gr').innerHTML = '<div class="mp-gr-topo"><b>Crescimento <span>' + esc(tit) + '</span></b>'
      + '<select class="mp-select" data-gr-met>' + [['contas', 'Contas'], ['usuarios', 'Usuários'], ['pastas', 'Pastas'], ['gb', 'Armazenamento (GB)']].map(([k, r]) => '<option value="' + k + '"' + (S.grMet === k ? ' selected' : '') + '>' + r + '</option>').join('') + '</select>'
      + seg([['mes', 'Por mês'], ['ano', 'Por ano'], ['todos', 'Linha contínua']], vis, 'data-gr-vis') + seg([['acum', 'Acumulado'], ['novo', 'Novos no mês']], mod, 'data-gr-mod')
      + '<div class="mp-anos"><button type="button" class="mp-todos-anos' + (todos ? ' on' : '') + '" data-gr-todos>Todos os anos</button>'
      + anos.map(a => { const on = S.grSel.includes(a), cor = corDoAno(a); return '<button type="button" class="mp-ano" data-gr-ano="' + a + '" style="--linha:' + cor + ';--bg:' + (on ? '#fff' : '#f3f6f8') + ';--borda:' + (on ? cor : '#e2e9ee') + ';--cor:' + (on ? '#12242f' : '#98a9b4') + '"><span class="mp-ano-linha"></span>' + a + '</button>'; }).join('') + '</div></div>'
      + '<div class="mp-gr-area">' + grade.map(g => '<span class="mp-gr-y" style="top:' + g.topPct + '">' + esc(g.v) + '</span>').join('')
      + rotulos.map((r, i) => '<span class="mp-gr-x" style="left:' + (X(i) / 760 * 100) + '%">' + esc(r) + '</span>').join('')
      + '<svg viewBox="0 0 760 230" aria-hidden="false">' + grade.map(g => '<line x1="44" x2="748" y1="' + g.y + '" y2="' + g.y + '" stroke="#eef2f5"></line>').join('')
      + series.map(s => '<path class="mp-gr-area-fill" d="' + s.area + '" fill="' + s.cor + '"></path><path class="mp-gr-linha" d="' + s.d + '" stroke="' + s.cor + '"></path>'
        + s.pts.map(p => '<g><circle class="mp-gr-ponto" cx="' + p.x + '" cy="' + p.y + '" r="2.6" stroke="' + s.cor + '"></circle><circle class="mp-gr-alvo" cx="' + p.x + '" cy="' + p.y + '" r="11" fill="' + s.cor + '"><title>' + esc(p.tip) + '</title></circle></g>').join('')).join('')
      + '</svg></div>';
  }

  /* ---------- Onde estamos atuando ---------- */
  const REG_BR = [['Norte', ['RO', 'AC', 'AM', 'RR', 'PA', 'AP', 'TO']], ['Nordeste', ['MA', 'PI', 'CE', 'RN', 'PB', 'PE', 'AL', 'SE', 'BA']], ['Centro-Oeste', ['MS', 'MT', 'GO', 'DF']], ['Sudeste', ['MG', 'ES', 'RJ', 'SP']], ['Sul', ['PR', 'SC', 'RS']]];
  const REG_MUNDO = [['América do Sul', ['Argentina', 'Bolívia', 'Chile', 'Colômbia', 'Equador', 'Paraguai', 'Peru', 'Uruguai', 'Venezuela']], ['América do Norte e Central', ['Canadá', 'México', 'Estados Unidos']], ['Europa', ['Portugal', 'Espanha', 'Itália', 'França', 'Alemanha', 'Reino Unido', 'Suíça', 'Países Baixos', 'Bélgica', 'Irlanda']], ['Lusofonia', ['Angola', 'Moçambique', 'Cabo Verde', 'Guiné-Bissau', 'São Tomé e Príncipe', 'Timor-Leste']]];

  function geo() {
    const M = D.visao.mapa;
    if (S.mapaQuem === 'esc') return { A: M.escritorios.A || {}, C: M.escritorios.C || {}, tend: {}, semEnd: M.escritorios.semEnd || 0, semLoc: 0 };
    const g = M.pessoas[S.mapaUsu] || {};
    return { A: g.A || {}, C: g.C || {}, tend: g.tend || {}, semEnd: 0, semLoc: g.semLoc || 0 };
  }

  function renderMapa() {
    const M = D.visao.mapa, G = geo(), ATUA = G.A, br = S.mapaModo !== 'mundo', ehPes = S.mapaQuem === 'pes';
    const exterior = M.exterior || 0, paisesExt = M.paises || [];
    const totBr = Object.values(ATUA).reduce((a, b) => a + b, 0), ufTotal = totBr + exterior;
    const maxAt = Math.max(1, ...Object.values(ATUA));
    const seg = (lista, atual, attr, comIcone) => '<div class="mp-seg mp-seg-28">' + lista.map(([k, rot, ic]) => '<button type="button" class="' + (atual === k ? 'on' : '') + '" ' + attr + '="' + k + '">' + (comIcone ? '<i class="bi ' + ic + '"></i>' : '') + esc(rot) + '</button>').join('') + '</div>';

    document.getElementById('mp-mapa-topo').innerHTML = '<b>Onde estamos atuando</b><span class="mp-mapa-sub">' + (br ? 'usuários por estado' : 'usuários por país') + ' · ' + num(ufTotal) + ' no total</span>'
      + (br ? seg([['pes', 'Pessoas', 'bi-people'], ['esc', 'Escritórios', 'bi-buildings']], S.mapaQuem, 'data-mapa-quem', true) : '')
      + (ehPes && br ? seg([['geral', 'Geral'], ['prim', 'Só primários']], S.mapaUsu, 'data-mapa-usu', false) : '')
      + seg([['br', 'Brasil', 'bi-geo-alt-fill'], ['mundo', 'Mundo', 'bi-globe-americas']], S.mapaModo, 'data-mapa-modo', true);

    let linhas;
    if (!br) {
      const P = [['076', 'Brasil', totBr]].concat(paisesExt), mx = Math.max(1, ...P.map(p => p[2]));
      linhas = P.sort((a, b) => b[2] - a[2]).map(p => '<div class="mp-uf-lin"><span class="mp-uf-barra" style="--w:' + pct(p[2], mx) + '"></span><span class="mp-uf-nome">' + esc(p[1]) + '</span><b>' + num(p[2]) + '</b><i class="mp-uf-seta"></i></div>').join('');
    } else {
      linhas = Object.entries(ATUA).sort((a, b) => b[1] - a[1]).map(([uf, n]) => {
        const t = S.mapaQuem === 'esc' ? { ag: 0, an: 0 } : (G.tend[uf] || { ag: 0, an: 0 }), dl = t.ag - t.an;
        const ic = dl > 0 ? 'bi-arrow-up-right' : dl < 0 ? 'bi-arrow-down-right' : 'bi-arrow-right', cor = dl > 0 ? '#1e7b45' : dl < 0 ? '#a3232b' : '#9aabb7';
        const tit = S.mapaQuem === 'esc' ? 'Tendência medida só para pessoas' : (t.ag >= 0 ? '+' : '') + t.ag + ' nos últimos 30 dias · ' + (t.an >= 0 ? '+' : '') + t.an + ' nos 30 anteriores';
        return '<div class="mp-uf-lin"><span class="mp-uf-barra" style="--w:' + pct(n, maxAt) + '"></span><span class="mp-uf-nome">' + esc(NM[uf] || uf) + '</span><b>' + num(n) + '</b><i class="bi ' + ic + ' mp-uf-seta" style="--cor:' + cor + '" title="' + esc(tit) + '"></i></div>';
      }).join('');
    }

    let grupos, tot, meta;
    if (br) {
      grupos = REG_BR.map(([rot, ufs]) => { const itens = ufs.filter(u => !ATUA[u]).map(u => NM[u]).sort((a, b) => a.localeCompare(b)); return { rot, n: itens.length, itens }; }).filter(g => g.n);
      tot = 27; meta = 'os 27 estados';
    } else {
      const tem = new Set(['Brasil'].concat(paisesExt.map(p => p[1])));
      grupos = REG_MUNDO.map(([rot, ps]) => { const itens = ps.filter(p => !tem.has(p)).sort((a, b) => a.localeCompare(b)); return { rot, n: itens.length, itens }; }).filter(g => g.n);
      tot = REG_MUNDO.reduce((a, g) => a + g[1].length, 0); meta = 'os ' + tot + ' países-alvo';
    }
    const faltamN = grupos.reduce((a, g) => a + g.n, 0), feitos = tot - faltamN, on = S.faltamOn;
    const usuTit = S.mapaQuem === 'esc' ? 'Escritórios' : S.mapaUsu === 'prim' ? 'Primários' : 'Pessoas';

    document.getElementById('mp-uf-tab').innerHTML = '<div class="mp-uf-cab"><span>' + (br ? 'Estado' : 'País') + '</span><span>' + usuTit + '</span><span></span></div>' + linhas
      + (br && ehPes ? blocoSeccionais() : '')
      + (br ? '<div class="mp-uf-outros"><span><i class="bi bi-globe-americas"></i>Outros países</span><b>' + num(exterior) + '</b></div>' : '')
      + (S.mapaQuem === 'esc' && G.semEnd > 0 ? '<div class="mp-uf-semend"><span><i class="bi bi-geo"></i>Sem endereço no cadastro</span><b>' + num(G.semEnd) + '</b></div>' : '')
      + (ehPes && G.semLoc > 0 ? '<div class="mp-uf-semend" title="Sem lotação Cidade/UF, sem UF da OAB e sem estado do escritório"><span><i class="bi bi-geo"></i>Sem localização no cadastro</span><b>' + num(G.semLoc) + '</b></div>' : '')
      + '<div class="mp-uf-total"><span>Total geral</span><span>' + num(ufTotal) + '</span></div>'
      + '<button type="button" class="mp-faltam-btn' + (on ? ' on' : '') + '" data-faltam title="Mostrar onde ainda não temos usuários"><i class="bi bi-bullseye"></i><span>' + (on ? 'Ocultar o que falta' : (br ? 'Estados que faltam' : 'Países que faltam')) + '</span><span class="mp-faltam-n">' + faltamN + '</span><i class="bi ' + (on ? 'bi-chevron-up' : 'bi-chevron-down') + '"></i></button>'
      + (on ? '<div class="mp-faltam"><div class="mp-faltam-cob">Cobertura: <b>' + feitos + ' de ' + tot + ' (' + Math.round(feitos / tot * 100) + '%)</b> · faltam ' + faltamN + ' para alcançar ' + meta + '</div>'
        + '<div class="mp-faltam-barra"><div style="--w:' + Math.round(feitos / tot * 100) + '%"></div></div><div class="mp-faltam-lista">'
        + grupos.map(g => '<div class="mp-faltam-grupo">' + esc(g.rot) + ' · ' + g.n + '</div>' + g.itens.map(f => '<div class="mp-faltam-item"><i></i><span>' + esc(f) + '</span><span>0</span></div>').join('')).join('')
        + '</div><div class="mp-faltam-rod">Oportunidades de expansão</div></div>' : '');

    // iframe: só troca o endereço quando os dados do mapa mudam (evita recarregar o mapa a cada clique).
    // O iframe busca as contagens no servidor (/master/dados/mapa) e as valida; a query string só escolhe a visão.
    const qs = 'quem=' + (S.mapaQuem === 'esc' ? 'esc' : 'pes') + '&usu=' + (S.mapaUsu === 'prim' ? 'prim' : 'geral');
    const src = (br ? URLS.mapaBrasil : URLS.mapaMundo) + '?' + qs, fr = document.getElementById('mp-mapa-iframe');
    fr.style.setProperty('--ratio', br ? '1.75' : '2.05');
    fr.title = br ? 'Mapa do Brasil por estado' : 'Mapa mundial por país';
    if (fr.dataset.src !== src) { fr.dataset.src = src; fr.src = src; }
  }

  /* ---------- Seccionais da OAB (desenho 05, Perfis 1.2, VS 21.10.26, L116-127; spec master-seccionais-oab) ----------
     Bloco "SECCIONAIS VISÍVEIS" embaixo das UFs, só no Brasil + Pessoas (convenção da spec até o arquivo inteiro do
     desenho: DONO). Dados de /master/dados/seccionais (o mesmo GET do iframe). Mostrar ou ocultar é do navegador
     (localStorage, chave lida também pelo mapa-brasil.js); a sede e as divulgações vêm do servidor. */
  const SEC_CHAVE = 'bj-mapa-seccionais-ocultas';
  const SEC_CFOAB = 'https://www.oab.org.br/institucionalconselhofederal/asiseccional';
  const SEC_COR = { material: 'var(--sec-material)', reuniao: 'var(--sec-reuniao)', parceria: 'var(--sec-parceria)' };
  const SEC_NOTA = 'Clique numa seccional para ver a sede. Botão direito na lista mostra ou oculta no mapa; botão direito no mapa registra a divulgação (material enviado, reunião, parceria). O ponto colorido indica seccional já trabalhada. Fonte: Conselho Federal da OAB.';
  const SEC = { dados: null, estado: null, aberta: null };
  const secOcultas = () => { try { const v = JSON.parse(localStorage.getItem(SEC_CHAVE) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
  const secGravarOcultas = ufs => { try { localStorage.setItem(SEC_CHAVE, JSON.stringify(ufs)); } catch (e) { /* navegador sem localStorage: vale só nesta tela */ } };
  const secDe = uf => (SEC.dados ? SEC.dados.seccionais.find(s => s.uf === uf) : null);
  const secUsuarios = n => (n === 1 ? '1 usuário' : num(n) + ' usuários');

  function carregarSeccionais() {
    if (SEC.estado === 'carregando') return;
    SEC.estado = 'carregando';
    fetch(URLS.seccionais, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(j => { SEC.dados = j; SEC.estado = null; })
      .catch(() => { SEC.estado = 'falhou'; })
      .finally(() => { if (S.sec === 'visao' && D.visao && document.getElementById('mp-uf-tab')) renderMapa(); });
  }
  // O iframe avisa depois de registrar uma divulgação (mesmo padrão do __bjFontesAuto do mapa-dados.js).
  window.__bjSeccionaisRecarregar = () => { SEC.estado = null; carregarSeccionais(); };

  function blocoSeccionais() {
    if (!SEC.dados) {
      if (SEC.estado !== 'falhou') carregarSeccionais();
      return '<div class="mp-sec-oab" data-sec-oab><span class="mp-sec-oab-nota">' + (SEC.estado === 'falhou' ? 'Não foi possível carregar as seccionais agora. Recarregue a página.' : 'Carregando as seccionais…') + '</span></div>';
    }
    const ocultas = secOcultas(), todas = SEC.dados.seccionais, algumaOculta = todas.some(s => ocultas.includes(s.uf));
    return '<div class="mp-sec-oab" data-sec-oab><div class="mp-sec-oab-cab"><b>SECCIONAIS VISÍVEIS</b><button type="button" data-sec-todas>' + (algumaOculta ? 'Mostrar todas' : 'Ocultar todas') + '</button></div>'
      + '<div class="mp-sec-oab-chips">' + todas.map(s => secChip(s, ocultas.includes(s.uf))).join('') + '</div>'
      + (secDe(SEC.aberta) ? secCartao(secDe(SEC.aberta)) : '')
      + '<div class="mp-sec-oab-nota">' + esc(SEC_NOTA) + '</div></div>';
  }

  function secChip(s, oculta) {
    const tit = s.nome + (oculta ? ' · oculta no mapa' : ' · visível no mapa') + (s.ultima ? ' · última divulgação: ' + s.ultima.rotulo + ' em ' + dataBr(s.ultima.data) : '');
    return '<button type="button" class="mp-sec-chip' + (oculta ? ' is-oculta' : '') + (SEC.aberta === s.uf ? ' is-aberta' : '') + '" data-sec-uf="' + esc(s.uf) + '" title="' + esc(tit) + '">' + esc(s.uf)
      + (s.ultima ? '<span class="mp-sec-ponto" style="--cor:' + (SEC_COR[s.ultima.tipo] || 'var(--sec-material)') + '"></span>' : '') + '</button>';
  }

  function secCartao(s) {
    const site = /^https:\/\//i.test(s.site || '') ? s.site : null;
    return '<div class="mp-sec-card" data-sec-card><div class="mp-sec-card-cab"><i class="bi bi-bank"></i><span>' + esc(s.nome) + '</span><button type="button" data-sec-fechar title="Fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button></div>'
      + '<div class="mp-sec-card-corpo"><span><i class="bi bi-people"></i>' + esc(secUsuarios(s.usuarios)) + '</span>'
      + '<span><i class="bi bi-geo-alt"></i>' + esc(s.endereco || 'Endereço não cadastrado. Confira no CFOAB.') + '</span>'
      + (s.telefone ? '<span><i class="bi bi-telephone"></i>' + esc(s.telefone) + '</span>' : '')
      + (site ? '<a href="' + esc(site) + '" target="_blank" rel="noopener"><i class="bi bi-globe2"></i>Site da seccional</a>' : '')
      + '<div class="mp-sec-card-acoes"><a href="' + SEC_CFOAB + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Endereço oficial (CFOAB)</a>'
      + '<button type="button" data-sec-editar><i class="bi bi-pencil"></i> Editar sede</button></div>'
      + secDivulgacoes(s.ultimas) + '</div></div>';
  }

  // As 5 últimas divulgações (spec: tipo, data, autor, nota). O desenho truncado não mostra a lista: convenção do cartão.
  const secDivulgacoes = lista => '<div class="mp-sec-divs"><b>ÚLTIMAS DIVULGAÇÕES</b>' + (lista.length
    ? lista.map(d => '<div class="mp-sec-div"><i style="--cor:' + (SEC_COR[d.tipo] || 'var(--sec-material)') + '"></i><span><b>' + esc(d.rotulo) + '</b> · ' + esc(dataBr(d.data)) + ' · ' + esc(d.autor || 'autor removido')
      + (d.nota ? '<span class="mp-sec-div-nota">' + esc(d.nota) + '</span>' : '') + '</span></div>').join('')
    : '<span class="mp-sec-div-vazio">Nenhuma divulgação registrada. Use o botão direito no mapa.</span>') + '</div>';

  function secAlternar(uf) {
    const ocultas = secOcultas();
    secGravarOcultas(ocultas.includes(uf) ? ocultas.filter(u => u !== uf) : ocultas.concat(uf));
    renderMapa();
  }

  function secTodas() {
    const algumaOculta = SEC.dados.seccionais.some(s => secOcultas().includes(s.uf));
    secGravarOcultas(algumaOculta ? [] : SEC.dados.seccionais.map(s => s.uf));
    renderMapa();
  }

  // Janela "Editar sede" no molde das janelas de limite e plano (caixa/campo do painel, host #mp-modal).
  function secAbrirEdicao(s) {
    const host = document.getElementById('mp-modal'); if (!host) return;
    host.innerHTML = caixa('bi-bank', 'Editar sede', '<b class="mp-lim-conta" data-sede="' + esc(s.uf) + '">' + esc(s.nome) + '</b>'
      + campo('ENDEREÇO', 'type="text" maxlength="255" data-sede-endereco value="' + esc(s.endereco || '') + '"')
      + campo('TELEFONE', 'type="text" maxlength="40" data-sede-telefone value="' + esc(s.telefone || '') + '"')
      + campo('SITE', 'type="url" maxlength="255" placeholder="https://" data-sede-site value="' + esc(s.site || '') + '"')
      + '<span class="mp-lim-msg" data-lim-msg></span>', 'Salvar sede');
    secAtualizarEdicao(null);
    host.querySelector('[data-sede-endereco]').focus();
  }

  function secAtualizarEdicao(erro) {
    const host = document.getElementById('mp-modal'), site = host.querySelector('[data-sede-site]').value.trim(), ok = site === '' || /^https:\/\/\S+$/i.test(site);
    const msg = host.querySelector('[data-lim-msg]');
    msg.textContent = erro || (ok ? 'Confira os dados no site do CFOAB. Campo vazio fica sem informação. A alteração fica registrada na auditoria.' : 'Informe o site completo, começando com https://.');
    msg.style.setProperty('--cor', erro || !ok ? '#a3232b' : '#4a6373');
    host.querySelector('[data-lim-ok]').disabled = !ok;
  }

  function secSalvarEdicao() {
    const host = document.getElementById('mp-modal'), uf = host.querySelector('[data-sede]').dataset.sede, v = sel => host.querySelector(sel).value;
    host.querySelector('[data-lim-ok]').disabled = true;
    secPostar(SEC.dados.urlSede.replace('/XX/', '/' + uf + '/'), { endereco: v('[data-sede-endereco]'), telefone: v('[data-sede-telefone]'), site: v('[data-sede-site]') }, () => {
      fecharModal(); window.__bjSeccionaisRecarregar();
    }, erro => { if (host.querySelector('[data-sede]')) secAtualizarEdicao(erro || 'Não foi possível salvar agora. Recarregue a página.'); });
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
    if (e.target.closest('[data-sec-editar]') && secDe(SEC.aberta)) return secAbrirEdicao(secDe(SEC.aberta));
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
  // Outra aba (ou o iframe) mudou o que fica visível: o bloco acompanha.
  window.addEventListener('storage', e => { if (e.key === SEC_CHAVE && SEC.dados && document.getElementById('mp-uf-tab')) renderMapa(); });

  function renderIdades() {
    const I = D.visao.idades, F = I.faixas, tot = F.reduce((a, x) => a + x.n, 0), mx = Math.max(1, ...F.map(x => x.n));
    document.getElementById('mp-idades').innerHTML = '<div class="mp-idades-topo"><b>Faixa etária dos usuários</b><span>idade média ' + (I.media === null ? 'sem informação' : I.media + ' anos') + '</span></div>'
      + '<div class="mp-idades-lista">' + F.map(x => '<div class="mp-idade"><span>' + esc(x.rot) + '</span><div class="mp-idade-trilho"><div style="--w:' + pct(x.n, mx) + '"></div></div><b>' + num(x.n) + '</b><span>' + pct(x.n, tot) + '</span></div>').join('') + '</div>'
      + '<span class="mp-idades-nota">Calculada pela data de nascimento do Meu Perfil. ' + num(I.semData) + ' usuário(s) sem data não entram na conta.'
      + (I.menores > 0 ? ' ' + num(I.menores) + ' com menos de 18 anos não entram nas faixas.' : '') + '</span>';
  }

  /* ================= CONTAS E ESCRITÓRIOS ================= */
  const acessoRelativo = iso => {
    if (!iso) return 'sem registro';
    const d = new Date(iso), h = new Date(); d.setHours(0, 0, 0, 0); h.setHours(0, 0, 0, 0);
    const dias = Math.round((h - d) / 864e5);
    return dias <= 0 ? 'hoje' : dias === 1 ? 'ontem' : 'há ' + dias + ' dias';
  };

  function montarContas() {
    // O bloco "Pedidos de exclusão de conta" (desenho 1.2, linha 148) vem antes da tabela. Escondido (hidden,
    // sem pedidos), ele não ocupa lugar nem abre o espaçamento da seção.
    elSecao.innerHTML = '<div id="mp-pedidos-exclusao" hidden></div><div class="mp-card mp-contas"><div class="mp-cab-cinza"><b>Contas e escritórios <span id="mp-contas-n"></span></b>'
      + '<a class="mp-btn-cinza" href="' + esc(URLS.convites) + '"><i class="bi bi-envelope-paper"></i>Convites</a>'
      + '<select class="mp-select mp-select-34" data-f-sit><option value="">Todas as situações</option><option value="Ativa"' + (S.fSit === 'Ativa' ? ' selected' : '') + '>Ativa</option><option value="Suspensa"' + (S.fSit === 'Suspensa' ? ' selected' : '') + '>Suspensa</option>'
      // C4b: as contas excluídas só aparecem nesta opção, com a data da purga.
      + '<option value="' + QUARENTENA + '"' + (S.fSit === QUARENTENA ? ' selected' : '') + '>Em quarentena</option></select>'
      + '<label class="mp-busca"><i class="bi bi-search"></i><input data-busca value="' + esc(S.busca) + '" placeholder="Buscar nº, conta ou primário" aria-label="Buscar nº, conta ou primário"></label></div>'
      + '<div id="mp-contas-aviso"></div><div class="mp-rolagem"><div class="mp-contas-grade"><div class="mp-contas-cab"><div>CONTA</div><div>PRIMÁRIO · PLANO</div><div>USUÁRIOS</div><div>PASTAS</div><div>DOCS</div><div>ARMAZENAMENTO</div><div>30 DIAS</div><div>PREVISÃO</div><div>SITUAÇÃO</div><div></div></div>'
      + '<div id="mp-contas-lista"></div></div></div></div>'
      // O menu da conta mora FORA do card e da rolagem (overflow), em posição fixa na janela: nunca é cortado.
      + '<div id="mp-menu-conta"></div>';
    renderPedidosDeExclusao();
    renderContasLista();
  }

  // Pedidos de exclusão de conta (desenho 1.2, linhas 148 e 679), de /master/dados/contas (`pedidosExclusao`).
  // Só aparece com pedido pendente. Reativar e Excluir abrem as MESMAS confirmações do menu da conta (regra do
  // dono: a exclusão é a quarentena, então o confirm/prompt do desenho, que diz que não dá para desfazer, não entra).
  // Os UseCases decidem o pedido junto; aqui a linha só sai da lista depois da resposta de sucesso.
  const pedidosDeExclusao = () => (D.contas && D.contas.pedidosExclusao) || [];
  const quemPediu = p => (p.pedidoPorNome
    ? p.pedidoPorNome + (p.pedidoPorEmail ? ' (' + p.pedidoPorEmail + ')' : '')
    : (p.pedidoPorEmail || 'autor não registrado'));
  function renderPedidosDeExclusao() {
    const host = document.getElementById('mp-pedidos-exclusao'); if (!host) return;
    const peds = pedidosDeExclusao();
    host.hidden = peds.length === 0;
    host.innerHTML = peds.length === 0 ? '' : '<div class="mp-pedidos" id="pedidos-exclusao" data-mp-pedidos>'
      + '<div class="mp-pedidos-cab"><i class="bi bi-trash3"></i><b>Pedidos de exclusão de conta · ' + num(peds.length) + '</b><span>contas suspensas aguardando sua decisão</span></div>'
      + peds.map(p => '<div class="mp-pedido" data-mp-pedido="' + p.contaId + '"><span class="mp-pedido-id"><b>' + esc(p.escritorio) + '</b>'
        + '<span>' + esc(quemPediu(p)) + ' · ' + esc(dataHora(p.criadoEm)) + ' · ' + esc(p.motivo) + '</span></span>'
        + '<button type="button" class="mp-pedido-reativar" data-pedido-op="reativar" data-conta-id="' + p.contaId + '">Reativar</button>'
        + '<button type="button" class="mp-pedido-excluir" data-pedido-op="excluir" data-conta-id="' + p.contaId + '">Excluir conta</button></div>').join('')
      + '</div>';
  }
  // Conta do pedido fora da lista (não deveria acontecer): monta o mínimo para a confirmação com o token do pedido.
  const contaDoPedido = id => {
    const p = pedidosDeExclusao().find(x => x.contaId === id);
    return p ? { id: p.contaId, nome: p.escritorio, token: p.token, situacao: 'Ativa', purgaEm: null } : null;
  };

  // Menu da conta (desenho 1.2, linha 735), na ordem: Editar limite de armazenamento, Definir limite de usuários,
  // Estatísticas, Histórico, Suspender OU Reativar (conforme a situação) e Excluir conta (oculto na conta do Master, C5).
  // Conta em quarentena (C4b): só Estatísticas, Histórico e Reativar conta (não há o que limitar até a purga).
  const opMenu = (op, classe, icone, rotulo, c) => '<button type="button" class="mp-menu-op' + classe + '" data-op="' + op + '" data-conta-id="' + c.id + '"><i class="bi ' + icone + '"></i>' + rotulo + '</button>';
  const menuDaConta = c => '<div class="mp-menu" data-parar><div class="mp-menu-cab">' + esc(c.nome) + '</div>'
    + (c.situacao !== QUARENTENA ? opMenu('limArm', '', 'bi-hdd', 'Editar limite de armazenamento', c) + opMenu('limUsu', '', 'bi-people', 'Definir limite de usuários', c) : '')
    + opMenu('estat', '', 'bi-bar-chart', 'Estatísticas de uso', c)
    + opMenu('hist', '', 'bi-clock-history', 'Histórico de alterações', c)
    + (c.situacao === 'Ativa'
      ? opMenu('suspender', ' mp-menu-sep mp-menu-suspender', 'bi-pause-circle', 'Suspender conta', c)
      : opMenu('reativar', ' mp-menu-sep mp-menu-reativar', 'bi-play-circle', 'Reativar conta', c))
    + (c.situacao !== QUARENTENA && !c.ehDoMaster ? opMenu('excluir', ' mp-menu-excluir', 'bi-trash3', 'Excluir conta', c) : '')
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
    suspender: c => ({ titulo: 'Suspender conta', icone: 'bi-pause-circle', cor: '#c08a12', botao: 'Suspender', texto: 'Os usuários de "' + c.nome + '" ficam sem acesso até a reativação. Nenhum dado é apagado.' }),
    reativar: c => ({ titulo: 'Reativar conta', icone: 'bi-play-circle', cor: '#1e7b45', botao: 'Reativar', texto: 'A conta "' + c.nome + '" volta a ter acesso normal.' }),
    // C4: texto e botão aprovados pelo dono, com a palavra EXCLUIR para liberar o botão. Os dias vêm do servidor
    // (`tenant_carencia_purga_dias`, no JSON de /master/dados/contas): com 365, o texto é o aprovado, letra a letra.
    excluir: c => ({ titulo: 'Excluir conta', icone: 'bi-exclamation-octagon', cor: '#a3232b', botao: 'Excluir conta', palavra: 'EXCLUIR', texto: 'A conta "' + c.nome + '" sai do ar agora e é apagada de vez em ' + D.contas.carenciaPurgaDias + ' dias. Até lá, pode ser recuperada pelo Master.' })
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
    S.aviso = { ok, msg: msg || (ok ? 'Situação da conta atualizada.' : 'Não foi possível concluir a ação. Recarregue a página.') };
    renderSecs();
    if (S.sec === 'contas') { renderPedidosDeExclusao(); renderContasLista(); }
    if (S.sec === 'arm') renderRanking();
  }

  // Armazenamento da linha (desenho 1.2, linhas 166 e 733): "<uso> de <limite>", %, barra e "<faixa> · livre <livre>".
  // Em quarentena o uso não é medido: só o plano e os limites fazem sentido. Sem limite: o uso e "sem limite".
  const livreDe = c => Math.max(0, c.espaco.limiteGb * GB - c.bytes);
  function celulaArm(c) {
    if (c.purgaEm) return '<div class="mp-conta-arm mp-hv" style="--cor:#5b7383"><span><span>uso não medido</span><b></b></span><span>conta em quarentena</span></div>';
    if (c.espaco.semLimite) return '<div class="mp-conta-arm mp-hv" style="--cor:#4a6373"><span><span>' + esc(gb(c.bytes)) + ' em uso</span><b></b></span><span>sem limite</span></div>';
    const [rotulo, cor] = faixaDe(c), p = c.espaco.percentual;
    return '<div class="mp-conta-arm mp-hv" style="--cor:' + cor + '"><span><span>' + esc(gb(c.bytes)) + ' de ' + num(c.espaco.limiteGb) + ' GB</span><b>' + p + '%</b></span>'
      + '<div class="mp-barra-6"><div style="--w:' + Math.min(100, p) + '%"></div></div><span>' + esc(rotulo) + ' · livre ' + esc(gb(livreDe(c))) + '</span></div>';
  }
  // Previsão (desenho, linha 733): meses até o limite no ritmo dos últimos 30 dias. Plural: convenção ("≈ 1 mês").
  function previsao(c) {
    if (c.espaco.semLimite || !(c.bytesRecentes > 0)) return '<div class="mp-conta-prev mp-hv">sem previsão</div>';
    const mes = (c.espaco.limiteGb * GB - c.bytes) / c.bytesRecentes, n = Math.round(mes);
    return '<div class="mp-conta-prev mp-hv" style="--cor:' + (mes < 3 ? '#a3232b' : '#4a6373') + '">' + (mes < 1 ? 'menos de 1 mês' : '≈ ' + n + (n === 1 ? ' mês' : ' meses')) + '</div>';
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
    document.getElementById('mp-contas-n').textContent = num(contas.length) + ' · botão direito na linha para ações';
    // Resultado de suspender/reativar: uma linha acima da tabela, até trocar de seção.
    document.getElementById('mp-contas-aviso').innerHTML = avisoHtml();
    document.getElementById('mp-contas-lista').innerHTML = contas.length ? contas.map(c => {
      const sit = situacaoVisivel(c), cor = ST[sit] || '#4a6373';
      return '<div class="mp-conta mp-lin" data-conta="' + c.id + '">'
        + '<div class="mp-conta-nome mp-hv"><span class="mp-conta-titulo"><span class="mp-conta-nro" title="Número da conta (ordem de abertura)">Nº ' + esc(c.numero) + '</span><b title="' + esc(c.nome) + '">' + esc(c.nome) + '</b></span><span>desde ' + esc(c.desde) + ' · último acesso ' + esc(acessoRelativo(c.ultimoAcesso)) + '</span></div>'
        + '<div class="mp-conta-prim mp-hv"><span>' + esc(c.titular || 'titular não registrado') + '</span><span>' + esc(c.espaco.planoNome) + '</span></div>'
        + '<div class="mp-conta-usu mp-hv"><b>' + num(c.usuarios) + '</b><span> / ' + (c.espaco.limiteUsuarios === null ? 'sem limite' : num(c.espaco.limiteUsuarios)) + '</span></div>'
        + '<div class="mp-conta-num mp-hv">' + num(c.pastas) + '</div><div class="mp-conta-num mp-hv">' + num(c.documentos) + '</div>'
        + celulaArm(c)
        + '<div class="mp-conta-cresc mp-hv">' + (c.bytesRecentes > 0 ? '+' + esc(gb(c.bytesRecentes)) : 'estável') + '</div>'
        + (c.purgaEm ? '<div class="mp-conta-prev mp-hv">sem previsão</div>' : previsao(c))
        + '<div class="mp-conta-st mp-hv">' + (c.purgaEm
          // Quarentena: a situação e, abaixo, a data da purga em duas linhas curtas que não quebram (coluna de 100px).
          ? '<div class="mp-conta-st-bloco"><span class="mp-conta-sit" style="--cor:' + cor + '"><i></i>' + esc(c.situacao) + '</span>'
            + '<span class="mp-conta-purga" title="A purga automática roda às 03h, a partir desta data">apagada a partir de<br>' + esc(dataBr(c.purgaEm)) + '</span></div>'
          : '<span style="--cor:' + cor + '"><i></i>' + esc(sit) + '</span>') + '</div>'
        + '<div class="mp-conta-acoes mp-hv"><button type="button" class="mp-btn-icone" data-menu="' + c.id + '" title="Ações da conta" aria-label="Ações da conta"><i class="bi bi-three-dots-vertical"></i></button></div>'
        + '</div>';
    }).join('') : '<div class="mp-vazio-linha">Nenhuma conta encontrada.</div>';
    renderMenuDaConta();
  }

  /* ================= USUÁRIOS E OABs ================= */
  function montarOab() {
    const O = D.oab, ufs = Object.entries(O.ufs).sort((a, b) => b[1] - a[1]), maxUf = Math.max(1, ...ufs.map(u => u[1]));
    const kpis = [
      { rot: 'COM OAB INFORMADA', n: O.registros, desc: 'registros nos cadastros', cor: '#0a6fa0' },
      { rot: 'INSCRIÇÕES ÚNICAS', n: O.unicas, desc: 'após normalizar número e UF', cor: '#1e7b45' },
      { rot: 'DUPLICADAS', n: O.duplicadas, desc: 'mesma inscrição repetida', cor: '#c08a12' },
      { rot: 'NÃO VALIDADAS', n: O.naoValidadas, desc: 'não contam como advogado confirmado', cor: '#a3232b' },
      { rot: 'UFs', n: ufs.length, desc: 'estados com inscrição', cor: '#7a3e8f' }
    ];
    const P = {
      incompleto: p => ['bi-exclamation-circle', '#a3232b', p.conta + ': ' + (p.inscricao ? p.inscricao + ' sem UF da OAB' : 'usuário sem número de OAB'), 'INCOMPLETO'],
      nao_validada: p => ['bi-hourglass', '#c08a12', p.conta + ': ' + p.normalizada + ' aguardando validação', 'NÃO VALIDADA'],
      divergente: p => ['bi-hourglass', '#c08a12', p.conta + ': ' + p.normalizada + ' divergente do cadastro da OAB', 'NÃO VALIDADA'],
      formatacao: p => ['bi-arrow-repeat', '#0a6fa0', p.conta + ': "' + p.inscricao + '" normalizado para ' + p.normalizada, 'FORMATAÇÃO']
    };
    elSecao.innerHTML = '<div class="mp-oab-kpis">' + kpis.map(k => '<div class="mp-card mp-oab-kpi" style="--cor:' + k.cor + '"><span>' + k.rot + '</span><span>' + num(k.n) + '</span><span>' + k.desc + '</span></div>').join('') + '</div>'
      + '<div class="mp-oab-duas"><div class="mp-card mp-oab-dist"><b>Distribuição por UF</b><div class="mp-oab-ufs">'
      + (ufs.length ? ufs.map(([uf, n]) => '<div class="mp-oab-uf"><b>' + esc(uf) + '</b><div class="mp-barra-8"><div style="--w:' + pct(n, maxUf) + ';--cor:#0a6fa0"></div></div><span>' + num(n) + '</span></div>').join('') : '<span class="mp-nota">Nenhuma inscrição informada.</span>')
      + '</div></div><div class="mp-card mp-oab-pend"><div class="mp-cab-simples"><b>Inscrições com pendência</b></div>'
      + (O.pendencias.length ? O.pendencias.map(p => { const [ic, cor, txt, tag] = (P[p.tipo] || P.nao_validada)(p); return '<div class="mp-pend" style="--cor:' + cor + '"><i class="bi ' + ic + '"></i><span>' + esc(txt) + '</span><span>' + tag + '</span></div>'; }).join('') : '<div class="mp-vazio-linha">Nenhuma pendência.</div>')
      + '</div></div>'
      + '<span class="mp-nota">Contagem normalizada: número sem pontos e zeros à esquerda + UF em maiúsculas. Inscrição só conta como confirmada após validação. <a href="' + esc(URLS.revisaoOab) + '">Abrir a Revisão de OAB</a></span>';
  }

  /* ================= ARMAZENAMENTO ================= */
  // Faixas de alerta (desenho 1.2, linhas 195-198 e 695): chips com a cor do desenho. A chave do bloqueio, o aviso ao
  // lado dela e o botão de salvar são convenção (spec P6). Ordem crescente, de 1 a 100: a Contribua revalida.
  const CHIPS = [['atencao', 'Atenção', '#8a5a12', '#fbf1dc'], ['alerta', 'Alerta', '#a3232b', '#fbe7e7'], ['critico', 'Crítico', '#7a1b22', '#f6dcdc'], ['bloqueio', 'Bloqueio', '#fff', '#7a1b22']];
  const AVISO_BLOQUEIO = 'Antes de ligar, confira os escritórios acima do plano e defina sem limite ou uma cota própria.';
  const ERRO_FAIXAS = 'As faixas precisam crescer em ordem, de 1% a 100%.';
  const fxDoServidor = () => Object.assign({}, D.planos.faixas, { bloqueioAtivo: D.planos.bloqueioAtivo });
  const fxValidas = f => { const v = CHIPS.map(([k]) => f[k]); return v.every(Number.isInteger) && v[0] > 0 && v[0] < v[1] && v[1] < v[2] && v[2] < v[3] && v[3] <= 100; };
  const fxMudou = () => { const o = fxDoServidor(); return CHIPS.some(([k]) => S.fx[k] !== o[k]) || S.fx.bloqueioAtivo !== o.bloqueioAtivo; };

  function montarArm() {
    if (!S.fx) S.fx = fxDoServidor();
    elSecao.innerHTML = '<div class="mp-card mp-faixas"><b class="mp-faixas-tit">Faixas de alerta de consumo <span>(configuráveis)</span></b>'
      + '<div class="mp-faixas-chips">' + CHIPS.map(([k, rot, cor, bg]) => '<label class="mp-faixa-chip" style="--cor:' + cor + ';--bg:' + bg + '"><span>' + rot + ' a partir de</span>'
        + '<input type="number" min="1" max="100" step="1" data-faixa="' + k + '" value="' + esc(S.fx[k]) + '" aria-label="' + rot + ' a partir de">%</label>').join('') + '</div>'
      + '<span class="mp-faixas-nota">No limite (100%), novos uploads acima da capacidade ficam bloqueados. Consulta, download e exportação continuam liberados. Nenhum documento é apagado automaticamente.</span>'
      + '<div class="mp-faixas-bloqueio"><label class="mp-chave"><input type="checkbox" data-faixa-bloqueio' + (S.fx.bloqueioAtivo ? ' checked' : '') + '><span>Bloquear novos envios na faixa Bloqueio</span></label>'
      + '<span class="mp-faixas-aviso"><i class="bi bi-exclamation-triangle"></i>' + esc(AVISO_BLOQUEIO) + '</span></div>'
      + '<div class="mp-faixas-rodape"><span class="mp-faixas-msg" data-faixas-msg></span><button type="button" class="mp-btn-azul" data-faixas-salvar>Salvar faixas</button></div></div>'
      + '<div class="mp-card mp-ranking"><div class="mp-cab-simples"><b>Contas por consumo</b></div><div id="mp-arm-aviso"></div><div id="mp-ranking-lista"></div></div>'
      + '<span class="mp-nota">Soma dos arquivos com tamanho gravado (pastas fora da lixeira, clientes, processos, cobrança, chamados e kanban). Justificativas de ponto e anexos de tarefa não têm tamanho no banco: não medidos.</span>';
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
      const sem = c.espaco.percentual === null, [rotulo, cor] = sem ? ['sem limite', '#4a6373'] : faixaDe(c), p = sem ? 0 : c.bytes / (c.espaco.limiteGb * GB) * 100;
      return '<div class="mp-rank" style="--cor:' + cor + '"><span title="' + esc(c.nome) + '">' + esc(c.nome) + '</span><div class="mp-barra-8"><div style="--w:' + Math.min(100, p) + '%"></div></div>'
        + '<b>' + (sem ? '' : c.espaco.percentual + '%') + '</b><span>' + esc(rotulo) + '</span><button type="button" class="mp-rank-btn" data-lim-arm="' + c.id + '">Editar limite</button></div>';
    }).join('') : '<div class="mp-vazio-linha">Nenhuma conta.</div>';
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
  const COR_PLANO = { free: '#c08a12', essencial: '#0a6fa0', profissional: '#1e7b45', avancado: '#7a3e8f' };
  const reais = cent => 'R$ ' + (cent % 100 === 0 ? num(cent / 100) : (cent / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  const nomeDoPlano = p => (p.codigo === 'free' ? 'Plano ' + p.nome : p.nome);
  // "usuários sem limite", e não "sem limite usuários" (o desenho sempre acrescenta " usuários"): convenção.
  const usuariosDoPlano = p => (p.limiteUsuarios === null ? 'usuários sem limite' : num(p.limiteUsuarios) + ' usuários');

  function montarPlanos() {
    const ativas = D.contas.contas.filter(c => c.situacao !== QUARENTENA);
    elSecao.innerHTML = '<div id="mp-planos-aviso"></div><div class="mp-planos">' + D.planos.planos.map(p => {
      const acima = ativas.filter(c => c.espaco.planoCodigo === p.codigo && pertoDaFranquia(c)).length;
      return '<div class="mp-card mp-plano" style="--cor:' + (COR_PLANO[p.codigo] || '#4a6373') + '"><div class="mp-plano-topo"><b>' + esc(nomeDoPlano(p)) + '</b>'
        + (p.codigo === 'free' ? '<span class="mp-plano-tag">Padrão: toda conta nova começa aqui</span>' : '')
        + (p.ativo ? '' : '<span class="mp-plano-inativo">inativo</span>') + '</div>'
        + '<span class="mp-plano-preco">' + esc(reais(p.precoCentavos)) + '<span> / mês</span></span>'
        + '<span class="mp-plano-cap">' + num(p.cotaGb) + ' GB · ' + esc(usuariosDoPlano(p)) + '</span>'
        + '<span class="mp-plano-contas">' + num(p.escritorios) + ' conta(s) · ' + num(acima) + ' perto ou acima da franquia</span>'
        + '<button type="button" class="mp-rank-btn mp-plano-editar" data-plano-editar="' + esc(p.codigo) + '"><i class="bi bi-pencil"></i>Editar plano</button></div>';
    }).join('') + '</div>'
      + '<span class="mp-nota">Toda conta nova entra no Plano Free (10 GB). A partir de 90% de uso o usuário primário recebe aviso no sistema e por e-mail recomendando ampliar o espaço; em 100% novos envios ficam bloqueados, mas consultar e baixar arquivos continua liberado. Mudanças de plano com impacto comercial exigem confirmação e ficam registradas na auditoria.</span>';
    document.getElementById('mp-planos-aviso').innerHTML = avisoHtml();
  }

  /* ---------- modal (limite e plano), no host #mp-modal do template ---------- */
  const elModal = document.getElementById('mp-modal');
  let modal = null;
  const fecharModal = () => { modal = null; if (elModal) elModal.innerHTML = ''; };
  const caixa = (icone, titulo, corpo, botao) => '<div class="mp-lim-fundo" data-lim-fundo><div class="mp-lim" role="dialog" aria-modal="true" aria-labelledby="mpLimTitulo">'
    + '<div class="mp-lim-cab"><i class="bi ' + icone + '"></i><span id="mpLimTitulo">' + esc(titulo) + '</span></div><div class="mp-lim-corpo">' + corpo + '</div>'
    + '<div class="mp-lim-rodape"><button type="button" class="mp-lim-cancelar" data-lim-cancelar>Cancelar</button><button type="button" class="mp-lim-ok" data-lim-ok disabled>' + esc(botao) + '</button></div></div></div>';
  const campo = (rotulo, attrs) => '<label class="mp-lim-campo"><span>' + rotulo + '</span><input ' + attrs + '></label>';
  const quadros = itens => '<div class="mp-lim-info">' + itens.map(([rot, val]) => '<div><div>' + rot + '</div><div>' + esc(val) + '</div></div>').join('') + '</div>';

  // Modal de limite (desenho 1.2, linhas 397-410 e 726-732): o campo vem com o limite atual e a justificativa vazia.
  // "Sem limite" (spec P4, contrato semLimite) só no armazenamento: libera o espaço e os usuários da conta.
  function abrirLimite(tipo, id) {
    const c = D.contas && D.contas.contas.find(x => x.id === id); if (!c || !elModal) return;
    const arm = tipo === 'arm', atual = arm ? c.espaco.limiteGb : c.espaco.limiteUsuarios;
    modal = { tipo: 'lim', lim: tipo, c, atual, erro: null, enviando: false };
    const info = arm
      ? [['EM USO', gb(c.bytes)], ['LIMITE ATUAL', atual === null ? 'sem limite' : num(atual) + ' GB'], ['LIVRE', atual === null ? 'sem limite' : gb(livreDe(c))]]
      : [['USUÁRIOS', num(c.usuarios)], ['LIMITE ATUAL', atual === null ? 'sem limite' : num(atual)], ['VAGAS', atual === null ? 'sem limite' : num(Math.max(0, atual - c.usuarios))]];
    elModal.innerHTML = caixa(arm ? 'bi-hdd' : 'bi-people', arm ? 'Editar limite de armazenamento' : 'Definir limite de usuários',
      '<b class="mp-lim-conta">' + esc(c.nome) + '</b>' + quadros(info)
      + campo(arm ? 'NOVO LIMITE (GB)' : 'NOVO LIMITE DE USUÁRIOS', 'type="number" min="1" step="1" data-lim-novo value="' + (atual === null ? '' : atual) + '"')
      + (arm ? '<label class="mp-lim-sem"><input type="checkbox" data-lim-sem' + (c.espaco.semLimite ? ' checked' : '') + '>Sem limite (espaço e usuários)</label>' : '')
      + '<span class="mp-lim-msg" data-lim-msg></span>'
      + (!arm && c.espaco.semLimite ? '<span class="mp-lim-nota">Esta conta está sem limite: o limite de usuários só passa a valer quando ela voltar a ter limite de espaço.</span>' : '')
      + campo('JUSTIFICATIVA', 'type="text" maxlength="500" data-lim-just placeholder="Obrigatória, fica na auditoria"'), 'Confirmar alteração');
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
    const unidade = arm ? ' GB' : ' usuário(s)', fim = '. A alteração fica registrada na auditoria.';
    let msg;
    if (sem) msg = 'Sem limite: a conta deixa de ter limite de espaço e de usuários' + fim;
    else if (abaixo) msg = arm ? 'O novo limite é menor que o volume em uso (' + gb(m.c.bytes) + '). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.' : 'Menor que os usuários já cadastrados.';
    else if (!inteiro) msg = 'Informe um número inteiro.';
    else if (m.atual === null) msg = 'Passa de sem limite para ' + novo + (arm ? ' GB' : ' usuários') + fim;
    else { const d = novo - m.atual; msg = 'Diferença: ' + (d >= 0 ? '+' : '') + d + unidade + fim; }
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
    modal.enviando = false; modal.erro = erro || 'Não foi possível concluir a ação. Recarregue a página.';
    if (modal.tipo === 'lim') atualizarLimite(); else atualizarPlano();
  }

  // Editar plano (spec P2; o desenho não tem este modal: convenção, no mesmo molde do modal de limite).
  // O código não muda. Free: continua grátis e ativo (a Contribua recusa com a mensagem dela).
  function abrirPlano(codigo) {
    const p = D.planos && D.planos.planos.find(x => x.codigo === codigo); if (!p || !elModal) return;
    modal = { tipo: 'plano', p, erro: null, enviando: false };
    const preco = (p.precoCentavos / 100).toLocaleString('pt-BR', { minimumFractionDigits: p.precoCentavos % 100 === 0 ? 0 : 2, maximumFractionDigits: 2, useGrouping: false });
    elModal.innerHTML = caixa('bi-sliders', 'Editar plano', '<b class="mp-lim-conta">' + esc(nomeDoPlano(p)) + '</b>'
      + campo('NOME', 'type="text" maxlength="60" data-plano-nome value="' + esc(p.nome) + '"')
      + '<div class="mp-lim-duas">' + campo('ESPAÇO (GB)', 'type="number" min="1" step="1" data-plano-cota value="' + p.cotaGb + '"')
      + campo('PREÇO (R$)', 'type="text" inputmode="decimal" data-plano-preco value="' + esc(preco) + '"') + '</div>'
      + campo('LIMITE DE USUÁRIOS', 'type="number" min="1" step="1" data-plano-usuarios placeholder="vazio para sem limite" value="' + (p.limiteUsuarios === null ? '' : p.limiteUsuarios) + '"')
      + campo('DESCRIÇÃO', 'type="text" maxlength="120" data-plano-descricao value="' + esc(p.descricao) + '"')
      + '<label class="mp-lim-sem"><input type="checkbox" data-plano-ativo' + (p.ativo ? ' checked' : '') + '>Plano ativo</label>'
      + '<span class="mp-lim-msg" data-lim-msg></span>', 'Salvar plano');
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
    const texto = modal.erro || (e.ok ? 'O código do plano (' + modal.p.codigo + ') não muda. A alteração fica registrada na auditoria.' : 'Preencha o nome, o espaço em GB e o preço em reais (por exemplo 19,90). Usuários: vazio para sem limite.');
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
  const AI = a => (/Limite/.test(a) ? ['bi-hdd', '#0a6fa0'] : /reativada|reativado|Exclusão de conta cancelada/i.test(a) ? ['bi-play-circle', '#1e7b45'] : /suspensa|excluída|excluído|removido|retirada|desativado/i.test(a) ? ['bi-slash-circle', '#a3232b'] : /Master|primário|Papel no sistema/i.test(a) ? ['bi-stars', '#7a3e8f'] : /Alerta/.test(a) ? ['bi-exclamation-triangle', '#c08a12'] : /^Plano|^Faixas de alerta/.test(a) ? ['bi-sliders', '#0a6fa0'] : ['bi-journal-check', '#4a6373']);
  const audFiltrada = () => D.audit.registros.filter(r => (S.audConta === null || r.contaId === S.audConta) && (!S.audTipo || r.acao === S.audTipo));

  function montarAudit() {
    const A = D.audit, audF = audFiltrada(), tipos = [...new Set(A.registros.map(r => r.acao))].sort((a, b) => a.localeCompare(b));
    const resumo = [['Limites', 'bi-hdd', '#0a6fa0', '#e5f1f7', r => /Limite/.test(r.acao)], ['Contas', 'bi-buildings', '#8a5a12', '#fbf1dc', r => /Conta/.test(r.acao)], ['Masters e primários', 'bi-stars', '#7a3e8f', '#f3e9f7', r => /Master|primário/i.test(r.acao)], ['Alertas', 'bi-exclamation-triangle', '#a3232b', '#fbe7e7', r => /Alerta/.test(r.acao)]];
    elSecao.innerHTML = '<div class="mp-card mp-audit"><div class="mp-audit-cab"><b>Trilha de auditoria <span>' + num(audF.length) + ' registro(s) · últimos ' + A.meses + ' meses' + (A.truncado ? ' · mostrando os ' + num(A.limite) + ' mais recentes' : '') + '</span></b>'
      + '<select class="mp-select" data-aud-tipo><option value="">Todas as ações</option>' + tipos.map(t => '<option value="' + esc(t) + '"' + (S.audTipo === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('') + '</select>'
      + '<button type="button" class="mp-btn-cinza" data-pdf><i class="bi bi-file-earmark-pdf"></i>Gerar PDF</button>'
      + (S.audConta !== null ? '<button type="button" class="mp-btn-link" data-aud-todas>Mostrar todas as contas</button>' : '') + '</div>'
      + '<div class="mp-audit-resumo">' + resumo.map(([rot, ic, cor, bg, f]) => '<span style="--cor:' + cor + ';--bg:' + bg + '"><i class="bi ' + ic + '"></i>' + rot + ' · ' + audF.filter(f).length + '</span>').join('') + '</div>'
      + '<div class="mp-audit-grade mp-audit-titulos"><span>DATA E HORA</span><span>RESPONSÁVEL</span><span>AÇÃO E CONTA</span><span>DETALHE</span></div>'
      + audF.map(r => { const [ic, cor] = AI(r.acao); return '<div class="mp-audit-grade mp-audit-lin mp-lin"><span class="mp-hv">' + esc(dataHora(r.quando)) + '</span><span class="mp-hv">' + esc(r.quem) + '</span>'
        + '<span class="mp-hv"><span style="--cor:' + cor + '"><i class="bi ' + ic + '"></i><b>' + esc(r.acao) + '</b></span><br><span>' + esc(r.conta) + '</span></span><span class="mp-hv">' + esc(r.detalhe) + '</span></div>'; }).join('')
      + (audF.length ? '' : '<div class="mp-vazio-linha">Nenhum registro ainda. Alterações de limite, suspensões e exclusões aparecem aqui. Entradas no sistema (login) não são auditadas.</div>')
      + '</div>';
  }

  function gerarPdf() {
    const audF = audFiltrada(), contaNome = S.audConta !== null ? ((D.contas && D.contas.contas.find(c => c.id === S.audConta)) || {}).nome || (audF[0] || {}).conta || '' : '';
    const linhas = audF.map(r => '<tr><td>' + esc(dataHora(r.quando)) + '</td><td>' + esc(r.quem) + '</td><td><b>' + esc(r.acao) + '</b><br><span>' + esc(r.conta) + '</span></td><td>' + esc(r.detalhe) + '</td></tr>').join('');
    const w = window.open('', '_blank'); if (!w) return;
    w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Auditoria BlueJus</title><style>body{font-family:Arial,sans-serif;color:#12242f;margin:0}header{background:#0a6fa0;color:#fff;padding:18px 28px;display:flex;align-items:center;gap:14px}header img{height:30px}h1{font-size:20px;margin:0}main{padding:22px 28px}p{color:#4a6373;font-size:12px;margin:0 0 14px}table{width:100%;border-collapse:collapse;font-size:12px}th{text-align:left;background:#f5f8fa;border-bottom:2px solid #d6e0e7;padding:8px;font-size:10.5px;letter-spacing:1px}td{border-bottom:1px solid #e6edf2;padding:8px;vertical-align:top}td span{color:#5b7383}footer{margin-top:18px;font-size:10.5px;color:#7d909d}@page{size:A4;margin:12mm}@media print{header{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body>'
      + '<header><img src="' + esc(new URL(URLS.logo, location.href).href) + '" alt="BlueJus"><h1>Trilha de auditoria do sistema</h1></header><main><p>Gerado em ' + esc(agora()) + ' por ' + esc(QUEM) + ' (Master) · ' + audF.length + ' registro(s)'
      + (S.audTipo ? ' · filtro: ' + esc(S.audTipo) : '') + (contaNome ? ' · conta: ' + esc(contaNome) : '') + '</p><table><thead><tr><th>DATA E HORA</th><th>RESPONSÁVEL</th><th>AÇÃO E CONTA</th><th>DETALHE</th></tr></thead><tbody>' + linhas
      + '</tbody></table><footer>BlueJus · documento gerado pelo painel do Master. Contém apenas metadados administrativos.</footer></main></body></html>');
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
    const usu = t.closest('[data-mapa-usu]'); if (usu) { S.mapaUsu = usu.dataset.mapaUsu; return renderMapa(); }
    const modo = t.closest('[data-mapa-modo]'); if (modo) { S.mapaModo = modo.dataset.mapaModo; return renderMapa(); }
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
