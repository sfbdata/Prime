/* BlueJus · Painel do Master (desenho docs/design/perfis-1.0/05 - Perfis 1.0.dc.html, aba "Painel do sistema").
   Abas abertas pelo hash como no desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html (linha 481):
   #aba=perm abre Permissões e #sec=contas abre a seção (é para onde voltam promover e remover Master).
   O bloco "Masters do sistema" da aba Permissões é do public/js/master/masters.js.
   Lógica portada do componente do desenho (kpis, gráfico com curva monotônica, mapa, faltam, auditoria, PDF).
   Regra do dono: nada inventado. Dado que não existe aparece como "sem informação" ou no estado vazio do desenho.
   Os dados vêm de /master/dados/* (só leitura, ROLE_SUPER_ADMIN no servidor). */
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
    faltamOn: false, busca: '', fSit: '', menu: null, audTipo: '', audConta: null, aviso: null };
  const D = { visao: null, contas: null, oab: null, audit: null };
  const ERRO = {};

  const SECS = [['visao', 'Visão geral', 'bi-speedometer2'], ['contas', 'Contas e escritórios', 'bi-buildings'], ['oab', 'Usuários e OABs', 'bi-person-vcard'],
    ['arm', 'Armazenamento', 'bi-hdd-stack'], ['planos', 'Planos e limites', 'bi-sliders'], ['custos', 'Custos e infraestrutura', 'bi-cash-coin'],
    ['desemp', 'Desempenho', 'bi-activity'], ['audit', 'Segurança e auditoria', 'bi-journal-check'], ['config', 'Configurações globais', 'bi-gear']];
  const VAZIAS = {
    custos: ['bi-cash-coin', 'Custos e infraestrutura', 'Mostra quanto custa manter o BlueJus e o impacto de cada conta, separando custos medidos, importados de fornecedores e estimados. Sem integração financeira, os valores podem ser lançados manualmente, com origem identificada.', ['Armazenamento', 'Banco de dados', 'Hospedagem', 'Tráfego', 'Backups', 'APIs de IA', 'Notificações', 'Receita recorrente', 'Custo por conta', 'Custo por GB', 'Margem por plano', 'Projeção 3, 6 e 12 meses']],
    desemp: ['bi-activity', 'Desempenho e disponibilidade', 'Saúde técnica da plataforma, com histórico filtrável por gravidade, período e categoria. Métricas não coletadas aparecem como indisponíveis, nunca estimadas.', ['Disponibilidade', 'Tempo de resposta', 'Erros', 'Falhas de upload', 'Falhas de login', 'Último backup', 'Teste de restauração', 'CPU e memória', 'Integrações', 'Incidentes']],
    config: ['bi-gear', 'Configurações globais', 'Parâmetros que valem para todas as contas. Cada alteração exige confirmação e fica na auditoria.', ['Faixas de alerta padrão', 'Período de teste', 'Política de excedente', 'Exceções temporárias', 'Autenticação reforçada', 'Retenção de auditoria']],
    // Planos (decisão D1 do dono): não há plano no banco, então a seção usa o estado vazio em vez dos cartões de exemplo.
    planos: ['bi-sliders', 'Planos e limites', 'Planos, franquias de armazenamento e limites de usuários por conta ainda não existem no sistema. Quando forem cadastrados, cada plano aparece aqui com as contas que o usam.', ['Planos', 'Preço mensal', 'Franquia de armazenamento', 'Limite de usuários', 'Contas por plano', 'Contas perto da franquia']]
  };
  const NM = { RO: 'Rondônia', AC: 'Acre', AM: 'Amazonas', RR: 'Roraima', PA: 'Pará', AP: 'Amapá', TO: 'Tocantins', MA: 'Maranhão', PI: 'Piauí', CE: 'Ceará', RN: 'Rio Grande do Norte', PB: 'Paraíba', PE: 'Pernambuco', AL: 'Alagoas', SE: 'Sergipe', BA: 'Bahia', MG: 'Minas Gerais', ES: 'Espírito Santo', RJ: 'Rio de Janeiro', SP: 'São Paulo', PR: 'Paraná', SC: 'Santa Catarina', RS: 'Rio Grande do Sul', MS: 'Mato Grosso do Sul', MT: 'Mato Grosso', GO: 'Goiás', DF: 'Distrito Federal' };
  const ST = { Ativa: '#1e7b45', Suspensa: '#a3232b' };

  /* ---------- carga ---------- */
  const carregar = (chave, url) => {
    if (D[chave] || ERRO[chave] === 'carregando') return;
    ERRO[chave] = 'carregando';
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(j => { D[chave] = j; ERRO[chave] = null; renderSecs(); if (precisa[S.sec] === chave) renderSecao(); })
      .catch(() => { ERRO[chave] = 'falhou'; if (precisa[S.sec] === chave) renderSecao(); });
  };
  const precisa = { visao: 'visao', contas: 'contas', arm: 'contas', oab: 'oab', audit: 'audit' };
  const urlDe = { visao: URLS.visao, contas: URLS.contas, oab: URLS.oab, audit: URLS.auditoria };
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
    const nAud = D.audit ? D.audit.registros.length : 0;
    elSecs.innerHTML = SECS.map(([k, rot, icone]) => '<button type="button" class="mp-sec' + (S.sec === k ? ' on' : '') + '" data-sec="' + k + '"><i class="bi ' + icone + '"></i>' + esc(rot)
      + (k === 'audit' && nAud > 0 ? '<span class="mp-sec-n">' + num(nAud) + '</span>' : '') + '</button>').join('');
  }
  elSecs.addEventListener('click', e => { const b = e.target.closest('[data-sec]'); if (b) irPara(b.dataset.sec); });
  function irPara(sec) { S.sec = sec; S.menu = null; S.aviso = null; renderSecs(); renderSecao(); }

  function renderSecao() {
    const sec = S.sec, dep = precisa[sec];
    if (dep && !D[dep]) { elSecao.innerHTML = aguardando(dep); carregar(dep, urlDe[dep]); return; }
    if (VAZIAS[sec]) { elSecao.innerHTML = vazia(VAZIAS[sec]); return; }
    if (sec === 'visao') return montarVisao();
    if (sec === 'contas') return montarContas();
    if (sec === 'oab') return montarOab();
    if (sec === 'arm') return montarArm();
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
      { rot: 'ARMAZENAMENTO', n: gb(k.bytes), desc: 'contratado: sem informação · ponto e tarefas não medidos', icone: 'bi-hdd-stack', cor: '#c08a12', bg: '#fbf1dc', ir: 'arm' },
      { rot: 'RECEITA MENSAL', n: 'sem informação', texto: true, desc: 'sem integração financeira ainda', icone: 'bi-cash-coin', cor: '#7d909d', bg: '#eef2f5', ir: 'custos' }
    ];
    document.getElementById('mp-kpis').innerHTML = kpis.map(x => '<button type="button" class="mp-kpi" data-ir="' + x.ir + '" style="--cor:' + x.cor + ';--bg:' + x.bg + '">'
      + '<span class="mp-kpi-ico"><i class="bi ' + x.icone + '"></i></span><span class="mp-kpi-rot">' + esc(x.rot) + '</span>'
      + '<span class="mp-kpi-n' + (x.texto ? ' mp-kpi-n-texto' : '') + '">' + esc(x.n) + '</span><span class="mp-kpi-desc">' + esc(x.desc) + '</span>'
      + '<span class="mp-kpi-base"></span><span class="mp-kpi-brilho"></span></button>').join('');
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
        const tit = S.mapaQuem === 'esc' ? 'Tendência medida só para pessoas' : '+' + t.ag + ' nos últimos 30 dias · +' + t.an + ' nos 30 anteriores · só entradas: saídas ainda não são medidas';
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
    elSecao.innerHTML = '<div class="mp-card mp-contas"><div class="mp-cab-cinza"><b>Contas e escritórios <span id="mp-contas-n"></span></b>'
      + '<a class="mp-btn-cinza" href="' + esc(URLS.convites) + '"><i class="bi bi-envelope-paper"></i>Convites</a>'
      + '<select class="mp-select mp-select-34" data-f-sit><option value="">Todas as situações</option><option value="Ativa"' + (S.fSit === 'Ativa' ? ' selected' : '') + '>Ativa</option><option value="Suspensa"' + (S.fSit === 'Suspensa' ? ' selected' : '') + '>Suspensa</option></select>'
      + '<label class="mp-busca"><i class="bi bi-search"></i><input data-busca value="' + esc(S.busca) + '" placeholder="Buscar conta ou primário" aria-label="Buscar conta ou primário"></label></div>'
      + '<div id="mp-contas-aviso"></div><div class="mp-rolagem"><div class="mp-contas-grade"><div class="mp-contas-cab"><div>CONTA</div><div>PRIMÁRIO · PLANO</div><div>USUÁRIOS</div><div>PASTAS</div><div>DOCS</div><div>ARMAZENAMENTO</div><div>30 DIAS</div><div>PREVISÃO</div><div>SITUAÇÃO</div><div></div></div>'
      + '<div id="mp-contas-lista"></div></div></div></div>';
    renderContasLista();
  }

  // Menu da conta (desenho 1.2, linha 735), na ordem: [limites: frente própria, ocultos até lá], Estatísticas,
  // Histórico, Suspender OU Reativar (conforme a situação) e [Excluir conta: parte 2 da spec, ainda não].
  const menuDaConta = c => '<div class="mp-menu" data-parar><div class="mp-menu-cab">' + esc(c.nome) + '</div>'
    + '<button type="button" class="mp-menu-op" data-op="estat"><i class="bi bi-bar-chart"></i>Estatísticas de uso</button>'
    + '<button type="button" class="mp-menu-op" data-op="hist" data-conta-id="' + c.id + '"><i class="bi bi-clock-history"></i>Histórico de alterações</button>'
    + (c.situacao === 'Suspensa'
      ? '<button type="button" class="mp-menu-op mp-menu-sep mp-menu-reativar" data-op="reativar" data-conta-id="' + c.id + '"><i class="bi bi-play-circle"></i>Reativar conta</button>'
      : '<button type="button" class="mp-menu-op mp-menu-sep mp-menu-suspender" data-op="suspender" data-conta-id="' + c.id + '"><i class="bi bi-pause-circle"></i>Suspender conta</button>')
    + '</div>';

  // Confirmação do desenho (linha 624), textos exatos. O servidor revalida tudo (CSRF por conta, Master, regras C2, C3 e C5).
  const CONF_CONTA = {
    suspender: c => ({ titulo: 'Suspender conta', icone: 'bi-pause-circle', cor: '#c08a12', botao: 'Suspender', texto: 'Os usuários de "' + c.nome + '" ficam sem acesso até a reativação. Nenhum dado é apagado.' }),
    reativar: c => ({ titulo: 'Reativar conta', icone: 'bi-play-circle', cor: '#1e7b45', botao: 'Reativar', texto: 'A conta "' + c.nome + '" volta a ter acesso normal.' })
  };

  function pedirAcaoNaConta(tipo, id) {
    const c = D.contas.contas.find(x => x.id === id);
    if (!c || !window.BJMasterConf) return;
    window.BJMasterConf.abrir(Object.assign(CONF_CONTA[tipo](c), { aoConfirmar: () => enviarAcaoNaConta(tipo, c) }));
  }

  function enviarAcaoNaConta(tipo, c) {
    const corpo = new FormData();
    corpo.append('_token', c.token || '');
    const url = (tipo === 'suspender' ? URLS.contaSuspender : URLS.contaReativar).replace('/0/', '/' + c.id + '/');
    fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.json().catch(() => null).then(j => ({ r, j })))
      .then(({ r, j }) => {
        // Só é sucesso a resposta JSON direta do servidor com a conta: redirecionamento (sessão caída, login),
        // corpo que não é JSON ou JSON sem `conta` contam como erro, nunca verde.
        const json = !r.redirected && j !== null && typeof j === 'object';
        if (json && r.ok && j.conta) {
          c.situacao = j.conta.situacao;
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
    if (S.sec === 'contas') renderContasLista();
  }

  function renderContasLista() {
    const b = S.busca.toLowerCase();
    const contas = D.contas.contas.filter(c => (!S.fSit || c.situacao === S.fSit) && (!b || (c.nome + ' ' + (c.titular || '')).toLowerCase().includes(b)));
    document.getElementById('mp-contas-n').textContent = num(contas.length) + ' · botão direito na linha para ações';
    // Resultado de suspender/reativar: uma linha acima da tabela, até trocar de seção.
    document.getElementById('mp-contas-aviso').innerHTML = S.aviso ? '<div class="mp-aviso ' + (S.aviso.ok ? 'is-ok' : 'is-erro') + '" role="status" data-mp-aviso><i class="bi '
      + (S.aviso.ok ? 'bi-check-circle' : 'bi-exclamation-circle') + '"></i>' + esc(S.aviso.msg) + '</div>' : '';
    document.getElementById('mp-contas-lista').innerHTML = contas.length ? contas.map(c => {
      const cor = ST[c.situacao] || '#4a6373', aberto = S.menu === c.id;
      return '<div class="mp-conta mp-lin" data-conta="' + c.id + '">'
        + '<div class="mp-conta-nome mp-hv"><b title="' + esc(c.nome) + '">' + esc(c.nome) + '</b><span>desde ' + esc(c.desde) + ' · último acesso ' + esc(acessoRelativo(c.ultimoAcesso)) + '</span></div>'
        + '<div class="mp-conta-prim mp-hv"><span>' + esc(c.titular || 'titular não registrado') + '</span><span>sem plano</span></div>'
        + '<div class="mp-conta-usu mp-hv"><b>' + num(c.usuarios) + '</b><span> / sem limite</span></div>'
        + '<div class="mp-conta-num mp-hv">' + num(c.pastas) + '</div><div class="mp-conta-num mp-hv">' + num(c.documentos) + '</div>'
        + '<div class="mp-conta-arm mp-hv" style="--cor:#4a6373"><span><span>' + esc(gb(c.bytes)) + ' em uso</span><b></b></span><span>sem cota definida</span></div>'
        + '<div class="mp-conta-cresc mp-hv">' + (c.bytesRecentes > 0 ? '+' + esc(gb(c.bytesRecentes)) : 'estável') + '</div>'
        + '<div class="mp-conta-prev mp-hv">sem previsão</div>'
        + '<div class="mp-conta-st mp-hv"><span style="--cor:' + cor + '"><i></i>' + esc(c.situacao) + '</span></div>'
        + '<div class="mp-conta-acoes mp-hv"><button type="button" class="mp-btn-icone" data-menu="' + c.id + '" title="Ações da conta" aria-label="Ações da conta"><i class="bi bi-three-dots-vertical"></i></button></div>'
        + (aberto ? menuDaConta(c) : '')
        + '</div>';
    }).join('') : '<div class="mp-vazio-linha">Nenhuma conta encontrada.</div>';
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
  function montarArm() {
    const contas = D.contas.contas.slice().sort((a, b) => b.bytes - a.bytes), mx = Math.max(1, ...contas.map(c => c.bytes));
    elSecao.innerHTML = '<div class="mp-card mp-ranking"><div class="mp-cab-simples"><b>Contas por consumo</b></div>'
      + (contas.length ? contas.map(c => '<div class="mp-rank" style="--cor:#0a6fa0"><span title="' + esc(c.nome) + '">' + esc(c.nome) + '</span><div class="mp-barra-8"><div style="--w:' + pct(c.bytes, mx) + '"></div></div><b>' + esc(gb(c.bytes)) + '</b><span>sem cota definida</span></div>').join('') : '<div class="mp-vazio-linha">Nenhuma conta.</div>')
      + '</div><span class="mp-nota">Soma dos arquivos com tamanho gravado (pastas fora da lixeira, clientes, processos, cobrança, chamados e kanban). Justificativas de ponto e anexos de tarefa não têm tamanho no banco: não medidos.</span>';
  }

  /* ================= SEGURANÇA E AUDITORIA ================= */
  const AI = a => (/Limite/.test(a) ? ['bi-hdd', '#0a6fa0'] : /suspensa|excluída|excluído|removido|retirada|desativado/i.test(a) ? ['bi-slash-circle', '#a3232b'] : /reativada|reativado/i.test(a) ? ['bi-play-circle', '#1e7b45'] : /Master|primário|Papel no sistema/i.test(a) ? ['bi-stars', '#7a3e8f'] : /Alerta/.test(a) ? ['bi-exclamation-triangle', '#c08a12'] : ['bi-journal-check', '#4a6373']);
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
    if (menu) { e.stopPropagation(); const id = +menu.dataset.menu; S.menu = S.menu === id ? null : id; return renderContasLista(); }
    const op = t.closest('[data-op]');
    if (op) {
      e.stopPropagation(); S.menu = null;
      if (op.dataset.op === 'estat') return irPara('arm');
      if (op.dataset.op === 'suspender' || op.dataset.op === 'reativar') { renderContasLista(); return pedirAcaoNaConta(op.dataset.op, +op.dataset.contaId); }
      S.audConta = +op.dataset.contaId; S.audTipo = ''; return irPara('audit');
    }
    if (t.closest('[data-pdf]')) return gerarPdf();
    if (t.closest('[data-aud-todas]')) { S.audConta = null; return montarAudit(); }
  });
  elSecao.addEventListener('contextmenu', e => {
    const lin = e.target.closest('[data-conta]'); if (!lin) return;
    e.preventDefault(); S.menu = +lin.dataset.conta; renderContasLista();
  });
  elSecao.addEventListener('change', e => {
    const t = e.target;
    if (t.matches('[data-gr-met]')) { S.grMet = t.value; return renderGrafico(); }
    if (t.matches('[data-f-sit]')) { S.fSit = t.value; S.menu = null; return renderContasLista(); }
    if (t.matches('[data-aud-tipo]')) { S.audTipo = t.value; return montarAudit(); }
  });
  elSecao.addEventListener('input', e => { if (e.target.matches('[data-busca]')) { S.busca = e.target.value; S.menu = null; renderContasLista(); } });
  document.addEventListener('click', e => { if (S.menu !== null && S.sec === 'contas' && !e.target.closest('[data-parar]')) { S.menu = null; renderContasLista(); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && S.menu !== null && S.sec === 'contas') { S.menu = null; renderContasLista(); } });

  renderSecs();
  renderSecao();
  // A trilha de auditoria só é carregada quando a seção é aberta (o contador aparece a partir daí).
})();
