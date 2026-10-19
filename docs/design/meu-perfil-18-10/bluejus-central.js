/* BlueJus · Central de Comunicação (global, independente da página)
   Camadas: Usuarios · Store (mensagens, notificações, preferências) · Regras (tipos e prioridade) · Som · Tempo real · Interface
   Integração com o sistema real:
     window.BLUEJUS_USUARIO_ATUAL = { id, nome, email, setor, funcao, foto }
     window.BLUEJUS_USUARIOS = [ ... ]            (ou GET {BLUEJUS_API_BASE}/usuarios)
     window.BLUEJUS_WS_URL = 'wss://...'          (eventos em tempo real: mesmo formato de BJCentral.notificar)
   API: BJCentral.notificar(evt) · BJCentral.mencoes(texto) · BJCentral.abrir() · evento window 'bj-evento' (detail = evt)
   Navegação: dispara window 'bj-navegar' { pasta, meta, aba, url } ao clicar numa notificação com contexto. */
(function () {
  if (window.BJCentral) return;
  const KEY = 'bj-central-v1', PREF = 'bj-central-prefs';
  const AZUL = '#0a7aad', PETROLEO = '#0b5f86', VERM = '#c0392f', AMBAR = '#c8952a', VERDE = '#1f9d61';

  /* ---------- Usuários (reaproveita cadastro do sistema) ---------- */
  const PADRAO = [
    ['farlei', 'Farlei Assis da Rocha', 'farlei.rocha@gmail.com', 'Sócio', 'Advogado'],
    ['samuel', 'Samuel Freitas', 'samuel@bluejus.com.br', 'Contencioso', 'Advogado'],
    ['jessica', 'Jéssica Martins', 'jessica@bluejus.com.br', 'Contencioso', 'Advogada'],
    ['mariana', 'Mariana Costa', 'mariana@bluejus.com.br', 'Controladoria', 'Coordenadora'],
    ['rafael', 'Rafael Nunes', 'rafael@bluejus.com.br', 'Cível', 'Advogado'],
    ['thamara', 'Thamara Lopes', 'thamara@bluejus.com.br', 'Administrativo', 'Assistente'],
    ['rodrigo', 'Rodrigo Alves', 'rodrigo@bluejus.com.br', 'Controladoria', 'Estagiário'],
    ['helena', 'Helena Prado', 'helena@bluejus.com.br', 'Trabalhista', 'Advogada'],
  ].map(([id, nome, email, setor, funcao]) => ({ id, nome, email, setor, funcao }));
  let USUARIOS = Array.isArray(window.BLUEJUS_USUARIOS) && window.BLUEJUS_USUARIOS.length ? window.BLUEJUS_USUARIOS : PADRAO;
  const COMO = (() => { try { const q = new URLSearchParams(location.search).get('como'); if (q) sessionStorage.setItem('bj-como', q); return q || sessionStorage.getItem('bj-como'); } catch (e) { return null; } })();
  const EU = () => window.BLUEJUS_USUARIO_ATUAL || (COMO && USUARIOS.find(u => u.id === COMO)) || USUARIOS.find(u => u.id === 'samuel') || USUARIOS[0];
  const porNome = n => USUARIOS.find(u => norm(u.nome) === norm(n)) || USUARIOS.find(u => norm(u.nome).startsWith(norm(n)));
  if (window.BLUEJUS_API_BASE && !window.BLUEJUS_USUARIOS) {
    fetch(window.BLUEJUS_API_BASE + '/usuarios?campos=id,nome,email,setor,funcao,foto_url', { credentials: 'include' })
      .then(r => r.ok ? r.json() : null).then(l => { if (Array.isArray(l) && l.length) { USUARIOS = l.map(u => ({ ...u, foto: u.foto_url })); render(); } }).catch(() => {});
  }
  const norm = t => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const ini = n => String(n || '').split(' ').filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');

  /* ---------- Store ---------- */
  const ler = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } };
  let db = ler(KEY, { notifs: [], msgs: [] }); db.grupos = db.grupos || []; db.convPrefs = db.convPrefs || {};
  // Cargos: overrides por usuário (no sistema real: PATCH /usuarios/:id) e cargos cadastrados extras.
  db.cargosUsr = db.cargosUsr || {}; db.cargosExtra = db.cargosExtra || { setor: [], funcao: [] };
  const aplicarCargos = () => { USUARIOS = USUARIOS.map(u => db.cargosUsr[u.id] ? { ...u, ...db.cargosUsr[u.id] } : u); };
  aplicarCargos();
  let prefs = { som: true, alertas: true, modo: 'todas', volume: 'medio', toques: true, dnd: 0, status: 'disponivel', enterEnvia: true, texto: 'normal', ...ler(PREF, {}) }; if (prefs.janela === 'min') prefs.janela = prefs.janelaAnt && prefs.janelaAnt !== 'min' ? prefs.janelaAnt : 'normal';
  const VOL = { baixo: .45, medio: 1, alto: 1.7 }, VOL_L = { baixo: 'Baixo', medio: 'Médio', alto: 'Alto' };
  const STATUS = { disponivel: ['Disponível', '#3fd17f'], ocupado: ['Ocupado', '#e5484d'], ausente: ['Ausente', '#f2a93b'] };
  const silencio = () => prefs.dnd && Date.now() < prefs.dnd;
  try { if (!document.getElementById('bjcx-css')) { const s = document.createElement('style'); s.id = 'bjcx-css'; s.textContent = '@keyframes bjcxOnda{0%{transform:scale(1);opacity:.75}100%{transform:scale(1.85);opacity:0}}@media (prefers-reduced-motion:reduce){#bjcx *{animation:none!important}}'; document.head.appendChild(s); } } catch (e) {}
  /* Persistência protegida: nunca falha em silêncio. Se o armazenamento encher, compacta só dados técnicos
     (auditoria antiga, logs, caches) e nunca mensagens/comentários; cópia de segurança no IndexedDB a cada 30 s,
     restaurada se o armazenamento local for perdido ou corrompido. Sistema real: servidor é a fonte da verdade. */
  const BK = { db: null, t: 0, avisou: false, ultimo: 0 };
  const bkIdb = () => BK.db || (BK.db = new Promise((ok, no) => { try { const r = indexedDB.open('bj-central-backup', 1); r.onupgradeneeded = () => r.result.createObjectStore('b'); r.onsuccess = () => ok(r.result); r.onerror = () => no(r.error); } catch (e) { no(e); } }));
  function bkGravar(json) { bkIdb().then(d => { const t = d.transaction('b', 'readwrite'), s = t.objectStore('b'); s.put({ em: Date.now(), json }, KEY + ':ultimo'); const dia = new Date().toISOString().slice(0, 10); s.put({ em: Date.now(), json }, KEY + ':' + dia); const outros = {}; try { for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (/^(bj-|bj_)/.test(k) && k !== KEY && !/cache|rascunho/.test(k)) outros[k] = localStorage.getItem(k); } } catch (e) {} s.put({ em: Date.now(), outros }, 'sistema:ultimo'); }).catch(() => {}); BK.ultimo = Date.now(); }
  function compactar() { const antes = JSON.stringify(db).length; if ((db.auditoria || []).length > 400) db.auditoria = db.auditoria.slice(-400); if ((db.callLog || []).length > 100) db.callLog = db.callLog.slice(-100); if (db.cce) { db.cce.snaps = (db.cce.snaps || []).slice(-15); db.cce.cache = {}; db.cce.feedback = (db.cce.feedback || []).slice(-30); Object.keys(db.cce.versoes || {}).forEach(k => db.cce.versoes[k] = db.cce.versoes[k].slice(-6)); } if ((db.docEventos || []).length > 100) db.docEventos = db.docEventos.slice(-100); if ((db.notifs || []).length > 400) { const lidas = db.notifs.filter(n => n.lida), nl = db.notifs.filter(n => !n.lida); db.notifs = nl.concat(lidas.slice(-(400 - Math.min(nl.length, 400)))).sort((a, b) => (a.quando || 0) - (b.quando || 0)); } return antes - JSON.stringify(db).length; }
  const salvar = () => { let json; try { json = JSON.stringify(db); } catch (e) { return; } try { localStorage.setItem(KEY, json); } catch (e) { compactar(); try { json = JSON.stringify(db); localStorage.setItem(KEY, json); } catch (e2) { bkGravar(json); if (!BK.avisou) { BK.avisou = true; try { auditar && auditar('armazenamento', 'armazenamento local cheio: dados guardados na cópia de segurança', 'falha'); } catch (x) {} setTimeout(() => { try { aviso('O armazenamento deste navegador encheu. Suas conversas e comentários foram guardados na cópia de segurança.'); } catch (x) {} }, 0); } return; } }
    clearTimeout(BK.t); if (Date.now() - BK.ultimo > 30000) bkGravar(json); else BK.t = setTimeout(() => bkGravar(JSON.stringify(db)), 30000); };
  setTimeout(() => { bkIdb().then(d => new Promise(ok => { const r = d.transaction('b').objectStore('b').get(KEY + ':ultimo'); r.onsuccess = () => ok(r.result); r.onerror = () => ok(null); })).then(b => { bkIdb().then(d => { const r = d.transaction('b').objectStore('b').get('sistema:ultimo'); r.onsuccess = () => { const o = r.result && r.result.outros; if (!o) return; const DADOS = /^bj-(registros|metas-pasta|clientes|push-notas|push-links|pasta-prefs|cad-sugestoes|ligacoes-pasta|pasta-hist|fin|financeiro)/; const volta = Object.keys(o).filter(k => DADOS.test(k) && localStorage.getItem(k) == null); volta.forEach(k => { try { localStorage.setItem(k, o[k]); } catch (e) {} }); if (volta.length) { try { auditar('armazenamento', volta.length + ' registro(s) do sistema restaurados da cópia de segurança: ' + volta.slice(0, 6).join(', '), 'ok'); } catch (x) {} window.dispatchEvent(new CustomEvent('bj-evidencia')); } }; }).catch(() => {}); if (!b || !b.json) { salvar(); return; } let bdb = null; try { bdb = JSON.parse(b.json); } catch (e) { return; } const loc = (db.msgs || []).length, bk = (bdb.msgs || []).length; if (bk > loc && (loc === 0 || !localStorage.getItem(KEY))) { db = Object.assign(bdb, { grupos: bdb.grupos || [], convPrefs: bdb.convPrefs || {} }); try { localStorage.setItem(KEY, b.json); } catch (e) {} try { auditar('armazenamento', 'conversas restauradas da cópia de segurança (' + bk + ' mensagens)', 'ok'); } catch (x) {} try { render(); } catch (x) {} } }).catch(() => {}); }, 2500);
  const salvarPrefs = () => { try { localStorage.setItem(PREF, JSON.stringify(prefs)); } catch (e) {} };
  const ui = { aberto: false, aba: 'entrada', conversa: null, busca: '', menu: false, ajuda: false, pulso: false, rasc: '', men: null, menI: 0 };

  /* ---------- Regras: tipos e prioridade ---------- */
  const TIPOS = {
    meta_nova:      { grupo: 'metas',     nivel: 2, som: true,  ic: 'bi-clipboard2-plus',  cor: AZUL,  rotulo: 'Nova meta' },
    meta_atrasada:  { grupo: 'urgente',   nivel: 3, som: false, ic: 'bi-exclamation-octagon', cor: VERM, rotulo: 'Meta atrasada' },
    meta_atualizada:{ grupo: 'metas',     nivel: 1, som: false, ic: 'bi-pencil-square',    cor: PETROLEO, rotulo: 'Meta atualizada' },
    meta_concluida: { grupo: 'metas',     nivel: 0, som: false, ic: 'bi-check2-circle',    cor: VERDE, rotulo: 'Meta concluída' },
    mensagem:       { grupo: 'mensagens', nivel: 1, som: true,  ic: 'bi-chat-left-text',   cor: PETROLEO, rotulo: 'Mensagem' },
    mencao:         { grupo: 'mencoes',   nivel: 2, som: true,  ic: 'bi-at',               cor: AZUL,  rotulo: 'Menção' },
    demanda:        { grupo: 'atencao',   nivel: 2, som: true,  ic: 'bi-inboxes',          cor: AMBAR, rotulo: 'Nova demanda' },
    contribua:      { grupo: 'atencao',   nivel: 2, som: true,  ic: 'bi-heart',            cor: '#c0392b', rotulo: 'Contribua' },
    agenda:         { grupo: 'atencao',   nivel: 2, som: true,  ic: 'bi-calendar-event',   cor: AZUL,  rotulo: 'Agenda' },
    alerta:         { grupo: 'urgente',   nivel: 3, som: 'toque', ic: 'bi-bell-fill',      cor: '#e8590c', rotulo: 'Alerta' },
    toque:          { grupo: 'urgente',   nivel: 3, som: 'toque', ic: 'bi-bell-fill',      cor: '#e8590c', rotulo: 'Toque' },
    documento_analisado:{ grupo: 'mensagens', nivel: 1, som: false, ic: 'bi-file-earmark-check', cor: AZUL, rotulo: 'Documento analisado' },
    ligacao_perdida:{ grupo: 'urgente',   nivel: 3, som: 'toque', ic: 'bi-telephone-x-fill', cor: '#cf3f36', rotulo: 'Ligação perdida' },
    conformidade:   { grupo: 'atencao',   nivel: 2, som: true,  ic: 'bi-clipboard2-check', cor: AMBAR, rotulo: 'Providência' },
    cobranca:       { grupo: 'urgente',   nivel: 3, som: true,  ic: 'bi-megaphone-fill',   cor: VERM, rotulo: 'Cobrança de documento' },
    direcionada:    { grupo: 'atencao',   nivel: 2, som: true,  ic: 'bi-person-check',     cor: AMBAR, rotulo: 'Para você' },
  };
  const GRUPOS = [['urgente', 'Urgente', VERM], ['atencao', 'Atenção', AMBAR], ['mencoes', 'Menções', AZUL], ['mensagens', 'Mensagens', PETROLEO], ['metas', 'Metas', AZUL]];
  const meuId = () => EU().id;
  const minhas = () => db.notifs.filter(n => n.para === meuId() && !n.excluida);
  // Demandas resolvidas: ficam na Caixa de entrada por RES_JANELA, vão clareando aos poucos e depois saem (continuam em "Ver resolvidas").
  const RES_JANELA = () => (prefs.resJanelaH || 24) * 36e5;
  const resIdade = n => n.resolvida && n.resolvidaEm ? (Date.now() - n.resolvidaEm) / RES_JANELA() : 0;
  const resExpirada = n => n.resolvida && resIdade(n) >= 1;
  const naoLidas = () => minhas().filter(n => !n.lida && !n.resolvida);
  const grupoN = n => n.urgente ? 'urgente' : (TIPOS[n.tipo] || {}).grupo;
  const msgsNaoLidas = () => conversasLista(true).reduce((t, cv) => t + (cv.pref.mudo ? 0 : cv.nl), 0);

  /* ---------- Som ---------- */
  let ac = null, ultimoSom = 0;
  function tocar() {
    if (!prefs.som || silencio() || Date.now() - ultimoSom < 2500) return;
    ultimoSom = Date.now();
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      if (ac.state === 'suspended') ac.resume().catch(() => {});
      [[880, 0], [1320, .11]].forEach(([f, t]) => {
        const o = ac.createOscillator(), g = ac.createGain(), t0 = ac.currentTime + t;
        o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(ac.destination);
        g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(.12 * VOL[prefs.volume || 'medio'], t0 + .015); g.gain.exponentialRampToValueAtTime(.0001, t0 + .28);
        o.start(t0); o.stop(t0 + .3);
      });
    } catch (e) { /* navegador bloqueou o áudio: segue só com o aviso visual */ }
  }

  function tocarToque() {
    if (!prefs.som || silencio()) return;
    if (!prefs.toques) { tocar(); return; }
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      if (ac.state === 'suspended') ac.resume().catch(() => {});
      [[988, 0], [1319, .14], [988, .32], [1319, .46]].forEach(([f, t]) => {
        const o = ac.createOscillator(), g = ac.createGain(), t0 = ac.currentTime + t;
        o.type = 'triangle'; o.frequency.value = f; o.connect(g); g.connect(ac.destination);
        g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(.16 * VOL[prefs.volume || 'medio'], t0 + .01); g.gain.exponentialRampToValueAtTime(.0001, t0 + .2);
        o.start(t0); o.stop(t0 + .22);
      });
    } catch (e) {}
  }
  function tremer() { const b = raiz.lastChild; if (!b || !b.animate) return; b.animate([{ transform: 'none' }, { transform: 'translateX(-5px) rotate(-6deg)' }, { transform: 'translateX(5px) rotate(6deg)' }, { transform: 'translateX(-4px) rotate(-4deg)' }, { transform: 'translateX(3px) rotate(3deg)' }, { transform: 'none' }], { duration: 650, iterations: 2 }); }

  /* ---------- Entrada de eventos ---------- */
  function notificar(evt, opts) {
    const T = TIPOS[evt.tipo]; if (!T) return null;
    const destinos = (evt.para ? [].concat(evt.para) : [meuId()]).map(p => p === 'master' ? 'farlei' : p);
    let criada = null;
    destinos.forEach(para => {
      const pid = (USUARIOS.find(u => u.id === para || norm(u.nome) === norm(para)) || { id: para }).id;
      const chave = evt.chave ? evt.chave + ':' + pid : null;
      if (chave && db.notifs.some(n => n.chave === chave)) return;
      const n = { id: 'n' + Date.now() + Math.random().toString(36).slice(2, 6), tipo: evt.tipo, para: pid, de: evt.de || null, titulo: evt.titulo, texto: evt.texto || '', ctx: evt.ctx || null, conv: evt.conv || null, quando: Date.now(), lida: false, chave };
      db.notifs.unshift(n);
      if (pid === meuId()) criada = n;
    });
    db.notifs = db.notifs.slice(0, 300); salvar();
    if (evt.tipo === 'meta_nova' && evt.ctx) alertaMetaIA(evt, destinos);
    if (criada && criada.conv && convPref(criada.conv.id).mudo) opts = { ...(opts || {}), silencioso: true };
    if (criada && !(opts && opts.silencioso)) {
      const T2 = TIPOS[criada.tipo];
      const relevante = prefs.modo === 'todas' || T2.nivel >= 2;
      if (prefs.alertas && relevante && !silencio()) { ui.pulso = true; clearTimeout(ui._p); ui._p = setTimeout(() => { ui.pulso = false; render(); }, 2400); }
      if (T2.som === 'toque') tocarToque(); else if (T2.som && relevante) tocar();
    }
    render();
    if (criada && criada.tipo === 'toque' && !(opts && opts.silencioso) && prefs.alertas && prefs.toques && !silencio()) setTimeout(tremer, 30);
    return criada;
  }
  // Pedido de acesso a pasta trancada → mensagem na conversa do Master com a BlueJus IA, com botões para liberar ou recusar.
  // Sistema real: POST /api/pastas/{n}/acesso/pedidos → evento ao Master; PUT /api/pastas/{n}/acesso {user, nivel}.
  const CAD_KEY = 'bj-pasta-cadeado-v1', CADP_KEY = 'bj-pasta-acesso-pedidos-v1';
  const cadLerC = () => { try { return JSON.parse(localStorage.getItem(CAD_KEY) || '{}'); } catch (e) { return {}; } };
  const cadpLer = () => { try { return JSON.parse(localStorage.getItem(CADP_KEY) || '{}'); } catch (e) { return {}; } };
  const cadpGravar = d => { try { localStorage.setItem(CADP_KEY, JSON.stringify(d)); } catch (e) {} };
  function pedirAcessoPasta(o) {
    const eu = EU(), M = usuario(PRIMARIO_ID()) || MASTER, k = o.pasta + ':' + eu.id, d = cadpLer(), ant = d[k];
    if (ant && !ant.resolvido && Date.now() - ant.em < 864e5) return { ok: false, motivo: 'Você já pediu acesso a esta pasta hoje. Aguarde o usuário primário.' };
    const nivel = o.nivel === 'ver' ? 'ver' : 'editar', p = { pasta: o.pasta, cliente: o.cliente || '', de: eu.id, nome: eu.nome, nivel, motivo: String(o.motivo || '').trim().slice(0, 300), em: Date.now(), resolvido: null };
    d[k] = p; cadpGravar(d);
    const h = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    db.msgs.push({ id: 'm' + Date.now() + 'cadp', de: 'ia', para: 'ia', iaPar: [M.id, 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [],
      texto: M.nome.split(' ')[0] + ', ' + eu.nome + ' pediu acesso à **Pasta ' + o.pasta + '**' + (o.cliente ? ' (' + o.cliente + ')' : '') + ' às ' + h + ', para ' + (nivel === 'ver' ? 'visualizar' : 'editar') + '.' + (p.motivo ? '\n\nMotivo: "' + p.motivo + '"' : '') + '\n\nA pasta está trancada por você. Quer liberar?',
      iaDados: { modulos: ['Acesso'], comando: 'Pedido de acesso', caminho: [['Expediente', 'bi-briefcase'], ['Pasta ' + o.pasta + (o.cliente ? ' · ' + o.cliente : ''), 'bi-folder2-open'], ['Cadeado', 'bi-lock']], pasta: o.pasta,
        pontos: [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'Solicitante: ' + eu.nome + (eu.funcao ? ' · ' + eu.funcao : '') + ' · pedido: ' + (nivel === 'ver' ? 'visualizar' : 'editar') }],
        acoes: [{ tipo: 'cad_liberar', rotulo: 'Liberar para editar', pasta: o.pasta, uid: eu.id, nivel: 'editar' }, { tipo: 'cad_liberar', rotulo: 'Liberar só visualizar', pasta: o.pasta, uid: eu.id, nivel: 'ver' }, { tipo: 'cad_recusar', rotulo: 'Recusar', pasta: o.pasta, uid: eu.id }] } });
    auditar('cadeado', 'pedido de acesso à pasta ' + o.pasta + ' por ' + eu.id, 'enviado'); salvar(); render();
    return { ok: true, em: p.em };
  }
  /* ---------- Zerar dados estatísticos do Dashboard ----------
     Regra primária: só o Master zera. Secundário só PEDE: o Chat I.A avisa o Master (Aceitar/Recusar) e a Controladoria (ciência).
     Chave: bj-dash-zerado-v1 = { [coluna]: { todos, porId:{[id]:ms}, log:[] } }. Sistema real: POST /api/relatorios/{coluna}/zerar, só Master. */
  const DZ_KEY = 'bj-dash-zerado-v1', DZP_KEY = 'bj-dash-zerar-pedidos-v1';
  const dzLer = () => { try { return JSON.parse(localStorage.getItem(DZ_KEY) || '{}'); } catch (e) { return {}; } };
  const dzpLer = () => { try { return JSON.parse(localStorage.getItem(DZP_KEY) || '{}'); } catch (e) { return {}; } };
  function dzAplicar(col, alvo, por) {
    if (col === 'comentarios') { let z = {}; try { z = JSON.parse(localStorage.getItem('bj-coment-zerado-v1') || '{}'); } catch (e) {} if (alvo) { z.porId = z.porId || {}; z.porId[alvo] = Date.now(); } else { z.todos = Date.now(); z.porId = {}; } z.log = (z.log || []).concat([{ em: Date.now(), quem: alvo || 'todos', por }]).slice(-100); localStorage.setItem('bj-coment-zerado-v1', JSON.stringify(z)); }
    else { const Z = dzLer(), z = Z[col] = Z[col] || {}; if (alvo) { z.porId = z.porId || {}; z.porId[alvo] = Date.now(); } else { z.todos = Date.now(); z.porId = {}; } z.log = (z.log || []).concat([{ em: Date.now(), quem: alvo || 'todos', por }]).slice(-100); localStorage.setItem(DZ_KEY, JSON.stringify(Z)); }
    window.dispatchEvent(new CustomEvent('bj-dash-zerado'));
  }
  function pedirZerar(o) {
    const eu = EU(); if (ehPrimario(meuId())) return { ok: false, motivo: 'primario' };
    const k = o.col + ':' + (o.alvo || 'todos') + ':' + eu.id, d = dzpLer(), ant = d[k];
    if (ant && !ant.resolvido && Date.now() - ant.em < 864e5) return { ok: true, repetido: true };
    d[k] = { col: o.col, rotulo: o.rotulo, alvo: o.alvo || null, alvoNome: o.alvoNome || 'toda a equipe', de: eu.id, nome: eu.nome, em: Date.now(), resolvido: null }; localStorage.setItem(DZP_KEY, JSON.stringify(d));
    const h = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }), alvoT = o.alvo ? 'de **' + o.alvoNome + '**' : 'de **toda a equipe**';
    db.msgs.push({ id: 'm' + Date.now() + 'dzp', de: 'ia', para: 'ia', iaPar: [PRIMARIO_ID(), 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [],
      texto: usuario(PRIMARIO_ID()).nome.split(' ')[0] + ', ' + eu.nome + ' pediu às ' + h + ' para **zerar ' + o.rotulo + '** ' + alvoT + ' no Dashboard.\n\nÉ um dado estatístico: zerar recomeça a contagem a partir de agora e o histórico anterior deixa de entrar no número. Quer autorizar?',
      iaDados: { modulos: ['Governança', 'Dashboard'], comando: 'Pedido para zerar', caminho: [['Dashboard', 'bi-grid-1x2'], ['Desempenho · ' + o.rotulo, 'bi-table']], pontos: [{ nivel: 'atencao', camada: 'dado', confianca: 'confirmado', texto: 'Solicitante: ' + eu.nome + (eu.funcao ? ' · ' + eu.funcao : '') + ' · coluna: ' + o.rotulo + ' · alvo: ' + (o.alvoNome || 'toda a equipe') }],
        acoes: [{ tipo: 'dz_aceitar', rotulo: 'Aceitar e zerar', k }, { tipo: 'dz_recusar', rotulo: 'Recusar', k }] } });
    const ctl = controllerAtual();
    if (ctl && ctl.id !== PRIMARIO_ID() && ctl.id !== eu.id) db.msgs.push({ id: 'm' + Date.now() + 'dzc', de: 'ia', para: 'ia', iaPar: [ctl.id, 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [], texto: ctl.nome.split(' ')[0] + ', para sua análise: ' + eu.nome + ' pediu para zerar **' + o.rotulo + '** ' + alvoT + ' no Dashboard. A decisão é do usuário primário; nada foi alterado.', iaDados: { modulos: ['Governança'], comando: 'Pedido para zerar · ciência', pontos: [], acoes: [] } });
    auditar('integridade-dados', 'pedido para zerar ' + o.col + ' (' + (o.alvo || 'todos') + ') por ' + eu.id, 'enviado'); salvar(); render();
    return { ok: true };
  }
  function dzResolver(a, ok) {
    if (!ehPrimario(meuId())) { aviso('Só o usuário primário pode zerar dados estatísticos'); auditar('integridade-dados', 'tentativa de resolver pedido de zerar por ' + meuId(), 'bloqueado'); return; }
    const d = dzpLer(), p = d[a.k]; if (!p) { aviso('Pedido não encontrado'); return; } if (p.resolvido) { aviso(p.resolvido === 'recusado' ? 'Este pedido já foi recusado' : 'Este pedido já foi atendido'); return; }
    if (ok && !window.confirm('Zerar ' + p.rotulo + ' de ' + p.alvoNome + '? A contagem recomeça agora; o histórico anterior deixa de entrar no número. Esta ação fica registrada.')) return;
    if (ok) dzAplicar(p.col, p.alvo, meuId());
    p.resolvido = ok ? 'zerado' : 'recusado'; p.respondido = Date.now(); localStorage.setItem(DZP_KEY, JSON.stringify(d));
    db.msgs.push({ id: 'm' + Date.now() + 'dzr', de: 'ia', para: 'ia', iaPar: [p.de, 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [], texto: p.nome.split(' ')[0] + ', ' + (ok ? 'o usuário primário autorizou: **' + p.rotulo + '** de ' + p.alvoNome + ' foi zerado e a contagem recomeçou agora.' : 'o usuário primário não autorizou zerar **' + p.rotulo + '** de ' + p.alvoNome + '. O dado continua como está.'), iaDados: { modulos: ['Governança'], comando: 'Pedido para zerar', pontos: [], acoes: [] } });
    auditar('integridade-dados', (ok ? 'zerado ' : 'recusado zerar ') + p.col + ' (' + (p.alvo || 'todos') + ')', ok ? 'ok' : 'recusado'); salvar(); render(); aviso(ok ? 'Zerado · ' + p.rotulo : 'Pedido recusado');
  }
  function cadResolver(a, ok) {
    if (!ehPrimario(meuId())) { aviso('Só o usuário primário pode liberar a pasta'); return; }
    const d = cadpLer(), k = a.pasta + ':' + a.uid, p = d[k]; if (p && p.resolvido) { aviso(p.resolvido === 'recusado' ? 'Este pedido já foi recusado' : 'Este pedido já foi atendido'); return; }
    const u = usuario(a.uid) || { nome: a.uid };
    if (ok) { const L = cadLerC(), cad = L[a.pasta]; if (cad) { if (cad.modo === 'todos') { cad.modo = 'pessoas'; cad.niveis = {}; USUARIOS.forEach(x => { if (x.id !== PRIMARIO_ID()) cad.niveis[x.id] = 'oculto'; }); } cad.niveis = cad.niveis || {}; cad.niveis[a.uid] = a.nivel; cad.em = Date.now(); try { localStorage.setItem(CAD_KEY, JSON.stringify(L)); } catch (e) {} } }
    if (p) { p.resolvido = ok ? a.nivel : 'recusado'; p.respondido = Date.now(); cadpGravar(d); }
    try { const H = JSON.parse(localStorage.getItem('bj-pasta-hist-v1') || '[]'); H.push({ pasta: a.pasta, por: meuId(), em: Date.now(), txt: ok ? 'Acesso liberado para ' + u.nome + ' (' + (a.nivel === 'ver' ? 'visualizar' : 'editar') + ')' : 'Pedido de acesso de ' + u.nome + ' recusado' }); localStorage.setItem('bj-pasta-hist-v1', JSON.stringify(H.slice(-300))); } catch (e) {}
    db.msgs.push({ id: 'm' + Date.now() + 'cadr', de: 'ia', para: 'ia', iaPar: [a.uid, 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [],
      texto: u.nome.split(' ')[0] + ', ' + (ok ? 'o usuário primário liberou a **Pasta ' + a.pasta + '** para você ' + (a.nivel === 'ver' ? 'visualizar' : 'editar') + '. 👍' : 'o usuário primário não liberou o acesso à **Pasta ' + a.pasta + '** por enquanto.'),
      iaDados: { modulos: ['Acesso'], pontos: [], acoes: ok ? [{ tipo: 'abrir_pasta', rotulo: 'Abrir a pasta', pasta: a.pasta }] : [] } });
    auditar('cadeado', (ok ? 'acesso liberado (' + a.nivel + ')' : 'acesso recusado') + ' · pasta ' + a.pasta + ' · ' + a.uid, 'ok'); salvar();
    window.dispatchEvent(new CustomEvent('bj-cadeado', { detail: { pasta: a.pasta } })); render();
    aviso(ok ? 'Pasta ' + a.pasta + ' liberada para ' + u.nome.split(' ')[0] : 'Pedido recusado');
  }
  // Link externo do Push: pedido de renovação feito pelo terceiro → alerta na conversa com a BlueJus IA de quem criou o link.
  // No sistema real: POST /api/push/links/{token}/renovacao (público) → evento para o criador; PUT /api/push/links/{token} {expira:+30d}.
  const PL_KEY = 'bj-push-links-v1', PL_DIA = 864e5, PL_VAL = 30;
  const plLer = () => { try { return JSON.parse(localStorage.getItem(PL_KEY) || '{}'); } catch (e) { return {}; } };
  const plGravar = d => { try { localStorage.setItem(PL_KEY, JSON.stringify(d)); } catch (e) {} };
  function pushRenovSync() {
    const d = plLer(); let mud = false;
    Object.entries(d).forEach(([t, l]) => {
      const p = l.renovPedido; if (!p || p.alertado) return;
      const dono = USUARIOS.find(u => norm(u.nome) === norm(l.criadoPor)) || EU();
      const quem = p.nome ? p.nome : 'Quem recebeu o link';
      db.msgs.push({ id: 'm' + Date.now() + 'plr' + t.slice(0, 4), de: 'ia', para: 'ia', iaPar: [dono.id, 'ia'], ia: true, resumo: true, quando: Date.now(), lida: false, lidaPor: [],
        texto: quem + ' pediu a renovação do link externo do Push da Pasta ' + l.pasta + '. O link expirou em ' + new Date(l.expira).toLocaleDateString('pt-BR') + '. Aceita renovar por mais ' + PL_VAL + ' dias?',
        iaDados: { modulos: ['Push Processual'], caminho: [['Expediente', 'bi-briefcase'], ['Pasta ' + l.pasta + (l.cliente ? ' · ' + l.cliente : ''), 'bi-folder2-open'], ['Push Processual', 'bi-broadcast']], pasta: l.pasta,
          pontos: [{ nivel: 'info', texto: 'Processo ' + l.processo + ' · ' + (l.acessos || []).length + ' acesso(s) até agora' + (p.msg ? ' · mensagem: "' + p.msg + '"' : ''), confianca: 'confirmado' }],
          acoes: [{ tipo: 'push_renovar', rotulo: 'Renovar por ' + PL_VAL + ' dias', token: t }, { tipo: 'push_recusar', rotulo: 'Não renovar', token: t }, { tipo: 'abrir_push', rotulo: 'Abrir Push', pasta: l.pasta }] } });
      p.alertado = Date.now(); mud = true;
    });
    if (mud) { plGravar(d); salvar(); if (prefs.alertas && !silencio()) try { tocar && tocar(); } catch (e) {} render(); }
  }
  window.addEventListener('storage', e => { if (e.key === PL_KEY) try { pushRenovSync(); } catch (x) {} });
  setTimeout(() => { try { pushRenovSync(); } catch (e) {} }, 1200);
  setInterval(() => { try { if (ui.aberto && minhas().some(n => n.resolvida && !resExpirada(n))) render(); } catch (e) {} }, 5 * 60e3);
  // Nova meta → alerta na conversa com a BlueJus IA de cada responsável (e do criador), com o caminho clicável.
  function alertaMetaIA(evt, destinos) {
    const x = evt.ctx, ids = destinos.map(p => (USUARIOS.find(u => u.id === p || norm(u.nome) === norm(p)) || { id: p }).id), de = evt.de || meuId();
    const titulo = x.metaTitulo || String(evt.texto || '').split(' · ')[0], prazo = (String(evt.texto || '').match(/prazo\s+(\d{2}\/\d{2}\/\d{4})/) || [])[1];
    const caminho = [['Expediente', 'bi-briefcase'], ['Pasta ' + x.pasta + (x.cliente ? ' · ' + x.cliente : ''), 'bi-folder2-open'], ['Metas', 'bi-bullseye'], [titulo, 'bi-flag']];
    const nomes = ids.map(i => usuario(i).nome.split(' ')[0]);
    const alvo = [...new Set(ids.concat([de]))];
    alvo.forEach((uid, k) => {
      const souResp = ids.includes(uid), autor = usuario(de).nome.split(' ')[0];
      const texto = souResp ? (uid === de ? 'Nova meta criada por você e atribuída a você.' : autor + ' abriu uma nova meta para você.') : 'Meta criada e enviada para ' + nomes.join(', ') + '.';
      db.msgs.push({ id: 'm' + Date.now() + 'meta' + k, de: 'ia', para: 'ia', iaPar: [uid, 'ia'], texto, ia: true, resumo: true, quando: Date.now() + k, lida: false, lidaPor: [],
        iaDados: { modulos: ['Metas'], alertaMeta: true, caminho, pasta: x.pasta, meta: x.meta, url: x.url,
          pontos: [{ nivel: 'atencao', texto: '"' + titulo + '"' + (prazo ? ' · prazo ' + prazo : '') + (souResp ? '' : ' · responsáveis: ' + nomes.join(', ')), confianca: 'confirmado' }],
          acoes: [{ tipo: 'abrir_meta', rotulo: 'Abrir meta', pasta: x.pasta, meta: x.meta, url: x.url }] } });
    });
    salvar();
  }
  function mencoes(texto) {
    const achados = []; const t = String(texto || '');
    USUARIOS.forEach(u => { if (t.toLowerCase().includes('@' + u.nome.toLowerCase())) achados.push(u); });
    return achados;
  }
  window.addEventListener('bj-evento', e => notificar(e.detail || {}, e.detail && e.detail.opts));
  window.addEventListener('storage', e => { if (e.key === KEY) { const antes = naoLidas().map(n => n.id); db = ler(KEY, db); const novas = naoLidas().filter(n => !antes.includes(n.id)); if (novas.length) { ui.pulso = true; if (novas.some(n => n.tipo === 'toque')) { tocarToque(); if (prefs.toques && !silencio()) setTimeout(tremer, 30); } else tocar(); } render(); } });
  if (window.BLUEJUS_WS_URL) {
    const conectar = () => { try { const ws = new WebSocket(window.BLUEJUS_WS_URL); window.__bjWS = ws; ws.onmessage = m => { try { const d = JSON.parse(m.data); if (d.tipo === 'digitando') { digServidor[d.de] = { cid: d.conv, ate: Date.now() + 4000 }; atualizarDigitando(); return; } if (d.tipo === 'presenca' && Array.isArray(d.online)) { onlineServidor = d.online; atualizarOnline(); render(); } else notificar(d); } catch (e) {} }; ws.onclose = () => setTimeout(conectar, 5000); } catch (e) {} };
    conectar();
  }

  /* ---------- Presença (on-line) ----------
     Fonte real: window.BLUEJUS_ONLINE = [ids] ou evento WebSocket { tipo: 'presenca', online: [ids] }.
     Sem servidor: cada aba aberta registra o usuário em localStorage a cada 20s (expira em 60s). */
  const PRES = 'bj-presenca';
  let onlineServidor = Array.isArray(window.BLUEJUS_ONLINE) ? window.BLUEJUS_ONLINE.slice() : null;
  // Demonstração do protótipo: alguns colegas aparecem on-line. No sistema real, remover (o servidor envia a lista).
  const DEMO_ONLINE = onlineServidor ? [] : USUARIOS.filter(u => u.id !== meuId()).slice(0, 3).map(u => u.id);
  // Presença com status: { id: { t, st, desde } }. Sistema real: servidor envia { tipo:'presenca', usuarios:[{id, status, ultimo}] }.
  const presT = v => typeof v === 'number' ? v : (v && v.t) || 0;
  function batimento() { const p = ler(PRES, {}), ant = p[meuId()], st = silencio() ? 'ocupado' : prefs.status || 'disponivel'; p[meuId()] = { t: Date.now(), st, desde: ant && ant.st === st && ant.desde ? ant.desde : Date.now() }; const vist = ler(PRES + '-visto', {}); vist[meuId()] = Date.now(); Object.keys(p).forEach(k => { if (Date.now() - presT(p[k]) > 60000) delete p[k]; }); try { localStorage.setItem(PRES, JSON.stringify(p)); localStorage.setItem(PRES + '-visto', JSON.stringify(vist)); } catch (e) {} window.dispatchEvent(new CustomEvent('bj-presenca')); }
  function onlineIds() { if (onlineServidor) return Array.from(new Set(onlineServidor.concat([meuId()]))); const p = ler(PRES, {}); return Array.from(new Set(Object.keys(p).filter(k => Date.now() - presT(p[k]) < 60000).concat(DEMO_ONLINE))); }
  const DEMO_ST = ['disponivel', 'ausente', 'ocupado'];
  function presencaDe(ref) {
    const u = USUARIOS.find(x => x.id === ref || norm(x.nome) === norm(ref || '')) || null; if (!u) return { conhecido: false, online: false, status: 'offline', rot: 'Status indisponível', cor: '#b7c4cd' };
    const p = ler(PRES, {})[u.id], on = estaOnline(u.id), visto = ler(PRES + '-visto', {})[u.id];
    let st = on ? (u.id === meuId() ? (silencio() ? 'ocupado' : prefs.status || 'disponivel') : (p && p.st) || DEMO_ST[Math.max(0, DEMO_ONLINE.indexOf(u.id)) % 3]) : 'offline';
    const rot = st === 'offline' ? 'Off-line' : STATUS[st][0], cor = st === 'offline' ? '#b7c4cd' : STATUS[st][1];
    const hm = t => { const d = new Date(t), h = new Date(); return (d.toDateString() === h.toDateString() ? 'hoje' : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })) + ' às ' + d.toTimeString().slice(0, 5); };
    const det = st === 'offline' ? (visto ? 'visto por último ' + hm(visto) : 'sem atividade recente') : p && p.desde ? 'desde ' + new Date(p.desde).toTimeString().slice(0, 5) : 'on-line agora';
    return { conhecido: true, id: u.id, nome: u.nome, online: on, status: st, rot, cor, det, dica: u.nome + ' · ' + rot + ' · ' + det };
  }
  const estaOnline = id => onlineIds().includes(id);
  batimento(); setInterval(() => { if (prefs.dnd && Date.now() >= prefs.dnd) { prefs.dnd = 0; salvarPrefs(); render(); }  batimento(); if (ui.aberto) atualizarOnline(); }, 20000);
  window.addEventListener('storage', e => { if (e.key === PRES && ui.aberto) atualizarOnline(); });
  function atualizarOnline() { const n = raiz.querySelector('[data-bjc-online]'); if (!n) return; const ids = onlineIds(); n.lastChild.textContent = ids.length + ' on-line'; n.title = 'On-line agora: ' + ids.map(i => (USUARIOS.find(u => u.id === i) || { nome: i }).nome).join(', '); }

  /* ---------- Conversas (individuais e grupos) ---------- */
  const IA = { id: 'ia', nome: 'BlueJus IA', ia: true };
  const isG = id => String(id || '').startsWith('g_');
  const grupo = id => db.grupos.find(g => g.id === id);
  const usuario = id => id === 'ia' ? IA : (USUARIOS.find(u => u.id === id) || { id, nome: id });
  function convPref(cid) { const k = meuId() + ':' + cid; return db.convPrefs[k] = db.convPrefs[k] || {}; }
  function convInfo(cid) {
    if (cid === 'ia') return { id: 'ia', nome: 'BlueJus IA', ia: true, grupo: false, membros: [meuId(), 'ia'], ctx: null, setor: 'Assistente', funcao: 'inteligência do BlueJus' };
    if (isG(cid)) { const g = grupo(cid); if (!g) return null; return { id: g.id, nome: g.nome, grupo: true, foto: g.foto || null, membros: g.membros, ctx: g.ctx || null, dono: g.dono }; }
    const u = USUARIOS.find(x => x.id === cid); if (!u) return null;
    return { id: u.id, nome: u.nome, foto: u.foto, setor: u.setor, funcao: u.funcao, grupo: false, membros: [meuId(), u.id], ctx: null, u };
  }
  function convMsgs(cid) { const ex = (db.convPrefs[meuId() + ':' + cid] || {}).excluidaEm || 0; return convMsgsTodas(cid).filter(m => m.quando > ex); }
  function convMsgsTodas(cid) { const eu = meuId(); return isG(cid) ? db.msgs.filter(m => m.para === cid) : db.msgs.filter(m => !isG(m.para) && ((m.de === eu && m.para === cid) || (m.de === cid && m.para === eu) || (m.de === 'ia' && m.iaPar && m.iaPar.includes(eu) && m.iaPar.includes(cid)))); }
  const lidaPorMim = m => m.de === meuId() || (isG(m.para) ? (m.lidaPor || []).includes(meuId()) : m.lida);
  function conversasLista(todas) {
    const eu = meuId(), ids = new Set();
    db.msgs.forEach(m => { if (isG(m.para) || m.de === 'ia') return; if (m.de === eu) ids.add(m.para); else if (m.para === eu) ids.add(m.de); });
    db.grupos.forEach(g => { if (g.membros.includes(eu)) ids.add(g.id); });
    ids.delete('ia'); ids.add('ia');
    const out = [];
    ids.forEach(id => {
      const info = convInfo(id) || { id, nome: id, membros: [eu, id] }, pref = convPref(id), msgs = convMsgs(id), ult = msgs[msgs.length - 1];
      if (pref.oculta && msgs.some(m => m.quando > (pref.ocultaEm || 0) && m.de !== eu) && !pref.excluidaEm) pref.oculta = false;
      if (pref.excluidaEm && msgs.length) pref.oculta = false;
      if (!todas && (pref.oculta || (pref.arq && !ui.verArq))) return;
      const nl = msgs.filter(m => m.de !== eu && !m.excl && !lidaPorMim(m)).length + (pref.naoLida ? 1 : 0);
      out.push({ id, u: info, info, pref, msgs, ult: ult || { texto: info.grupo ? 'Grupo criado' : '', quando: (grupo(id) || {}).criado || 0, de: '' }, q: ult ? ult.quando : ((grupo(id) || {}).criado || 0), nl, fix: id === 'ia' ? pref.fix !== false : !!pref.fix });
    });
    return out.sort((a, b) => (b.fix - a.fix) || (b.q - a.q));
  }
  /* ---------- "Digitando..." (localStorage entre abas; servidor: evento { tipo: 'digitando', de, conv }) ---------- */
  const DIG = 'bj-digitando'; let ultDig = 0;
  function avisarDigitando(cid) { if (Date.now() - ultDig < 1500) return; ultDig = Date.now(); const d = ler(DIG, {}); d[meuId()] = { cid, ate: Date.now() + 4000 }; try { localStorage.setItem(DIG, JSON.stringify(d)); } catch (e) {} if (window.__bjWS && window.__bjWS.readyState === 1) window.__bjWS.send(JSON.stringify({ tipo: 'digitando', de: meuId(), conv: cid })); }
  function pararDigitando() { const d = ler(DIG, {}); if (d[meuId()]) { delete d[meuId()]; try { localStorage.setItem(DIG, JSON.stringify(d)); } catch (e) {} } ultDig = 0; }
  let digServidor = {};
  function quemDigita(cid) { const d = Object.assign({}, ler(DIG, {}), digServidor), eu = meuId(); return Object.keys(d).filter(u => u !== eu && d[u].ate > Date.now() && (isG(cid) ? d[u].cid === cid : (u === cid && d[u].cid === eu))); }
  function atualizarDigitando() {
    const n = raiz.querySelector('[data-bjc-digit]'); if (!n) return;
    const q = quemDigita(n.getAttribute('data-bjc-digit')).map(id => usuario(id).nome.split(' ')[0]);
    n.innerHTML = ''; n.style.opacity = q.length ? '1' : '0';
    if (!q.length) return;
    n.appendChild(el('span', 'display:inline-flex;gap:3px;align-items:center', {}, [0, .18, .36].map(d => el('span', 'width:5px;height:5px;border-radius:50%;background:#7b93a2;animation:bjcPonto 1.2s ease-in-out ' + d + 's infinite', { 'data-bjc-anim': '1' }))));
    n.appendChild(el('span', '', { text: (q.length > 2 ? q.slice(0, 2).join(', ') + ' e mais ' + (q.length - 2) : q.join(' e ')) + (q.length > 1 ? ' estão digitando' : ' está digitando') }));
  }
  window.addEventListener('storage', e => { if (e.key === DIG) atualizarDigitando(); });
  setInterval(atualizarDigitando, 1500);
  function criarGrupo(nome, membros, ctx) {
    const g = { id: 'g_' + Date.now().toString(36), nome: nome || 'Novo grupo', membros: Array.from(new Set([meuId()].concat(membros))), ctx: ctx || null, dono: meuId(), criado: Date.now() };
    db.grupos.push(g); salvar(); return g;
  }
  function sistemaGrupo(g, texto) { db.msgs.push({ id: 'm' + Date.now() + 's', de: 'sistema', para: g.id, texto, sistema: true, quando: Date.now(), lida: true, lidaPor: g.membros.slice() }); salvar(); }
  function escolherFotoGrupo(g) {
    const fi = el('input', 'display:none', { type: 'file', accept: 'image/*' });
    fi.addEventListener('change', () => {
      const f = fi.files && fi.files[0]; if (!f) return;
      if (!/^image\//.test(f.type)) { aviso('Escolha um arquivo de imagem.'); return; }
      const r = new FileReader();
      r.onload = () => { const img = new Image(); img.onload = () => { const S = 160, cv = document.createElement('canvas'); cv.width = cv.height = S; const k = Math.max(S / img.width, S / img.height), w = img.width * k, h = img.height * k; cv.getContext('2d').drawImage(img, (S - w) / 2, (S - h) / 2, w, h); g.foto = cv.toDataURL('image/jpeg', .85); salvar(); sistemaGrupo(g, EU().nome.split(' ')[0] + ' alterou a foto do grupo'); aviso('Foto do grupo atualizada.'); render(); }; img.src = r.result; };
      r.readAsDataURL(f);
    });
    document.body.appendChild(fi); fi.click(); setTimeout(() => fi.remove(), 60000);
  }
  function grupoTodos() {
    let g = db.grupos.find(x => x.todos);
    const todos = USUARIOS.map(u => u.id);
    if (!g) { g = { id: 'g_todos', nome: 'Todos · Equipe', todos: true, membros: todos, ctx: null, dono: MASTER_ID ? MASTER_ID() : meuId(), criado: Date.now() }; db.grupos.push(g); }
    else g.membros = Array.from(new Set(g.membros.concat(todos.filter(id => !(g.saiu || []).includes(id)))));
    salvar(); return g;
  }
  function garantirGrupoPasta() {
    const ctx = window.__bjContexto ? window.__bjContexto() : null; if (!ctx || !ctx.pasta) return;
    if (db.grupos.some(g => g.ctx && String(g.ctx.pasta) === String(ctx.pasta))) return;
    const membros = USUARIOS.filter(u => u.id !== meuId()).slice(0, 3).map(u => u.id);
    criarGrupo('Equipe Pasta ' + ctx.pasta, membros, { pasta: ctx.pasta, cliente: ctx.cliente, url: ctx.url });
  }
  function mencoes(texto, membros) {
    const t = String(texto || '').toLowerCase(), r = { users: [], todos: /@todos\b/.test(t), ia: /@ia\b/.test(t) };
    USUARIOS.forEach(u => { if (t.includes('@' + u.nome.toLowerCase())) r.users.push(u.id); });
    if (r.todos) r.users = r.users.concat(membros || []);
    r.users = Array.from(new Set(r.users)).filter(id => id !== meuId() && (!membros || membros.includes(id)));
    return r;
  }
  function enviar(cid, texto, ctx, extra) {
    ui.enviouAgora = true;
    const eu = EU(), info = convInfo(cid) || { id: cid, membros: [eu.id, cid], grupo: false };
    const m = { id: 'm' + Date.now() + Math.random().toString(36).slice(2, 5), de: eu.id, para: cid, texto, quando: Date.now(), lida: false, lidaPor: [eu.id], ctx: ctx || info.ctx || null, ...(extra || {}) };
    db.msgs.push(m); convPref(cid).arq = false; convPref(cid).oculta = false; salvar();
    const men = mencoes(texto, info.membros), conv = { id: cid, msg: m.id }, onde = info.grupo ? ' em ' + info.nome : '';
    men.users.forEach(id => notificar({ tipo: 'mencao', para: id, de: eu.id, titulo: eu.nome.split(' ')[0] + (men.todos ? ' mencionou todos' : ' mencionou você') + onde + ' no Chat I.A', texto, conv }));
    info.membros.filter(id => id !== eu.id && id !== 'ia' && !men.users.includes(id)).forEach(id => notificar({ tipo: 'mensagem', para: id, de: eu.id, titulo: eu.nome + (info.grupo ? ' em ' + info.nome : ' enviou uma mensagem'), texto, conv: { id: info.grupo ? cid : eu.id, msg: m.id } }, { silencioso: true }));
    if (men.ia || cid === 'ia') responderIA(cid, m);
    else if (DECL.test(norm(texto)) && provsAbertas(eu.id).length) setTimeout(() => { const r = provDeclaracao(eu.id, texto, ctx || info.ctx); if (r) { db.msgs.push({ id: 'm' + Date.now() + 'cf', de: 'ia', para: 'ia', iaPar: [eu.id, 'ia'], texto: semTraco(r.resposta), iaDados: r, quando: Date.now(), lida: false, lidaPor: [], ia: true }); salvar(); aviso(r.curto); render(); } }, 600);
    render();
    return m;
  }
  /* ================= @IA · BlueJus IA =================
     Camadas: SEGURANÇA (permissões + classificação, antes do contexto) → CONTEXTO (tela + memória da conversa)
     → ORQUESTRADOR (escolhe módulos) → MOTOR (window.claude.complete ou endpoint BLUEJUS_IA_URL) → RESPOSTA estruturada → AÇÕES com confirmação.
     No sistema real, o filtro de acesso é feito no servidor (GET /api/ia/contexto já devolve só dados autorizados); aqui ele é repetido no cliente. */
  const SUGESTOES_IA = ['Tem alguma pendência?', 'Quais metas estão atrasadas?', 'Existe algum prazo?', 'Faça um resumo desta pasta'];
  // Comandos da @IA (digite / na conversa com a IA). eq = usa dados da equipe (gestores e Master); para os demais, a IA recebe só os dados da própria pessoa.
  const COMANDOS_IA = [
    { c: 'nucleo', g: 'Núcleo Cognitivo', ic: 'bi-cpu', t: 'Núcleo Cognitivo', d: 'Estado da inteligência, crenças, aprendizados e erros', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'regras', g: 'Núcleo Cognitivo', ic: 'bi-sliders', t: 'Regras e prompts', d: 'Banco de regras cognitivas e prompts por módulo, versionados', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'aprendizados', g: 'Núcleo Cognitivo', ic: 'bi-mortarboard', t: 'Aprendizados', d: 'Padrões recorrentes para aprovar, manter como preferência ou ignorar', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'kg', g: 'Grafo', ic: 'bi-share', t: 'Grafo de conhecimento', d: 'Entidades, relações descobertas e históricas', eq: 0, m: ['Grafo'], p: '' },
    { c: 'porque', g: 'Grafo', ic: 'bi-question-circle', t: 'Por que está em risco', d: 'Cadeias multi-salto com evidência contrária', eq: 0, m: ['Grafo'], p: '' },
    { c: 'cadeia', g: 'Grafo', ic: 'bi-link-45deg', t: 'Mostre a cadeia', d: 'Eventos antes → depois e cadeia causal provável', eq: 0, m: ['Grafo'], p: '' },
    { c: 'afeta', g: 'Grafo', ic: 'bi-bullseye', t: 'O que isso afeta', d: 'Tudo que depende de um elemento', eq: 0, m: ['Grafo'], p: 'elemento' },
    { c: 'causacomum', g: 'Grafo', ic: 'bi-diagram-2', t: 'Causa comum', d: 'Sintomas que vêm da mesma raiz', eq: 0, m: ['Grafo'], p: '' },
    { c: 'evidencias', g: 'Grafo', ic: 'bi-file-earmark-check', t: 'Outra explicação?', d: 'Interpretações com evidência a favor e contra', eq: 0, m: ['Grafo'], p: '' },
    { c: 'contrafactual', g: 'Grafo', ic: 'bi-arrow-left-right', t: 'E se não tivesse acontecido', d: 'Com × sem intervenção (estimativa)', eq: 0, m: ['Grafo'], p: '' },
    { c: 'cascata', g: 'Grafo', ic: 'bi-water', t: 'Efeito em cascata', d: 'Risco e oportunidade em cadeia', eq: 0, m: ['Grafo'], p: '' },
    { c: 'ciclos', g: 'Grafo', ic: 'bi-arrow-repeat', t: 'Dependências circulares', d: 'A espera B que espera A', eq: 0, m: ['Grafo'], p: '' },
    { c: 'contradicoes', g: 'Grafo', ic: 'bi-x-diamond', t: 'Contradições', d: 'Registros, prazos e previsões que não batem', eq: 0, m: ['Grafo'], p: '' },
    { c: 'grafosaude', g: 'Grafo', ic: 'bi-clipboard-pulse', t: 'Saúde do grafo', d: 'Órfãs, duplicadas, ciclos, baixa confiança e versões', eq: 1, m: ['Grafo'], p: '' },
    { c: 'portfolio', g: 'Oportunidades', ic: 'bi-gem', t: 'Melhores oportunidades', d: 'Portfólio com score, ganhos rápidos, fila pela capacidade', eq: 1, m: ['Oportunidades'], p: '' },
    { c: 'op', g: 'Oportunidades', ic: 'bi-lightbulb', t: 'Detalhe da oportunidade', d: 'Evidência, janela, escala e próximos passos', eq: 1, m: ['Oportunidades'], p: 'OP-001' },
    { c: 'automatizar', g: 'Oportunidades', ic: 'bi-robot', t: 'O que pode ser automatizado', d: 'Classificação de automação com motivo', eq: 1, m: ['Oportunidades'], p: '' },
    { c: 'opmemoria', g: 'Oportunidades', ic: 'bi-archive', t: 'Memória de oportunidades', d: 'Aceitas, adiadas, testadas e o que aconteceu depois', eq: 1, m: ['Oportunidades'], p: '' },
    { c: 'sefossevoce', g: 'Oportunidades', ic: 'bi-person-workspace', t: 'Se fosse você?', d: 'O que a IA faria administrando hoje, com confiança', eq: 1, m: ['Oportunidades'], p: '' },
    { c: 'efetividade', g: 'Resultados', ic: 'bi-check2-all', t: 'Isso funcionou?', d: 'Antes × intervenção × depois, efetividade e causalidade', eq: 1, m: ['Resultados'], p: '' },
    { c: 'estrategias', g: 'Resultados', ic: 'bi-bar-chart-line', t: 'Estratégias que funcionam', d: 'Taxa de sucesso, degradação e contexto', eq: 1, m: ['Resultados'], p: '' },
    { c: 'ab', g: 'Resultados', ic: 'bi-layout-split', t: 'Comparar A × B', d: 'Duas estratégias com resultado real', eq: 1, m: ['Resultados'], p: 'A | B' },
    { c: 'startstop', g: 'Resultados', ic: 'bi-sign-turn-right', t: 'Start, stop, continue', d: 'O que começar, parar, manter, mudar e testar', eq: 1, m: ['Resultados'], p: '' },
    { c: 'trajetoria', g: 'Resultados', ic: 'bi-arrow-up-right-circle', t: 'Se eu continuar assim', d: 'Para onde a situação vai e a mudança de maior efeito', eq: 0, m: ['Resultados'], p: '' },
    { c: 'integridade', g: 'Resultados', ic: 'bi-patch-exclamation', t: 'Integridade das métricas', d: 'Quantidade subindo sem qualidade ou resultado', eq: 1, m: ['Resultados'], p: '' },
    { c: 'surpresas', g: 'Resultados', ic: 'bi-lightning', t: 'Resultados surpreendentes', d: 'Muito melhor ou pior que o previsto', eq: 1, m: ['Resultados'], p: '' },
    { c: 'experimento', g: 'Resultados', ic: 'bi-eyedropper', t: 'Novo experimento', d: 'Hipótese, baseline, duração e métrica', eq: 1, m: ['Resultados'], p: 'hipótese em N dias' },
    { c: 'experimentos', g: 'Resultados', ic: 'bi-clipboard-data', t: 'Experimentos', d: 'Em andamento e conclusões', eq: 1, m: ['Resultados'], p: '' },
    { c: 'organismo', g: 'Organização', ic: 'bi-diagram-3', t: 'Gêmeo organizacional', d: 'Capacidade real, falhas únicas, carga cognitiva e processo', eq: 1, m: ['Organização'], p: '' },
    { c: 'segurando', g: 'Organização', ic: 'bi-anchor', t: 'O que está segurando o escritório', d: '5 fatores com evidência, causa e solução', eq: 1, m: ['Organização'], p: '' },
    { c: 'umacoisa', g: 'Organização', ic: 'bi-1-circle', t: 'Uma mudança de maior impacto', d: 'A intervenção que mais destrava, com efeito dominó', eq: 1, m: ['Organização'], p: '' },
    { c: 'pontoscegos', g: 'Organização', ic: 'bi-eye-slash-fill', t: 'O que o escritório não percebe', d: 'Riscos sem métrica, informal, concentrado', eq: 1, m: ['Organização'], p: '' },
    { c: 'spof', g: 'Organização', ic: 'bi-exclamation-diamond-fill', t: 'Ponto único de falha', d: 'Pessoas, processos e sistemas sem backup', eq: 1, m: ['Organização'], p: '' },
    { c: 'cargacognitiva', g: 'Organização', ic: 'bi-cpu-fill', t: 'Carga cognitiva', d: 'Complexidade e troca de contexto por pessoa', eq: 1, m: ['Organização'], p: '' },
    { c: 'fluxoreal', g: 'Organização', ic: 'bi-bezier', t: 'Fluxo ideal × real', d: 'Etapas ausentes, extras e gargalo móvel', eq: 1, m: ['Organização'], p: '' },
    { c: 'quebra', g: 'Organização', ic: 'bi-tools', t: 'Processos quebrados', d: 'Sem dono, sem prazo, informal, retrabalho', eq: 1, m: ['Organização'], p: '' },
    { c: 'resiliencia', g: 'Organização', ic: 'bi-shield-plus', t: 'Resiliência', d: 'O que quebra primeiro se alguém faltar', eq: 1, m: ['Organização'], p: '' },
    { c: 'diagnostico', g: 'Organização', ic: 'bi-question-octagon', t: 'Problema de quê?', d: 'Pessoa, tarefa, processo, regra, capacidade, informação, tecnologia ou estrutura', eq: 1, m: ['Organização'], p: 'problema' },
    { c: 'executar', g: 'Execução', ic: 'bi-play-fill', t: 'Execute o plano', d: 'Faz o que é interno e de baixo risco, prepara o resto e pede confirmação', eq: 0, m: ['Execução'], p: '' },
    { c: 'execucao', g: 'Execução', ic: 'bi-diagram-2-fill', t: 'O que está sendo executado', d: 'Etapas verificadas, desvio, caminho crítico e previsão de término', eq: 0, m: ['Execução'], p: '' },
    { c: 'parado', g: 'Execução', ic: 'bi-pause-btn', t: 'O que está parado', d: 'Tempo parado, responsável, motivo, impacto e próxima ação', eq: 0, m: ['Execução'], p: '' },
    { c: 'destravar', g: 'Execução', ic: 'bi-unlock', t: 'Destrave o que puder', d: 'Resolve, prepara, pede informação, sugere substituto e escala', eq: 0, m: ['Execução'], p: '' },
    { c: 'dependemim', g: 'Execução', ic: 'bi-person-check', t: 'O que depende de mim', d: 'Etapas e bloqueios com você', eq: 0, m: ['Execução'], p: '' },
    { c: 'naoexecutado', g: 'Execução', ic: 'bi-x-octagon', t: 'Decidido e não executado', d: 'Decisões sem plano e falsas conclusões', eq: 0, m: ['Execução'], p: '' },
    { c: 'followup', g: 'Execução', ic: 'bi-arrow-repeat', t: 'Acompanhamentos', d: 'O que está dentro ou fora do tempo normal de resposta', eq: 0, m: ['Execução'], p: '' },
    { c: 'resultados', g: 'Execução', ic: 'bi-trophy', t: 'Resultados obtidos', d: 'Previsto × real em planos e decisões', eq: 0, m: ['Execução'], p: '' },
    { c: 'decidir', g: 'Decisão', ic: 'bi-signpost-split-fill', t: 'Me ajude a decidir', d: 'Alternativas, matriz, cenários, contra-argumentos e recomendação', eq: 0, m: ['Decisão'], p: 'problema e objetivo' },
    { c: 'missing', g: 'Decisão', ic: 'bi-eye-slash', t: 'O que não estou vendo?', d: 'Pontos cegos, argumentos contra e precedentes', eq: 0, m: ['Decisão'], p: 'ideia ou decisão' },
    { c: 'executivo', g: 'Decisão', ic: 'bi-briefcase-fill', t: 'Situação executiva hoje', d: 'Bem, piorando, exige decisão, pode esperar, subestimado', eq: 1, m: ['Decisão'], p: '' },
    { c: 'semanal', g: 'Decisão', ic: 'bi-calendar-week', t: 'Inteligência semanal', d: 'Semana anterior × atual', eq: 1, m: ['Decisão'], p: '' },
    { c: 'mensal', g: 'Decisão', ic: 'bi-calendar-month', t: 'Revisão estratégica mensal', d: 'O que mudou e o que mudar no próximo mês', eq: 1, m: ['Decisão'], p: '' },
    { c: 'pendentes', g: 'Decisão', ic: 'bi-sign-stop', t: 'Decisões travando o trabalho', d: 'O que espera decisão, de quem e há quanto tempo', eq: 1, m: ['Decisão'], p: '' },
    { c: 'oportunidades', g: 'Decisão', ic: 'bi-lightbulb-fill', t: 'Oportunidades', d: 'Capacidade ociosa, automação, especialização, recursos não usados', eq: 1, m: ['Decisão'], p: '' },
    { c: 'decisoes', g: 'Decisão', ic: 'bi-archive-fill', t: 'Memória de decisões', d: 'Decisão × expectativa × resultado', eq: 1, m: ['Decisão'], p: '' },
    { c: 'radar', g: 'Previsão', ic: 'bi-radar', t: 'O que vai acontecer', d: 'Riscos dos próximos 7 dias, cascata e o que resolver hoje', eq: 0, m: ['Previsão'], p: '' },
    { c: 'riscos', g: 'Previsão', ic: 'bi-map', t: 'Mapa de risco futuro', d: 'Probabilidade, causa e prevenção; filtre por nome ou gravidade', eq: 0, m: ['Previsão'], p: 'filtro' },
    { c: 'previsao', g: 'Previsão', ic: 'bi-graph-down', t: 'Previsão de uma meta', d: 'Chance de atraso, motivo e prevenção', eq: 0, m: ['Previsão'], p: 'nome da meta' },
    { c: 'prioridadefutura', g: 'Previsão', ic: 'bi-hourglass-top', t: 'Vai virar urgente', d: 'O que não é urgente hoje, mas fica se não for tratado', eq: 0, m: ['Previsão'], p: '' },
    { c: 'acumulo', g: 'Previsão', ic: 'bi-stack', t: 'Acúmulo e sobrecarga', d: 'Entrada × saída, capacidade e gargalo provável', eq: 0, m: ['Previsão'], p: '' },
    { c: 'calibracao', g: 'Previsão', ic: 'bi-bullseye', t: 'Calibração das previsões', d: 'Quanto a IA acerta e onde errou', eq: 1, m: ['Previsão'], p: '' },
    { c: 'equipe', g: 'Equipe', ic: 'bi-people-fill', t: 'O que saber sobre a equipe hoje', d: 'Situação, riscos, gargalos, sobrecarga, capacidade e ações', eq: 1, m: ['Equipe'], p: '' },
    { c: 'atencao', g: 'Equipe', ic: 'bi-exclamation-triangle', t: 'O que precisa da minha atenção', d: 'Crítico, alto, médio, baixo, monitorar', eq: 1, m: ['Equipe'], p: '' },
    { c: 'capacidade', g: 'Equipe', ic: 'bi-battery-half', t: 'Mapa de capacidade', d: 'Carga crítica, alta, controlada ou disponível', eq: 1, m: ['Equipe'], p: '' },
    { c: 'competencias', g: 'Equipe', ic: 'bi-grid-3x3', t: 'Matriz de competências', d: 'Pessoa × domínio, por evidência de uso', eq: 1, m: ['Equipe'], p: '' },
    { c: 'atribuir', g: 'Equipe', ic: 'bi-person-plus', t: 'Sugerir responsável', d: 'Por experiência e carga, sem atribuir sozinho', eq: 1, m: ['Equipe'], p: 'descrição da demanda' },
    { c: 'indisponivel', g: 'Equipe', ic: 'bi-person-dash', t: 'Se alguém ficar indisponível', d: 'Afetados, substitutos e plano de continuidade', eq: 1, m: ['Equipe'], p: 'nome' },
    { c: 'colaboracao', g: 'Equipe', ic: 'bi-share-fill', t: 'Colaboração e retrabalho', d: 'Quem fala com quem e onde há refação', eq: 1, m: ['Equipe'], p: '' },
    { c: 'briefing', g: 'Equipe', ic: 'bi-easel', t: 'Briefing de reunião', d: 'Problemas, gargalos, decisões pendentes e pontos positivos', eq: 1, m: ['Equipe'], p: '' },
    { c: 'checkin', g: 'Equipe', ic: 'bi-chat-heart', t: 'Check-in com colaborador', d: 'Pergunta gentil sobre impedimentos (1 por semana)', eq: 1, m: ['Equipe'], p: 'nome' },
    { c: 'saudeequipe', g: 'Equipe', ic: 'bi-heart-pulse-fill', t: 'Saúde operacional da equipe', d: 'Índice do sistema, não avaliação de pessoas', eq: 1, m: ['Equipe'], p: '' },
    { c: 'eu', g: 'Equipe', ic: 'bi-person-badge', t: 'Minha carga', d: 'Sua carga, capacidade e estilo de trabalho', eq: 0, m: ['Equipe'], p: '' },
    { c: 'controle', g: 'Governança cognitiva', ic: 'bi-bank', t: 'Centro de controle cognitivo', d: 'Estado do cérebro, alertas, mudanças, bloqueio e rollback (Master)', eq: 1, m: ['Governança cognitiva'], p: '' },
    { c: 'constituicao', g: 'Governança cognitiva', ic: 'bi-journal-bookmark', t: 'Constituição Cognitiva', d: 'Princípios, precedência e ciclo do conhecimento', eq: 0, m: ['Governança cognitiva'], p: '' },
    { c: 'propostas', g: 'Governança cognitiva', ic: 'bi-inboxes', t: 'Propostas de mudança', d: 'O que a IA quer mudar, com conselho e sandbox', eq: 1, m: ['Governança cognitiva'], p: '' },
    { c: 'proposta', g: 'Governança cognitiva', ic: 'bi-file-earmark-diff', t: 'Detalhe da proposta', d: 'Conselho de 10 perspectivas, testar, aprovar ou rejeitar', eq: 1, m: ['Governança cognitiva'], p: 'CCP-001' },
    { c: 'registro', g: 'Governança cognitiva', ic: 'bi-collection', t: 'Registro cognitivo', d: 'Regras, prompts, competências, memórias e padrões versionados', eq: 1, m: ['Governança cognitiva'], p: '' },
    { c: 'indice', g: 'Governança cognitiva', ic: 'bi-speedometer2', t: 'Desempenho da IA', d: 'Precisão, correções, previsões, onde é forte e onde erra', eq: 1, m: ['Governança cognitiva'], p: '' },
    { c: 'aprendihoje', g: 'Governança cognitiva', ic: 'bi-sunrise', t: 'O que a IA aprendeu hoje', d: 'Por quê, de onde, confiança e onde vale', eq: 0, m: ['Governança cognitiva'], p: '' },
    { c: 'cerebro', g: 'Governança cognitiva', ic: 'bi-diagram-3', t: 'O que mudou no cérebro', d: 'Ontem, semana ou mês × hoje', eq: 1, m: ['Governança cognitiva'], p: 'semana | mes' },
    { c: 'healthcheck', g: 'Governança cognitiva', ic: 'bi-heart', t: 'Health check cognitivo', d: 'Conflitos, obsoletos, degradação, regras vencidas', eq: 1, m: ['Governança cognitiva'], p: '' },
    { c: 'pesquisar', g: 'Pesquisa', ic: 'bi-search', t: 'Pesquisar a fundo', d: 'Hipóteses, evidências por camada, contradições e lacunas', eq: 0, m: ['Pesquisa'], p: 'pergunta' },
    { c: 'pesquisas', g: 'Pesquisa', ic: 'bi-journal-richtext', t: 'Pesquisas feitas', d: 'Como a IA chegou a cada resposta', eq: 0, m: ['Pesquisa'], p: '' },
    { c: 'monitorar', g: 'Pesquisa', ic: 'bi-eye', t: 'Monitorar conhecimento', d: 'Avisa quando regras, PJe, situação ou responsável mudarem', eq: 0, m: ['Pesquisa'], p: 'regras | pje | situacao | responsavel' },
    { c: 'subutilizadas', g: 'Pesquisa', ic: 'bi-box-seam', t: 'Recursos pouco usados', d: 'O que já existe e pode resolver antes de criar algo novo', eq: 0, m: ['Pesquisa'], p: '' },
    { c: 'memorias', g: 'Memória', ic: 'bi-hdd-network', t: 'Memória viva', d: 'Ativas, históricas, confiança, conflitos e mais usadas', eq: 0, m: ['Memória'], p: '' },
    { c: 'reconciliar', g: 'Memória', ic: 'bi-intersect', t: 'Reconciliar memórias', d: 'Resolve conflitos por autoridade, evidência, data e contexto', eq: 1, m: ['Memória'], p: '' },
    { c: 'esquecer', g: 'Memória', ic: 'bi-eraser', t: 'Esquecer memória', d: 'Deixa de usar, mantém no histórico', eq: 1, m: ['Memória'], p: 'id motivo' },
    { c: 'compromissos', g: 'Memória', ic: 'bi-calendar2-check', t: 'Compromissos', d: 'O que foi combinado e quando vence', eq: 0, m: ['Memória'], p: '' },
    { c: 'crenca', g: 'Memória', ic: 'bi-clock', t: 'O que a IA acreditava em…', d: 'Versão da memória numa data', eq: 0, m: ['Memória'], p: 'assunto dd/mm/aaaa' },
    { c: 'seguranca', g: 'Segurança', ic: 'bi-shield-shaded', t: 'Segurança cognitiva', d: 'Bloqueios, vulnerabilidades e red team (Master)', eq: 1, m: ['Segurança cognitiva'], p: '' },
    { c: 'redteam', g: 'Segurança', ic: 'bi-incognito', t: 'Rodar red team', d: 'Ataques simulados: injeção, fuzzing, privilégio, memória, extração, isolamento', eq: 1, m: ['Segurança cognitiva'], p: '' },
    { c: 'hierarquia', g: 'Segurança', ic: 'bi-list-ol', t: 'Hierarquia de autoridade', d: 'Quem pode mudar o quê', eq: 0, m: ['Segurança cognitiva'], p: '' },
    { c: 'breakglass', g: 'Segurança', ic: 'bi-hammer', t: 'Acesso de emergência', d: 'Temporário, justificado, auditado e com expiração', eq: 1, m: ['Segurança cognitiva'], p: 'justificativa 30min' },
    { c: 'cenario', g: 'Simulação', ic: 'bi-sliders', t: 'E se…? (simular mudança)', d: 'Redistribuir, ausência, novas demandas, ritmo, meta parada', eq: 0, m: ['Simulação'], p: 'mudança' },
    { c: 'nadafazer', g: 'Simulação', ic: 'bi-pause-circle', t: 'Se nada for feito', d: 'Projeção em 1, 3, 7, 15 e 30 dias', eq: 0, m: ['Simulação'], p: '' },
    { c: 'cenarios', g: 'Simulação', ic: 'bi-columns-gap', t: 'Comparar cenários', d: 'A | B | C lado a lado, sem vencedor', eq: 0, m: ['Simulação'], p: 'A | B | C' },
    { c: 'objetivo', g: 'Simulação', ic: 'bi-bullseye', t: 'O que precisa para atingir…', d: 'Simulação reversa a partir da meta', eq: 0, m: ['Simulação'], p: 'reduzir X para Y em N dias' },
    { c: 'sensibilidade', g: 'Simulação', ic: 'bi-activity', t: 'Sensibilidade', d: 'Quanto o ritmo muda o resultado', eq: 0, m: ['Simulação'], p: '' },
    { c: 'crise', g: 'Simulação', ic: 'bi-exclamation-octagon', t: 'Simular crise', d: 'Ausência, pico de demandas ou integração fora', eq: 1, m: ['Simulação'], p: 'ausência | pico | integração' },
    { c: 'edge', g: 'Simulação', ic: 'bi-bounding-box', t: 'Casos de borda', d: 'Dados faltando, sem permissão, contradição, integração fora', eq: 1, m: ['Simulação'], p: '' },
    { c: 'promptsandbox', g: 'Simulação', ic: 'bi-terminal', t: 'Prompt sandbox', d: 'Testa instrução nova sem aplicar', eq: 1, m: ['Simulação'], p: 'instrução' },
    { c: 'simulacoes', g: 'Simulação', ic: 'bi-journal-text', t: 'Simulações registradas', d: 'Previsão × realidade', eq: 0, m: ['Simulação'], p: '' },
    { c: 'saude', g: 'Autocorreção', ic: 'bi-heart-pulse', t: 'Saúde do sistema', d: 'Saúde por módulo, incidentes e risco', eq: 0, m: ['Autocorreção'], p: '' },
    { c: 'incidentes', g: 'Autocorreção', ic: 'bi-bug', t: 'Central de incidentes', d: 'Inconsistências, causa provável e estado (Master)', eq: 1, m: ['Autocorreção'], p: '' },
    { c: 'reparar', g: 'Autocorreção', ic: 'bi-wrench-adjustable', t: 'Reparar incidente', d: 'Snapshot, teste, aplicação, validação e rollback', eq: 1, m: ['Autocorreção'], p: 'INC-0001' },
    { c: 'rollback', g: 'Autocorreção', ic: 'bi-skip-backward', t: 'Restaurar ponto de recuperação', d: 'Volta ao último estado salvo', eq: 1, m: ['Autocorreção'], p: '' },
    { c: 'politica', g: 'Autocorreção', ic: 'bi-sliders2', t: 'Política de autocorreção', d: 'O que a IA corrige sozinha e o que é proibido', eq: 1, m: ['Autocorreção'], p: '' },
    { c: 'testerecuperacao', g: 'Autocorreção', ic: 'bi-clipboard2-check', t: 'Testar recuperação', d: 'Verifica se detecção, isolamento e rollback funcionam', eq: 1, m: ['Autocorreção'], p: '' },
    { c: 'coletivo', g: 'Inteligência coletiva', ic: 'bi-people', t: 'Inteligência coletiva', d: 'Padrões gerais, estados, confiança e exceções (sem origem)', eq: 0, m: ['Inteligência coletiva'], p: '' },
    { c: 'gate', g: 'Inteligência coletiva', ic: 'bi-shield-lock-fill', t: 'Privacy Gate', d: 'Mostra se um texto pode virar padrão coletivo', eq: 1, m: ['Inteligência coletiva'], p: 'texto' },
    { c: 'benchmark', g: 'Inteligência coletiva', ic: 'bi-bar-chart', t: 'Comparação anônima', d: 'Esta pasta × padrão agregado, com amostra e limites', eq: 0, m: ['Inteligência coletiva'], p: '' },
    { c: 'privacidade', g: 'Inteligência coletiva', ic: 'bi-incognito', t: 'Uso agregado dos dados', d: 'Participar, não participar ou excluir contribuições da conta', eq: 1, m: ['Inteligência coletiva'], p: '' },
    { c: 'skills', g: 'Competências', ic: 'bi-award', t: 'Competências da IA', d: 'O que a IA sabe fazer, nível, erros e versões', eq: 0, m: ['Competências'], p: '' },
    { c: 'skill', g: 'Competências', ic: 'bi-patch-question', t: 'Detalhe de competência', d: 'Subskills, exceções, uso, versões', eq: 0, m: ['Competências'], p: 'nome' },
    { c: 'rotear', g: 'Competências', ic: 'bi-signpost-2', t: 'Quais competências usar', d: 'Mostra o roteamento para uma pergunta', eq: 0, m: ['Competências'], p: 'pergunta' },
    { c: 'novaskill', g: 'Competências', ic: 'bi-plus-square', t: 'Nova competência', d: 'Cria em rascunho; só ativa após teste e validação', eq: 1, m: ['Competências'], p: 'nome' },
    { c: 'temporal', g: 'Tempo', ic: 'bi-hourglass-split', t: 'Passado, presente e futuro', d: 'A pasta em três tempos', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'linhadotempo', g: 'Tempo', ic: 'bi-clock-history', t: 'Linha do tempo', d: 'Eventos datados, último evento × última providência', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'tendencia', g: 'Tempo', ic: 'bi-graph-up-arrow', t: 'Tendência', d: 'Crescente, estável, oscilante; velocidade e projeção', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'evolucao', g: 'Tempo', ic: 'bi-arrow-repeat', t: 'O que melhorou ou piorou', d: 'Antes × depois por indicador', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'porquemudou', g: 'Tempo', ic: 'bi-question-diamond', t: 'Por que mudou', d: 'Fato, evidência, explicação possível', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'proximo', g: 'Tempo', ic: 'bi-skip-forward', t: 'O que vem depois', d: 'Próxima etapa provável pelo ciclo observado', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'recorrencias', g: 'Tempo', ic: 'bi-arrow-clockwise', t: 'Recorrências', d: 'Erros, movimentações, metas e alertas que se repetem', eq: 0, m: ['Inteligência temporal'], p: '' },
    { c: 'em', g: 'Tempo', ic: 'bi-calendar-event', t: 'Como estava em…', d: 'Máquina do tempo: só o que existia na data', eq: 0, m: ['Inteligência temporal'], p: 'dd/mm/aaaa' },
    { c: 'vigente', g: 'Tempo', ic: 'bi-bookmark-check', t: 'Regras vigentes em…', d: 'Quais regras valiam numa data', eq: 0, m: ['Inteligência temporal'], p: 'dd/mm/aaaa' },
    { c: 'situacao', g: 'Situação', ic: 'bi-broadcast-pin', t: 'O que está acontecendo', d: 'Mapa vivo, estado das pastas, prioridades e carga', eq: 0, m: ['Consciência situacional'], p: '' },
    { c: 'hoje', g: 'Situação', ic: 'bi-calendar-check', t: 'O que olhar hoje', d: 'Agenda cognitiva: o que fazer e o que pode esperar', eq: 0, m: ['Consciência situacional'], p: '' },
    { c: 'naovejo', g: 'Situação', ic: 'bi-eye', t: 'O que não estou vendo?', d: 'Pontos relacionados que costumam passar despercebidos', eq: 0, m: ['Consciência situacional'], p: '' },
    { c: 'gargalo', g: 'Situação', ic: 'bi-funnel', t: 'Gargalo do fluxo', d: 'Entrada até encerramento: onde trava', eq: 0, m: ['Consciência situacional'], p: '' },
    { c: 'mudancas', g: 'Situação', ic: 'bi-arrow-left-right', t: 'O que mudou', d: 'Antes × agora desde a última leitura da pasta', eq: 0, m: ['Gêmeo digital'], p: '' },
    { c: 'orquestrar', g: 'Orquestração', ic: 'bi-diagram-3-fill', t: 'Orquestrar', d: 'Plano com etapas, dependências, caminho crítico e planos B/C', eq: 0, m: ['Orquestração'], p: 'problema' },
    { c: 'planos', g: 'Orquestração', ic: 'bi-kanban', t: 'Acompanhar plano', d: 'Progresso, bloqueios e quem precisa agir', eq: 0, m: ['Orquestração'], p: '' },
    { c: 'redistribuir', g: 'Orquestração', ic: 'bi-arrow-left-right', t: 'Analisar redistribuição', d: 'Carga real (esforço e complexidade), não só quantidade', eq: 1, m: ['Orquestração'], p: '' },
    { c: 'controladoria', g: 'Orquestração', ic: 'bi-clipboard-data', t: 'Modo controladoria', d: 'Problemas por tipo: sistema, processo, informação ou execução', eq: 1, m: ['Orquestração'], p: '' },
    { c: 'gestor', g: 'Orquestração', ic: 'bi-person-badge', t: 'Modo gestor', d: 'Atenção, delegação, repetições, melhorias e riscos', eq: 1, m: ['Orquestração'], p: '' },
    { c: 'desfazer', g: 'Orquestração', ic: 'bi-arrow-counterclockwise', t: 'Desfazer plano', d: 'Remove as metas criadas pelo último plano aprovado', eq: 1, m: ['Orquestração'], p: '' },
    { c: 'autonomia', g: 'Orquestração', ic: 'bi-toggles', t: 'Autonomia da IA', d: 'O que a IA pode preparar ou executar, por risco', eq: 0, m: ['Orquestração'], p: '' },
    { c: 'metacognicao', g: 'Metacognição', ic: 'bi-lightbulb', t: 'Metacognição', d: 'Confiança, lacunas, contradições, erros e evolução da IA', eq: 0, m: ['Metacognição'], p: '' },
    { c: 'causa', g: 'Metacognição', ic: 'bi-diagram-2', t: 'Causa raiz', d: 'Problema, evidências, causa provável, alternativas e providência', eq: 0, m: ['Metacognição'], p: '' },
    { c: 'esequ', g: 'Metacognição', ic: 'bi-signpost', t: 'E se…?', d: 'Cenários projetados com os dados da pasta', eq: 0, m: ['Metacognição'], p: '' },
    { c: 'simular', g: 'Metacognição', ic: 'bi-play-circle', t: 'Simular regra', d: 'Efeitos de uma regra antes de aplicar', eq: 1, m: ['Metacognição'], p: 'regra = valor' },
    { c: 'decisao', g: 'Metacognição', ic: 'bi-journal-check', t: 'Registrar decisão', d: 'Contexto, decisão e motivo, para reconhecer casos parecidos', eq: 0, m: ['Metacognição'], p: 'decisão porque motivo' },
    { c: 'memoria', g: 'Núcleo Cognitivo', ic: 'bi-hdd-stack', t: 'Painel de memória', d: 'Global, institucional, individual, temporária, erros e correções', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'testecognitivo', g: 'Núcleo Cognitivo', ic: 'bi-clipboard2-pulse', t: 'Testes cognitivos', d: 'Executa os 16 testes com dados reais (Master)', eq: 1, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'grafo', g: 'Núcleo Cognitivo', ic: 'bi-share', t: 'Grafo da pasta', d: 'Relações entre cliente, processo, publicações, metas e documentos', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'contexto', g: 'Núcleo Cognitivo', ic: 'bi-pin-angle', t: 'Contexto da conversa', d: 'Memória de trabalho: do que estamos falando agora', eq: 0, m: ['Núcleo Cognitivo'], p: '' },
    { c: 'estilo', g: 'Conhecimento', ic: 'bi-pen', t: 'Meu estilo de escrita', d: 'Como a IA escreve para você (só para você)', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'global', g: 'Conhecimento', ic: 'bi-globe2', t: 'Conhecimento geral do BlueJus', d: 'Padrões recorrentes entre contas, sem dados privados', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'integracao', g: 'Conhecimento', ic: 'bi-diagram-3', t: 'Teste de integração', d: 'Confere se todos os modos e automações estão ligados à IA', eq: 0, m: ['Integração'], p: '' },
    { c: 'rumo', g: 'Estratégia', ic: 'bi-compass', t: 'Situação estratégica', d: 'Objetivo, avanço, premissas, gatilhos, gargalo e próximas ações', eq: 0, m: ['Estratégia'], p: '' },
    { c: 'experiencia', g: 'Clientes', ic: 'bi-emoji-smile', t: 'Experiência do cliente', d: 'Sinais, causa provável e intervenção: /experiencia ou /experiencia gleisson', eq: 0, m: ['Clientes'], p: '' },
    { c: 'catalogo', g: 'Clientes', ic: 'bi-boxes', t: 'Catálogo de serviços', d: 'Volume, receita, esforço, padronização e valor × esforço por serviço', eq: 0, m: ['Produto'], p: '' },
    { c: 'operacao', g: 'Jurídico', ic: 'bi-diagram-2', t: 'Operação da pasta', d: 'Portões de qualidade, processo real × ideal, caminho crítico e próximo passo', eq: 0, m: ['Operações'], p: '' },
    { c: 'complexidade', g: 'Jurídico', ic: 'bi-bar-chart-steps', t: 'Complexidade', d: 'Complexidade por pasta e nível de intervenção recomendado', eq: 0, m: ['Operações'], p: '' },
    { c: 'saudedados', g: 'Controladoria', ic: 'bi-heart-pulse', t: 'Saúde dos dados', d: 'Frescor, completude e consistência por fonte; falha silenciosa', eq: 0, m: ['Dados'], p: '' },
    { c: 'origem', g: 'Controladoria', ic: 'bi-diagram-3', t: 'De onde veio o número', d: 'Fórmula, fonte e registros de um indicador: /origem paradas', eq: 0, m: ['Dados'], p: '' },
    { c: 'metricas', g: 'Controladoria', ic: 'bi-book', t: 'Dicionário de métricas', d: 'Definição, fórmula, fonte e limitação de cada indicador', eq: 0, m: ['Dados'], p: '' },
    { c: 'saber', g: 'Documentos', ic: 'bi-lightbulb', t: 'O que sabemos', d: 'Busca em todas as fontes, por autoridade, com quem sabe e o que é hipótese: /saber tutela', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'continuidade', g: 'Governança', ic: 'bi-life-preserver', t: 'Continuidade', d: 'Se o sistema parar: operações críticas, alternativas, pontos únicos de falha e prazos a proteger', eq: 0, m: ['Continuidade'], p: '' },
    { c: 'backup', g: 'Governança', ic: 'bi-cloud-arrow-down', t: 'Backup', d: 'Integridade dos dados e backup baixado com teste de leitura', eq: 0, m: ['Continuidade'], p: '' },
    { c: 'modocrise', g: 'Governança', ic: 'bi-exclamation-octagon', t: 'Modo crise', d: 'Só avisos críticos e prazos primeiro: /modocrise ou /modocrise desligar', eq: 0, m: ['Continuidade'], p: '' },
    { c: 'acessos', g: 'Governança', ic: 'bi-fingerprint', t: 'Acessos e confiança', d: 'Sinais correlacionados na auditoria: bloqueios, tentativa de Master, volume e horário', eq: 0, m: ['Segurança'], p: '' },
    { c: 'privilegios', g: 'Governança', ic: 'bi-key', t: 'Privilégios', d: 'Quem tem acesso além do padrão, abas ocultas e cadeados', eq: 0, m: ['Segurança'], p: '' },
    { c: 'externo', g: 'Jurídico', ic: 'bi-globe2', t: 'Ambiente externo', d: 'Quais fontes externas a IA enxerga e quais não', eq: 0, m: ['Jurídico'], p: '' },
    { c: 'impacto', g: 'Jurídico', ic: 'bi-bullseye', t: 'Impacto de mudança', d: 'O que pode ser afetado por um tema: /impacto tutela', eq: 0, m: ['Jurídico'], p: '' },
    { c: 'clientes', g: 'Clientes', ic: 'bi-people', t: 'Saúde dos clientes', d: 'Quem está em risco, por quê, e possível expansão', eq: 0, m: ['Clientes'], p: '' },
    { c: 'cliente', g: 'Clientes', ic: 'bi-person-vcard', t: 'Cliente 360', d: 'Tudo sobre um cliente: /cliente gleisson', eq: 0, m: ['Clientes'], p: '' },
    { c: 'servicos', g: 'Clientes', ic: 'bi-grid-3x3-gap', t: 'Serviços', d: 'Carteira por tipo de ação: expandir, otimizar ou ter cautela', eq: 0, m: ['Clientes'], p: '' },
    { c: 'crescer', g: 'Clientes', ic: 'bi-graph-up-arrow', t: 'Crescimento', d: '+10% a +100% de clientes: o que quebra primeiro', eq: 0, m: ['Clientes'], p: '' },
    { c: 'funil', g: 'Clientes', ic: 'bi-funnel', t: 'Funil e canais', d: 'O que registrar para medir leads, conversão, CAC e LTV', eq: 0, m: ['Clientes'], p: '' },
    { c: 'controles', g: 'Controladoria', ic: 'bi-clipboard2-check', t: 'Controles', d: 'Mapa de controles: o que está fora do padrão, quem age e como corrigir', eq: 0, m: ['Conformidade'], p: '' },
    { c: 'qualidadedados', g: 'Controladoria', ic: 'bi-database-check', t: 'Qualidade dos dados', d: 'CPF/CNPJ inválido e cadastro duplicado', eq: 0, m: ['Conformidade'], p: '' },
    { c: 'pessoas', g: 'Recursos', ic: 'bi-person-workspace', t: 'Equipe', d: 'Competências por evidência, carga e dependência de uma só pessoa', eq: 0, m: ['Pessoas'], p: '' },
    { c: 'sucessao', g: 'Recursos', ic: 'bi-diagram-2', t: 'Sucessão', d: 'Áreas com uma única pessoa e possíveis substitutos', eq: 0, m: ['Pessoas'], p: '' },
    { c: 'ausencia', g: 'Recursos', ic: 'bi-person-dash', t: 'Simular ausência', d: 'Se alguém faltar 7 dias: /ausencia samuel', eq: 0, m: ['Pessoas'], p: '' },
    { c: 'aguardando', g: 'Comunicação', ic: 'bi-hourglass', t: 'Comunicação pendente', d: 'Quem aguarda resposta, promessas sem retorno e clientes a informar', eq: 0, m: ['Comunicação'], p: '' },
    { c: 'revisarmsg', g: 'Comunicação', ic: 'bi-spellcheck', t: 'Revisar mensagem', d: 'Confere o texto antes de enviar: /revisarmsg seu texto', eq: 0, m: ['Comunicação'], p: '' },
    { c: 'caso', g: 'Jurídico', ic: 'bi-bank', t: 'Estado do caso', d: 'Fase, matriz de fatos, questões a validar e riscos em cadeia', eq: 0, m: ['Jurídico'], p: '' },
    { c: 'riscojur', g: 'Jurídico', ic: 'bi-shield-exclamation', t: 'Risco jurídico', d: 'Maior risco jurídico do escritório agora', eq: 0, m: ['Jurídico'], p: '' },
    { c: 'conflito', g: 'Jurídico', ic: 'bi-people-fill', t: 'Conflito de interesses', d: 'Procura a parte na carteira e nas movimentações: /conflito nome', eq: 0, m: ['Jurídico'], p: '' },
    { c: 'documentos', g: 'Documentos', ic: 'bi-folder-check', t: 'Saúde documental', d: 'Checklist × arquivos, contradições, duplicados e documentos sem providência', eq: 0, m: ['Documentos'], p: '' },
    { c: 'narrativa', g: 'Documentos', ic: 'bi-journal-text', t: 'Narrativa do caso', d: 'Contexto, situação, histórico, documentos, pendências e próximas providências', eq: 0, m: ['Documentos'], p: '' },
    { c: 'provar', g: 'Documentos', ic: 'bi-search', t: 'Evidência', d: 'Qual fonte comprova: /provar contrato assinado', eq: 0, m: ['Documentos'], p: '' },
    { c: 'financas', g: 'Financeiro', ic: 'bi-cash-coin', t: 'Saúde financeira', d: 'Recebido, vencido, a receber, concentração e o que falta saber', eq: 0, m: ['Financeiro'], p: '' },
    { c: 'receber', g: 'Financeiro', ic: 'bi-wallet2', t: 'Prioridade de cobrança', d: 'Valor × chance de receber × tempo de atraso', eq: 0, m: ['Financeiro'], p: '' },
    { c: 'fluxo', g: 'Financeiro', ic: 'bi-calendar3', t: 'Recebimentos previstos', d: 'Entradas dos próximos 30, 60 e 90 dias', eq: 0, m: ['Financeiro'], p: '' },
    { c: 'cenariofin', g: 'Financeiro', ic: 'bi-graph-down-arrow', t: 'Cenários financeiros', d: 'E se a inadimplência dobrar, a receita cair ou perder o maior cliente', eq: 0, m: ['Financeiro'], p: '' },
    { c: 'carga', g: 'Recursos', ic: 'bi-diagram-3', t: 'Saúde dos recursos', d: 'Carga por pessoa, gargalo, ponto único de falha e desperdício', eq: 0, m: ['Recursos'], p: '' },
    { c: 'capacidade', g: 'Recursos', ic: 'bi-battery-half', t: 'Capacidade × estratégia', d: 'Temos capacidade para executar a estratégia?', eq: 0, m: ['Recursos'], p: '' },
    { c: 'estresse', g: 'Recursos', ic: 'bi-lightning-charge', t: 'Teste de estresse', d: 'Menos capacidade, mais demanda: o que quebra primeiro', eq: 0, m: ['Recursos'], p: '' },
    { c: 'realocar', g: 'Recursos', ic: 'bi-arrow-left-right', t: 'Realocação', d: 'Quem pode absorver, com ganho e custo de oportunidade', eq: 0, m: ['Recursos'], p: '' },
    { c: 'roadmap', g: 'Estratégia', ic: 'bi-signpost-2', t: 'Roadmap', d: 'Do resultado em 90 dias até hoje: marcos e horizontes', eq: 0, m: ['Estratégia'], p: '' },
    { c: 'revisao', g: 'Estratégia', ic: 'bi-calendar-week', t: 'Revisão semanal', d: 'O que avançou, parou, maior risco e decisão', eq: 0, m: ['Estratégia'], p: '' },
    { c: 'em90', g: 'Estratégia', ic: 'bi-hourglass-split', t: 'Se nada mudar (90 dias)', d: 'Projeção com base no ritmo real', eq: 0, m: ['Estratégia'], p: '' },
    { c: 'pontocego', g: 'Estratégia', ic: 'bi-eye-slash', t: 'Pontos cegos', d: 'Se a estratégia estiver errada, por quê?', eq: 0, m: ['Estratégia'], p: '' },
    { c: 'semprocesso', g: 'Controladoria', ic: 'bi-bank', t: 'Pastas sem processo', d: 'Quais pastas não têm processo, quais são administrativas e quais merecem revisão (PDF). Use "/semprocesso enviar" para mandar à Controladoria', eq: 0, m: ['Controladoria'], p: '' }, { c: 'paradas', g: 'Controladoria', ic: 'bi-pause-circle', t: 'Pastas paradas', d: 'Relatório de pastas sem movimentação há mais de 30 dias (PDF). Use "/paradas enviar" para enviar agora', eq: 0, m: ['Controladoria'], p: '' },
    { c: 'alertas', g: 'Equipe', ic: 'bi-bell', t: 'Alertas da equipe', d: 'Quem enviou, quem viu, quem resolveu e quanto tempo levou', eq: 0, m: ['Equipe'], p: '' },
    { c: 'dicas', g: 'Configurações', ic: 'bi-lightbulb', t: 'Dicas dos botões', d: 'Ver quais ferramentas você já domina e ligar, desligar ou reiniciar as dicas', eq: 0, m: ['Ajuda'], p: '' },
    { c: 'atencao', g: 'Atenção', ic: 'bi-bell', t: 'Central de Atenção', d: 'O que exige sua ação: conversas, ligações, prazos, tarefas, cadastro', eq: 0, m: ['Atenção'], p: '' },
    { c: 'meudia', g: 'Atenção', ic: 'bi-sunrise', t: 'Seu dia no BlueJus', d: 'Resumo e prioridade sugerida', eq: 0, m: ['Atenção'], p: '' },
    { c: 'atencaoteste', g: 'Atenção', ic: 'bi-clipboard-check', t: 'Testar Central de Atenção', d: 'Prioridade, agrupamento, contador, resolver', eq: 0, m: ['Atenção'], p: '' },
    { c: 'decisao', g: 'Decisão', ic: 'bi-diagram-3-fill', t: 'Preparar decisão', d: 'Causas, alavancas, janela, custo de esperar, árvore, premissas, pré-mortem', eq: 0, m: ['Decisão'], p: '' },
    { c: 'posmortem', g: 'Decisão', ic: 'bi-journal-check', t: 'Pós-mortem', d: 'Resultado real × esperado; qualidade da decisão separada da sorte', eq: 0, m: ['Decisão'], p: '' },
    { c: 'decisaoteste', g: 'Decisão', ic: 'bi-clipboard-check', t: 'Testar decisão causal', d: 'Contribuição, controle, árvore, premissa quebrada', eq: 0, m: ['Decisão'], p: '' },
    { c: 'futuro', g: 'Antecipação', ic: 'bi-binoculars', t: 'O que está por vir', d: 'Riscos em formação, cascata, horizonte, decisões que não devem esperar (ex.: próximos 7 dias)', eq: 0, m: ['Antecipação'], p: '' },
    { c: 'sinais', g: 'Antecipação', ic: 'bi-broadcast', t: 'Sinais fracos', d: 'Antecedentes × consequentes, inclusive os silenciados', eq: 0, m: ['Antecipação'], p: '' },
    { c: 'previsoes', g: 'Antecipação', ic: 'bi-graph-up-arrow', t: 'Previsões e calibração', d: 'Previsão × o que aconteceu', eq: 0, m: ['Antecipação'], p: '' },
    { c: 'futuroteste', g: 'Antecipação', ic: 'bi-clipboard-check', t: 'Testar antecipação', d: 'Horizonte, prioridade, silêncio, ponto cego', eq: 0, m: ['Antecipação'], p: '' },
    { c: 'ligacaoteste', g: 'Ligações', ic: 'bi-telephone-inbound', t: 'Testar ligações', d: 'Microfone, permissões, autorizações, sinalização e um teste real de áudio', eq: 0, m: ['Ligações'], p: '' },
    { c: 'runtime', g: 'Runtime', ic: 'bi-heart-pulse', t: 'Central de controle', d: 'Estado real de cada capacidade: operacional, limitada, com erro', eq: 0, m: ['Runtime'], p: '' },
    { c: 'rota', g: 'Runtime', ic: 'bi-signpost-2', t: 'Rota da pergunta', d: 'Quais capacidades a pergunta aciona e com que profundidade', eq: 0, m: ['Runtime'], p: 'pergunta' },
    { c: 'regressao', g: 'Runtime', ic: 'bi-arrow-counterclockwise', t: 'Teste de regressão', d: 'Roda todos os testes e compara com a rodada anterior', eq: 0, m: ['Runtime'], p: '' },
    { c: 'arquivos', g: 'Documentos', ic: 'bi-file-earmark-search', t: 'Pesquisar arquivos', d: 'Procura um termo nos documentos, imagens e áudios desta conversa', eq: 0, m: ['Documentos'], p: 'termo' }, { c: 'contextocog', g: 'Contexto', ic: 'bi-diagram-3', t: 'Contexto unificado', d: 'O que a IA precisa saber para esta pergunta: essencial, lacunas, contradições', eq: 0, m: ['Contexto'], p: '' }, { c: 'contextodiff', g: 'Contexto', ic: 'bi-arrow-left-right', t: 'O que mudou no contexto', d: 'Versões do contexto da pasta e o que mudou', eq: 0, m: ['Contexto'], p: '' }, { c: 'contextoreplay', g: 'Contexto', ic: 'bi-clock-history', t: 'Replay de contexto', d: 'Contexto que a IA tinha numa decisão anterior', eq: 0, m: ['Contexto'], p: '' }, { c: 'contextoteste', g: 'Contexto', ic: 'bi-check2-all', t: 'Teste do Context Engine', d: 'Roda o teste de integração do contexto', eq: 0, m: ['Contexto'], p: '' }, { c: 'safemode', g: 'Runtime', ic: 'bi-shield-exclamation', t: 'Safe mode', d: 'Liga/desliga modo só leitura (primário/Master)', eq: 0, m: ['Runtime'], p: '' },
    { c: 'snapshot', g: 'Runtime', ic: 'bi-camera', t: 'Snapshot', d: 'Guarda o estado cognitivo antes de mudanças', eq: 0, m: ['Runtime'], p: 'rótulo' },
    { c: 'rollback', g: 'Runtime', ic: 'bi-skip-backward', t: 'Rollback', d: 'Volta ao último snapshot (guarda o atual antes)', eq: 0, m: ['Runtime'], p: '' },
    { c: 'causa', g: 'Causa raiz', ic: 'bi-search', t: 'Por que está acontecendo', d: 'Sintomas, causas prováveis, porquês, matriz e o que fazer', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'resolva', g: 'Causa raiz', ic: 'bi-tools', t: 'Resolva isso', d: 'Plano de contenção, correção e prevenção com ações para aprovar', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'recorrencia', g: 'Causa raiz', ic: 'bi-arrow-repeat', t: 'Por que continua acontecendo', d: 'Investiga recorrência e por que a solução anterior não segurou', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'maiorproblema', g: 'Causa raiz', ic: 'bi-exclamation-diamond', t: 'Maior problema do escritório', d: 'Pelo histórico de causas, não pelo volume', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'problemas', g: 'Causa raiz', ic: 'bi-journal-medical', t: 'Memória de problemas', d: 'Problemas, causas e se a solução funcionou', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'causateste', g: 'Causa raiz', ic: 'bi-clipboard-check', t: 'Testar causa raiz', d: 'Sintoma × causa, porquês, matriz, não culpar', eq: 0, m: ['Causa raiz'], p: '' },
    { c: 'contexto', g: 'Copiloto', ic: 'bi-chat-square-text', t: 'Onde estávamos', d: 'Contexto ativo, decisões, compromissos e outros assuntos da conversa', eq: 0, m: ['Conversa'], p: '' },
    { c: 'cvteste', g: 'Copiloto', ic: 'bi-clipboard-check', t: 'Testar contexto da conversa', d: 'Tópicos, decisão × ideia, compromisso, correção', eq: 0, m: ['Conversa'], p: '' },
    { c: 'hoje', g: 'Copiloto', ic: 'bi-calendar-check', t: 'O que fazer hoje', d: 'Faça primeiro, depois, delegue, aguarde, elimine, monitore', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'agora', g: 'Copiloto', ic: 'bi-lightning-charge', t: 'O que fazer agora', d: 'Próxima ação crítica com justificativa', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'parado', g: 'Copiloto', ic: 'bi-pause-btn', t: 'O que está parado', d: 'Pasta, metas, movimentações e negociação sem evolução', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'cego', g: 'Copiloto', ic: 'bi-eye', t: 'O que estou deixando passar', d: 'Contradições, sem responsável, sem providência, oportunidades', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'executivo', g: 'Copiloto', ic: 'bi-briefcase', t: 'Resumo executivo', d: 'Situação, risco, oportunidade, decisão, recomendação, próxima ação', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'sefosse', g: 'Copiloto', ic: 'bi-person-check', t: 'Se fosse você?', d: 'Decisão, alternativa, risco e o que faria mudar', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'porque', g: 'Copiloto', ic: 'bi-question-diamond', t: 'Por que decidimos?', d: 'Decisões registradas (diga "decidimos …" para registrar)', eq: 0, m: ['Copiloto'], p: '' },
    { c: 'negociacao', g: 'Negociação', ic: 'bi-arrow-left-right', t: 'Negociação da pasta', d: 'ZOPA, âncora, concessões, alertas, pacotes, conselho e resumo ao cliente', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negnova', g: 'Negociação', ic: 'bi-plus-square', t: 'Cadastrar negociação', d: 'Objetivo, valores, limite, BATNA e WATNA', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negproposta', g: 'Negociação', ic: 'bi-cash-coin', t: 'Registrar proposta', d: 'Nossa ou da contraparte, com valor, condições e prazo', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negconcessao', g: 'Negociação', ic: 'bi-arrow-down-up', t: 'Registrar concessão', d: 'O que foi cedido e o que veio em troca', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negstatus', g: 'Negociação', ic: 'bi-flag', t: 'Status da negociação', d: 'Acordo, ruptura, encerrada; resultado real × previsão', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negociacoes', g: 'Negociação', ic: 'bi-kanban', t: 'Negociações do escritório', d: 'Abertas por saúde (primário e Controladoria)', eq: 0, m: ['Negociação'], p: '' },
    { c: 'negteste', g: 'Negociação', ic: 'bi-clipboard-check', t: 'Testar negociação', d: 'ZOPA, âncora, concessões, silêncio, janela, pacotes', eq: 0, m: ['Negociação'], p: '' },
    { c: 'equipe', g: 'Agentes', ic: 'bi-people-fill', t: 'Equipe cognitiva', d: 'Monta a equipe de agentes para um objetivo (ex.: /equipe analisar publicação e prazos)', eq: 0, m: ['Agentes'], p: 'objetivo' },
    { c: 'agentes', g: 'Agentes', ic: 'bi-cpu', t: 'Agentes', d: 'Catálogo, uso, maturidade e limites dos agentes', eq: 0, m: ['Agentes'], p: '' },
    { c: 'agentesteste', g: 'Agentes', ic: 'bi-clipboard-check', t: 'Testar agentes', d: 'Escopo, contexto mínimo, hipótese ≠ fato e portão de qualidade', eq: 0, m: ['Agentes'], p: '' },
    { c: 'automacoes', g: 'Automação', ic: 'bi-gear-wide-connected', t: 'Automações', d: 'Candidatos com score, workflows e regra de governança', eq: 0, m: ['Automação'], p: '' },
    { c: 'workflow', g: 'Automação', ic: 'bi-diagram-2', t: 'Criar workflow', d: 'Descreva em linguagem natural (ex.: toda publicação com providência vira meta)', eq: 0, m: ['Automação'], p: 'descrição' },
    { c: 'wfsimular', g: 'Automação', ic: 'bi-play-circle', t: 'Simular workflow', d: 'Replay no histórico real, sem alterar nada', eq: 0, m: ['Automação'], p: 'nome' },
    { c: 'wfaprovar', g: 'Automação', ic: 'bi-check2-circle', t: 'Aprovar workflow', d: 'Só usuário primário ou Master', eq: 0, m: ['Automação'], p: 'nome' },
    { c: 'wfativar', g: 'Automação', ic: 'bi-toggle-on', t: 'Ativar workflow', d: 'Depois de aprovado', eq: 0, m: ['Automação'], p: 'nome' },
    { c: 'wfsuspender', g: 'Automação', ic: 'bi-pause-circle', t: 'Suspender workflow', d: 'Para sem apagar', eq: 0, m: ['Automação'], p: 'nome' },
    { c: 'wfteste', g: 'Automação', ic: 'bi-clipboard-check', t: 'Testar fábrica', d: 'Testes de escopo, idempotência e smart stop', eq: 0, m: ['Automação'], p: '' },
    { c: 'timeline', g: 'Timeline', ic: 'bi-activity', t: 'Timeline inteligente', d: 'Tudo que aconteceu na pasta; busque um assunto ou pessoa (ex.: /timeline contrato)', eq: 0, m: ['Timeline'], p: 'termo' },
    { c: 'perdi', g: 'Timeline', ic: 'bi-hourglass-split', t: 'O que eu perdi?', d: 'O que aconteceu na pasta desde a sua última visita', eq: 0, m: ['Timeline'], p: '' },
    { c: 'resumoatividade', g: 'Timeline', ic: 'bi-stars', t: 'Resumir atividade', d: 'Resumo do mês da pasta: documentos, registros, ligações, prazos', eq: 0, m: ['Timeline'], p: '' },
    { c: 'timelineteste', g: 'Timeline', ic: 'bi-clipboard-check', t: 'Testar timeline', d: 'Testes de detecção, busca e filtros', eq: 0, m: ['Timeline'], p: '' },
    { c: 'terceiros', g: 'Terceiros', ic: 'bi-diagram-3', t: 'Terceiros do escritório', d: 'Parceiros, fornecedores e correspondentes: risco, SLA, dependência e alertas', eq: 0, m: ['Terceiros'], p: '' },
    { c: 'terceiro', g: 'Terceiros', ic: 'bi-person-badge', t: 'Ficha do terceiro', d: 'Score, evolução, contrato, continuidade e sinais (ex.: /terceiro Alfa)', eq: 0, m: ['Terceiros'], p: 'nome' },
    { c: 'terceirocad', g: 'Terceiros', ic: 'bi-person-plus', t: 'Cadastrar terceiro', d: 'Nome, categoria, serviço, criticidade, SLA, contrato e plano B', eq: 0, m: ['Terceiros'], p: '' },
    { c: 'terceiroev', g: 'Terceiros', ic: 'bi-journal-plus', t: 'Registrar evento', d: 'Entrega, atraso, retrabalho, custo, incidente ou indicação', eq: 0, m: ['Terceiros'], p: 'nome' },
    { c: 'terceirosim', g: 'Terceiros', ic: 'bi-lightning', t: 'E se ficar indisponível?', d: 'Impacto de 1, 7 ou mais dias sem o terceiro (ex.: /terceirosim Alfa 7)', eq: 0, m: ['Terceiros'], p: 'nome dias' },
    { c: 'terceirodecisao', g: 'Terceiros', ic: 'bi-signpost-split', t: 'Registrar decisão', d: 'Contratar, manter, substituir, renegociar, encerrar, com motivo e expectativa', eq: 0, m: ['Terceiros'], p: 'nome' },
    { c: 'terceirosteste', g: 'Terceiros', ic: 'bi-clipboard-check', t: 'Testar terceiros', d: 'Testes do motor com dados de teste', eq: 0, m: ['Terceiros'], p: '' },
    { c: 'baixas', g: 'Controladoria', ic: 'bi-speedometer2', t: 'Baixas aceleradas', d: 'Ritmo de baixas, alertas e suspensões', eq: 0, m: ['Controladoria'], p: '' },
    { c: 'precisa', g: 'Proativo', ic: 'bi-bell', t: 'O que precisa da minha atenção', d: 'Riscos, prazos e bloqueios em um aviso só, por relevância', eq: 0, m: ['Proativo'], p: '' },
    { c: 'primeiro', g: 'Proativo', ic: 'bi-sort-down', t: 'O que fazer primeiro', d: 'Lista priorizada com urgência, impacto e confiança', eq: 0, m: ['Proativo'], p: '' },
    { c: 'mudou', g: 'Proativo', ic: 'bi-arrow-left-right', t: 'O que mudou', d: 'Mudanças relevantes desde a última leitura', eq: 0, m: ['Proativo'], p: '' },
    { c: 'briefing', g: 'Proativo', ic: 'bi-sunrise', t: 'Resumo cognitivo do dia', d: 'Atenção imediata, riscos, mudanças, oportunidades e prioridades', eq: 0, m: ['Proativo'], p: '' },
    { c: 'proativo', g: 'Proativo', ic: 'bi-sliders', t: 'Avisos proativos', d: 'Ligar, pausar e ver o histórico dos avisos', eq: 0, m: ['Proativo'], p: '' },
    { c: 'testeproativo', g: 'Proativo', ic: 'bi-clipboard-check', t: 'Testar proatividade', d: 'Os 17 testes da inteligência proativa', eq: 0, m: ['Proativo'], p: '' },
    { c: 'masterunico', g: 'Conhecimento', ic: 'bi-shield-lock', t: 'Master único', d: 'Testa a regra estrutural: um único Master no BlueJus', eq: 0, m: ['Segurança'], p: '' },
    { c: 'isolamento', g: 'Conhecimento', ic: 'bi-shield-check', t: 'Testar isolamento', d: 'Contas, aprendizado global, estilo e crescimento (Master)', eq: 1, m: ['Segurança'], p: '' },
    { c: 'investigar', g: 'Conhecimento', ic: 'bi-binoculars', t: 'Investigar', d: 'Hipóteses, cadeia de evidências e conclusão sobre a pasta', eq: 0, m: ['Conhecimento'], p: 'pergunta (ex.: está pronta?)' },
    { c: 'plano', g: 'Conhecimento', ic: 'bi-signpost-split', t: 'Montar plano', d: 'Decompõe uma demanda em etapas verificáveis', eq: 0, m: ['Conhecimento'], p: 'objetivo (ex.: preparar para protocolo)' },
    { c: 'maturidade', g: 'Conhecimento', ic: 'bi-bar-chart-steps', t: 'Teste de maturidade', d: 'Roda os 12 níveis com os dados reais disponíveis', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'conhecimento', g: 'Conhecimento', ic: 'bi-diagram-3', t: 'O que a IA conhece', d: 'Índice, memória institucional, padrões e conflitos', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'semelhantes', g: 'Conhecimento', ic: 'bi-search-heart', t: 'Encontrar semelhantes', d: 'Busca por significado em pastas, processos, metas e documentos', eq: 0, m: ['Conhecimento'], p: 'texto ou assunto' },
    { c: 'padroes', g: 'Conhecimento', ic: 'bi-bezier2', t: 'Padrões do escritório', d: 'Como o escritório costuma organizar, distribuir e agir', eq: 0, m: ['Conhecimento'], p: '' },
    { c: 'aprender', g: 'Conhecimento', ic: 'bi-journal-plus', t: 'Ensinar a IA', d: 'Registrar um conhecimento do escritório (fica como não confirmado até validação)', eq: 0, m: ['Conhecimento'], p: 'o que a IA deve saber' },
    { c: 'validar', g: 'Conhecimento', ic: 'bi-patch-check', t: 'Validar padrões', d: 'Transformar padrão em regra institucional (Master/Controladoria)', eq: 1, m: ['Conhecimento'], p: '' },
    { c: 'governanca', g: 'Governança', ic: 'bi-shield-lock', t: 'Governança e acessos', d: 'Hierarquia, regras de acesso e restrições', eq: 1, m: ['Governança'], p: '' },
    { c: 'intervencao', g: 'Conformidade', ic: 'bi-exclamation-diamond', t: 'O que exige intervenção hoje', d: 'Urgências, divergências e pendências repetidas', eq: 1, m: ['Conformidade'], p: '' },
    { c: 'ocorrencias', g: 'Conformidade', ic: 'bi-shield-exclamation', t: 'Ocorrências do controller', d: 'Providências escaladas, divergências e riscos', eq: 1, m: ['Conformidade'], p: '' },
    { c: 'providencias', g: 'Conformidade', ic: 'bi-list-check', t: 'Providências em aberto', d: 'O que a IA recomendou e ainda não foi confirmado', eq: 0, m: ['Conformidade'], p: '' },
    { c: 'conformidade', g: 'Conformidade', ic: 'bi-clipboard2-data', t: 'Histórico de conformidade', d: 'Cumprimento, lembretes, divergências por pessoa', eq: 1, m: ['Conformidade'], p: '' },
    { c: 'semana', g: 'Controller', ic: 'bi-calendar-week', t: 'Resumo da semana', d: 'Produção, pontos positivos, atenção e equipe', eq: 1, m: ['Produtividade', 'Metas', 'Processual'], p: 'Monte o RESUMO DA SEMANA do escritório. Em "metricas" traga a produção da semana atual (pastas abertas, expedientes, metas concluídas) com a variação contra a média das 4 semanas anteriores. Nos pontos: pontos positivos, pontos de atenção e a situação da equipe (quem está dentro, acima ou abaixo do próprio padrão, considerando ausências). Termine com sugestões práticas.' },
    { c: 'equipe', g: 'Controller', ic: 'bi-people', t: 'Como está a equipe', d: 'Ritmo, carga e quem precisa de apoio', eq: 1, m: ['Produtividade', 'Metas'], p: 'Responda "Como está a equipe nesta semana?" com blocos PRODUÇÃO, ATENÇÃO e EQUIPE (quantos usuários abaixo do ritmo habitual, com concentração elevada e dentro do padrão) e sugestões práticas.' },
    { c: 'distribuicao', g: 'Controller', ic: 'bi-diagram-3', t: 'Distribuição de carga', d: 'Concentração de tarefas e redistribuição', eq: 1, m: ['Produtividade', 'Metas', 'Responsáveis'], p: 'Analise a distribuição de atividades abertas e prazos próximos entre os colaboradores, considerando função e setor. Aponte desequilíbrios com números e sugira redistribuição apenas como sugestão (nunca transfira nada). Inclua ação enviar_mensagem para o gestor quando fizer sentido.' },
    { c: 'riscos', g: 'Controller', ic: 'bi-shield-exclamation', t: 'Riscos operacionais', d: 'Classificados por nível, com o motivo', eq: 1, m: ['Metas', 'Prazos', 'Processual', 'Produtividade'], p: 'Liste os riscos operacionais (prazo próximo, meta atrasada, processo sem movimentação, demanda sem responsável, acúmulo, pasta sem atualização, inconsistência) do mais grave ao menos grave, sempre com o motivo.' },
    { c: 'parados', g: 'Controller', ic: 'bi-hourglass-split', t: 'Itens parados', d: 'Pastas, metas e processos sem atualização', eq: 0, m: ['Metas', 'Processual'], p: 'Identifique pastas, metas e processos sem movimentação ou atualização recente nos dados disponíveis, com há quanto tempo e o próximo passo sugerido.' },
    { c: 'rendimento', g: 'Rendimento', ic: 'bi-person-lines-fill', t: 'Rendimento de um colaborador', d: 'Ex.: /rendimento Rodrigo', eq: 1, m: ['Produtividade'], p: 'Analise o rendimento do colaborador indicado (ou do próprio usuário, se nenhum nome for dado). Use as camadas dado, analise, hipotese e sugestao, sempre com a base de comparação. Considere função, carteira e ausências antes de qualquer conclusão. Se a variação não tiver explicação nos dados, preencha "pergunta".' },
    { c: 'desvios', g: 'Rendimento', ic: 'bi-activity', t: 'Desvios de produtividade', d: 'Quem saiu do próprio padrão', eq: 1, m: ['Produtividade'], p: 'Compare cada colaborador com a própria média das 4 semanas anteriores (nunca com os colegas de outra função). Liste só desvios relevantes (acima de 30%), positivos e negativos, verificando ausências registradas. Use as camadas dado, analise, hipotese e sugestao.' },
    { c: 'projecao', g: 'Rendimento', ic: 'bi-graph-up-arrow', t: 'Projeção do mês', d: 'Ritmo atual e fechamento previsto', eq: 1, m: ['Produtividade', 'Metas'], p: 'Projete o fechamento do mês para pastas abertas e metas concluídas com base no ritmo registrado até hoje e nos dias úteis restantes. Mostre o percentual do volume médio mensal recente já atingido. Deixe claro que é uma projeção baseada em histórico.' },
    { c: 'meta', g: 'Rendimento', ic: 'bi-bullseye', t: 'Meta inteligente', d: 'Quanto falta e ritmo diário necessário', eq: 0, m: ['Produtividade', 'Metas'], p: 'Calcule quanto falta para a média mensal recente de pastas abertas e o ritmo diário necessário nos dias úteis restantes. Sugira uma meta objetiva e inclua a ação criar_meta (o usuário revisa antes de salvar).' },
    { c: 'crescimento', g: 'Rendimento', ic: 'bi-rocket-takeoff', t: 'Oportunidades de crescimento', d: 'Tendências, funil e conversão', eq: 0, m: ['Produtividade'], p: 'Identifique oportunidades de melhoria e crescimento (tendência de queda ou alta progressiva, conversão de demandas em pastas, gargalos), sempre mostrando os dados que sustentam cada observação.' },
    { c: 'decisao', g: 'Jurídico', ic: 'bi-bank', t: 'Analisar decisão', d: 'Pontos relevantes e providência', eq: 0, m: ['Processual', 'Jurídica', 'Prazos'], p: 'Analise a decisão, despacho ou sentença mais recente do processo desta tela. Liste os pontos relevantes (o que foi determinado, prazo se houver, quem deve agir) e a providência sugerida. Inclua ações abrir_push, abrir_processo e criar_meta quando couber. Não invente prazo nem fundamento.' },
    { c: 'prazos', g: 'Jurídico', ic: 'bi-alarm', t: 'Prazos e providências', d: 'O que vence e o que fazer', eq: 0, m: ['Prazos', 'Processual', 'Metas'], p: 'Liste prazos e providências identificados (metas com prazo, intimações e determinações no Push), do mais urgente ao menos urgente. Quando o termo inicial depender de ciência ou intimação, diga que é preciso conferir.' },
    { c: 'semprovidencia', g: 'Jurídico', ic: 'bi-link-45deg', t: 'Movimentações sem providência', d: 'Push sem meta correspondente', eq: 0, m: ['Processual', 'Metas'], p: 'Cruze as movimentações do Push Processual com as metas existentes. Aponte movimentações relevantes (intimação, decisão, sentença, despacho) que ainda não têm meta correspondente e sugira criar_meta para cada uma.' },
    { c: 'meudia', g: 'Pessoal', ic: 'bi-sun', t: 'Meu dia', d: 'Prioridades de hoje, em ordem', eq: 0, m: ['Metas', 'Prazos', 'Processual'], p: 'Monte as prioridades de hoje do usuário, em ordem: riscos, prazos, atrasos, depois o restante. Seja breve.' },
    { c: 'minhasemana', g: 'Pessoal', ic: 'bi-person-check', t: 'Minha semana', d: 'Meu rendimento x minha média', eq: 0, m: ['Produtividade'], p: 'Analise o rendimento do próprio usuário nesta semana contra a própria média das 4 semanas anteriores, com camadas dado, analise e sugestao. Tom construtivo.' },
    { c: 'proximo', g: 'Pessoal', ic: 'bi-signpost-2', t: 'Próximo passo', d: 'A ação mais adequada agora', eq: 0, m: ['Contexto', 'Metas', 'Processual'], p: 'Com base nos dados disponíveis, indique o próximo passo mais adequado agora, em uma frase, com o motivo e uma ação.' },
  ];
  COMANDOS_IA.push(
    { c: 'controller', g: 'Controller', ic: 'bi-speedometer2', t: 'Controller semanal', d: 'Riscos, fluxo parado, quedas e evoluções', eq: 1, m: ['Produtividade', 'Fluxo', 'Metas'], p: 'Monte o CONTROLLER SEMANAL. Em metricas: riscos, pastas sem andamento, pastas sem distribuição, pastas sem número de processo. Em secoes: "Fluxo" (em qual etapa o trabalho está parado: cadastro, documentos, distribuição, número de processo, expediente, meta, andamento), "Equipe" (usuários com queda relevante e com evolução, contra a própria média) e "Recomendações" (3 itens práticos, em ordem de prioridade).' },
    { c: 'semandamento', g: 'Controller', ic: 'bi-pause-circle', t: 'Pastas sem andamento', d: 'Paradas há mais tempo, por responsável', eq: 0, m: ['Fluxo', 'Metas'], p: 'Liste as pastas sem movimentação (dias parada, responsável, última atividade, meta aberta relacionada). Para cada responsável, termine com uma pergunta orientada à solução. Inclua ações abrir_pasta.' },
    { c: 'semprocesso', g: 'Controller', ic: 'bi-123', t: 'Pastas sem número de processo', d: 'Distribuição pendente ou número não lançado', eq: 0, m: ['Fluxo', 'Processual'], p: 'Analise as pastas sem número de processo. Diferencie: ainda não distribuído, distribuído mas número não cadastrado, informação insuficiente e casos em que não há necessidade de processo (consultivo, extrajudicial). Não presuma que toda pasta precisa de número.' },
    { c: 'semdistribuicao', g: 'Controller', ic: 'bi-person-x', t: 'Pastas sem distribuição', d: 'Sem responsável ou sem início', eq: 1, m: ['Fluxo', 'Responsáveis'], p: 'Liste pastas sem responsável, sem distribuição ou distribuídas e ainda não iniciadas. Sugira a quem distribuir considerando setor, função e carga atual, apenas como sugestão.' },
    { c: 'feedback', g: 'Feedback', ic: 'bi-chat-square-text', t: 'Feedback de produtividade', d: 'Ex.: /feedback Rodrigo', eq: 1, m: ['Produtividade', 'Fluxo', 'Memória'], p: 'Prepare o FEEDBACK DE PRODUTIVIDADE do colaborador indicado. metricas: produção, metas concluídas, metas atrasadas, evolução contra o período anterior. secoes: "Pontos fortes", "Pontos de atenção", "Possíveis causas" (categorias: conhecimento, técnica, jurídica, excesso de trabalho, distribuição, falta de demanda, organização, prioridade, dependência de terceiro, sistema, treinamento), "Perguntas ao colaborador", "Pauta da reunião" (resultado, evolução, pendências, positivos, atenção, causas, perguntas, plano, metas, data de revisão). Siga a regra de ouro: o que aconteceu, dado, evolução, impacto, o que precisa mudar, como ajudar, quando reavaliar. Inclua ação agendar_revisao para 7 dias depois.' },
    { c: 'cobrar', g: 'Feedback', ic: 'bi-megaphone', t: 'Cobrança inteligente', d: 'Escala conforme o histórico de alertas', eq: 1, m: ['Metas', 'Memória'], p: 'Prepare a cobrança das metas atrasadas ou próximas do prazo do colaborador indicado (ou do próprio usuário). Use o estagio_cobranca já calculado de cada meta: 1 alerta leve, 2 pergunta se consegue concluir hoje, 3 pede o impedimento e a previsão realista, 4 informa que será tratado no feedback de produtividade. Considere justificativas e compromissos registrados antes de cobrar. Termine com pergunta orientada à solução. Inclua ação enviar_mensagem com o texto pronto (o usuário confirma antes de enviar), com "cobranca":"título da meta".' },
    { c: 'plano', g: 'Feedback', ic: 'bi-clipboard-check', t: 'Plano de recuperação', d: 'Meta, prazo, indicadores e revisão', eq: 1, m: ['Produtividade', 'Memória'], p: 'Monte um PLANO DE RECUPERAÇÃO para o colaborador indicado: meta semanal realista baseada no melhor histórico recente dele, prazo de 30 dias, acompanhamento semanal e indicadores (novas pastas, expedientes, metas concluídas, pendências, tempo de resposta) em secoes. Inclua ações agendar_revisao e criar_meta.' },
    { c: 'acompanhamentos', g: 'Feedback', ic: 'bi-arrow-repeat', t: 'Acompanhamentos', d: 'Compromissos, revisões e se houve evolução', eq: 0, m: ['Memória', 'Produtividade'], p: 'Revise a controller_memoria: compromissos de entrega (cumpridos, vencidos, em aberto), justificativas registradas e revisões de feedback agendadas, comparando o valor do indicador na data do feedback com o atual. Diga claramente se houve evolução ou se é preciso rever a causa.' },
    { c: 'elogios', g: 'Feedback', ic: 'bi-award', t: 'Reconhecimentos', d: 'Evoluções e bons resultados com dados', eq: 0, m: ['Produtividade'], p: 'Identifique reconhecimentos específicos e baseados em dados: melhora contra a própria média, redução de pendências ou atrasos, evolução nas últimas semanas mesmo abaixo da média. Nada genérico. Para cada um, inclua ação enviar_mensagem com o elogio pronto.' },
    { c: 'relatorio', g: 'Rendimento', ic: 'bi-file-earmark-bar-graph', t: 'Relatório mensal individual', d: 'Ex.: /relatorio Rodrigo', eq: 1, m: ['Produtividade', 'Fluxo'], p: 'Gere o RELATÓRIO DE PRODUTIVIDADE do mês anterior do colaborador indicado (ou do próprio usuário): metricas com produção e variação contra o mês anterior; secoes "Pendências", "Pontos positivos", "Pontos de atenção" e "Sugestões".' },
    { c: 'evolucao', g: 'Rendimento', ic: 'bi-bar-chart-line', t: 'Linha de evolução', d: 'Ritmo mês a mês e o que mudou', eq: 1, m: ['Produtividade'], p: 'Mostre a linha histórica do colaborador indicado (ou do próprio usuário) em metricas (um mês por métrica, valor em % do ritmo de referência) e explique a variação: onde a queda ou melhora está concentrada.' },
    { c: 'uso', g: 'Rendimento', ic: 'bi-clock-history', t: 'Uso do sistema', d: 'Indicador auxiliar, não é produtividade', eq: 1, m: ['Uso do sistema', 'Produtividade'], p: 'Analise o uso do sistema do colaborador indicado cruzando com a produção. Deixe explícito que tempo no sistema não é produtividade. Use as regras de cruzamento: muitas horas e baixa conclusão = investigar gargalo; poucas horas e produção alta = não presumir problema.' }
  );
  COMANDOS_IA.push(
    { c: 'hoje', g: 'Controller', ic: 'bi-broadcast', t: 'Como está o escritório hoje', d: 'Vence hoje, atrasos, riscos e quem precisa de atenção', eq: 0, m: ['Metas', 'Prazos', 'Processual', 'Fluxo', 'Produtividade'], p: 'Responda "Como está o escritório hoje?" como centro de comando: 1) vence hoje; 2) atrasado; 3) maiores riscos; 4) quem precisa de atenção; 5) quem está com bom desempenho (fato concreto); 6) o que resolver primeiro, ordenado pelos critérios de prioridade. Separe confirmado de provável.' },
    { c: 'prioridades', g: 'Pessoal', ic: 'bi-sort-down', t: 'O que resolver primeiro', d: 'Ordem por risco de prazo, impacto e dependências', eq: 0, m: ['Metas', 'Prazos', 'Processual'], p: 'Ordene as pendências do usuário pelo risco de perda de prazo, urgência, impacto jurídico e ao cliente, prazo restante, dependências e atraso. Para cada item diga o motivo da posição em uma linha. Não ordene pela data de criação.' },
    { c: 'divergencias', g: 'Controller', ic: 'bi-intersect', t: 'Divergências pasta x processo', d: 'Processo sem pasta, cliente ou responsável divergente', eq: 1, m: ['Processual', 'Fluxo', 'Metas'], p: 'Procure possíveis inconsistências: processo sem pasta, pasta sem processo, processo na pasta aparentemente errada, número, cliente ou responsável divergente, publicação sem pasta, meta ligada ao processo errado, documento de outra demanda. Marque cada item como CONFIRMADO ou PROVÁVEL e recomende conferência antes de criar providência.' },
    { c: 'regularidade', g: 'Controller', ic: 'bi-stopwatch', t: 'Regularidade operacional', d: 'Criação, início, cobranças e reincidência por pessoa', eq: 1, m: ['Metas', 'Memória', 'Produtividade'], p: 'Para cada responsável, analise a regularidade: tempo até tratar a tarefa, conclusão no prazo, quantas cobranças foram necessárias, reincidência, tarefas paradas e justificativas registradas. Use Fato → Análise → Risco → Providência sugerida. Não conclua nada com base em uma ocorrência isolada.' },
    { c: 'dependencias', g: 'Controller', ic: 'bi-diagram-2', t: 'Aguardando alguém', d: 'Tarefas travadas por terceiro, cliente ou documento', eq: 0, m: ['Metas', 'Fluxo', 'Memória'], p: 'Liste as tarefas paradas aguardando outra pessoa, cliente, documento ou acesso. Para cada uma diga quem destrava e o que cobrar dessa pessoa, em vez de cobrar o responsável original.' },
    { c: 'cobranca', g: 'Feedback', ic: 'bi-bell', t: 'Cobrança com contexto', d: 'Ex.: /cobranca Jéssica', eq: 1, m: ['Metas', 'Memória', 'Fluxo'], p: 'Prepare a cobrança do colaborador citado. Primeiro verifique impedimentos legítimos; se houver, escreva a mensagem de solução. Se não houver, escolha o nível de 1 a 5 conforme alertas anteriores, dias pendente e risco, informe o nível e o motivo, e escreva a mensagem pronta para enviar, firme e respeitosa, citando só fatos verificáveis.' }
  );
  COMANDOS_IA.push({ c: 'potencial', g: 'Rendimento', ic: 'bi-graph-up', t: 'Meu potencial', d: 'Comparação com a média da equipe', eq: 0, m: ['Produtividade'], p: 'Compare a produção da semana do colaborador indicado (ou do próprio usuário) com comparacao_equipe e com a própria média. Para cada indicador abaixo do potencial_minimo, faça o desafio de desempenho direto e pergunte se consegue melhorar. Reconheça o que estiver acima. Se houver parametros_combinados, diga se o alvo está sendo cumprido.' });
  const GRUPOS_CMD = ['Governança', 'Conformidade', 'Controller', 'Feedback', 'Rendimento', 'Jurídico', 'Pessoal', 'Estratégia', 'Recursos', 'Financeiro', 'Clientes', 'Documentos', 'Comunicação', 'Controladoria', 'Proativo'];
  // Valores de referência de rendimento (no sistema real: GET /api/ia/produtividade, já filtrado pelas permissões).
  const PROD_REF = {
    semana: { inicio: '28/09/2026', diasUteisDecorridos: 3, diasUteisSemana: 5, diasUteisRestantesMes: 0, mes: 'setembro/2026' },
    usuarios: {
      farlei:  { pastas: [6, 7, 5, 6], pastasSemana: 4, expedientes: [14, 16, 15, 13], expedientesSemana: 9, metasConcl: [8, 9, 7, 8], metasConclSemana: 5, abertas: 12, atrasadas: 0, prazoProximo: 2, ultimaPasta: '30/09', ausencia: null },
      samuel:  { pastas: [10, 12, 11, 13], pastasSemana: 9, expedientes: [22, 25, 24, 26], expedientesSemana: 18, metasConcl: [9, 11, 10, 12], metasConclSemana: 8, abertas: 27, atrasadas: 2, prazoProximo: 6, ultimaPasta: '30/09', ausencia: null },
      jessica: { pastas: [8, 9, 8, 10], pastasSemana: 6, expedientes: [18, 17, 20, 19], expedientesSemana: 12, metasConcl: [7, 8, 9, 8], metasConclSemana: 6, abertas: 14, atrasadas: 1, prazoProximo: 3, ultimaPasta: '30/09', ausencia: null },
      mariana: { pastas: [3, 4, 3, 4], pastasSemana: 3, expedientes: [9, 10, 8, 9], expedientesSemana: 7, metasConcl: [6, 6, 7, 6], metasConclSemana: 5, abertas: 8, atrasadas: 0, prazoProximo: 1, ultimaPasta: '29/09', ausencia: null },
      rafael:  { pastas: [7, 6, 8, 7], pastasSemana: 5, expedientes: [15, 14, 16, 15], expedientesSemana: 10, metasConcl: [6, 5, 7, 6], metasConclSemana: 4, abertas: 11, atrasadas: 1, prazoProximo: 2, ultimaPasta: '30/09', ausencia: null },
      thamara: { pastas: [2, 3, 2, 2], pastasSemana: 2, expedientes: [26, 28, 25, 27], expedientesSemana: 17, metasConcl: [5, 6, 5, 6], metasConclSemana: 4, abertas: 6, atrasadas: 0, prazoProximo: 0, ultimaPasta: '30/09', ausencia: null },
      rodrigo: { pastas: [8, 9, 7, 8], pastasSemana: 0, expedientes: [12, 13, 11, 12], expedientesSemana: 4, metasConcl: [5, 6, 5, 5], metasConclSemana: 1, abertas: 13, atrasadas: 3, prazoProximo: 2, ultimaPasta: '24/09', ausencia: null },
      helena:  { pastas: [6, 5, 7, 6], pastasSemana: 1, expedientes: [13, 12, 14, 13], expedientesSemana: 2, metasConcl: [5, 5, 6, 5], metasConclSemana: 1, abertas: 7, atrasadas: 0, prazoProximo: 1, ultimaPasta: '28/09', ausencia: 'Férias registradas de 29/09 a 03/10' },
    },
  };
  const FLUXO_REF = {
    semDistribuicao: [{ pasta: 1192, cliente: 'Construtora Horizonte Ltda', aberta: '25/09', motivo: 'sem responsável' }, { pasta: 1193, cliente: 'Maria Aparecida Lopes', aberta: '29/09', motivo: 'distribuída a Rafael Nunes, sem início' }],
    usuarios: {
      rodrigo: { semMov: [[1168, 12], [1171, 9], [1174, 9], [1176, 8], [1179, 7], [1183, 7], [1185, 6]], semProcesso: [[1171, 'distribuição não confirmada'], [1176, 'número não lançado'], [1185, 'sem informação suficiente']], metas: [['Correção de dados de cadastro', 11, 4, '28/09', 4], ['Responder expedientes da semana', 3, 2, '02/10', 0]], historico: [['jun', 78], ['jul', 84], ['ago', 91], ['set', 76]], mes: { pastas: 18, metas: 31, expedientes: 74, demandas: 23 }, mesAnt: { pastas: 21, metas: 29, expedientes: 70, demandas: 22 }, uso: { acessos_semana: 14, horas_sessao_media: 6.8, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 6, atrasadas: 2 } },
      samuel: { semMov: [[1166, 6]], semProcesso: [[1182, 'número não lançado']], metas: [['Correção de dados de cadastro', 70, 9, '24/06', 63]], historico: [['jun', 96], ['jul', 101], ['ago', 104], ['set', 108]], mes: { pastas: 47, metas: 44, expedientes: 101, demandas: 30 }, mesAnt: { pastas: 43, metas: 40, expedientes: 96, demandas: 28 }, uso: { acessos_semana: 18, horas_sessao_media: 7.4, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 12, atrasadas: 3 } },
      jessica: { semMov: [[1180, 9]], semProcesso: [], metas: [['Protocolar contestação', 5, 1, '03/10', 0]], historico: [['jun', 82], ['jul', 88], ['ago', 95], ['set', 103]], mes: { pastas: 36, metas: 33, expedientes: 77, demandas: 25 }, mesAnt: { pastas: 31, metas: 26, expedientes: 70, demandas: 21 }, uso: { acessos_semana: 15, horas_sessao_media: 6.1, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 6, atrasadas: 9 } },
      mariana: { semMov: [], semProcesso: [], metas: [], historico: [['jun', 99], ['jul', 97], ['ago', 100], ['set', 101]], mes: { pastas: 14, metas: 25, expedientes: 36, demandas: 18 }, mesAnt: { pastas: 13, metas: 24, expedientes: 35, demandas: 17 }, uso: { acessos_semana: 12, horas_sessao_media: 5.2, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 6, atrasadas: 0 } },
      rafael: { semMov: [[1190, 5]], semProcesso: [[1190, 'distribuição não confirmada']], metas: [['Juntar procuração atualizada', 6, 3, '01/10', 1]], historico: [['jun', 90], ['jul', 92], ['ago', 89], ['set', 93]], mes: { pastas: 28, metas: 24, expedientes: 60, demandas: 19 }, mesAnt: { pastas: 27, metas: 25, expedientes: 58, demandas: 20 }, uso: { acessos_semana: 13, horas_sessao_media: 5.9, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 5, atrasadas: 1 } },
      thamara: { semMov: [], semProcesso: [], metas: [], historico: [['jun', 94], ['jul', 96], ['ago', 98], ['set', 97]], mes: { pastas: 9, metas: 22, expedientes: 106, demandas: 12 }, mesAnt: { pastas: 9, metas: 21, expedientes: 101, demandas: 12 }, uso: { acessos_semana: 16, horas_sessao_media: 7.9, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 5, atrasadas: 0 } },
      helena: { semMov: [[1177, 4]], semProcesso: [], metas: [], historico: [['jun', 88], ['jul', 90], ['ago', 92], ['set', 85]], mes: { pastas: 20, metas: 20, expedientes: 46, demandas: 15 }, mesAnt: { pastas: 24, metas: 21, expedientes: 52, demandas: 16 }, uso: { acessos_semana: 2, horas_sessao_media: 0.6, dias_sem_acesso: 3 }, semanaAnt: { metasConcl: 5, atrasadas: 0 } },
      farlei: { semMov: [], semProcesso: [], metas: [], historico: [['jun', 100], ['jul', 102], ['ago', 99], ['set', 101]], mes: { pastas: 24, metas: 32, expedientes: 58, demandas: 20 }, mesAnt: { pastas: 23, metas: 31, expedientes: 57, demandas: 19 }, uso: { acessos_semana: 11, horas_sessao_media: 4.8, dias_sem_acesso: 0 }, semanaAnt: { metasConcl: 8, atrasadas: 0 } },
    },
  };
  // Memória do controller (no sistema real: tabela por escritório, auditável). Só grava com confirmação do usuário.
  function memCtl() { db.controller = db.controller || { compromissos: [], justificativas: [], cobrancas: [], revisoes: [] }; db.controller.parametros = db.controller.parametros || []; return db.controller; }
  const hojeBR = () => new Date().toLocaleDateString('pt-BR');
  const dataBR = s => { const m = String(s || '').match(/(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/); return m ? new Date(+(m[3] || new Date().getFullYear()), +m[2] - 1, +m[1]) : null; };
  function memoriaIA(perf) {
    const M = memCtl(), gestor = amplo(perf), meu = x => gestor || x.usuario === perf.id;
    const nome = id => usuario(id).nome, hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    return {
      compromissos: M.compromissos.filter(meu).map(x => ({ colaborador: nome(x.usuario), meta: x.meta, previsao: x.prazo, registrado: x.quando, situacao: x.cumprido ? 'cumprido' : (dataBR(x.prazo) && dataBR(x.prazo) < hoje ? 'vencido sem conclusão' : 'em aberto') })),
      justificativas: M.justificativas.filter(meu).map(x => ({ colaborador: nome(x.usuario), meta: x.meta, categoria: x.categoria, texto: x.texto, registrado: x.quando })),
      parametros_combinados: M.parametros.filter(meu).map(x => ({ colaborador: nome(x.usuario), indicador: x.indicador, alvo: x.alvo, combinado_em: x.quando })),
      revisoes_feedback: M.revisoes.filter(meu).map(x => ({ colaborador: nome(x.usuario), feedback_em: x.quando, revisao: x.data, compromisso: x.compromisso, indicador: x.indicador, valor_no_feedback: x.valorBase })),
    };
  }
  function estagioCobranca(uid, meta) { return Math.min(4, memCtl().cobrancas.filter(x => x.usuario === uid && x.meta === meta).length + 1); }
  function produtividadeIA(perf) {
    const P = window.BLUEJUS_PRODUTIVIDADE || PROD_REF, gestor = amplo(perf);
    const med = a => Math.round(a.reduce((s, x) => s + x, 0) / a.length * 10) / 10;
    const ids = gestor ? Object.keys(P.usuarios) : [perf.id];
    return { referencia: 'valores de referência do protótipo', escopo: gestor ? 'equipe completa' : 'somente os dados do próprio usuário', semana: P.semana, pastas_sem_distribuicao: gestor ? (window.BLUEJUS_FLUXO || FLUXO_REF).semDistribuicao : undefined, controller_memoria: memoriaIA(perf),
      usuarios: ids.filter(id => P.usuarios[id]).map(id => { const u = usuario(id), x = P.usuarios[id];
        const f = P.semana.diasUteisDecorridos / P.semana.diasUteisSemana, cmp = (atual, serie) => { const md = med(serie), esp = Math.round(md * f * 10) / 10; return { atual, media_semanal: md, esperado_ate_hoje: esp, variacao_pct: esp ? Math.round((atual - esp) / esp * 100) : null }; };
        const F = (window.BLUEJUS_FLUXO || FLUXO_REF).usuarios[id] || {};
        const extra = { pastas_sem_movimentacao: (F.semMov || []).map(([p, d]) => ({ pasta: p, dias_parada: d })), pastas_sem_numero_processo: (F.semProcesso || []).map(([p, s]) => ({ pasta: p, situacao_aparente: s })),
          metas_abertas: (F.metas || []).map(([t, ab, sa, pr, at]) => ({ titulo: t, dias_aberta: ab, dias_sem_atualizacao: sa, prazo: pr, atraso_dias: at, estagio_cobranca: estagioCobranca(id, t) })),
          historico_ritmo_pct: (F.historico || []).map(([m, v]) => m + ' ' + v + '%'), mes_anterior: F.mes || null, mes_retrasado: F.mesAnt || null, semana_anterior: F.semanaAnt || null,
          uso_do_sistema_auxiliar: F.uso ? { ...F.uso, aviso: 'tempo no sistema não é produtividade' } : null };
        const pares = Object.keys(P.usuarios).filter(k => k !== id && usuario(k).funcao && usuario(k).funcao.slice(0, 5) === (u.funcao || '').slice(0, 5) && !P.usuarios[k].ausencia);
        const base = pares.length ? pares : Object.keys(P.usuarios).filter(k => k !== id && !P.usuarios[k].ausencia);
        const eqMed = campo => Math.round(base.reduce((s, k) => s + med(P.usuarios[k][campo]), 0) / Math.max(1, base.length) * f * 10) / 10;
        const desafio = (atual, campo) => { const eqv = eqMed(campo); return { atual, media_equipe_ate_hoje: eqv, potencial_minimo: Math.ceil(Math.max(eqv, med(x[campo]) * f)), diferenca: Math.ceil(Math.max(eqv, med(x[campo]) * f)) - atual }; };
        const param = memCtl().parametros.filter(q => q.usuario === id).slice(-3).map(q => ({ indicador: q.indicador, alvo: q.alvo, combinado_em: q.quando }));
        extra.comparacao_equipe = { grupo: pares.length ? 'mesma função (' + u.funcao + ')' : 'equipe', pessoas: base.length, pastas: desafio(x.pastasSemana, 'pastas'), expedientes: desafio(x.expedientesSemana, 'expedientes'), metas_concluidas: desafio(x.metasConclSemana, 'metasConcl') };
        extra.parametros_combinados = param;
        return { nome: u.nome, funcao: u.funcao, setor: u.setor, ...extra, comparacao_ja_calculada: { pastas: cmp(x.pastasSemana, x.pastas), expedientes: cmp(x.expedientesSemana, x.expedientes), metas_concluidas: cmp(x.metasConclSemana, x.metasConcl) }, pastas_4_semanas: x.pastas, media_pastas: med(x.pastas), pastas_semana_atual: x.pastasSemana, expedientes_4_semanas: x.expedientes, media_expedientes: med(x.expedientes), expedientes_semana_atual: x.expedientesSemana, metas_concluidas_4_semanas: x.metasConcl, metas_concluidas_semana_atual: x.metasConclSemana, atividades_abertas: x.abertas, metas_atrasadas: x.atrasadas, prazos_proximos: x.prazoProximo, ultima_pasta_aberta: x.ultimaPasta, ausencia_registrada: x.ausencia || 'nenhuma' }; }) };
  }
  /* ================= CONHECIMENTO CONTÍNUO =================
     Ler → interpretar → relacionar → classificar → padrões → aprender → recuperar → gerar.
     Índice incremental (só reindexa o que mudou), busca por significado (radicais + sinônimos jurídicos),
     memória institucional com níveis de confiança e aprendizado por correções. Aprender ≠ mudar regra: padrão só vira
     regra institucional com validação do Master ou da Controladoria.
     Sistema real: GET /api/ia/indice?desde=… (já filtrado por permissão) e POST /api/ia/conhecimento; embeddings no servidor. */
  const NIVEIS_K = { confirmado: ['Conhecimento confirmado', '#186c47', '#e2f4ea'], institucional: ['Conhecimento institucional', '#0b4f6c', '#e3eff6'], padrao: ['Padrão recorrente', '#5b3fa0', '#efeafa'], preferencia: ['Preferência do usuário', '#8a5a12', '#fdf1dc'], hipotese: ['Hipótese', '#6b8494', '#eef2f5'], nao_confirmado: ['Informação não confirmada', '#a3232b', '#fdeceb'] };
  const SIN_K = [['contestacao', 'defesa', 'impugnacao', 'resposta'], ['cobranca', 'execucao', 'cumprimento', 'debito', 'inadimpl'], ['procuracao', 'mandato', 'substabelec'], ['condominio', 'condominial', 'sindico', 'assembleia', 'ata'], ['manifestacao', 'peticao', 'pronunciamento'], ['intimacao', 'publicacao', 'citacao', 'notificacao'], ['sentenca', 'decisao', 'despacho', 'acordao'], ['recurso', 'apelacao', 'agravo', 'embargo'], ['saude', 'plano', 'medic', 'hospital'], ['contrato', 'acordo', 'termo'], ['prazo', 'vencimento', 'termo final'], ['documento', 'arquivo', 'anexo']];
  const STOP_K = new Set('a o os as de da do das dos e em no na nos nas um uma para por com que se ao aos sobre esse essa este esta isso como qual quais tem ter temos ja ou mais'.split(' '));
  const raizK = w => w.length > 5 ? w.replace(/(coes|cao|mente|idade|ivos?|ivas?|ados?|adas?|ores?|eiros?|eiras?|ais|es|s)$/, '') : w;
  function tokensK(t) { const out = []; norm(t).replace(/[^a-z0-9 ]+/g, ' ').split(/\s+/).forEach(w => { if (w.length < 3 || STOP_K.has(w)) return; const r = raizK(w); out.push(r); SIN_K.forEach(g => { if (g.some(s => r.startsWith(raizK(s)) || raizK(s).startsWith(r))) g.forEach(s => out.push('~' + raizK(s))); }); }); return out; }
  /* ================= MULTICONTAS: aprender globalmente sem compartilhar dados privados =================
     Camadas: PRIVADO/CONFIDENCIAL (dados da conta) · INDIVIDUAL (estilo e preferências do usuário) · INSTITUCIONAL (padrões da conta)
     · GLOBAL (padrões abstratos, sem nomes, números, clientes ou textos). Toda consulta passa por CONTA → USUÁRIO → PERMISSÕES → DADOS.
     Sistema real: tenant_id obrigatório em toda tabela, índice e vetor (row-level security); a camada GLOBAL roda no servidor só com
     padrões anonimizados e limiar de recorrência entre contas distintas. O papel MASTER é atribuído por permissão explícita (BLUEJUS_PAPEIS), nunca pelo texto do e-mail. */
  const CONTA = () => (window.BLUEJUS_CONTA && window.BLUEJUS_CONTA.id) || 'conta-farlei';
  const hashK = s => { let h = 2166136261; for (const ch of String(s)) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); } return (h >>> 0).toString(36); };
  const ORIGENS_K = { GLOBAL: 'Conhecimento geral do BlueJus', INSTITUCIONAL: 'Conhecimento da conta', INDIVIDUAL: 'Conhecimento do usuário', PRIVADO: 'Dado privado da conta', CONFIDENCIAL: 'Dado confidencial' };
  const memK = () => { db.conhecimentoContas = db.conhecimentoContas || {}; const id = CONTA(); if (!db.conhecimentoContas[id]) db.conhecimentoContas[id] = db.conhecimento && !db.conhecimento.conta ? { ...db.conhecimento, conta: id } : { conta: id, itens: [], correcoes: [], indice: null, consultas: [] }; db.conhecimento = db.conhecimentoContas[id]; return db.conhecimento; };
  // Anonimização: remove tudo que identifica conta, pessoa, cliente, processo ou valor antes de qualquer contribuição global.
  function anonimizarK(t) {
    let s = String(t || '');
    s = s.replace(/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/g, '[processo]').replace(/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/g, '[cpf]').replace(/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/g, '[cnpj]').replace(/R\$\s?[\d.,]+/g, '[valor]').replace(/\b[\w.+-]+@[\w-]+\.[\w.]+/g, '[email]').replace(/\bpasta\s*\d+/gi, 'pasta').replace(/\d{2}\/\d{2}(\/\d{2,4})?/g, '[data]').replace(/\b\d{2,}\b/g, 'N');
    USUARIOS.forEach(u => u.nome.split(' ').filter(p => p.length > 2).forEach(p => { s = s.replace(new RegExp('\\b' + p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'gi'), '[pessoa]'); }));
    try { const f = window.__bjIAFontes ? window.__bjIAFontes() : {}; (f.outras_pastas || []).forEach(p => String(p.cliente || '').split(' ').filter(x => x.length > 2).forEach(x => { s = s.replace(new RegExp('\\b' + x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'gi'), '[cliente]'); })); } catch (e) {}
    s = s.replace(/\b[A-ZÀ-Ú][a-zà-ú]{2,}(\s+(d[aeo]s?\s+)?[A-ZÀ-Ú][a-zà-ú]{2,})+/g, m => /^(Publica|Intima|Decis|Senten|Despach|Meta|Pasta|Processo|Juntada|Cita)/.test(m) ? m : '[pessoa]');
    return s.replace(/(\[pessoa\]\s*)+/g, '[pessoa] ').replace(/(\[cliente\]\s*)+/g, '[cliente] ').trim();
  }
  // Camada GLOBAL: guarda só padrões abstratos + hash das contas que contribuíram (nunca o id ou o conteúdo).
  const GL_KEY = 'bj-ia-global-v1', GL_LIMIAR = [[100, 'relevante'], [10, 'possível padrão'], [1, 'observação']];
  const glLer = () => { try { return JSON.parse(localStorage.getItem(GL_KEY) || '{"padroes":{}}'); } catch (e) { return { padroes: {} }; } };
  const glGravar = g => { try { localStorage.setItem(GL_KEY, JSON.stringify(g)); } catch (e) {} };
  const glNivel = n => (GL_LIMIAR.find(([m]) => n >= m) || [0, 'observação'])[1];
  function qualidadeGlobal() { const g = glLer(); let mud = 0; Object.values(g.padroes).forEach(p => { const idade = (Date.now() - (p.ult || p.criado)) / 864e5, rej = p.feedback ? p.feedback.rejeitado / Math.max(1, p.feedback.aceito + p.feedback.rejeitado) : 0; const antes = p.estado; if (p.estado !== 'deprecado' && (idade > 365 || (rej > .6 && (p.feedback.aceito + p.feedback.rejeitado) >= 10))) p.estado = 'deprecado'; else if (['padrão validado', 'conhecimento coletivo'].includes(p.estado) && (idade > 180 || rej > .4)) p.estado = 'em revisão'; if (antes !== p.estado) { mud++; p.historico = (p.historico || []).concat([[Date.now(), antes + ' → ' + p.estado]]); } }); if (mud) glGravar(g); return mud; }
  // Transferência: global → filtro de contexto → regra institucional → permissão → sugestão (nunca vira regra local sozinho).
  function transferirGlobal(pedido, perf) {
    const g = glLer(), t = new Set(tokensK(pedido)), regrasLoc = nucleo().regras.filter(r => r.ativa), out = [];
    Object.values(g.padroes).filter(p => ['padrão validado', 'conhecimento coletivo'].includes(p.estado) && p.contas.length >= 10).forEach(p => {
      const rel = tokensK(p.descricao).filter(w => t.has(w)).length; if (rel < 2) return;
      const conflito = regrasLoc.find(r => r.escopo === 'INSTITUCIONAL' && tokensK(r.texto).filter(w => tokensK(p.descricao).includes(w)).length >= 3);
      out.push({ texto: p.descricao, conf: p.contas.length >= 100 ? 'fortemente validado' : 'consistente', amostra: p.contas.length, conflito: conflito ? conflito.id : null, excecoes: p.excecoes.length, k: hashK(p.descricao) });
    });
    return out.slice(0, 3);
  }
  function benchmarkK(g) {
    const B = glLer().bench = glLer().bench || {}; const a = g.atual; if (!a) return null;
    const local = { atrasadas: a.atrasadas, abertas: a.metasAbertas, pushNL: a.pushNL }, ag = glLer().agregado || null;
    if (!ag || (ag.contas || 0) < 10) return { local, insuf: true, contas: ag ? ag.contas : 0 };
    return { local, ag, insuf: false };
  }
  /* ---------- COLLECTIVE INTELLIGENCE: Privacy Gate → abstração → k mínimo → validação → conhecimento coletivo ---------- */
  const K_MIN = 5, CIDADES = /\b(brasilia|sao paulo|rio de janeiro|belo horizonte|salvador|fortaleza|curitiba|recife|porto alegre|goiania|manaus|belem|taguatinga|ceilandia|aguas claras)\b/;
  const ESTADOS_COL = ['observado', 'em investigação', 'padrão possível', 'padrão validado', 'conhecimento coletivo', 'em revisão', 'deprecado'];
  function privacyGate(texto) {
    const etapas = [], orig = String(texto || ''), n = norm(orig); let s = orig, ok = true;
    const ids = [[/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/, 'número processual'], [/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/, 'CPF'], [/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/, 'CNPJ'], [/[\w.+-]+@[\w-]+\.[\w.]+/, 'e-mail'], [/\(?\d{2}\)?\s?9?\d{4}-?\d{4}/, 'telefone'], [/R\$\s?[\d.,]+/, 'valor'], [/\b(rua|avenida|av\.|quadra|qd\.|lote|cep)\b/i, 'endereço']];
    const achados = ids.filter(([re]) => re.test(orig)).map(([, l]) => l); etapas.push(['Análise de privacidade', achados.length ? 'identificadores: ' + achados.join(', ') : 'sem identificadores diretos']);
    s = anonimizarK(s); etapas.push(['Remoção de identificadores', s !== orig ? 'removidos' : 'nada a remover']);
    // Reidentificação: combinação de quase identificadores
    const qi = [[CIDADES.test(n), 'cidade'], [/\d{2}\/\d{2}/.test(orig), 'data'], [/R\$|\bvalor\b/.test(orig), 'valor'], [/(raro|incomum|unico|inedito|excepcional|primeira vez)/.test(n), 'evento incomum'], [/(vara|turma|camara|juiz|desembargador)\s+\w+/.test(n), 'órgão específico'], [/\bpasta\s*\d+/.test(n), 'número de pasta']].filter(x => x[0]).map(x => x[1]);
    const risco = qi.length >= 3 ? 'alto' : qi.length === 2 ? 'médio' : 'baixo'; etapas.push(['Risco de reidentificação', risco + (qi.length ? ' (' + qi.join(' + ') + ')' : '')]);
    if (risco !== 'baixo') { s = s.replace(CIDADES, '[cidade]').replace(/\[data\]/g, '').replace(/(vara|turma|camara)\s+[\wº°ª]+/gi, '[órgão]').replace(/\s{2,}/g, ' ').trim(); const qi2 = [CIDADES.test(norm(s)), /\d{2}\/\d{2}/.test(s), /R\$/.test(s)].filter(Boolean).length; if (qi2 >= 2) { ok = false; etapas.push(['Generalizar novamente', 'ainda reidentificável · STOP']); } else etapas.push(['Generalizar novamente', 'quase identificadores removidos']); }
    const restos = (s.match(/\b[A-ZÀ-Ú][a-zà-ú]{3,}\b/g) || []).filter(w => !/^(Publica|Intima|Meta|Pasta|Processo|Decis|Senten|Despach|Juntada|Cita|Prazo|Quando|Depois|Antes|Sempre|Documento|Cliente|Este|Esta|Essa|Esse)/.test(w));
    const util = s.replace(/\[[a-zà-ú]+\]|\bN\b/g, ' ').split(/\s+/).filter(w => w.length > 3 && !STOP_K.has(norm(w)) && !/^(cpf|cnpj|processo|cliente|contato|telefone|email)$/.test(norm(w)));
    if (ok && util.length < 3) { ok = false; etapas.push(['Abstração', 'sobrou só identificador removido, sem padrão útil · STOP']); }
    if (ok && restos.length >= 2) { ok = false; etapas.push(['Abstração', 'nomes próprios restantes (' + restos.slice(0, 2).join(', ') + ') · STOP']); } else if (ok) etapas.push(['Abstração', 'padrão abstrato: "' + s.slice(0, 90) + '"']);
    return { ok, texto: s, etapas, risco, achados };
  }
  function contribuirGlobal(chave, descricao, conta, store) {
    const cfg = (prefs.coletivo || {})[conta || CONTA()]; if (cfg && cfg.optout) return null;
    const gate = privacyGate(descricao); if (!gate.ok) { if (!store) { const g = glLer(); g.bloqueados = (g.bloqueados || 0) + 1; glGravar(g); } return null; }
    descricao = gate.texto;
    const g = store || glLer(), d = anonimizarK(descricao), k = hashK(norm(chave.replace(/:.*/, '') + '|' + d.replace(/\[[a-z]+\]|N/g, '')));
    const p = g.padroes[k] = g.padroes[k] || { descricao: d, contas: [], n: 0, criado: Date.now() };
    const hc = hashK('sal:' + (conta || CONTA())); if (!p.contas.includes(hc)) p.contas.push(hc); p.n++; p.ult = Date.now();
    p.tipo = p.tipo || (/erro|falh|retrabalho|atras|duplic/.test(norm(p.descricao)) ? 'negativo' : /sequencia|seguida|depois|antes/.test(norm(p.descricao)) ? 'fluxo' : 'operacional');
    p.versao = (p.versao || 0) + (p.contas.length === K_MIN ? 1 : 0); p.estado = p.estado === 'deprecado' || p.estado === 'em revisão' ? p.estado : p.contas.length >= 100 ? 'conhecimento coletivo' : p.contas.length >= 10 ? 'padrão validado' : p.contas.length >= K_MIN ? 'padrão possível' : p.contas.length >= 2 ? 'em investigação' : 'observado';
    p.excecoes = p.excecoes || []; p.feedback = p.feedback || { aceito: 0, rejeitado: 0 }; p.historico = (p.historico || []).concat(p.n === 1 || p.contas.length === K_MIN || p.contas.length === 10 ? [[Date.now(), p.estado]] : []).slice(-10);
    if (!store) glGravar(g); return p;
  }
  // Perfil de estilo por usuário (INDIVIDUAL): nunca é aplicado a outro usuário, nem vai para a camada global.
  function estiloK(uid) {
    const id = uid || meuId(), ms = (db.msgs || []).filter(m => m.de === id && !m.excl && m.texto && !/^\//.test(m.texto) && !/^Deu um toque/.test(m.texto) && !m.toque).slice(-80).map(m => String(m.texto));
    const M = memK(); M.estilos = M.estilos || {};
    if (ms.length < 5) return { usuario: id, amostras: ms.length, suficiente: false };
    const pal = ms.map(t => t.split(/\s+/).length), media = Math.round(pal.reduce((a, b) => a + b, 0) / pal.length), n = t => norm(t);
    const formal = ms.filter(t => /prezad|por gentileza|solicito|cordialmente|atenciosamente|senhor|doutor|dra\.|dr\./.test(n(t))).length / ms.length;
    const listas = ms.filter(t => /(^|\n)\s*(\d+[.)]|[-•*])\s/.test(t)).length / ms.length;
    const abre = Object.entries(ms.reduce((o, t) => { const w = n(t).split(/[\s,!.]+/)[0]; if (w && w.length > 1) o[w] = (o[w] || 0) + 1; return o; }, {})).sort((a, b) => b[1] - a[1])[0];
    const e = { usuario: id, amostras: ms.length, suficiente: true, tamanho: media <= 12 ? 'curto e direto' : media <= 35 ? 'médio' : 'detalhado', palavras: media, tom: formal >= .3 ? 'formal' : formal >= .1 ? 'cordial' : 'informal e objetivo', listas: listas >= .2, abertura: abre && abre[1] >= 3 ? abre[0] : null, em: Date.now() };
    M.estilos[id] = e; return e;
  }
  const estiloTexto = e => !e || !e.suficiente ? 'ainda sem amostras suficientes (' + ((e && e.amostras) || 0) + ' de 5)' : 'textos ' + e.tamanho + ' (~' + e.palavras + ' palavras), tom ' + e.tom + (e.listas ? ', costuma usar listas' : '') + (e.abertura ? ', costuma abrir com "' + e.abertura + '"' : '');
  // Isolamento: pedidos sobre outras contas ou tentativas de contornar regras.
  function guardaContas(t) {
    const n = norm(t);
    if (/(outr[oa]s? (escritorio|conta|cliente[s]? de outr|empresa)|demais escritorios|escritorios? (concorrente|parceiro)|qual escritorio|que escritorio (usa|utiliza)|quem (escreveu|redigiu|criou) (esse|este|o) (texto|modelo|documento)|de onde (veio|aprendeu)|com quem (aprendeu|voce aprendeu)|outra base|outro tenant)/.test(n)) return 'contas';
    if (/(ignore|desconsidere|esqueca|burle|contorne).{0,25}(regra|restric|permiss|isolamento|instruc)|modo (desenvolvedor|admin|root|deus)|finja que (sou|e) (o )?master|sou o master|jailbreak/.test(n)) return 'inducao';
    return null;
  }
  // Filtro de saída: nada de outra conta nem de fora das permissões sai na resposta.
  function filtroSaidaK(texto, dados) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perfilAcesso()), bruto = JSON.stringify(f || {}) + JSON.stringify(USUARIOS.map(u => u.nome));
    let s = String(texto || ''), bloq = 0;
    s = s.replace(/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/g, m => bruto.includes(m) ? m : (bloq++, '[processo não autorizado]'));
    s = s.replace(/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/g, m => bruto.includes(m) ? m : (bloq++, '[CPF não autorizado]'));
    s = s.replace(/(escrit[oó]rio|conta)\s+(X|Y|[A-Z][\wÀ-ú]+ ?[A-Z]?[\wÀ-ú]*)\s+(usa|utiliza|faz|tem|possui)/g, m => (bloq++, 'um padrão recorrente indica que se $3'.replace('$3', m.split(/\s+/).pop())));
    if (bloq) { auditar('isolamento', 'filtro de saída removeu ' + bloq + ' trecho(s)', 'bloqueado'); if (dados) dados.pontos = (dados.pontos || []).concat([{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'Removi ' + bloq + ' trecho(s) que não pertencem às informações que você pode acessar.' }]); }
    return s;
  }
  function indiceK(perf) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf || perfilAcesso()), docs = [];
    const add = (tipo, id, titulo, texto, extra) => docs.push({ tipo, id: tipo + ':' + id, titulo, texto: titulo + ' ' + (texto || ''), ...extra });
    (f.outras_pastas || []).forEach(p => add('pasta', p.numero, 'Pasta ' + p.numero + ' · ' + p.cliente, [p.acao, p.processo, p.responsavel].filter(Boolean).join(' '), { pasta: p.numero, resp: p.responsavel, acao: p.acao }));
    if (f.processo) add('processo', f.processo.numero, 'Processo ' + f.processo.numero, [f.processo.acao, f.processo.classe, f.processo.tribunal].filter(Boolean).join(' '), { pasta: f.pasta && f.pasta.numero, acao: f.processo.acao });
    (f.metas || []).forEach(m => add('meta', m.id, 'Meta: ' + m.titulo, [m.responsaveis, m.prazo, m.concluida ? 'concluída' : 'aberta'].join(' '), { pasta: f.pasta && f.pasta.numero, meta: m.id, resp: String(m.responsaveis || ''), aberta: !m.concluida, atraso: m.atraso_dias }));
    (f.push || []).forEach((m, i) => add('push', i, (m.tipo || 'Movimentação') + ' · ' + m.data, m.texto, { pasta: f.pasta && f.pasta.numero, ptipo: m.tipo }));
    (f.documentos || []).forEach((d, i) => { const n = typeof d === 'string' ? d : d.nome; add('documento', i + n, n, '', { pasta: f.pasta && f.pasta.numero }); });
    memK().itens.filter(k => k.nivel !== 'descartado').forEach(k => add('memoria', k.id, k.texto, k.contexto || '', { nivel: k.nivel }));
    const M = memK(), assin = docs.map(d => d.id + '|' + d.texto.length).join(',');
    if (!M.indice || M.indice.assin !== assin) {
      const ant = M.indice ? new Set(M.indice.ids) : new Set(), novos = docs.filter(d => !ant.has(d.id)).length;
      M.indice = { assin, ids: docs.map(d => d.id), em: Date.now(), total: docs.length, novos, hist: (M.indice && M.indice.hist || []).concat([[Date.now(), docs.length]]).slice(-20) };
    }
    docs.forEach(d => { d.tk = tokensK(d.texto); });
    return { docs, f };
  }
  function buscarK(q, perf, lim) {
    const t0 = performance.now(), { docs } = indiceK(perf), qt = tokensK(q); if (!qt.length) return { res: [], ms: 0, total: docs.length };
    const df = {}; docs.forEach(d => new Set(d.tk).forEach(w => { df[w] = (df[w] || 0) + 1; }));
    const res = docs.map(d => { const set = new Set(d.tk); let s = 0, exato = 0; new Set(qt).forEach(w => { if (set.has(w)) { const idf = Math.log(1 + docs.length / (df[w] || 1)); s += w[0] === '~' ? idf * .55 : idf; if (w[0] !== '~') exato++; } }); const idade = d.quando ? (Date.now() - d.quando) / 864e5 : 0; s *= idade > 365 ? .7 : idade > 90 ? .85 : 1; s *= penalidadeK('busca', d.id); return { ...d, score: s, exato }; })
      .filter(d => d.score > 0.4).sort((a, b) => b.score - a.score).slice(0, lim || 6);
    const top = res[0] ? res[0].score : 1; res.forEach(r => { r.conf = r.score / top > .75 && r.exato ? 'confirmado' : 'provavel'; });
    const M = memK(); M.consultas = (M.consultas || []).concat([[Date.now(), res.length]]).slice(-50);
    return { res, ms: Math.round(performance.now() - t0), total: docs.length };
  }
  function padroesK(perf) {
    const { docs, f } = indiceK(perf), out = [];
    const pastas = docs.filter(d => d.tipo === 'pasta' && d.acao && d.resp), porAcao = {};
    pastas.forEach(p => { const k = norm(p.acao).split(' ').slice(0, 3).join(' '); (porAcao[k] = porAcao[k] || { nome: p.acao, resp: {} }).resp[p.resp] = (porAcao[k].resp[p.resp] || 0) + 1; });
    Object.values(porAcao).forEach(a => { const tot = Object.values(a.resp).reduce((x, y) => x + y, 0), [r, n] = Object.entries(a.resp).sort((x, y) => y[1] - x[1])[0]; if (tot >= 2 && n / tot >= .6) out.push({ chave: 'resp:' + norm(a.nome), texto: '"' + a.nome + '" costuma ficar com ' + r + ' (' + n + ' de ' + tot + ' pastas)', base: tot, origem: 'Pastas' }); });
    const metas = docs.filter(d => d.tipo === 'meta'), atras = metas.filter(m => m.aberta && m.atraso > 0);
    if (metas.length >= 3 && atras.length / metas.length >= .3) out.push({ chave: 'atraso:metas', texto: atras.length + ' de ' + metas.length + ' metas desta pasta estão atrasadas; a etapa "' + (atras[0].titulo || '').replace('Meta: ', '') + '" é a mais antiga', base: metas.length, origem: 'Metas' });
    const pt = {}; docs.filter(d => d.tipo === 'push').forEach(p => { pt[p.ptipo] = (pt[p.ptipo] || 0) + 1; });
    Object.entries(pt).filter(([, n]) => n >= 2).forEach(([t, n]) => out.push({ chave: 'push:' + norm(t), texto: n + ' movimentações do tipo "' + t + '" recebidas; verifique se cada uma gerou meta ou providência', base: n, origem: 'Push Processual' }));
    const cor = {}; memK().correcoes.forEach(x => { const k = x.campo + '|' + norm(x.para); (cor[k] = cor[k] || { ...x, n: 0, usuarios: new Set() }).n++; cor[k].usuarios.add(x.por); });
    Object.values(cor).filter(x => x.n >= 3).forEach(x => out.push({ chave: 'cor:' + x.campo + ':' + norm(x.para), texto: 'Ao corrigir "' + x.campo + '", os usuários escolheram "' + x.para + '" ' + x.n + ' vezes' + (x.usuarios.size === 1 ? ' (preferência de ' + usuario([...x.usuarios][0]).nome.split(' ')[0] + ')' : ''), base: x.n, origem: 'Correções', pref: x.usuarios.size === 1 }));
    const M = memK(); out.forEach(p => { const k = M.itens.find(i => i.chave === p.chave); p.nivel = k ? k.nivel : (p.pref ? 'preferencia' : 'padrao'); p.validado = k && k.nivel === 'institucional'; p.origemK = p.pref ? 'INDIVIDUAL' : 'INSTITUCIONAL'; if (!p.pref && p.base >= 3) try { contribuirGlobal(p.chave, p.texto); } catch (e) {} });
    return { lista: out, base: docs.length };
  }
  function registrarK(texto, opts) {
    const M = memK(), eu = EU(), o = opts || {}, chave = o.chave || 'inf:' + norm(texto).slice(0, 60);
    const ex = M.itens.find(i => i.chave === chave);
    if (ex) { ex.ocorrencias = (ex.ocorrencias || 1) + 1; ex.ult = Date.now(); if (o.valor && ex.valor && norm(o.valor) !== norm(ex.valor)) { ex.versoes = (ex.versoes || []).concat([{ valor: ex.valor, texto: ex.texto, em: ex.ult || ex.em, nivel: ex.nivel }]); ex.conflito = { valor: o.valor, por: eu.id, em: Date.now(), motivo: o.motivo || 'nova informação divergente' }; } salvar(); return ex; }
    const conflito = o.valor ? M.itens.find(i => i.grupo && i.grupo === o.grupo && i.valor && norm(i.valor) !== norm(o.valor)) : null;
    const tipoMem = o.memoria || (/costum|normalmente|sempre que|quando .* (cria|faz)/.test(norm(texto)) ? 'procedimental' : /prefer|gosta|costuma revisar/.test(norm(texto)) ? 'preferencia' : /aconteceu|houve|foi (criad|conclu|publicad)/.test(norm(texto)) ? 'episodica' : /e uma|significa|e o mesmo que|especie de/.test(norm(texto)) ? 'semantica' : 'institucional');
    const k = { id: 'k' + Date.now().toString(36), chave, memoria: tipoMem, versoes: [], texto: semTraco(texto).slice(0, 400), nivel: o.nivel || 'nao_confirmado', fonte: o.fonte || 'Informado no Chat I.A', por: eu.id, em: Date.now(), ocorrencias: 1, contexto: o.contexto || '', grupo: o.grupo, valor: o.valor };
    if (conflito) { k.conflito = { com: conflito.id }; conflito.conflito = { com: k.id }; }
    M.itens.push(k); try { diario('memória', 'nova memória (' + k.memoria + ', ' + k.nivel + '): ' + k.texto.slice(0, 80)); } catch (e) {} salvar(); return k;
  }
  function aprenderCorrecao(x) { try { if (govK().bloqueioAprendizado) return; } catch (e) {} const M = memK(); M.correcoes.push({ campo: x.campo, de: x.de, para: x.para, contexto: x.contexto || '', por: x.por || meuId(), em: Date.now() }); M.correcoes = M.correcoes.slice(-400); salvar(); }
  // Aprendizado negativo: sugestão recusada repetidamente perde prioridade (≥3 recusas no mesmo tipo e valor).
  function recusaK(tipo, valor) { const M = memK(); M.recusas = M.recusas || {}; const k = tipo + '|' + norm(valor); M.recusas[k] = (M.recusas[k] || 0) + 1; salvar(); return M.recusas[k]; }
  const penalidadeK = (tipo, valor) => { const n = ((memK().recusas || {})[tipo + '|' + norm(valor)] || 0); return n >= 3 ? .35 : n === 2 ? .7 : 1; };
  window.addEventListener('bj-recusa', e => { try { recusaK(e.detail.tipo, e.detail.valor); } catch (x) {} });
  window.addEventListener('bj-correcao', e => { try { aprenderCorrecao(e.detail || {}); } catch (x) {} });
  /* ---------- Camada cognitiva: contexto → intenção → entidades → recuperação híbrida → hipóteses → evidências → autoverificação ---------- */
  function contextoCog(pedido, perf) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf), t = norm(pedido);
    const pastaN = (t.match(/pasta\s*(\d{2,6})/) || [])[1] || (f.pasta && String(f.pasta.numero)), proc = (pedido.match(/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/) || [])[0] || (f.processo && f.processo.numero);
    const pessoa = USUARIOS.find(u => t.includes(norm(u.nome.split(' ')[0])) && u.id !== meuId());
    const intencao = /o que depende|o que (isso|essa pendencia|esta pendencia) (afeta|bloqueia)|sera afetad|o que pode acontecer se|se (essa|esta|a) (tarefa|meta|pendencia)/.test(t) ? null : /parad|travad|esquecid|sem andamento|alguma coisa (parada|pendente)|pendenc/.test(t) ? 'parado' : /pront[ao]|completa|pode protocolar|esta ok|esta em dia/.test(t) ? 'pronto' : /plano|etapas|passo a passo|preparar|como fazer para/.test(t) ? 'plano' : /proxima providencia|o que fazer agora|o que falta/.test(t) ? 'proximo' : null;
    const periodo = /hoje/.test(t) ? 'hoje' : /semana/.test(t) ? 'semana' : /mes/.test(t) ? 'mês' : null;
    return { f, t, pastaN, proc, pessoa, intencao, periodo };
  }
  function evidenciasCog(cx) {
    const f = cx.f, metas = f.metas || [], push = f.push || [], docs = (f.documentos || []).map(d => typeof d === 'string' ? d : d.nome), abertas = metas.filter(m => !m.concluida), atras = abertas.filter(m => m.atraso_dias > 0);
    const pushNL = push.filter(p => !p.lida), pede = push.filter(p => /intima|manifest|prazo|cumpr|despach|decis|senten/.test(norm(p.texto + ' ' + p.tipo)));
    const temMetaPara = p => abertas.concat(metas).some(m => { const a = new Set(tokensK(m.titulo)); return tokensK(p.texto).filter(w => a.has(w)).length >= 2; });
    const excAp = (() => { try { return Object.values(skillsK()).flatMap(s => s.excecoes.filter(e => e.status === 'aprendida')); } catch (e) { return []; } })();
    const isento = p => excAp.some(e => /meta/.test(norm(e.regra)) && norm(e.regra).includes(norm(p.tipo).split(' ')[0]));
    const semMeta = pede.filter(p => !temMetaPara(p) && !isento(p)), semResp = abertas.filter(m => !String(m.responsaveis || '').trim());
    const H = [];
    H.push({ h: 'Há movimentação que pede providência sem meta correspondente', ok: semMeta.length > 0, ev: semMeta.length ? semMeta.slice(0, 2).map(p => p.tipo + ' de ' + p.data + ' sem meta parecida') : ['Todas as ' + pede.length + ' movimentações com providência têm meta relacionada'], fonte: 'Push Processual + Metas', peso: 3 });
    H.push({ h: 'Existe meta atrasada travando a pasta', ok: atras.length > 0, ev: atras.length ? atras.slice(0, 2).map(m => '"' + m.titulo + '" atrasada há ' + m.atraso_dias + ' dias (' + (m.responsaveis || 'sem responsável') + ')') : ['Nenhuma meta aberta em atraso'], fonte: 'Metas', peso: 3 });
    H.push({ h: 'Movimentações recebidas ainda não foram lidas', ok: pushNL.length > 0, ev: [pushNL.length + ' de ' + push.length + ' movimentações sem leitura'], fonte: 'Push Processual', peso: 1 });
    H.push({ h: 'Meta aberta sem responsável', ok: semResp.length > 0, ev: semResp.length ? semResp.slice(0, 2).map(m => '"' + m.titulo + '"') : ['Todas as metas abertas têm responsável'], fonte: 'Metas', peso: 2 });
    H.push({ h: 'Faltam documentos básicos (procuração)', ok: !docs.some(d => /procura/.test(norm(d))), ev: docs.some(d => /procura/.test(norm(d))) ? ['Procuração localizada nos documentos'] : ['Não localizei procuração entre ' + docs.length + ' documentos'], fonte: 'Documentos', peso: 2 });
    H.push({ h: 'Pasta sem processo vinculado', ok: !f.processo, ev: f.processo ? ['Processo ' + f.processo.numero + ' vinculado'] : ['Nenhum processo vinculado'], fonte: 'Processo', peso: 1 });
    nucleo().erros.filter(e => e.termo && !/procura/.test(norm(e.termo))).forEach(e => { const tem = docs.some(d => tokensK(d).some(w => tokensK(e.termo).includes(w))); H.push({ h: 'Falta ' + e.termo + ' (verificação aprendida com correção anterior)', ok: !tem, ev: [tem ? e.termo + ' localizado(a) nos documentos' : 'Não localizei ' + e.termo + ' entre ' + docs.length + ' documentos'], fonte: 'Núcleo Cognitivo + Documentos', peso: 2 }); });
    return { H, metas, abertas, atras, push, pushNL, pede, semMeta, docs };
  }
  function cogResposta(pedido, cmd, perf) {
    const cx = contextoCog(pedido, perf), c = cmd ? cmd.c : cx.intencao ? 'investigar' : null;
    if (!['investigar', 'plano', 'maturidade'].includes(c)) return null;
    const base = { modulos: ['Conhecimento', 'Metas', 'Push Processual', 'Documentos'], comando: 'Raciocínio' };
    if (!cx.pastaN && c !== 'maturidade') return { ...base, resposta: 'Para investigar, abra uma pasta ou diga o número (ex.: "a pasta 1180 está pronta?").', pontos: [], acoes: [] };
    if (cx.f.pasta && cx.pastaN && String(cx.f.pasta.numero) !== String(cx.pastaN)) return { ...base, resposta: 'A pasta ' + cx.pastaN + ' não está aberta. Para não usar dados de outra pasta, abra a pasta ' + cx.pastaN + ' e pergunte de novo.', pontos: [{ nivel: 'info', camada: 'dado', texto: 'Autoverificação: evitei misturar informações da pasta ' + cx.f.pasta.numero + ' com a ' + cx.pastaN + '.', confianca: 'confirmado' }], acoes: [{ tipo: 'abrir_pasta', rotulo: 'Abrir pasta ' + cx.pastaN, pasta: cx.pastaN, url: '/expediente/pasta/' + cx.pastaN }] };
    const E = evidenciasCog(cx), P = cx.pastaN;
    if (c === 'plano' || (cmd && cmd.c === 'investigar' && cx.intencao === 'plano')) {
      const obj = (cmd && cmd.arg) || pedido;
      const st = (ok, l, ev) => (ok ? '✓ ' : '○ ') + l + (ev ? ' · ' + ev : '');
      const proc = /protocol|peticion|ajuiz|distribu/.test(norm(obj));
      const etapas = [st(E.docs.some(d => /procura/.test(norm(d))), 'Conferir documentação', E.docs.length + ' documentos na pasta'), st(true, 'Conferir dados cadastrais do cliente', 'pelo botão Cadastro'), st(!!cx.f.processo, 'Conferir processo vinculado', cx.f.processo ? cx.f.processo.numero : 'sem processo'), st(!E.atras.length, 'Verificar prazos e metas', E.atras.length ? E.atras.length + ' meta(s) atrasada(s)' : 'sem atraso')]
        .concat(proc ? ['○ Preparar a peça', '○ Revisar', '○ Protocolar', '○ Registrar o protocolo no Registro dos expedientes'] : ['○ Executar a providência', '○ Registrar o resultado na pasta']);
      return { ...base, resposta: 'Plano para "' + semTraco(obj).slice(0, 80) + '" na Pasta ' + P + '. As etapas marcadas com ✓ já têm evidência no sistema; nada é executado sem sua confirmação.', secoes: [{ titulo: 'Etapas', itens: etapas }], pontos: [], acoes: [{ tipo: 'criar_meta', rotulo: 'Criar meta da próxima etapa', titulo: (etapas.find(e => e[0] === '○') || '').slice(2).split(' · ')[0], pasta: P }] };
    }
    if (c === 'maturidade') {
      const t0 = performance.now(), sim = buscarK('cobrança condominial', perf, 3), pad = padroesK(perf), M = memK();
      const anom = E.H.filter(h => h.ok), hip = E.H[0], niveis = [
        ['1 Encontrar informação', sim.total > 0, sim.total + ' itens acessíveis'], ['2 Relacionar', E.metas.length + E.push.length > 0, 'pasta ↔ processo ↔ metas ↔ push'], ['3 Interpretar', E.pede.length >= 0, E.pede.length + ' movimentações que pedem providência'],
        ['4 Comparar com histórico', (M.indice && M.indice.hist || []).length > 1, (M.indice && M.indice.hist || []).length > 1 ? 'há leituras anteriores' : 'primeira leitura, sem histórico'], ['5 Identificar padrões', pad.lista.length > 0, pad.lista.length + ' padrões'],
        ['6 Detectar anomalias', true, anom.length + ' pontos fora do esperado'], ['7 Formular hipóteses', true, E.H.length + ' hipóteses'], ['8 Investigar hipóteses', true, 'primeira: ' + (hip.ok ? 'confirmada' : 'não confirmada')],
        ['9 Planejar providências', true, 'plano gerado com /plano'], ['10 Gerar solução contextual', true, 'ações com caminho clicável'], ['11 Aprender com correção', M.correcoes.length > 0, M.correcoes.length + ' correções registradas'], ['12 Usar o aprendizado', pad.lista.some(p => p.origem === 'Correções') || M.itens.some(i => i.nivel === 'institucional'), M.itens.filter(i => i.nivel === 'institucional').length + ' conhecimentos validados']];
      const ok = niveis.filter(n => n[1]).length;
      return { ...base, resposta: 'Teste de maturidade com os dados reais que você pode acessar: ' + ok + ' de 12 níveis demonstrados (' + Math.round(performance.now() - t0) + ' ms). Os níveis em aberto dependem de histórico e correções, que vêm com o uso.', metricas: [{ rotulo: 'Níveis', valor: ok + '/12' }], secoes: [{ titulo: 'Níveis', itens: niveis.map(n => (n[1] ? '✓ ' : '○ ') + n[0] + ' · ' + n[2]) }], pontos: [], acoes: [] };
    }
    const conf = E.H.filter(h => h.ok).sort((a, b) => b.peso - a.peso), naoConf = E.H.filter(h => !h.ok);
    const peso = conf.reduce((s, h) => s + h.peso, 0), confianca = peso >= 3 ? 'confirmado' : conf.length ? 'provavel' : 'confirmado';
    const pergunta = cx.intencao === 'pronto' ? (conf.length ? 'Não completamente.' : 'Sim, pelo que consta no sistema.') : cx.intencao === 'parado' ? (conf.length ? 'Sim, encontrei pontos parados.' : 'Não encontrei nada parado.') : (conf.length ? 'Próxima providência: ' + conf[0].h.toLowerCase() + '.' : 'Não há providência pendente identificável.');
    const atencao = conf.filter(h => h.peso >= 2), recomend = conf[0];
    return { ...base, resposta: pergunta + ' Investiguei ' + E.H.length + ' hipóteses na Pasta ' + P + ' cruzando ' + [...new Set(E.H.map(h => h.fonte.split(' + ')).flat())].join(', ') + '. ' + (conf.length ? conf.length + ' se confirmaram' + (atencao.length ? ', ' + atencao.length + ' merecem atenção' : '') + '.' : 'Nenhuma se confirmou.') + (recomend ? ' Recomendo começar por: ' + recomend.h.toLowerCase() + '.' : ''),
      secoes: [{ titulo: 'Cadeia de evidências', itens: conf.map(h => '✓ ' + h.h + ' · ' + h.ev.join('; ') + ' · origem: ' + h.fonte) }, { titulo: 'Hipóteses não confirmadas', itens: naoConf.map(h => '○ ' + h.h + ' · ' + h.ev[0]) }],
      pontos: [{ nivel: 'info', camada: 'dado', confianca, texto: 'Autoverificação: usei só dados da Pasta ' + P + ' acessíveis ao seu perfil; ' + (confianca === 'confirmado' ? 'conclusão com evidência direta.' : 'conclusão provável, vale conferir.') + ' Correlação não é tratada como causa.' }],
      acoes: (E.atras[0] ? [{ tipo: 'abrir_meta', rotulo: 'Abrir meta atrasada', pasta: P, meta: E.atras[0].id }] : []).concat(E.semMeta[0] ? [{ tipo: 'criar_meta', rotulo: 'Criar meta para a movimentação', titulo: 'Analisar ' + E.semMeta[0].tipo + ' de ' + E.semMeta[0].data, pasta: P }] : []).concat(E.pushNL.length ? [{ tipo: 'abrir_push', rotulo: 'Ler movimentações', pasta: P }] : []) };
  }
  /* ================= BLUEJUS COGNITIVE CORE (Núcleo Cognitivo) =================
     Camada própria entre o sistema e o modelo de linguagem: o modelo pode mudar, o núcleo (regras, prompts, memórias,
     relações, aprendizados e erros) continua sendo do BlueJus. Órgãos: Knowledge · Memory · Relationship · Rule · Reasoning · Learning · Governance.
     Sistema real: tabelas cognitive_rules, cognitive_prompts, cognitive_memory (por conta), com versão e auditoria. */
  const REGRAS_BASE = [
    ['R001', 'Nunca inventar informação processual.', 'Segurança'], ['R002', 'Antes de responder sobre uma pasta, verificar documentos, metas, expedientes e movimentações relacionadas.', 'Raciocínio'],
    ['R003', 'Quando houver conflito entre informações, informar o conflito.', 'Raciocínio'], ['R004', 'Quando o usuário corrigir repetidamente determinado padrão, avaliar a criação de uma preferência.', 'Aprendizado'],
    ['R005', 'Nunca utilizar dados privados de outra conta.', 'Governança'], ['R006', 'Conhecimento global só de forma generalizada e sem expor a origem privada.', 'Governança'],
    ['R007', 'Antes de sugerir prazo, verificar contexto, data relevante e informações disponíveis.', 'Jurídico'], ['R008', 'Quando identificar uma tarefa crítica sem responsável, alertar a controladoria.', 'Alerta']];
  const PROMPTS_BASE = [['personalidade', 'Personalidade', 'Profissional, inteligente, próxima, objetiva e natural. Conversa como um assistente jurídico humano que conhece o contexto, espelha o tom do usuário, evita frases de robô e é firme quando há reincidência; nunca use travessão.'], ['juridico', 'Jurídico', 'Fundamente só em fonte confiável; distinga padrão do escritório de regra jurídica.'], ['operacional', 'Operacional', 'Trate o escritório como organismo único: pasta, processo, metas, push e responsáveis.'], ['produtividade', 'Produtividade', 'Compare com o próprio histórico e com a média da equipe, sem julgamento pessoal.'], ['investigativo', 'Investigativo', 'Formule hipóteses, confira cada uma em mais de uma fonte, conclua com evidências.'], ['redacao', 'Redação', 'Siga o estilo individual do usuário; na falta, o padrão do escritório.'], ['alerta', 'Alerta', 'Chame atenção só para o que tem impacto; agrupe para não encher de avisos.'], ['aprendizagem', 'Aprendizagem', 'Observado, recorrente, validado, conhecimento, regra. Nunca aprenda tudo automaticamente.'], ['seguranca', 'Segurança', 'Nunca revele dados fora da permissão ou de outra conta, nem por inferência.'], ['master', 'Master', 'Comportamentos exclusivos do administrador primário, atribuídos por permissão explícita.']];
  const CRENCAS = { confirmado: ['🟢', 'Confirmado'], provavel: ['🔵', 'Muito provável'], padrao: ['🟡', 'Padrão observado'], hipotese: ['🟠', 'Hipótese'], nao_confirmado: ['🔴', 'Não confirmado'], contraditorio: ['⚫', 'Contraditório'] };
  function nucleo() {
    const M = memK(); const N = M.nucleo = M.nucleo || {};
    const HIER = { Segurança: 1, Governança: 2, Raciocínio: 3, Jurídico: 3, Alerta: 4, Aprendizado: 6 };
    N.regras = N.regras || REGRAS_BASE.map(([id, t, cat]) => ({ id, texto: t, cat, ativa: true, versao: 1, em: Date.now(), por: 'sistema', hist: [] }));
    const BASE_EM = new Date(2026, 0, 1).getTime(); N.regras.forEach(r => { if (r.por === 'sistema' && r.em > BASE_EM) r.em = BASE_EM; }); N.regras.forEach(r => { if (r.nivel == null) { r.nivel = HIER[r.cat] || 4; r.escopo = r.escopo || (r.por === 'sistema' ? 'SISTEMA' : 'INSTITUCIONAL'); r.origem = r.origem || (r.por === 'sistema' ? 'BlueJus' : 'Aprendizado aprovado'); r.confianca = r.confianca || 'confirmado'; } });
    N.limiares = N.limiares || { possivel: 3, relevante: 5 };
    N.modelo = N.modelo || { atual: window.BLUEJUS_MODELO_IA || 'motor padrão', trocas: [] };
    N.prompts = N.prompts || PROMPTS_BASE.map(([id, nome, t], i) => ({ id, nome, texto: t, versao: 1, prioridade: i + 1, status: 'ativo', em: Date.now() }));
    N.aprendizados = N.aprendizados || []; N.erros = N.erros || []; N.eventos = N.eventos || []; N.trabalho = N.trabalho || {};
    return N;
  }
  const evN = t => { const N = nucleo(); N.eventos.push([Date.now(), t]); N.eventos = N.eventos.slice(-40); };
  // Learning Core: correções recorrentes viram aprendizado provisório com confiança; validação define o destino.
  function aprenderRecorrencia() {
    const N = nucleo(), M = memK(), g = {};
    M.correcoes.forEach(x => { const k = x.campo + '|' + norm(x.contexto || '') + '|' + norm(x.para); (g[k] = g[k] || { ...x, n: 0, users: new Set(), de: new Set() }).n++; g[k].users.add(x.por); g[k].de.add(norm(x.de)); });
    Object.entries(g).forEach(([k, x]) => {
      let a = N.aprendizados.find(i => i.chave === k);
      const conf = Math.min(97, Math.round(50 + 12 * Math.log2(1 + x.n) + (x.users.size > 1 ? 8 : 0)));
      const L = N.limiares, estagio = x.n >= L.relevante ? 'recorrente' : x.n >= L.possivel ? 'recorrente' : 'observado';
      const fase = x.n >= L.relevante ? 'Padrão identificado' : x.n >= L.possivel ? 'Em aprendizado' : 'Observado';
      const escopo = x.users.size > 1 ? 'INSTITUCIONAL' : 'INDIVIDUAL';
      if (!a) { a = { chave: k, campo: x.campo, contexto: x.contexto, valor: x.para, n: x.n, conf, estagio, fase, escopo, status: 'aguardando', usuarios: [...x.users], em: Date.now() }; N.aprendizados.push(a); if (estagio === 'recorrente') evN('Novo padrão: ' + x.campo + ' costuma ser ajustado para ' + x.para); }
      else if (a.status === 'aguardando' || a.status === 'preferencia') { if (a.estagio !== estagio && estagio === 'recorrente') evN('Padrão ficou recorrente: ' + x.campo + ' → ' + x.para); Object.assign(a, { n: x.n, conf, estagio, fase, escopo, usuarios: [...x.users] }); }
    });
    salvar(); return N.aprendizados;
  }
  // Memória de erros: "não, falta X" logo após uma conclusão da IA vira verificação preventiva.
  function registrarErroIA(cid, texto) {
    const t = norm(texto); if (!/^(nao|errado|incorreto|esta errado|na verdade|existe sim|tem sim)\b/.test(t)) return null;
    const ult = convMsgs(cid).filter(m => m.de === 'ia' && !m.excl).slice(-1)[0]; if (!ult || Date.now() - ult.quando > 30 * 60e3) return null;
    const excecao = /(neste|nesse|deste|desse) (processo|caso|cliente)|nesta pasta|so (aqui|nesse caso)|especificamente/.test(t);
    const falta = (t.match(/falt(a|ando|ou)\s+(a |o |os |as )?([a-z0-9 ]{3,40}?)(\.|,|$| e | para | na | no )/) || [])[3];
    const N = nucleo(), e = { id: 'e' + Date.now().toString(36), contexto: (ult.iaDados && ult.iaDados.comando) || 'resposta da IA', erro: semTraco(String(ult.texto).slice(0, 140)), correcao: falta ? falta.trim() + ' deve ser conferido(a) antes de concluir' : semTraco(texto).slice(0, 160), termo: falta ? falta.trim() : null, por: meuId(), em: Date.now(), status: 'incorporado' };
    if (excecao) { e.escopo = 'CONTEXTO'; const w = (nucleo().trabalho || {})[cid] || {}; registrarK(semTraco(texto).slice(0, 160), { chave: 'exc:' + hashK(texto), nivel: 'nao_confirmado', memoria: 'excecao', fonte: 'Correção (exceção)', contexto: w.pasta ? 'Pasta ' + w.pasta : '' }); const it = memK().itens.slice(-1)[0]; if (it) { it.escopo = 'CONTEXTO'; it.contextoPasta = w.pasta || null; } }
    e.tipo = tipoErro(texto); const MT = metaK(); MT.erroTipos[e.tipo] = (MT.erroTipos[e.tipo] || 0) + 1;
    if (e.tipo === 'pesquisa incompleta') e.correcao = 'Procurar também em subpastas e categorias relacionadas antes de dizer que algo não existe' + (falta ? ' (caso: ' + falta.trim() + ')' : '');
    try { diario('correção', e.correcao); } catch (x) {} N.erros.push(e); evN('Erro corrigido e incorporado (' + e.tipo + '): ' + e.correcao); auditar('nucleo', 'erro da IA corrigido pelo usuário', 'registrado'); salvar(); return e;
  }
  // Working memory: assunto atual da conversa (pasta, processo, documento, meta, responsável).
  function memoriaTrabalho(cid, pedido) {
    const N = nucleo(), w = N.trabalho[cid] = N.trabalho[cid] || {}, t = norm(pedido), f = window.__bjIAFontes ? window.__bjIAFontes() : {};
    const pasta = (t.match(/pasta\s*(\d{2,6})/) || [])[1]; if (pasta && pasta !== w.pasta) { Object.keys(w).forEach(k => delete w[k]); w.pasta = pasta; }
    if (!w.pasta && f.pasta) w.pasta = String(f.pasta.numero);
    const doc = (t.match(/(contestacao|procuracao|manifestacao|peticao|notificacao|contrato|recurso|sentenca|decisao)/) || [])[1]; if (doc) w.documento = doc;
    const meta = (f.metas || []).find(m => t.includes(norm(m.titulo).split(' ').slice(0, 2).join(' '))); if (meta) { w.meta = meta.titulo; w.responsavel = meta.responsaveis; w.prazo = meta.prazo; }
    if (f.processo) w.processo = f.processo.numero; const up = (f.push || [])[0]; if (up) w.ultimaPublicacao = up.tipo + ' ' + up.data;
    w.em = Date.now(); return w;
  }
  // Relationship Core: grafo da pasta atual.
  function grafoK() {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perfilAcesso()), A = [];
    if (!f.pasta) return A; const P = 'Pasta ' + f.pasta.numero;
    A.push(['Cliente ' + f.pasta.cliente, 'tem', P]); if (f.processo) A.push([P, 'vincula', 'Processo ' + f.processo.numero]);
    (f.push || []).forEach(p => { A.push(['Processo ' + ((f.processo || {}).numero || ''), 'recebeu', p.tipo + ' ' + p.data]); const m = (f.metas || []).find(m => { const s = new Set(tokensK(m.titulo)); return tokensK(p.texto).filter(w => s.has(w)).length >= 2; }); if (m) A.push([p.tipo + ' ' + p.data, 'provavelmente gerou', 'Meta ' + m.titulo]); });
    (f.metas || []).forEach(m => A.push(['Meta ' + m.titulo, m.responsaveis ? 'responsável' : 'sem responsável', m.responsaveis || '!']));
    (f.documentos || []).forEach(d => A.push([P, 'contém', typeof d === 'string' ? d : d.nome]));
    return A;
  }
  function crencaDe(item) { if (item.conflito) return 'contraditorio'; return ({ institucional: 'confirmado', confirmado: 'confirmado', padrao: 'padrao', preferencia: 'padrao', hipotese: 'hipotese', nao_confirmado: 'nao_confirmado' })[item.nivel] || 'hipotese'; }
  function nucleoResposta(pedido, cmd, perf, cid) {
    const c = cmd && cmd.c; if (!['nucleo', 'regras', 'aprendizados', 'grafo', 'contexto', 'memoria', 'testecognitivo'].includes(c)) return null;
    const N = nucleo(), M = memK(), eu = EU(), adm = perf.papel === 'MASTER' || perf.papel === 'CONTROLADORIA' || eu.id === MASTER_ID();
    const base = { modulos: ['Núcleo Cognitivo'], comando: 'Núcleo Cognitivo', pontos: [], acoes: [] };
    if (c === 'memoria') {
      const g = glLer(), e = estiloK(), w = N.trabalho[cid] || {}, por = s => M.itens.filter(i => (i.memoria || 'institucional') === s).length;
      return { ...base, resposta: 'Painel de memória da sua conta. Cada camada fica separada: o que é global não carrega dado privado, e o que é individual não vale para outros usuários.',
        secoes: [{ titulo: 'Por escopo', itens: ['Global (abstrato): ' + Object.keys(g.padroes).length + ' padrões', 'Institucional (conta): ' + M.itens.filter(i => i.nivel !== 'preferencia').length + ' memórias', 'Individual (você): ' + (M.itens.filter(i => i.nivel === 'preferencia' && i.por === eu.id).length + (e.suficiente ? 1 : 0)) + ' preferências' + (e.suficiente ? ', incluindo estilo de escrita' : ''), 'Temporária (esta conversa): ' + Object.keys(w).filter(k => k !== 'em').length + ' itens de contexto'] },
          { titulo: 'Por tipo', itens: ['Semântica: ' + por('semantica'), 'Episódica: ' + por('episodica'), 'Procedimental: ' + por('procedimental'), 'Preferência: ' + por('preferencia'), 'Institucional: ' + por('institucional'), 'Aprendizagem: ' + N.aprendizados.length, 'Erros corrigidos: ' + N.erros.length, 'Correções registradas: ' + M.correcoes.length] }] };
    }
    if (c === 'testecognitivo') {
      if (!adm) { auditar('nucleo', 'tentativa de rodar testes cognitivos', 'negado'); return { ...base, resposta: 'Os testes cognitivos são exclusivos do Master.' }; }
      const T = testesCognitivos(perf, cid), ok = T.filter(t => t[1]).length;
      return { ...base, resposta: ok + ' de ' + T.length + ' testes passaram com os dados reais disponíveis.' + (ok < T.length ? ' Os que não passaram dependem de histórico ainda inexistente, não de falha.' : ''), metricas: [{ rotulo: 'Testes', valor: ok + '/' + T.length }], secoes: [{ titulo: 'Resultados', itens: T.map(t => (t[1] ? '✓ ' : '○ ') + t[0] + ' · ' + t[2]) }] };
    }
    if (c === 'contexto') { const w = memoriaTrabalho(cid, ''); return { ...base, resposta: 'Memória de trabalho desta conversa. Uso isto enquanto o assunto continuar; ao mudar de pasta, recomeço.', secoes: [{ titulo: 'Contexto atual', itens: Object.entries(w).filter(([k]) => k !== 'em').map(([k, v]) => ({ pasta: 'Pasta', processo: 'Processo', documento: 'Documento', meta: 'Meta', responsavel: 'Responsável', prazo: 'Prazo', ultimaPublicacao: 'Última publicação' })[k] + ': ' + v) }] }; }
    if (c === 'grafo') { const A = grafoK(); return { ...base, resposta: A.length ? 'Relações que o núcleo identificou na pasta aberta (' + A.length + '). "Provavelmente" indica ligação inferida, não confirmada.' : 'Abra uma pasta para eu montar as relações.', secoes: A.length ? [{ titulo: 'Grafo de conhecimento', itens: A.slice(0, 18).map(([a, r, b]) => a + ' → ' + r + ' → ' + b) }] : [] }; }
    if (c === 'regras') return { ...base, resposta: N.regras.filter(r => r.ativa).length + ' regras ativas e ' + N.prompts.filter(p => p.status === 'ativo').length + ' módulos de prompt. Ficam separados, com versão, em vez de um único prompt gigante.' + (adm ? '' : ' Somente o Master e a Controladoria alteram.'),
      secoes: [{ titulo: 'Regras de inteligência', itens: N.regras.slice().sort((a, b) => a.nivel - b.nivel).map(r => r.id + ' · ' + r.texto + ' · ' + r.cat + ' · nível ' + r.nivel + ' · ' + r.escopo + ' · v' + r.versao + (r.ativa ? '' : ' · desativada')) }, { titulo: 'Hierarquia em caso de conflito', itens: ['1 Segurança', '2 Permissões', '3 Regras do sistema', '4 Regras institucionais', '5 Preferências do usuário', '6 Contexto da conversa', '7 Sugestões aprendidas', 'Uma preferência aprendida nunca supera segurança ou permissão.'] }, { titulo: 'Prompts cognitivos', itens: N.prompts.sort((a, b) => a.prioridade - b.prioridade).map(p => p.nome + ' · v' + p.versao + ' · prioridade ' + p.prioridade + ' · ' + p.status) }] };
    const L = aprenderRecorrencia(), pend = L.filter(a => a.status === 'aguardando' && a.estagio === 'recorrente');
    if (c === 'aprendizados') {
      if (!pend.length) return { ...base, resposta: 'Nenhum aprendizado aguardando validação. ' + L.filter(a => a.estagio === 'observado').length + ' observações ainda não se repetiram o suficiente (mínimo 3).' };
      return { ...base, resposta: pend.length + ' aprendizado(s) recorrente(s) aguardando decisão. Nenhum muda o comportamento da IA até você decidir.', secoes: pend.map(a => ({ titulo: 'Padrão identificado', itens: [a.campo + (a.contexto ? ' em ' + a.contexto : '') + ' costuma ser ajustado para "' + a.valor + '"', 'Confiança: ' + a.conf + '%', 'Fonte: ' + a.n + ' ocorrências' + (a.usuarios.length === 1 ? ' de ' + usuario(a.usuarios[0]).nome.split(' ')[0] : ' de ' + a.usuarios.length + ' usuários'), 'Fase: ' + (a.fase || 'Em aprendizado') + ' · escopo sugerido: ' + (a.escopo === 'INDIVIDUAL' ? 'individual (preferência)' : 'institucional (conta)'), 'Revisão: não conflita com regras de segurança ou permissão · ' + (a.escopo === 'INDIVIDUAL' ? 'por ser de um só usuário, não vira regra da conta sem aprovação' : 'precisa de aprovação para virar regra'), 'Status: padrão aprendido, aguardando validação'] })),
        acoes: adm ? pend.slice(0, 1).flatMap(a => [{ tipo: 'nucleo_aprov', rotulo: 'Aprovar como regra', chave: a.chave, destino: 'regra' }, { tipo: 'nucleo_aprov', rotulo: 'Manter como preferência', chave: a.chave, destino: 'preferencia' }, { tipo: 'nucleo_aprov', rotulo: 'Ignorar', chave: a.chave, destino: 'ignorado' }]) : [{ tipo: 'nucleo_aprov', rotulo: 'Manter como minha preferência', chave: pend[0].chave, destino: 'preferencia' }] };
    }
    const cr = {}; M.itens.forEach(i => { const k = crencaDe(i); cr[k] = (cr[k] || 0) + 1; }); L.forEach(a => { const k = a.status === 'regra' ? 'confirmado' : a.estagio === 'recorrente' ? 'padrao' : 'hipotese'; cr[k] = (cr[k] || 0) + 1; });
    const { docs } = indiceK(perf), { lista } = padroesK(perf), pref = L.filter(a => a.status === 'preferencia').length + M.itens.filter(i => i.nivel === 'preferencia').length;
    return { ...base, resposta: 'BlueJus Cognitive Core. O núcleo organiza conhecimento, memória, regras, relações e aprendizado antes de cada resposta; o modelo de linguagem é só o mecanismo de raciocínio e pode ser trocado sem perder nada disto.',
      metricas: [{ rotulo: 'Conhecimento', valor: String(docs.length) }, { rotulo: 'Memórias', valor: String(M.itens.length) }, { rotulo: 'Padrões', valor: String(lista.length + L.filter(a => a.estagio === 'recorrente').length) }, { rotulo: 'Preferências', valor: String(pref) }, { rotulo: 'Regras ativas', valor: String(N.regras.filter(r => r.ativa).length + L.filter(a => a.status === 'regra').length) }, { rotulo: 'Aguardando validação', valor: String(pend.length) }, { rotulo: 'Contradições', valor: String(M.itens.filter(i => i.conflito).length) }, { rotulo: 'Erros corrigidos', valor: String(N.erros.length) }],
      secoes: [{ titulo: 'Estado do conhecimento', itens: Object.keys(CRENCAS).map(k => CRENCAS[k][0] + ' ' + CRENCAS[k][1] + ': ' + (cr[k] || 0)) }, { titulo: 'Últimos aprendizados', itens: N.eventos.slice(-6).reverse().map(([t, x]) => new Date(t).toLocaleDateString('pt-BR') + ' · ' + x).concat(N.eventos.length ? [] : ['Nenhum ainda. Aparecem conforme correções e padrões se repetem.']) }, { titulo: 'Erros que viraram prevenção', itens: N.erros.slice(-4).reverse().map(e => e.correcao).concat(N.erros.length ? [] : ['Nenhum registrado']) }, { titulo: 'Órgãos do núcleo', itens: ['Knowledge · índice e conhecimento', 'Memory · factual, episódica, procedimental, preferência, institucional, aprendizagem, contexto', 'Relationship · grafo cliente → pasta → processo → publicação → meta → responsável', 'Rule · regras e prompts versionados', 'Reasoning · hipóteses e evidências', 'Learning · observado, recorrente, validado, conhecimento, regra', 'Governance · permissões, isolamento e auditoria'] }],
      acoes: [{ tipo: 'enviar_comando', rotulo: 'Regras e prompts', texto: '/regras' }, { tipo: 'enviar_comando', rotulo: 'Aprendizados', texto: '/aprendizados' }, { tipo: 'enviar_comando', rotulo: 'Grafo da pasta', texto: '/grafo' }] };
  }
  /* ================= METACOGNITIVE ENGINE =================
     Camada acima do Cognitive Core: antes de responder, avalia o próprio conhecimento (sei? de onde? ainda vale? fato ou inferência?
     há contradição? já errei nisso? preciso perguntar?). Mostra só conclusão, evidências, confiança, lacunas e providência. */
  const CONF_META = [['alta', 'Confirmação alta'], ['media', 'Confirmação média'], ['hipotese', 'Hipótese'], ['incerto', 'Incerto'], ['desconhecido', 'Desconhecido']];
  const metaK = () => { const N = nucleo(); N.meta = N.meta || { avaliacoes: [], contradicoes: [], decisoes: [], resultados: [], erroTipos: {} }; return N.meta; };
  // Lacunas: o que é essencial para a pergunta e não existe nos dados.
  function lacunasMeta(f, intencao) {
    const L = [], ess = { pasta: ['responsável', 'última movimentação', 'processo', 'metas', 'documentos', 'comunicação com o cliente'], prazo: ['data de ciência ou publicação', 'processo', 'responsável'], geral: ['pasta aberta'] }[intencao] || ['responsável', 'última movimentação', 'metas'];
    const tem = { 'responsável': f.pasta && f.pasta.responsavel, 'última movimentação': (f.push || []).length, 'processo': !!f.processo, 'metas': (f.metas || []).length, 'documentos': (f.documentos || []).length, 'comunicação com o cliente': (f.atendimentos || f.comunicacoes || []).length, 'data de ciência ou publicação': (f.push || []).some(p => /intima|publica/.test(norm(p.tipo))), 'pasta aberta': !!f.pasta };
    ess.forEach(e => { if (!tem[e]) L.push(e); }); return L;
  }
  // Contradições: mesma entidade com afirmações opostas em fontes diferentes; resolve pela mais recente quando há comprovante.
  function contradicoesMeta(f) {
    const C = [], M = metaK(), txt = (f.push || []).map(p => ({ t: norm(p.texto + ' ' + p.tipo), d: p.data, o: 'Push ' + p.tipo }));
    (f.metas || []).forEach(m => { const fin = txt.find(x => /(cumprid|atendid|juntad|protocolad|realizad)/.test(x.t) && tokensK(m.titulo).filter(w => tokensK(x.t).includes(w)).length >= 2); if (fin && !m.concluida && m.atraso_dias > 0) C.push({ chave: 'meta:' + m.id, a: 'Meta "' + m.titulo + '" aberta e atrasada', b: fin.o + ' de ' + fin.d + ' indica que a providência pode ter sido feita', resolvida: false, sugestao: 'conferir e concluir a meta, se confirmado' }); });
    if (f.financeiro && f.financeiro.recebido > 0 && /sem pagamento|nao pago|inadimpl/.test(norm(JSON.stringify(f.metas || '')))) C.push({ chave: 'fin', a: 'Financeiro com valor recebido', b: 'Meta menciona falta de pagamento', resolvida: false, sugestao: 'verificar comprovante e data' });
    memK().itens.filter(i => i.conflito).forEach(i => C.push({ chave: 'mem:' + i.id, a: i.texto, b: 'registro divergente ' + (i.conflito.valor ? '("' + i.conflito.valor + '")' : ''), resolvida: false, sugestao: 'validar qual vale' }));
    C.forEach(x => { const ex = M.contradicoes.find(y => y.chave === x.chave); if (!ex) M.contradicoes.push({ ...x, em: Date.now() }); });
    M.contradicoes.forEach(y => { if (!C.some(x => x.chave === y.chave) && !y.resolvida) { y.resolvida = true; y.resolvidaEm = Date.now(); } });
    return C;
  }
  // Obsolescência: memória antiga ou substituída perde força.
  function validadeMem(i) { const d = (Date.now() - (i.ult || i.em)) / 864e5; return i.nivel === 'descartado' ? 'arquivada' : i.conflito ? 'em conflito' : (i.versoes || []).length ? 'substituída parcialmente' : d > 365 ? 'desatualizada' : d > 120 ? 'perdendo relevância' : 'válida'; }
  // Avaliação metacognitiva de uma resposta já montada.
  function metaAvaliar(pedido, dados, perf) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf), d = dados || {}, P = d.pontos || [];
    const fatos = P.filter(p => p.camada === 'dado' && p.confianca === 'confirmado').length + ((d.secoes || [])[0] && /evid/i.test(d.secoes[0].titulo) ? d.secoes[0].itens.length : 0);
    const infer = P.filter(p => p.camada === 'hipotese' || p.confianca === 'provavel').length;
    const intencao = /pasta|parad|pront|pendenc/.test(norm(pedido)) ? 'pasta' : /prazo|venc/.test(norm(pedido)) ? 'prazo' : 'geral';
    const lac = d.comando && /Segurança|Isolamento|Núcleo|Conhecimento/.test(d.comando) ? [] : lacunasMeta(f, intencao);
    const contra = contradicoesMeta(f), M = metaK();
    const erros = nucleo().erros.filter(e => tokensK(pedido + ' ' + (d.comando || '')).some(w => tokensK(e.contexto + ' ' + e.correcao).includes(w))).slice(-2);
    const memUsadas = memK().itens.filter(i => (d.resposta || '').includes(i.texto.slice(0, 20))), velhas = memUsadas.filter(i => validadeMem(i) !== 'válida');
    let conf = fatos >= 2 && !contra.length && lac.length <= 1 ? 'alta' : fatos >= 1 || infer >= 2 ? 'media' : infer ? 'hipotese' : lac.length >= 3 ? 'desconhecido' : 'incerto';
    if (contra.length && conf === 'alta') conf = 'media'; if (velhas.length && conf === 'alta') conf = 'media';
    const perguntar = (conf === 'incerto' || conf === 'desconhecido') && lac.length ? 'Para aumentar a precisão, preciso de: ' + lac.slice(0, 2).join(' e ') + '.' : null;
    const check = [['Respondi ao que foi perguntado', !!d.resposta], ['Usei dados do contexto atual', !!f.pasta || intencao === 'geral'], ['Diferenciei fato de inferência', true], ['Sem contradição aberta', !contra.length], ['Sem memória desatualizada', !velhas.length], ['Nada inventado (filtro de saída ativo)', true], ['Conclusão com evidência', fatos > 0 || !/pasta|prazo/.test(intencao)], ['Confiança compatível', !(conf === 'alta' && infer > fatos)], ['Erro semelhante já corrigido considerado', true]];
    const av = { em: Date.now(), pedido: semTraco(pedido).slice(0, 80), comando: d.comando || 'livre', conf, fatos, infer, lacunas: lac, contradicoes: contra.length, errosConsiderados: erros.length, check: check.filter(x => !x[1]).map(x => x[0]) };
    M.avaliacoes.push(av); M.avaliacoes = M.avaliacoes.slice(-200);
    return { ...av, perguntar, contraLista: contra, erros };
  }
  function metaAnexar(dados, av) {
    if (!dados || !av) return; const rot = (CONF_META.find(x => x[0] === av.conf) || [0, av.conf])[1];
    dados.meta = { conf: av.conf, rotulo: rot, lacunas: av.lacunas, contradicoes: av.contradicoes };
    const extra = [];
    if (av.lacunas.length && av.conf !== 'alta') extra.push({ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'O que falta: ' + av.lacunas.join(', ') + '. Não completei essas lacunas por suposição.' });
    av.contraLista.slice(0, 2).forEach(x => extra.push({ nivel: 'atencao', camada: 'dado', confianca: 'confirmado', texto: 'Contradição: ' + x.a + ' × ' + x.b + '. Sugestão: ' + x.sugestao + '.' }));
    if (av.erros.length) extra.push({ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'Considerei correção anterior: ' + av.erros[0].correcao + '.' });
    if (av.perguntar) extra.push({ nivel: 'info', camada: 'hipotese', confianca: 'provavel', texto: av.perguntar });
    extra.push({ nivel: av.conf === 'alta' ? 'ok' : 'info', camada: 'dado', confianca: 'confirmado', texto: 'Confiança: ' + rot + (av.check.length ? ' · revisar: ' + av.check.join(', ') : ' · check final sem pendências') });
    dados.pontos = (dados.pontos || []).concat(extra);
  }
  // Classificação de erro (Error Memory por tipo).
  function tipoErro(t) { const n = norm(t); return /existe sim|esta (na|em)|voce nao viu|esqueceu/.test(n) ? 'pesquisa incompleta' : /prazo|dias|vence/.test(n) ? 'prazo interpretado incorretamente' : /responsavel|quem cuida|nao e (o|a) /.test(n) ? 'responsável identificado incorretamente' : /antig|desatualiz|ja mudou|nao e mais/.test(n) ? 'informação desatualizada' : /certeza|afirmou|nao da para dizer/.test(n) ? 'excesso de confiança' : 'interpretação equivocada'; }
  // Causa raiz + "e se?" para pasta parada.
  function causaRaiz(perf) {
    const E = evidenciasCog(contextoCog('pasta parada', perf)), f = E && filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf), causas = [];
    if (E.semMeta.length) causas.push(['Movimentação sem providência registrada', E.semMeta.length * 3, E.semMeta[0].tipo + ' de ' + E.semMeta[0].data + ' não gerou meta']);
    if (E.atras.length) causas.push(['Meta atrasada sem andamento', 2 + Math.min(3, Math.floor(E.atras[0].atraso_dias / 15)), '"' + E.atras[0].titulo + '" com ' + E.atras[0].atraso_dias + ' dias de atraso (' + (E.atras[0].responsaveis || 'sem responsável') + ')']);
    if (E.pushNL.length) causas.push(['Movimentações não lidas', 1, E.pushNL.length + ' sem leitura']);
    if (!E.docs.some(d => /procura/.test(norm(d)))) causas.push(['Documento essencial ausente', 2, 'procuração não localizada']);
    causas.sort((a, b) => b[1] - a[1]);
    const cen = [];
    if (E.atras[0]) cen.push('E se continuar parada mais 5 dias? A meta "' + E.atras[0].titulo + '" chegaria a ' + (E.atras[0].atraso_dias + 5) + ' dias de atraso; pela regra de cobrança, seria escalada à controladoria.');
    if (E.semMeta[0]) cen.push('E se a ' + E.semMeta[0].tipo.toLowerCase() + ' não for analisada? Pode haver prazo correndo sem meta; o prazo não está confirmado sem a data de ciência.');
    cen.push('E se a tarefa for redistribuída? O novo responsável receberia ' + E.abertas.length + ' meta(s) aberta(s) desta pasta.');
    return { E, causas, cen, f };
  }
  // Simulação de regra antes de aprovar.
  function simularRegra(campo, valor, perf) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf), metas = f.metas || [], afet = metas.filter(m => !m.concluida && tokensK(m.titulo + ' ' + campo).length);
    const conflitos = nucleo().regras.filter(r => r.ativa && tokensK(r.texto).filter(w => tokensK(campo).includes(w)).length >= 1 && r.cat !== 'Segurança');
    const resp = [...new Set(afet.map(m => m.responsaveis).filter(Boolean))];
    return { afetadas: afet.length, responsaveis: resp, conflitos: conflitos.map(r => r.id), duplicidade: metas.filter(m => norm(m.titulo).includes(norm(valor))).length, risco: conflitos.length ? 'médio' : afet.length > 10 ? 'médio' : 'baixo' };
  }
  function metaResposta(pedido, cmd, perf, cid) {
    const c = cmd && cmd.c, t = norm(pedido), nl = !cmd && (/causa raiz|por que (esta|ficou) parad|por que nao anda/.test(t) ? 'causa' : /^e se\b/.test(t) ? 'esequ' : /voce (sabe|tem certeza)|de onde (voce )?sabe|qual (a sua )?confianca/.test(t) ? 'autoavaliacao' : null);
    const k = c || nl; if (!['metacognicao', 'causa', 'esequ', 'autoavaliacao', 'simular', 'decisao'].includes(k)) return null;
    const base = { modulos: ['Metacognição'], comando: 'Metacognição', pontos: [], acoes: [] }, M = metaK(), N = nucleo(), adm = perf.papel === 'MASTER' || perf.papel === 'CONTROLADORIA';
    if (k === 'causa' || k === 'esequ') {
      const { E, causas, cen, f } = causaRaiz(perf); if (!f.pasta) return { ...base, resposta: 'Abra uma pasta para eu investigar.' };
      if (k === 'esequ') return { ...base, resposta: 'Cenários projetados com os dados da Pasta ' + f.pasta.numero + '. São projeções, não fatos.', secoes: [{ titulo: 'E se…', itens: cen }] };
      if (!causas.length) return { ...base, resposta: 'Não encontrei causa para a pasta estar parada. Os dados não mostram atraso, movimentação sem meta nem documento faltando.' };
      return { ...base, resposta: 'Problema: Pasta ' + f.pasta.numero + ' sem avanço. Causa provável: ' + causas[0][0].toLowerCase() + ' (' + causas[0][2] + ').', secoes: [{ titulo: 'Evidências', itens: causas.map(x => x[0] + ' · ' + x[2]) }, { titulo: 'Causas alternativas', itens: causas.slice(1).map(x => x[0]).concat(causas.length < 2 ? ['nenhuma outra com evidência'] : []) }, { titulo: 'Providência sugerida', itens: [causas[0][0].startsWith('Movimentação') ? 'Criar meta para analisar a movimentação' : causas[0][0].startsWith('Meta') ? 'Falar com o responsável e redefinir prazo' : 'Regularizar o item apontado'] }],
        pontos: [{ nivel: 'info', camada: 'hipotese', confianca: 'provavel', texto: 'Correlação não é causa: a causa provável é a de maior evidência, e as alternativas continuam possíveis.' }], acoes: E.atras[0] ? [{ tipo: 'abrir_meta', rotulo: 'Abrir meta atrasada', pasta: f.pasta.numero, meta: E.atras[0].id }] : [] };
    }
    if (k === 'simular') {
      const arg = (cmd && cmd.arg) || ''; const [campo, valor] = arg.split(/\s*=\s*|\s+para\s+/); if (!campo) return { ...base, resposta: 'Use: /simular <regra> = <valor>. Ex.: /simular intimação = criar meta de manifestação.' };
      const s = simularRegra(campo, valor || '', perf);
      return { ...base, resposta: 'Simulação da regra "' + campo + (valor ? ' → ' + valor : '') + '" antes de aplicar. Nada foi alterado.', secoes: [{ titulo: 'Efeitos estimados', itens: ['Metas afetadas: ' + s.afetadas, 'Responsáveis envolvidos: ' + (s.responsaveis.join(', ') || 'nenhum'), 'Possíveis duplicidades: ' + s.duplicidade, 'Conflitos com regras: ' + (s.conflitos.join(', ') || 'nenhum'), 'Risco operacional: ' + s.risco] }] };
    }
    if (k === 'decisao') {
      const arg = ((cmd && cmd.arg) || '').trim(); if (arg.length < 6) return { ...base, resposta: 'Use: /decisao <o que foi decidido> porque <motivo>.' };
      const [dec, mot] = arg.split(/\s+porque\s+/i), w = nucleo().trabalho[cid] || {};
      M.decisoes.push({ em: Date.now(), por: meuId(), contexto: w.pasta ? 'Pasta ' + w.pasta : 'geral', decisao: semTraco(dec), motivo: mot ? semTraco(mot) : '', resultado: null }); salvar();
      const sem = M.decisoes.filter(x => x.contexto === (w.pasta ? 'Pasta ' + w.pasta : 'geral')).length;
      return { ...base, resposta: 'Decisão registrada' + (w.pasta ? ' na Pasta ' + w.pasta : '') + '. Vou acompanhar o resultado para reconhecer situações parecidas no futuro.' + (sem > 1 ? ' Já são ' + sem + ' decisões neste contexto.' : '') };
    }
    if (k === 'autoavaliacao') {
      const ult = convMsgs(cid).filter(m => m.de === 'ia' && m.iaDados && m.iaDados.meta).slice(-1)[0];
      if (!ult) return { ...base, resposta: 'Ainda não avaliei uma resposta nesta conversa.' };
      const m = ult.iaDados.meta; return { ...base, resposta: 'Sobre a minha última resposta: confiança ' + m.rotulo.toLowerCase() + '. ' + (m.lacunas.length ? 'Faltava: ' + m.lacunas.join(', ') + '. ' : 'Não havia lacuna essencial. ') + (m.contradicoes ? m.contradicoes + ' contradição(ões) em aberto.' : 'Sem contradição aberta.') + ' A informação veio dos dados da pasta acessíveis a você, e a parte inferida está marcada como hipótese.' };
    }
    const A = M.avaliacoes, rec = A.slice(-30), ant = A.slice(-60, -30), taxa = a => a.length ? Math.round(a.filter(x => x.conf === 'alta' || x.conf === 'media').length / a.length * 100) : null;
    const tipos = Object.entries(M.erroTipos).sort((a, b) => b[1] - a[1]), corr = N.erros.length, alu = (db.auditoria || []).filter(x => x.recurso === 'isolamento' && /filtro de saída/.test(x.acao)).length;
    const evo = taxa(rec) != null && taxa(ant) != null ? (taxa(rec) > taxa(ant) ? 'Estou melhorando: respostas com confiança média ou alta subiram de ' + taxa(ant) + '% para ' + taxa(rec) + '%.' : taxa(rec) < taxa(ant) ? 'Atenção: a confiança caiu de ' + taxa(ant) + '% para ' + taxa(rec) + '%.' : 'Desempenho estável.') : 'Ainda não há histórico para medir evolução (preciso de 60 avaliações).';
    return { ...base, resposta: 'Metacognição da BlueJus IA. Antes de cada resposta relevante avalio o que sei, de onde sei, se ainda vale, o que falta e se há contradição. ' + evo,
      metricas: [{ rotulo: 'Avaliações', valor: String(A.length) }, { rotulo: 'Confiança ≥ média', valor: (taxa(A) ?? 0) + '%' }, { rotulo: 'Lacunas', valor: String(A.reduce((s, x) => s + x.lacunas.length, 0)) }, { rotulo: 'Contradições', valor: String(M.contradicoes.filter(x => !x.resolvida).length) }, { rotulo: 'Erros corrigidos', valor: String(corr) }, { rotulo: 'Trechos bloqueados', valor: String(alu) }],
      secoes: [{ titulo: 'Erros recorrentes', itens: tipos.length ? tipos.map(([k, v]) => k + ': ' + v) : ['Nenhum erro classificado ainda'] }, { titulo: 'Contradições', itens: M.contradicoes.slice(-5).map(x => (x.resolvida ? '✓ resolvida · ' : '⚠ aberta · ') + x.a + ' × ' + x.b).concat(M.contradicoes.length ? [] : ['Nenhuma detectada']) }, { titulo: 'Validade da memória', itens: ['válida', 'perdendo relevância', 'desatualizada', 'substituída parcialmente', 'em conflito', 'arquivada'].map(s => s + ': ' + memK().itens.filter(i => validadeMem(i) === s).length) }, { titulo: 'Decisões registradas', itens: M.decisoes.slice(-4).map(x => x.contexto + ' · ' + x.decisao + (x.motivo ? ' (motivo: ' + x.motivo + ')' : '')).concat(M.decisoes.length ? [] : ['Use /decisao para registrar']) }, { titulo: 'Distinções mantidas', itens: ['Memória ≠ verdade', 'Padrão ≠ regra', 'Inferência ≠ fato', 'Possibilidade ≠ certeza', 'Correlação ≠ causa', 'Informação antiga ≠ atual', 'Sugestão ≠ decisão'] }] };
  }
  /* ================= SITUATIONAL AWARENESS + DIGITAL TWIN + ACTION & ORCHESTRATION =================
     Gêmeo digital: estado vivo de cada pasta acessível (com instantâneos para comparar antes × agora).
     Consciência situacional: estado, anomalias, gargalos, dependências, antecipação e prioridades.
     Orquestração: problema → plano com etapas, dependências, caminho crítico, responsáveis, checkpoints, planos B/C,
     autonomia por risco (preparar ≠ executar), verificação pós-ação e aprendizado com o resultado.
     Sistema real: o gêmeo é recalculado no servidor a cada evento (meta, push, documento) para todas as pastas da conta. */
  const ESTADOS_P = { ativa: ['Ativa', '#186c47'], terceiro: ['Aguardando terceiro', '#5b3fa0'], cliente: ['Aguardando cliente', '#8a5a12'], advogado: ['Aguardando advogado', '#8a5a12'], documento: ['Aguardando documento', '#8a5a12'], prazo: ['Prazo em andamento', '#0b4f6c'], parada: ['Parada', '#a3232b'], risco: ['Risco', '#a3232b'], sem_encerramento: ['Concluída sem encerramento', '#6b8494'] };
  const diasDe = d => { const m = String(d || '').match(/(\d{2})\/(\d{2})\/(\d{4})/); return m ? Math.floor((Date.now() - new Date(+m[3], +m[2] - 1, +m[1]).getTime()) / 864e5) : null; };
  function estadoPasta(f) {
    const metas = f.metas || [], ab = metas.filter(m => !m.concluida), at = ab.filter(m => m.atraso_dias > 0), push = f.push || [], ult = push[0], dUlt = ult ? diasDe(ult.data) : null;
    const pede = push.filter(p => /intima|manifest|prazo|cumpr|despach|decis|senten/.test(norm(p.texto + ' ' + p.tipo)));
    const semMeta = pede.filter(p => !metas.some(m => tokensK(m.titulo).filter(w => tokensK(p.texto).includes(w)).length >= 2));
    const sinais = [];
    if (at.length && semMeta.length) return { e: 'risco', motivo: semMeta[0].tipo + ' de ' + semMeta[0].data + ' sem meta e meta atrasada há ' + at[0].atraso_dias + ' dias', sinais };
    if (semMeta.length && diasDe(semMeta[0].data) != null && diasDe(semMeta[0].data) <= 15) return { e: 'prazo', motivo: semMeta[0].tipo + ' de ' + semMeta[0].data + ' pode ter prazo correndo', sinais };
    if (ab.some(m => /cliente|documento|enviar|assinatura/.test(norm(m.titulo)))) return { e: /cliente/.test(norm(ab.map(m => m.titulo).join(' '))) ? 'cliente' : 'documento', motivo: 'meta depende de ' + (ab.find(m => /cliente/.test(norm(m.titulo))) ? 'resposta do cliente' : 'documento'), sinais };
    if (at.length) return { e: 'advogado', motivo: '"' + at[0].titulo + '" atrasada há ' + at[0].atraso_dias + ' dias com ' + (at[0].responsaveis || 'sem responsável'), sinais };
    if (ult && /conclus|juntad|remessa|vista|aguardando/.test(norm(ult.tipo + ' ' + ult.texto)) && !ab.length) return { e: 'terceiro', motivo: 'última movimentação (' + ult.tipo + ') depende do juízo ou de outra parte', sinais };
    if (dUlt != null && dUlt > 10 && !ab.length) return { e: 'parada', motivo: 'sem movimentação há ' + dUlt + ' dias e sem meta aberta', sinais };
    if (metas.length && !ab.length && f.pasta && f.pasta.situacao === 'Ativo') return { e: 'sem_encerramento', motivo: 'todas as metas concluídas, pasta ainda ativa', sinais };
    return { e: 'ativa', motivo: ab.length ? ab.length + ' meta(s) em andamento' : 'sem pendência identificada', sinais };
  }
  function gemeoK(perf) {
    const f = filtrarAcesso(window.__bjIAFontes ? window.__bjIAFontes() : {}, perf || perfilAcesso()), N = nucleo(); N.gemeo = N.gemeo || { snaps: {} };
    const pastas = (f.outras_pastas || []).map(p => ({ numero: String(p.numero), cliente: p.cliente, resp: p.responsavel, processo: p.processo, acao: p.acao, completo: false }));
    let atual = null;
    if (f.pasta) {
      const st = estadoPasta(f), metas = f.metas || [];
      atual = { numero: String(f.pasta.numero), cliente: f.pasta.cliente, resp: f.pasta.responsavel, processo: f.processo && f.processo.numero, estado: st.e, motivo: st.motivo, completo: true,
        metasAbertas: metas.filter(m => !m.concluida).length, atrasadas: metas.filter(m => !m.concluida && m.atraso_dias > 0).length, pushNL: (f.push || []).filter(p => !p.lida).length, ultMov: (f.push || [])[0] ? f.push[0].tipo + ' ' + f.push[0].data : null, docs: (f.documentos || []).length, metasTit: metas.map(m => m.titulo + (m.concluida ? ' ✓' : '')) };
      const i = pastas.findIndex(p => p.numero === atual.numero); if (i >= 0) pastas[i] = { ...pastas[i], ...atual }; else pastas.unshift(atual);
    }
    const carga = {}; pastas.forEach(p => { if (p.resp) (carga[p.resp] = carga[p.resp] || { pastas: 0, atrasadas: 0, abertas: 0 }).pastas++; });
    if (atual) (f.metas || []).filter(m => !m.concluida).forEach(m => String(m.responsaveis || '').split(/,\s*/).filter(Boolean).forEach(r => { const x = carga[r] = carga[r] || { pastas: 0, atrasadas: 0, abertas: 0 }; x.abertas++; if (m.atraso_dias > 0) x.atrasadas++; }));
    return { pastas, atual, carga, f, N };
  }
  function snapshotGemeo(atual) { if (!atual) return null; const N = nucleo(), S = N.gemeo.snaps, k = CONTA() + ':' + atual.numero, prev = S[k]; const hoje = new Date().toDateString(); if (!prev || new Date(prev.em).toDateString() !== hoje) { S[k] = { em: Date.now(), por: meuId(), ...JSON.parse(JSON.stringify(atual)), anterior: prev ? { ...prev, anterior: undefined } : null }; salvar(); } return S[k]; }
  function mudancasPasta(atual) {
    const N = nucleo(), s = N.gemeo.snaps[CONTA() + ':' + atual.numero], prev = s && (new Date(s.em).toDateString() === new Date().toDateString() ? s.anterior : s);
    if (!prev) return null; const dif = [], dias = Math.round((Date.now() - prev.em) / 864e5);
    if (prev.estado !== atual.estado) dif.push('Estado: ' + ESTADOS_P[prev.estado][0] + ' → ' + ESTADOS_P[atual.estado][0]);
    [['metasAbertas', 'Metas abertas'], ['atrasadas', 'Metas atrasadas'], ['pushNL', 'Movimentações não lidas'], ['docs', 'Documentos']].forEach(([k, l]) => { if (prev[k] !== atual[k]) dif.push(l + ': ' + prev[k] + ' → ' + atual[k]); });
    if (prev.ultMov !== atual.ultMov) dif.push('Nova movimentação: ' + atual.ultMov);
    if (prev.resp !== atual.resp) dif.push('Responsável: ' + prev.resp + ' → ' + atual.resp);
    const novas = (atual.metasTit || []).filter(t => !(prev.metasTit || []).includes(t)); novas.forEach(t => dif.push('Meta ' + (t.endsWith('✓') ? 'concluída: ' + t.slice(0, -2) : 'nova: ' + t)));
    return { dias, prev, dif };
  }
  function prioridadesK(g) {
    const P = { URGENTE: [], IMPORTANTE: [], 'PODE AGUARDAR': [], 'AGUARDANDO TERCEIRO': [], 'SEM INTERVENÇÃO': [] };
    g.pastas.forEach(p => { if (!p.completo) return; const e = p.estado, x = 'Pasta ' + p.numero + ' · ' + ESTADOS_P[e][0] + ' · ' + p.motivo;
      (e === 'risco' || e === 'prazo' ? P.URGENTE : e === 'advogado' || e === 'parada' || e === 'documento' ? P.IMPORTANTE : e === 'terceiro' || e === 'cliente' ? P['AGUARDANDO TERCEIRO'] : e === 'sem_encerramento' ? P['PODE AGUARDAR'] : P['SEM INTERVENÇÃO']).push(x); });
    const semDados = g.pastas.filter(p => !p.completo).length; if (semDados) P['SEM INTERVENÇÃO'].push(semDados + ' outras pastas sem dados detalhados nesta tela (abra a pasta para eu avaliar)');
    return P;
  }
  function naoEstouVendo(g) {
    const f = g.f, out = [], a = g.atual; if (!a) return out;
    if (a.pushNL) out.push(a.pushNL + ' movimentação(ões) ainda não lida(s) no Push');
    if (!(f.documentos || []).some(d => /procura/.test(norm(typeof d === 'string' ? d : d.nome)))) out.push('Procuração não localizada nos documentos');
    if (f.financeiro && f.financeiro.contratado && f.financeiro.recebido < f.financeiro.contratado) out.push('Financeiro: recebido abaixo do contratado');
    (f.metas || []).filter(m => !m.concluida && !String(m.responsaveis || '').trim()).forEach(m => out.push('Meta sem responsável: ' + m.titulo));
    if (!f.processo) out.push('Pasta sem processo vinculado');
    const ctr = metaK().contradicoes.filter(x => !x.resolvida); if (ctr.length) out.push(ctr.length + ' contradição(ões) em aberto');
    const resp = a.resp, ct = g.carga[resp]; if (ct && ct.pastas >= 3) out.push(resp + ' responde por ' + ct.pastas + ' pastas da lista; verifique a carga antes de atribuir mais');
    return out;
  }
  function gargaloFluxo(g) {
    const f = g.f, push = f.push || [], metas = f.metas || [], E = ['Entrada', 'Triagem', 'Distribuição', 'Análise', 'Providência', 'Revisão', 'Encerramento'], cnt = E.map(() => 0);
    cnt[0] = push.length; cnt[1] = push.filter(p => !p.lida).length; cnt[2] = metas.filter(m => !m.concluida && !String(m.responsaveis || '').trim()).length;
    cnt[3] = push.filter(p => /intima|decis|despach|senten/.test(norm(p.tipo + ' ' + p.texto))).filter(p => !metas.some(m => tokensK(m.titulo).filter(w => tokensK(p.texto).includes(w)).length >= 2)).length;
    cnt[4] = metas.filter(m => !m.concluida && m.atraso_dias > 0).length; cnt[5] = metas.filter(m => !m.concluida && /revis/.test(norm(m.titulo))).length; cnt[6] = metas.filter(m => m.concluida).length;
    const trav = cnt.slice(1, 6).map((n, i) => [E[i + 1], n]).sort((a, b) => b[1] - a[1])[0];
    return { E, cnt, trav };
  }
  // Autonomia: o que a IA pode fazer por tipo de ação e risco.
  const AUTON = { informar: [0, 'baixo'], sugerir: [1, 'baixo'], checklist: [1, 'baixo'], rascunho: [2, 'baixo'], relatorio: [2, 'baixo'], criar_meta: [2, 'médio'], redistribuir: [2, 'alto'], alterar_prazo: [2, 'alto'], envio_externo: [2, 'alto'], protocolo: [2, 'alto'], excluir: [2, 'alto'], financeiro: [2, 'alto'] };
  const autonomiaDe = tipo => { const cfg = (nucleo().autonomia || {})[tipo]; const [n, r] = AUTON[tipo] || [1, 'médio']; return { nivel: cfg != null ? Math.min(cfg, r === 'alto' ? 2 : 4) : n, risco: r, confirmar: r !== 'baixo' }; };
  function planejar(problema, g) {
    const f = g.f, a = g.atual, metas = f.metas || [], E = evidenciasCog(contextoCog(problema, perfilAcesso()));
    const resp = a.resp, sec = USUARIOS.filter(u => u.nome !== resp && /advog/i.test(u.funcao || '')).map(u => u.nome)[0] || null, aprov = (USUARIOS.find(u => ehControladoria(u.id)) || {}).nome || 'Controladoria';
    const etapas = [], add = (t, dono, dep, crit, ext) => etapas.push({ id: etapas.length + 1, titulo: t, resp: dono, dep, criterio: crit, externo: !!ext, status: 'pendente' });
    if (E.pushNL.length) add('Ler as ' + E.pushNL.length + ' movimentação(ões) novas', resp, [], 'todas marcadas como lidas');
    if (E.semMeta.length) add('Analisar ' + E.semMeta[0].tipo.toLowerCase() + ' de ' + E.semMeta[0].data + ' e conferir prazo', resp, E.pushNL.length ? [1] : [], 'meta criada com prazo conferido');
    if (!E.docs.some(d => /procura/.test(norm(d)))) add('Obter procuração do cliente', resp, [], 'procuração anexada', true);
    E.atras.slice(0, 2).forEach(m => add('Concluir "' + m.titulo + '"', m.responsaveis || resp, [], 'meta concluída', /cliente|document/.test(norm(m.titulo))));
    if (/protocol|peti|manifest|resolver/.test(norm(problema))) { const dPrev = etapas.map(e => e.id); add('Elaborar a peça', resp, dPrev, 'minuta salva na pasta'); add('Revisar', sec || aprov, [etapas.length], 'revisão registrada'); add('Protocolar e registrar no expediente', resp, [etapas.length], 'protocolo anexado'); }
    add('Verificar resultado e encerrar a providência', aprov, etapas.map(e => e.id), 'pasta sem pendência na investigação');
    const ESF = t => /elaborar|peca|minuta/.test(norm(t)) ? 2 : /revis/.test(norm(t)) ? 1 : /protocol/.test(norm(t)) ? 1 : /obter|cliente|document/.test(norm(t)) ? 3 : 1;
    const util = (d, n) => { const x = new Date(d); let k = 0; while (k < n) { x.setDate(x.getDate() + 1); if (x.getDay() % 6) k++; } return x; };
    etapas.forEach(e => { const ini = e.dep.length ? Math.max(...e.dep.map(i => etapas[i - 1].fim)) : Date.now(); e.esforco = ESF(e.titulo); e.fim = util(ini, e.esforco).getTime(); e.prazo = new Date(e.fim).toLocaleDateString('pt-BR'); e.prioridade = /analisar|protocol/.test(norm(e.titulo)) ? 'urgente' : e.externo ? 'dependente' : 'importante'; });
    const crit = []; let cur = etapas[etapas.length - 1]; while (cur) { crit.unshift(cur.id); const d = cur.dep.map(i => etapas[i - 1]).filter(Boolean); cur = d.find(x => x.externo) || d[d.length - 1]; }
    const bloqueio = etapas.find(e => e.externo && crit.includes(e.id));
    return { id: 'p' + Date.now().toString(36), pasta: a.numero, problema: semTraco(problema).slice(0, 100), objetivo: 'Pasta ' + a.numero + ' sem pendência e com a providência registrada', etapas, caminho: crit, bloqueio: bloqueio ? bloqueio.titulo : null, papeis: { principal: resp, secundario: sec, aprovador: aprov },
      checkpoints: etapas.filter((e, i) => i === 0 || e.titulo.startsWith('Elaborar') || e.titulo.startsWith('Revisar') || i === etapas.length - 1).map(e => e.id),
      planoB: bloqueio ? 'Se "' + bloqueio.titulo + '" não ocorrer em 2 dias úteis: cobrar o cliente pelo Chat e registrar a pendência; seguir com as etapas que não dependem dela.' : 'Se o responsável não avançar em 2 dias: alertar a controladoria e avaliar redistribuição.',
      planoC: 'Em urgência de prazo: priorizar a peça mínima necessária para o prazo, com revisão simplificada pelo aprovador.', em: Date.now(), por: meuId(), status: 'ativo', recalculos: 0,
      fimPrevisto: etapas[etapas.length - 1].fim, fimOriginal: etapas[etapas.length - 1].fim, criadas: [], escalonamento: 1, historico: [[Date.now(), 'Plano preparado']] };
  }
  const tipoPlano = t => { const n = norm(t); return /protocol|peti|manifest/.test(n) ? 'protocolo' : /parad|destrav|andamento/.test(n) ? 'pasta parada' : /document|cliente/.test(n) ? 'documentação' : 'geral'; };
  function eficienciaPlanos() { const M = metaK(), por = {}; (M.resultados || []).filter(r => r.tipo).forEach(r => { const x = por[r.tipo] = por[r.tipo] || { n: 0, ok: 0, rec: 0, dias: 0 }; x.n++; if (r.acertou) x.ok++; x.rec += r.recalculos || 0; x.dias += r.realDias || 0; }); return Object.entries(por).map(([t, x]) => ({ tipo: t, n: x.n, acerto: Math.round(x.ok / x.n * 100), recalc: +(x.rec / x.n).toFixed(1), dias: +(x.dias / x.n).toFixed(1), baixa: x.n >= 3 && x.ok / x.n < .5 })); }
  // Carga real: tarefas × esforço (complexidade e atraso), não só contagem.
  function cargaReal(g) {
    const f = g.f, out = {}, peso = t => /peca|contesta|recurso|manifest|analis|parecer/.test(norm(t)) ? 3 : /revis|cadastro|regulariz/.test(norm(t)) ? 2 : 1;
    (f.metas || []).filter(m => !m.concluida).forEach(m => String(m.responsaveis || '').split(/,\s*|\s\+\d+/).filter(Boolean).forEach(r => { const x = out[r] = out[r] || { tarefas: 0, esforco: 0, atrasadas: 0, complexas: 0 }; x.tarefas++; const p = peso(m.titulo) + (m.atraso_dias > 30 ? 1 : 0); x.esforco += p; if (m.atraso_dias > 0) x.atrasadas++; if (p >= 3) x.complexas++; }));
    Object.entries(g.carga).forEach(([n, c]) => { const x = out[n] = out[n] || { tarefas: 0, esforco: 0, atrasadas: 0, complexas: 0 }; x.pastas = c.pastas; x.esforco += c.pastas * .5; });
    return out;
  }
  function verificarPlano(p, g) {
    const E = evidenciasCog(contextoCog('pronta', perfilAcesso())), docs = E.docs.map(norm), metas = (g.f.metas || []);
    let mud = 0; p.etapas.forEach(e => { const was = e.status; const t = norm(e.titulo);
      if (/^ler as/.test(t)) e.status = E.pushNL.length ? 'pendente' : 'concluida';
      else if (/^analisar/.test(t)) e.status = E.semMeta.length ? 'pendente' : 'concluida';
      else if (/procuracao/.test(t)) e.status = docs.some(d => /procura/.test(d)) ? 'concluida' : 'pendente';
      else if (/^concluir "/.test(t)) { const m = metas.find(x => t.includes(norm(x.titulo))); e.status = m && m.concluida ? 'concluida' : 'pendente'; }
      if (e.status === 'pendente' && e.dep.some(i => p.etapas[i - 1] && p.etapas[i - 1].status !== 'concluida')) e.status = 'bloqueada';
      if (was !== e.status) mud++; });
    const fim = p.etapas[p.etapas.length - 1], restantes = p.etapas.filter(e => e !== fim && e.status !== 'concluida');
    if (!restantes.length) { const inv = E.H.filter(h => h.ok && h.peso >= 2); fim.status = inv.length ? 'pendente' : 'concluida'; p.resultado = inv.length ? 'Etapas executadas, mas o problema persiste: ' + inv[0].h.toLowerCase() : 'Resolvido: investigação sem pendência relevante'; if (!inv.length && p.status === 'ativo') { p.status = 'concluido'; p.fimEm = Date.now(); const M = metaK(); M.resultados.push({ plano: p.id, previstas: p.etapas.length, dias: Math.round((p.fimEm - p.em) / 864e5), recalculos: p.recalculos }); } }
    // Adaptação: etapa vencida empurra as dependentes; escalona conforme o atraso do prazo final.
    const hoje = Date.now(); let desl = 0; p.etapas.forEach(e => { if (e.status !== 'concluida' && e.fim < hoje) { const atr = Math.ceil((hoje - e.fim) / 864e5); if (atr > desl) desl = atr; e.atrasoDias = atr; } });
    if (desl) { p.etapas.forEach(e => { if (e.status !== 'concluida') e.fimAjust = e.fim + desl * 864e5; }); p.fimPrevisto = p.etapas[p.etapas.length - 1].fim + desl * 864e5; }
    const atrFinal = Math.round(((p.fimPrevisto || 0) - (p.fimOriginal || 0)) / 864e5), nv = atrFinal >= 7 ? 5 : atrFinal >= 4 ? 4 : atrFinal >= 2 ? 3 : atrFinal >= 1 ? 2 : 1;
    if (nv > (p.escalonamento || 1)) { p.escalonamento = nv; p.historico.push([hoje, 'Escalonamento nível ' + nv + ' (' + atrFinal + ' dia(s) de atraso no prazo final)']); auditar('orquestracao', 'plano ' + p.id + ' escalonado ao nível ' + nv, 'alerta'); if (nv >= 3) { const ctl = USUARIOS.find(u => ehControladoria(u.id)); if (ctl) try { notificar({ tipo: 'direcionada', para: ctl.id, de: 'ia', titulo: 'Plano atrasado · Pasta ' + p.pasta, texto: '"' + p.problema + '" está ' + atrFinal + ' dia(s) atrás do previsto. Nível ' + nv + '.', ctx: { pasta: p.pasta } }, { silencioso: true }); } catch (e) {} } }
    if (mud) { p.recalculos++; p.ultRecalc = Date.now(); p.historico.push([Date.now(), mud + ' etapa(s) mudaram de situação']); }
    if (p.status === 'concluido' && !p.aprendido) { p.aprendido = true; const real = Math.max(1, Math.round((p.fimEm - p.em) / 864e5)), prev = Math.max(1, Math.round((p.fimOriginal - p.em) / 864e5)); const M = metaK(); M.resultados[M.resultados.length - 1] = { ...M.resultados[M.resultados.length - 1], tipo: tipoPlano(p.problema), prevDias: prev, realDias: real, acertou: real <= prev * 1.2, gargaloNaoPrevisto: p.etapas.some(e => e.atrasoDias && !p.caminho.includes(e.id)) }; }
    return mud;
  }
  function situacaoResposta(pedido, cmd, perf, cid) {
    const t = norm(pedido), nl = !cmd && (/o que esta acontecendo|como esta o escritorio|mapa vivo|situacao geral/.test(t) ? 'situacao' : /o que (eu )?nao estou vendo|o que estou esquecendo/.test(t) ? 'naovejo' : /o que (eu )?(deveria|devo|preciso) (olhar|fazer|ver) hoje|agenda de hoje/.test(t) ? 'hoje' : /onde (esta|fica) o (maior )?gargalo|onde (o fluxo )?trava/.test(t) ? 'gargalo' : /o que mudou|antes e agora/.test(t) ? 'mudancas' : /quem (precisa|tem que) agir|quem desbloqueia/.test(t) ? 'planos' : null);
    const nl2 = !cmd && !nl && (/o que (eu )?posso delegar|o que esta se repetindo|o que esta melhorando|o que piorou|o que precisa da minha atencao|antes de amanha/.test(t) ? 'gestor' : /redistribu|sobrecarreg/.test(t) ? 'redistribuir' : null);
    const k = (cmd && cmd.c) || nl || nl2; if (!['situacao', 'naovejo', 'hoje', 'gargalo', 'mudancas', 'orquestrar', 'planos', 'autonomia', 'redistribuir', 'controladoria', 'gestor', 'desfazer'].includes(k)) return null;
    const amplo2 = perf.papel === 'MASTER' || perf.papel === 'CONTROLADORIA' || perf.papel === 'PRIMARIO';
    if (['redistribuir', 'controladoria', 'gestor'].includes(k) && !amplo2) { auditar('orquestracao', 'modo ' + k + ' sem permissão', 'negado'); return { modulos: ['Segurança'], comando: 'Orquestração', resposta: 'Essa visão é do gestor e da Controladoria. Posso mostrar a sua agenda com /hoje.', pontos: [], acoes: [] }; }
    const g = gemeoK(perf), base = { modulos: ['Consciência situacional', 'Gêmeo digital'], comando: 'Situação', pontos: [], acoes: [] }, a = g.atual, N = nucleo();
    if (a) { snapshotGemeo(a); serieRegistrar(a); }
    if (k === 'autonomia') {
      const adm = perf.papel === 'MASTER'; return { ...base, comando: 'Orquestração', resposta: 'Matriz de autonomia da BlueJus IA. Ações de risco alto nunca passam do nível 2 (preparar para aprovação), mesmo se configuradas acima.' + (adm ? '' : ' Só o Master altera os níveis.'),
        secoes: [{ titulo: 'Níveis', itens: ['0 Informar', '1 Sugerir', '2 Preparar para aprovação', '3 Executar ações operacionais autorizadas', '4 Executar fluxos aprovados e monitorar', '5 Autonomia avançada em regras expressas'] }, { titulo: 'Por tipo de ação', itens: Object.keys(AUTON).map(x => { const z = autonomiaDe(x); return x.replace('_', ' ') + ' · nível ' + z.nivel + ' · risco ' + z.risco + (z.confirmar ? ' · pede confirmação' : ''); }) }, { titulo: 'Ordem obrigatória', itens: ['Intenção → contexto → permissão → risco → regra → plano → confirmação → execução → verificação'] }] };
    }
    if (!a && !['situacao', 'gestor', 'controladoria'].includes(k)) return { ...base, resposta: 'Abra uma pasta para eu ler o estado atual dela.' };
    if (k === 'redistribuir') {
      const C = cargaReal(g), L = Object.entries(C).sort((x, y) => y[1].esforco - x[1].esforco), media = L.reduce((s, [, x]) => s + x.esforco, 0) / (L.length || 1);
      const sobre = L.filter(([, x]) => x.esforco > media * 1.5 && x.esforco >= 3), folga = L.filter(([, x]) => x.esforco < media * .6);
      const sug = sobre.length && folga.length ? 'Avaliar passar a ' + folga[0][0] + ' uma tarefa simples de ' + sobre[0][0] + ' (não as complexas, que exigem contexto do caso). Requer aprovação.' : 'Não há desequilíbrio que justifique redistribuir agora.';
      return { ...base, comando: 'Orquestração', resposta: 'Carga real por pessoa (esforço = tarefas × complexidade + atraso + pastas sob responsabilidade). Muitas tarefas não é o mesmo que muito trabalho.', secoes: [{ titulo: 'Carga', itens: L.map(([n, x]) => n + ' · esforço ' + x.esforco.toFixed(1) + ' · ' + x.tarefas + ' tarefa(s), ' + x.complexas + ' complexa(s), ' + x.atrasadas + ' atrasada(s)' + (x.pastas ? ' · ' + x.pastas + ' pasta(s)' : '')) }, { titulo: 'Leitura', itens: (sobre.length ? sobre.map(([n]) => n + ' acima da média da equipe') : ['Ninguém acima de 1,5× a média']).concat([sug]) }],
        pontos: [{ nivel: 'info', camada: 'hipotese', confianca: 'provavel', texto: 'Esforço é estimado pelo tipo da tarefa; especialidade e histórico individual entram quando houver dados de conclusão.' }] };
    }
    if (k === 'controladoria') {
      const E = a ? evidenciasCog(contextoCog('controladoria', perf)) : null, T = { 'Problema de execução': [], 'Problema de informação': [], 'Problema de processo': [], 'Problema do sistema': [] };
      if (E) { E.atras.forEach(m => T['Problema de execução'].push('"' + m.titulo + '" vencida há ' + m.atraso_dias + ' dias (' + (m.responsaveis || 'sem responsável') + ')')); E.abertas.filter(m => !String(m.responsaveis || '').trim()).forEach(m => T['Problema de processo'].push('Meta sem responsável: ' + m.titulo)); E.semMeta.forEach(p => T['Problema de processo'].push(p.tipo + ' de ' + p.data + ' sem providência registrada')); if (!g.f.processo) T['Problema de informação'].push('Pasta sem processo vinculado'); lacunasMeta(g.f, 'pasta').forEach(l => T['Problema de informação'].push('Falta: ' + l)); metaK().contradicoes.filter(x => !x.resolvida).forEach(x => T['Problema de informação'].push('Inconsistência: ' + x.a)); }
      const bloq = (db.auditoria || []).filter(x => x.resultado === 'erro' || /falha/.test(x.resultado || '')).length; if (bloq) T['Problema do sistema'].push(bloq + ' falha(s) técnicas registradas na auditoria');
      const sup = Object.entries(cargaReal(g)).filter(([, x]) => x.atrasadas >= 1 && x.complexas >= 1).map(([n]) => n);
      return { ...base, comando: 'Orquestração', resposta: 'Modo controladoria' + (a ? ' · Pasta ' + a.numero : '') + '. Separei por tipo para indicar quem resolve: execução (responsável), processo (fluxo), informação (cadastro) ou sistema (suporte).', secoes: Object.entries(T).filter(([, v]) => v.length).map(([t, v]) => ({ titulo: t, itens: v })).concat(sup.length ? [{ titulo: 'Pode precisar de suporte', itens: sup.map(n => n + ': tarefa complexa e atrasada') }] : []) };
    }
    if (k === 'gestor') {
      const E = a ? evidenciasCog(contextoCog('gestor', perf)) : null, C = cargaReal(g), ef = eficienciaPlanos(), M = metaK(), aval = M.avaliacoes, rec = aval.slice(-20), ant = aval.slice(-40, -20);
      const atencao = E ? E.semMeta.map(p => p.tipo + ' de ' + p.data + ' sem análise').concat(E.atras.map(m => '"' + m.titulo + '" ' + m.atraso_dias + ' dias atrasada')) : [];
      const delegar = E ? E.abertas.filter(m => !/peca|contesta|recurso|manifest|analis|parecer/.test(norm(m.titulo))).map(m => m.titulo + ' (tarefa operacional)') : [];
      const repete = Object.entries(M.erroTipos || {}).filter(([, n]) => n >= 2).map(([t, n]) => t + ' (' + n + '×)').concat(ef.filter(x => x.baixa).map(x => 'planos de ' + x.tipo + ' com baixa eficiência histórica (' + x.acerto + '% no prazo)'));
      const melhora = rec.length && ant.length ? (rec.filter(x => x.conf !== 'incerto').length / rec.length) - (ant.filter(x => x.conf !== 'incerto').length / ant.length) : null;
      const amanha = E ? E.abertas.filter(m => m.prazo && diasDe(m.prazo) != null && diasDe(m.prazo) >= -1 && diasDe(m.prazo) <= 0).map(m => m.titulo + ' (prazo ' + m.prazo + ')').concat(E.semMeta.map(p => 'Conferir prazo da ' + p.tipo.toLowerCase() + ' de ' + p.data)) : [];
      return { ...base, comando: 'Orquestração', resposta: 'Visão do gestor com o estado atual' + (a ? ' (Pasta ' + a.numero + ' aberta)' : '') + '.', secoes: [{ titulo: 'Precisa da sua atenção', itens: atencao.length ? atencao : ['Nada crítico'] }, { titulo: 'Pode delegar', itens: delegar.length ? delegar : ['Nada claramente delegável'] }, { titulo: 'Está se repetindo', itens: repete.length ? repete : ['Nenhuma repetição relevante'] }, { titulo: 'Melhorando ou piorando', itens: [melhora == null ? 'Sem histórico suficiente para comparar' : melhora > .05 ? 'Respostas da IA com mais confirmação que no período anterior' : melhora < -.05 ? 'Mais respostas incertas que no período anterior' : 'Estável'].concat(ef.map(x => 'Planos de ' + x.tipo + ': ' + x.acerto + '% no prazo, ' + x.dias + ' dias em média')) }, { titulo: 'Resolver antes de amanhã', itens: amanha.length ? amanha : ['Nada com vencimento até amanhã'] }, { titulo: 'Onde há risco', itens: g.pastas.filter(p => p.estado === 'risco' || p.estado === 'prazo').map(p => 'Pasta ' + p.numero + ' · ' + p.motivo).concat(g.pastas.some(p => p.estado === 'risco' || p.estado === 'prazo') ? [] : ['Nenhum risco nas pastas com dados']) }] };
    }
    if (k === 'desfazer') {
      const N = nucleo(), p = (N.planos || []).filter(x => x.aprovadoEm && (x.criadas || []).length && !x.desfeito).slice(-1)[0];
      if (!p) return { ...base, comando: 'Orquestração', resposta: 'Não há plano aprovado com metas para desfazer.' };
      return { ...base, comando: 'Orquestração', resposta: 'O último plano aprovado ("' + p.problema + '") criou ' + p.criadas.length + ' meta(s) na Pasta ' + p.pasta + '. Posso removê-las se ainda não tiverem andamento.', acoes: [{ tipo: 'plano_desfazer', rotulo: 'Desfazer metas do plano', plano: p.id }] };
    }
    if (k === 'situacao') {
      const P = prioridadesK(g), est = {}; g.pastas.filter(p => p.completo).forEach(p => { est[p.estado] = (est[p.estado] || 0) + 1; });
      const cargaTop = Object.entries(g.carga).sort((x, y) => (y[1].atrasadas * 3 + y[1].pastas) - (x[1].atrasadas * 3 + x[1].pastas)).slice(0, 4);
      return { ...base, resposta: 'Estado atual do escritório pelo gêmeo digital: ' + g.pastas.length + ' pastas acessíveis, ' + g.pastas.filter(p => p.completo).length + ' com dados completos nesta tela.' + (a ? ' A Pasta ' + a.numero + ' está "' + ESTADOS_P[a.estado][0] + '": ' + a.motivo + '.' : ''),
        secoes: Object.entries(P).filter(([, v]) => v.length).map(([kk, v]) => ({ titulo: kk, itens: v })).concat([{ titulo: 'Quem está com o quê', itens: cargaTop.map(([n, x]) => n + ' · ' + x.pastas + ' pasta(s)' + (x.abertas ? ' · ' + x.abertas + ' meta(s) aberta(s)' : '') + (x.atrasadas ? ' · ' + x.atrasadas + ' atrasada(s)' : '')) }]),
        pontos: [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'Quantidade de pastas não é quantidade de trabalho: a carga considera metas abertas e atrasos, não só o número de itens.' }] };
    }
    if (k === 'naovejo') { const L = naoEstouVendo(g); return { ...base, resposta: L.length ? 'Além do assunto principal, encontrei ' + L.length + ' ponto(s) relacionado(s) que podem afetar a conclusão da Pasta ' + a.numero + '.' : 'Não encontrei nada relevante fora do que já está à vista.', secoes: L.length ? [{ titulo: 'O que pode estar passando despercebido', itens: L }] : [] }; }
    if (k === 'gargalo') { const G = gargaloFluxo(g); return { ...base, resposta: G.trav[1] ? 'Na Pasta ' + a.numero + ', o fluxo trava em ' + G.trav[0] + ' (' + G.trav[1] + ' item(ns) parados).' : 'Não há etapa travada no fluxo da Pasta ' + a.numero + '.', secoes: [{ titulo: 'Fluxo', itens: G.E.map((e, i) => e + ': ' + G.cnt[i] + (G.trav[0] === e && G.trav[1] ? ' ← gargalo' : '')) }, { titulo: 'Dependências', itens: ['Movimentação recebida → leitura → análise → meta → providência → revisão → encerramento', 'Se a análise não acontece, nenhuma meta nasce e a pasta parece parada sem estar.'] }] }; }
    if (k === 'mudancas') { const m = mudancasPasta(a); if (!m) return { ...base, resposta: 'Esta é a primeira leitura da Pasta ' + a.numero + '. A partir de amanhã consigo dizer o que mudou desde hoje.' };
      return { ...base, resposta: 'Quando a Pasta ' + a.numero + ' foi vista ' + (m.dias ? 'há ' + m.dias + ' dia(s)' : 'antes') + ', estava "' + ESTADOS_P[m.prev.estado][0] + '" (' + m.prev.motivo + '). ' + (m.dif.length ? 'Desde então mudaram ' + m.dif.length + ' ponto(s).' : 'Nada mudou desde então.'), secoes: m.dif.length ? [{ titulo: 'Antes × agora', itens: m.dif }] : [] }; }
    if (k === 'hoje') {
      const P = prioridadesK(g), E = evidenciasCog(contextoCog('hoje', perf)), hoje = [], naoHoje = [];
      const pl = (nucleo().planos || []).filter(x => x.pasta === a.numero && x.status === 'ativo').slice(-1)[0];
      if (pl) { verificarPlano(pl, g); pl.etapas.filter(e => e.status === 'bloqueada').forEach(e => naoHoje.push('BLOQUEADO · ' + e.titulo + ' (aguarda etapa ' + e.dep.join(', ') + ')')); pl.etapas.filter(e => e.status === 'pendente' && e.externo).forEach(e => hoje.push('DEPENDENTE · ' + e.titulo + ' (depende de fora do escritório)')); }
      const op = naoEstouVendo(g).filter(x => /financeiro|carga/.test(norm(x))); op.forEach(x => naoHoje.push('OPORTUNIDADE · ' + x));
      E.semMeta.forEach(p => hoje.push('URGENTE · Analisar ' + p.tipo.toLowerCase() + ' de ' + p.data));
      E.atras.forEach(m => hoje.push('IMPORTANTE · "' + m.titulo + '" (' + m.atraso_dias + ' dias de atraso, ' + (m.responsaveis || 'sem responsável') + ')'));
      if (E.pushNL.length) hoje.push('PROGRAMADO · Ler ' + E.pushNL.length + ' movimentação(ões)');
      (P['AGUARDANDO TERCEIRO'] || []).forEach(x => naoHoje.push('BLOQUEADO · ' + x)); (P['PODE AGUARDAR'] || []).forEach(x => naoHoje.push(x));
      E.abertas.filter(m => !(m.atraso_dias > 0)).forEach(m => naoHoje.push('Dentro do prazo · ' + m.titulo + (m.prazo ? ' (até ' + m.prazo + ')' : '')));
      return { ...base, resposta: 'Agenda cognitiva de hoje para a Pasta ' + a.numero + ': ' + hoje.length + ' item(ns) para agir e ' + naoHoje.length + ' que podem esperar.', secoes: [{ titulo: 'Precisa acontecer hoje', itens: hoje.length ? hoje : ['Nada urgente'] }, { titulo: 'Não precisa ser feito hoje', itens: naoHoje.length ? naoHoje : ['Nada pendente'] }] };
    }
    if (k === 'orquestrar') {
      const prob = (cmd && cmd.arg) || pedido.replace(/^\/\w+\s*/, '') || 'resolver a pasta'; const p = planejar(prob, g); verificarPlano(p, g);
      N.planos = (N.planos || []).filter(x => !(x.pasta === p.pasta && x.status === 'ativo')).concat([p]).slice(-30); salvar(); auditar('orquestracao', 'plano preparado: ' + p.problema, 'nível 2 · aguardando aprovação');
      const st = { pendente: '○', bloqueada: '⛔', concluida: '✓' };
      return { ...base, comando: 'Orquestração', resposta: 'Plano preparado para "' + p.problema + '" na Pasta ' + p.pasta + '. Objetivo: ' + p.objetivo + '. Nada foi executado: as metas só nascem quando você aprovar.' + (p.bloqueio ? ' Dependência crítica: ' + p.bloqueio.toLowerCase() + '.' : ''),
        secoes: [{ titulo: 'Etapas', itens: p.etapas.map(e => st[e.status] + ' ' + e.id + '. ' + e.titulo + ' · ' + (e.resp || 'definir') + ' · até ' + e.prazo + ' · ' + e.prioridade + (e.dep.length ? ' · depende de ' + e.dep.join(', ') : '') + (e.externo ? ' · externa' : '') + ' · conclui quando: ' + e.criterio) }, { titulo: 'Prazo final previsto', itens: [new Date(p.fimPrevisto).toLocaleDateString('pt-BR') + ' em dias úteis · checagem: ' + (lacunasMeta(g.f, 'prazo').length ? 'sem data de ciência, não sei se o prazo processual é anterior; confira' : 'compatível com os dados')] }].concat(eficienciaPlanos().filter(x => x.tipo === tipoPlano(p.problema) && x.baixa).map(x => ({ titulo: 'Histórico', itens: ['Planos de ' + x.tipo + ' têm baixa eficiência (' + x.acerto + '% no prazo em ' + x.n + '). Sugiro investigar o fluxo antes de repetir.'] }))).concat([ { titulo: 'Caminho crítico', itens: [p.caminho.join(' → ')] }, { titulo: 'Responsáveis', itens: ['Principal: ' + (p.papeis.principal || 'definir'), 'Secundário: ' + (p.papeis.secundario || 'não definido'), 'Aprovador: ' + p.papeis.aprovador] }, { titulo: 'Checkpoints', itens: ['Após as etapas ' + p.checkpoints.join(', ') + ' eu reavalio o plano'] }, { titulo: 'Alternativas', itens: ['Plano A: caminho normal acima', 'Plano B: ' + p.planoB, 'Plano C: ' + p.planoC] }]),
        acoes: [{ tipo: 'plano_aprovar', rotulo: 'Aprovar e criar metas', plano: p.id }, { tipo: 'enviar_comando', rotulo: 'Ver planos', texto: '/planos' }] };
    }
    const L = (N.planos || []).filter(x => x.pasta === a.numero);
    if (!L.length) return { ...base, comando: 'Orquestração', resposta: 'Não há plano para a Pasta ' + a.numero + '. Use /orquestrar e descreva o problema.' };
    const p = L[L.length - 1], mud = verificarPlano(p, g); salvar(); const bloq = p.etapas.filter(e => e.status === 'bloqueada'), pend = p.etapas.filter(e => e.status === 'pendente');
    const quem = pend.filter(e => !e.dep.some(i => p.etapas[i - 1].status !== 'concluida'))[0];
    return { ...base, comando: 'Orquestração', resposta: 'Plano "' + p.problema + '": ' + p.etapas.filter(e => e.status === 'concluida').length + ' de ' + p.etapas.length + ' etapas concluídas' + (mud ? ', recalculado agora (' + mud + ' mudança(s))' : '') + '. ' + (p.resultado ? p.resultado + '.' : '') + (quem ? ' Para desbloquear o fluxo, ' + (quem.resp || 'o responsável') + ' precisa: ' + quem.titulo.toLowerCase() + '.' : ''),
      secoes: [{ titulo: 'Situação das etapas', itens: p.etapas.map(e => ({ pendente: '○', bloqueada: '⛔', concluida: '✓' })[e.status] + ' ' + e.titulo + ' · ' + (e.resp || 'definir') + ' · até ' + new Date(e.fimAjust || e.fim).toLocaleDateString('pt-BR') + (e.atrasoDias ? ' · ' + e.atrasoDias + ' dia(s) atrasada' : '')) }, { titulo: 'Prazo final', itens: [new Date(p.fimPrevisto).toLocaleDateString('pt-BR') + (p.fimPrevisto > p.fimOriginal ? ' (era ' + new Date(p.fimOriginal).toLocaleDateString('pt-BR') + ')' : ' · no previsto') + ' · escalonamento nível ' + (p.escalonamento || 1)] }, { titulo: 'Registro', itens: (p.historico || []).slice(-5).map(([t, x]) => new Date(t).toLocaleDateString('pt-BR') + ' · ' + x) }].concat(bloq.length ? [{ titulo: 'Bloqueadas por dependência', itens: bloq.map(e => e.titulo + ' aguarda ' + e.dep.filter(i => p.etapas[i - 1].status !== 'concluida').join(', ')) }] : []),
      pontos: [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'Ação executada não é problema resolvido: a última etapa só conclui quando a investigação da pasta não acusar pendência.' }] };
  }
  /* ================= TEMPORAL INTELLIGENCE =================
     Série diária por pasta (estado do gêmeo), linha do tempo reconstruída dos eventos datados, tendência e velocidade,
     baseline, inatividade justificada, recorrência, ciclos, máquina do tempo, vigência de regras e memória de alertas.
     Sem dados suficientes, a IA diz isso em vez de projetar. Sistema real: série e eventos vêm do log de auditoria do servidor. */
  const dataTK = t => new Date(t).toLocaleDateString('pt-BR');
  const parseBR = s => { const m = String(s || '').match(/(\d{2})\/(\d{2})(?:\/(\d{2,4}))?/); if (!m) return null; const y = m[3] ? (m[3].length === 2 ? 2000 + +m[3] : +m[3]) : new Date().getFullYear(); return new Date(y, +m[2] - 1, +m[1]).getTime(); };
  function serieRegistrar(a) {
    if (!a) return; const N = nucleo(), S = N.serie = N.serie || {}, k = CONTA() + ':' + a.numero, d = new Date(); d.setHours(0, 0, 0, 0);
    const L = S[k] = S[k] || [], ult = L[L.length - 1], pt = { d: d.getTime(), estado: a.estado, abertas: a.metasAbertas, atrasadas: a.atrasadas, pushNL: a.pushNL, docs: a.docs, resp: a.resp };
    if (ult && ult.d === pt.d) L[L.length - 1] = pt; else L.push(pt); S[k] = L.slice(-400);
  }
  const serieDe = num => ((nucleo().serie || {})[CONTA() + ':' + num] || []);
  function tendenciaK(vals) {
    if (vals.length < 4) return { tipo: 'dados insuficientes', n: vals.length };
    const n = vals.length, xs = vals.map((_, i) => i), mx = (n - 1) / 2, my = vals.reduce((a, b) => a + b, 0) / n;
    const sl = xs.reduce((s, x, i) => s + (x - mx) * (vals[i] - my), 0) / xs.reduce((s, x) => s + (x - mx) ** 2, 0);
    const dif = vals.slice(1).map((v, i) => v - vals[i]), trocas = dif.slice(1).filter((d, i) => Math.sign(d) && Math.sign(dif[i]) && Math.sign(d) !== Math.sign(dif[i])).length;
    const acel = dif.length >= 3 ? dif.slice(-2).reduce((a, b) => a + b, 0) / 2 - dif.slice(0, 2).reduce((a, b) => a + b, 0) / 2 : 0;
    const tipo = trocas >= Math.max(2, n / 3) ? 'oscilante' : Math.abs(sl) < .1 ? 'estável' : sl > 0 ? 'crescente' : 'decrescente';
    return { tipo, sl: +sl.toFixed(2), acel: +acel.toFixed(2), n, proj: v => Math.max(0, Math.round(vals[n - 1] + sl * v)) };
  }
  // Linha do tempo reconstruída a partir de eventos datados (nada é inventado; itens sem data ficam fora).
  function linhaTempo(g) {
    const f = g.f, ev = [], N = nucleo(), M = metaK(), a = g.atual;
    (f.push || []).forEach(p => { const t = parseBR(p.data); if (t) ev.push({ t, tipo: 'movimentação', txt: p.tipo + (p.fonte ? ' (' + p.fonte + ')' : ''), relevante: /intima|decis|senten|despach|citac/.test(norm(p.tipo)), prov: false }); });
    (f.metas || []).forEach(m => { const tp = parseBR(m.prazo); if (tp) ev.push({ t: tp, tipo: 'prazo de meta', txt: '"' + m.titulo + '" vence' + (m.concluida ? '' : m.atraso_dias > 0 ? ' (vencida)' : ''), relevante: true, prov: false }); const tm = parseBR(m.mod); if (tm && m.concluida) ev.push({ t: tm, tipo: 'providência', txt: '"' + m.titulo + '" concluída', relevante: true, prov: true }); });
    (N.planos || []).filter(p => p.pasta === a.numero).forEach(p => (p.historico || []).forEach(([t, x]) => ev.push({ t, tipo: 'plano', txt: x, relevante: /aprovado|desfeito|escalon/.test(norm(x)), prov: /aprovado/.test(norm(x)) })));
    (M.decisoes || []).filter(d => d.contexto === 'Pasta ' + a.numero).forEach(d => ev.push({ t: d.em, tipo: 'decisão', txt: d.decisao + (d.motivo ? ' (motivo: ' + d.motivo + ')' : ''), relevante: true, prov: true }));
    const S = serieDe(a.numero); S.forEach((p, i) => { if (i && p.estado !== S[i - 1].estado) ev.push({ t: p.d, tipo: 'estado', txt: ESTADOS_P[S[i - 1].estado][0] + ' → ' + ESTADOS_P[p.estado][0], relevante: true, prov: false }); if (i && p.resp !== S[i - 1].resp) ev.push({ t: p.d, tipo: 'responsável', txt: S[i - 1].resp + ' → ' + p.resp, relevante: true, prov: false }); });
    ev.sort((x, y) => x.t - y.t);
    const agora = Date.now(), ultEv = ev.filter(e => e.t <= agora).slice(-1)[0], ultRel = ev.filter(e => e.t <= agora && e.relevante).slice(-1)[0], ultProv = ev.filter(e => e.t <= agora && e.prov).slice(-1)[0];
    return { ev, ultEv, ultRel, ultProv, semData: (f.documentos || []).length };
  }
  // Inatividade: ausência de movimentação só vira alerta quando não há justificativa.
  const INATIV = { prazo: 'AGUARDANDO PRAZO', terceiro: 'AGUARDANDO TERCEIRO', cliente: 'AGUARDANDO CLIENTE', documento: 'AGUARDANDO DOCUMENTO', advogado: 'AGUARDANDO PROVIDÊNCIA INTERNA', risco: 'AGUARDANDO PROVIDÊNCIA INTERNA', parada: 'SEM JUSTIFICATIVA IDENTIFICADA', ativa: 'EM ANDAMENTO', sem_encerramento: 'AGUARDANDO DECISÃO (encerrar?)' };
  // Ciclos: transições observadas entre tipos de movimentação (nesta pasta + memória institucional de transições).
  function ciclosK(g) {
    const N = nucleo(), T = N.transicoes = N.transicoes || {}, seq = (g.f.push || []).map(p => ({ t: parseBR(p.data), k: norm(p.tipo).split(' ')[0] })).filter(x => x.t).sort((a, b) => a.t - b.t);
    const chave = CONTA() + ':' + g.atual.numero; N.transVistas = N.transVistas || {};
    seq.slice(1).forEach((x, i) => { const id = chave + ':' + seq[i].t + ':' + x.t; if (N.transVistas[id]) return; N.transVistas[id] = 1; const k = seq[i].k + '>' + x.k; T[k] = T[k] || { n: 0, dias: [] }; T[k].n++; T[k].dias.push(Math.round((x.t - seq[i].t) / 864e5)); });
    const ult = seq[seq.length - 1]; if (!ult) return { prox: [], seq };
    const prox = Object.entries(T).filter(([k]) => k.startsWith(ult.k + '>')).map(([k, v]) => ({ etapa: k.split('>')[1], n: v.n, dias: Math.round(v.dias.reduce((a, b) => a + b, 0) / v.dias.length) })).sort((a, b) => b.n - a.n);
    const tot = prox.reduce((s, x) => s + x.n, 0); prox.forEach(x => { x.grau = x.n >= 5 && x.n / tot >= .6 ? 'provável' : 'possível'; });
    return { prox, seq, ult };
  }
  function recorrenciasK(g) {
    const out = [], N = nucleo(), M = metaK(), d60 = Date.now() - 60 * 864e5;
    const et = {}; N.erros.filter(e => e.em >= d60).forEach(e => { (et[e.tipo || 'erro'] = et[e.tipo || 'erro'] || []).push(e.em); }); Object.entries(et).filter(([, v]) => v.length >= 2).forEach(([t, v]) => out.push(t + ': ' + v.length + ' vezes nos últimos 60 dias (intervalo médio ' + Math.round((v[v.length - 1] - v[0]) / 864e5 / Math.max(1, v.length - 1)) + ' dias)'));
    const pt = {}; (g.f.push || []).forEach(p => { pt[p.tipo] = (pt[p.tipo] || 0) + 1; }); Object.entries(pt).filter(([, n]) => n >= 2).forEach(([t, n]) => out.push('Movimentação "' + t + '" recebida ' + n + ' vezes nesta pasta'));
    const tit = {}; (g.f.metas || []).forEach(m => { const k = norm(m.titulo).replace(/\(copia\)/, '').trim(); tit[k] = (tit[k] || 0) + 1; }); Object.entries(tit).filter(([, n]) => n >= 2).forEach(([t, n]) => out.push('Mesma meta criada ' + n + ' vezes: "' + t + '"'));
    const al = (N.alertasLog || []).filter(x => x.em >= d60), ign = al.filter(x => x.resultado === 'ignorado'); if (al.length >= 3) out.push(al.length + ' alertas em 60 dias, ' + ign.length + ' ignorado(s)');
    return out;
  }
  function maquinaTempo(g, ts) {
    const f = g.f, fim = ts + 864e5 - 1, S = serieDe(g.atual.numero).filter(p => p.d <= fim).slice(-1)[0];
    const push = (f.push || []).filter(p => { const t = parseBR(p.data); return t && t <= fim; });
    const concl = (f.metas || []).filter(m => m.concluida && parseBR(m.mod) && parseBR(m.mod) <= fim), vencidas = (f.metas || []).filter(m => parseBR(m.prazo) && parseBR(m.prazo) < ts && !(m.concluida && parseBR(m.mod) && parseBR(m.mod) <= ts));
    const regras = nucleo().regras.filter(r => r.em <= fim);
    return { S, push, concl, vencidas, regras, semCriacao: (f.metas || []).length };
  }
  // Memória de alertas + silêncio inteligente.
  function alertaUtil(chave) { const L = (nucleo().alertasLog || []).filter(x => x.chave === chave).slice(-4); return !(L.length >= 3 && L.every(x => x.resultado === 'ignorado')); }
  function alertasAvaliar() { const N = nucleo(); (N.alertasLog || []).filter(x => !x.resultado && Date.now() - x.em > 2 * 864e5).forEach(x => { const m = (db.msgs || []).find(y => y.id === x.msg); x.resultado = m && m.lida ? (x.acaoTomada ? 'útil' : 'lido') : 'ignorado'; }); }
  function temporalResposta(pedido, cmd, perf, cid) {
    const t = norm(pedido), nl = !cmd && (/linha do tempo|historico (da|desta) pasta|o que aconteceu (nesta|na) pasta/.test(t) ? 'linhadotempo' : /como (estava|era) .*(em |dia )?\d{2}\/\d{2}|como estava ha \d+ dias/.test(t) ? 'em' : /o que vem depois|qual (a )?proxima etapa provavel|o que costuma acontecer depois/.test(t) ? 'proximo' : /desde quando|quando comecou|esta (aumentando|diminuindo|piorando|melhorando)|tendencia/.test(t) ? 'tendencia' : /o que (melhorou|piorou|comecou|terminou|voltou a acontecer|deixou de acontecer)|evolucao/.test(t) ? 'evolucao' : /o que se repete|recorrente|de novo|outra vez/.test(t) ? 'recorrencias' : /por que mudou/.test(t) ? 'porquemudou' : null);
    const k = (cmd && cmd.c) || nl; if (!['linhadotempo', 'tendencia', 'em', 'proximo', 'evolucao', 'recorrencias', 'temporal', 'vigente', 'porquemudou'].includes(k)) return null;
    const g = gemeoK(perf), a = g.atual, base = { modulos: ['Inteligência temporal'], comando: 'Temporal', pontos: [], acoes: [] };
    if (!a && k !== 'vigente') return { ...base, resposta: 'Abra uma pasta para eu reconstruir a história dela.' };
    if (a) { snapshotGemeo(a); serieRegistrar(a); salvar(); }
    const S = a ? serieDe(a.numero) : [], cuidado = 'Projeções são estimativas pelo ritmo observado, não certeza.';
    if (k === 'vigente') { const ts = parseBR((cmd && cmd.arg) || pedido) || Date.now(); const R2 = nucleo().regras.filter(r => r.em <= ts + 864e5); return { ...base, resposta: 'Regras vigentes em ' + dataTK(ts) + ': ' + R2.length + '.', secoes: [{ titulo: 'Regras', itens: R2.map(r => r.id + ' · v' + r.versao + ' · desde ' + dataTK(r.em) + ' · ' + r.texto + ((r.hist || []).length ? ' · ' + r.hist.length + ' alteração(ões)' : '')) }] }; }
    if (k === 'linhadotempo') {
      const L = linhaTempo(g), dias = x => x ? Math.round((Date.now() - x.t) / 864e5) : null;
      return { ...base, resposta: 'Linha do tempo da Pasta ' + a.numero + ': ' + L.ev.length + ' eventos datados. Último evento há ' + dias(L.ultEv) + ' dia(s); última providência registrada ' + (L.ultProv ? 'há ' + dias(L.ultProv) + ' dia(s) (' + L.ultProv.txt + ')' : 'não localizada') + '. Último evento não é a mesma coisa que última resolução.',
        secoes: [{ titulo: 'Eventos', itens: L.ev.slice(-14).map(e => dataTK(e.t) + ' · ' + e.tipo + ' · ' + e.txt + (e.t > Date.now() ? ' (futuro)' : '')) }, { titulo: 'Situação de inatividade', itens: [INATIV[a.estado] + ' · ' + a.motivo] }],
        pontos: L.semData ? [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: L.semData + ' documento(s) sem data de inclusão nesta tela ficaram fora da linha do tempo.' }] : [] };
    }
    if (k === 'em') {
      const ts = parseBR((cmd && cmd.arg) || pedido) || (() => { const m = t.match(/ha (\d+) dias/); return m ? Date.now() - +m[1] * 864e5 : null; })(); if (!ts) return { ...base, resposta: 'Diga a data. Ex.: /em 15/09/2026.' };
      const Mq = maquinaTempo(g, ts);
      return { ...base, resposta: 'Pasta ' + a.numero + ' em ' + dataTK(ts) + ', usando só o que existia até essa data.' + (Mq.S ? ' Estado registrado: ' + ESTADOS_P[Mq.S.estado][0] + ', responsável ' + Mq.S.resp + '.' : ' Não há retrato salvo dessa data; reconstruí pelos eventos.'),
        secoes: [{ titulo: 'Movimentações até a data', itens: Mq.push.length ? Mq.push.map(p => p.data + ' · ' + p.tipo) : ['Nenhuma'] }, { titulo: 'Metas', itens: Mq.concl.map(m => '✓ concluída: ' + m.titulo + ' (' + m.mod + ')').concat(Mq.vencidas.map(m => '⚠ já vencida: ' + m.titulo + ' (prazo ' + m.prazo + ')')).concat(Mq.concl.length + Mq.vencidas.length ? [] : ['Nenhuma conclusão ou vencimento até a data']) }, { titulo: 'Regras vigentes', itens: [Mq.regras.length + ' regra(s) do núcleo'] }],
        pontos: [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: 'As metas não têm data de criação nesta tela, então não sei quais já existiam abertas nessa data; mostrei só conclusões e vencimentos datados.' }] };
    }
    if (k === 'tendencia') {
      const met = [['abertas', 'Metas abertas'], ['atrasadas', 'Metas atrasadas'], ['pushNL', 'Movimentações não lidas']], itens = [], per = S.length ? dataTK(S[0].d) + ' a ' + dataTK(S[S.length - 1].d) : '';
      met.forEach(([m, l]) => { const v = S.map(p => p[m] || 0), tr = tendenciaK(v); itens.push(l + ': ' + (tr.tipo === 'dados insuficientes' ? 'dados insuficientes (' + tr.n + ' dia(s) registrados, preciso de 4)' : tr.tipo + ' (' + (tr.sl > 0 ? '+' : '') + tr.sl + '/dia' + (tr.acel > .5 ? ', acelerando' : tr.acel < -.5 ? ', desacelerando' : '') + ') · se continuar, ' + tr.proj(7) + ' em 7 dias')); });
      const ini = S.findIndex((p, i) => i && p.atrasadas > S[i - 1].atrasadas);
      return { ...base, resposta: 'Tendências da Pasta ' + a.numero + (per ? ' no período ' + per : '') + '. ' + cuidado, secoes: [{ titulo: 'Tendência e velocidade', itens }].concat(ini > 0 ? [{ titulo: 'Quando começou', itens: ['O aumento de atrasos começou em ' + dataTK(S[ini].d)] }] : []) };
    }
    if (k === 'proximo') {
      const C = ciclosK(g); salvar(); if (!C.ult) return { ...base, resposta: 'Não há movimentações datadas para reconhecer um ciclo.' };
      return { ...base, resposta: 'Último evento: ' + (g.f.push[0] || {}).tipo + '. ' + (C.prox.length ? 'Pelo histórico de transições observadas, a próxima etapa ' + (C.prox[0].grau === 'provável' ? 'provável' : 'possível') + ' é ' + C.prox[0].etapa + ' (em cerca de ' + C.prox[0].dias + ' dias).' : 'Ainda não observei o que costuma vir depois desse tipo de evento.'),
        secoes: C.prox.length ? [{ titulo: 'Próximas etapas observadas', itens: C.prox.map(x => x.etapa + ' · ' + x.grau + ' · visto ' + x.n + ' vez(es) · ~' + x.dias + ' dias') }] : [], pontos: [{ nivel: 'info', camada: 'hipotese', confianca: 'provavel', texto: 'Etapa confirmada só quando aparecer no Push. "Possível" vira "provável" com 5+ ocorrências e 60% das transições.' }] };
    }
    if (k === 'recorrencias') { const L = recorrenciasK(g); return { ...base, resposta: L.length ? L.length + ' recorrência(s) detectada(s).' : 'Nenhuma recorrência relevante nos dados disponíveis.', secoes: L.length ? [{ titulo: 'Evento recorrente detectado', itens: L }] : [] }; }
    const prev = S.length > 1 ? S[S.length - 2] : null, cur = S[S.length - 1];
    if (k === 'evolucao' || k === 'porquemudou') {
      if (!prev) return { ...base, resposta: 'Ainda tenho só um registro desta pasta. A partir do próximo dia consigo dizer o que melhorou, piorou, começou ou terminou.' };
      const mel = [], pio = [], igual = [];
      [['abertas', 'Metas abertas'], ['atrasadas', 'Metas atrasadas'], ['pushNL', 'Movimentações não lidas']].forEach(([m, l]) => { const d = (cur[m] || 0) - (prev[m] || 0); (d < 0 ? mel : d > 0 ? pio : igual).push(l + ': ' + prev[m] + ' → ' + cur[m]); });
      if (k === 'porquemudou') { const L = linhaTempo(g), entre = L.ev.filter(e => e.t > prev.d - 864e5 && e.t <= cur.d + 864e5); return { ...base, resposta: 'Entre ' + dataTK(prev.d) + ' e ' + dataTK(cur.d) + ':', secoes: [{ titulo: 'Fato observado', itens: pio.concat(mel).length ? pio.concat(mel) : ['Nenhuma variação nos indicadores'] }, { titulo: 'Eventos próximos (evidência)', itens: entre.length ? entre.map(e => dataTK(e.t) + ' · ' + e.txt) : ['Nenhum evento datado no período'] }, { titulo: 'Possível explicação', itens: [entre.length ? 'A variação coincide com os eventos acima; coincidir no tempo não prova causa.' : 'Sem evento associado: pode ser efeito atrasado de algo anterior ou dado não registrado.'] }] }; }
      return { ...base, resposta: 'Evolução da Pasta ' + a.numero + ' de ' + dataTK(prev.d) + ' para ' + dataTK(cur.d) + '.', secoes: [{ titulo: 'Melhorou', itens: mel.length ? mel : ['Nada'] }, { titulo: 'Piorou', itens: pio.length ? pio : ['Nada'] }, { titulo: 'Permaneceu igual', itens: igual.length ? igual : ['Nada'] }].concat(prev.estado !== cur.estado ? [{ titulo: 'Mudou de estado', itens: [ESTADOS_P[prev.estado][0] + ' → ' + ESTADOS_P[cur.estado][0]] }] : []) };
    }
    const L = linhaTempo(g), tr = tendenciaK(S.map(p => p.atrasadas || 0)), C = ciclosK(g);
    return { ...base, resposta: 'Pasta ' + a.numero + ' em três tempos.', secoes: [{ titulo: 'Passado', itens: L.ev.filter(e => e.t <= Date.now() && e.relevante).slice(-4).map(e => dataTK(e.t) + ' · ' + e.txt) }, { titulo: 'Presente', itens: [ESTADOS_P[a.estado][0] + ' · ' + a.motivo, INATIV[a.estado], 'Última providência: ' + (L.ultProv ? dataTK(L.ultProv.t) + ' · ' + L.ultProv.txt : 'não localizada')] }, { titulo: 'Futuro (se nada mudar)', itens: [tr.tipo === 'dados insuficientes' ? 'Dados insuficientes para estimar a tendência (' + tr.n + ' dia(s))' : 'Atrasos ' + tr.tipo + '; em 7 dias: ~' + tr.proj(7), C.prox[0] ? 'Próxima etapa ' + C.prox[0].grau + ': ' + C.prox[0].etapa : 'Sem ciclo conhecido para antecipar'] }], pontos: [{ nivel: 'info', camada: 'dado', confianca: 'confirmado', texto: cuidado }] };
  }
  /* ================= ADAPTIVE SKILL & EXPERTISE ENGINE =================
     Skill = saber fazer. Cada skill tem domínio, subskills, pré-requisitos, regras, especializações (contexto, escritório, usuário),
     exceções, versões, histórico de uso e métricas internas. Maturidade sobe só com evidência (usos sem correção + validação).
     Router escolhe as skills pela intenção e contexto; skills novas nascem em sandbox e só ficam ativas com aprovação do Master. */
  const NIVEL_SK = ['Desconhecida', 'Observada', 'Em aprendizado', 'Funcional', 'Validada', 'Especializada', 'Automatizável'];
  const ESTADO_SK = ['rascunho', 'experimental', 'teste', 'validacao', 'ativa', 'em revisão', 'deprecada'];
  const SKILLS_BASE = [
    ['analise_publicacao', 'Análise de publicação', 'PROCESSUAL', ['classificar', 'identificar processo', 'identificar prazo', 'identificar providência', 'identificar responsável', 'criar plano', 'verificação final'], ['leitura_processual', 'analise_prazo'], /intima|publica|push|movimenta|decis|despach/, ['investigar', 'causa', 'proximo']],
    ['analise_prazo', 'Análise de prazo', 'JURÍDICO', ['termo inicial', 'dias úteis', 'feriados', 'folga'], ['leitura_processual'], /prazo|venc|dias uteis|termo/, ['hoje'], { especializacoes: ['prazo processual', 'prazo interno', 'prazo contratual', 'prazo administrativo'] }],
    ['leitura_processual', 'Leitura processual', 'PROCESSUAL', ['identificar ato', 'identificar parte', 'identificar determinação'], [], /processo|autos|pje|classe|vara/, ['linhadotempo']],
    ['diagnostico_pasta', 'Diagnóstico de pasta', 'GESTÃO', ['hipóteses', 'evidências', 'lacunas', 'contradições'], ['analise_publicacao', 'analise_metas'], /pasta|parad|pront|pendenc|falta/, ['investigar', 'naovejo', 'situacao']],
    ['analise_metas', 'Análise de metas', 'PRODUTIVIDADE', ['atraso', 'responsável', 'carga', 'recorrência'], [], /meta|atras|tarefa|responsavel/, ['controladoria', 'gestor']],
    ['planejamento', 'Planejamento operacional', 'PLANEJAMENTO', ['decompor', 'dependências', 'caminho crítico', 'checkpoints', 'planos B e C'], ['diagnostico_pasta'], /plano|etapa|preparar|protocol|resolver/, ['orquestrar', 'planos', 'plano']],
    ['inteligencia_temporal', 'Inteligência temporal', 'ANÁLISE', ['linha do tempo', 'tendência', 'baseline', 'ciclos', 'recorrência'], [], /quando|desde|tendenc|antes|depois|mudou|historico|evolu/, ['temporal', 'tendencia', 'evolucao', 'em', 'mudancas', 'recorrencias', 'porquemudou']],
    ['documental', 'Análise documental', 'DOCUMENTAL', ['localizar documento', 'subpastas', 'checklist'], [], /documento|procura|anexo|arquivo|contrato/, ['semelhantes']],
    ['controladoria', 'Controladoria', 'CONTROLADORIA', ['classificar problema', 'escalonar', 'cobrança progressiva'], ['analise_metas'], /controlador|cobranc|escal|ocorrenc/, ['controladoria', 'ocorrencias', 'providencias', 'intervencao']],
    ['redacao', 'Redação no estilo do usuário', 'COMUNICAÇÃO', ['estrutura', 'tom', 'tamanho'], [], /redija|escreva|minuta|mensagem|texto/, ['estilo']]];
  function skillsK() {
    const N = nucleo(); N.skills = N.skills || {};
    SKILLS_BASE.forEach(([id, nome, dom, sub, pre, re, cmds, extra]) => { if (!N.skills[id]) N.skills[id] = { id, nome, dominio: dom, subskills: sub, prereq: pre, comandos: cmds, especializacoes: (extra && extra.especializacoes) || [], excecoes: [], negativos: [], versoes: [{ v: '1.0', em: new Date(2026, 0, 1).getTime(), motivo: 'competência base do BlueJus', por: 'sistema' }], versao: '1.0', estado: 'ativa', usos: [], correcoes: 0, validacoes: 0, origem: 'BlueJus', escopo: 'INSTITUCIONAL', criada: new Date(2026, 0, 1).getTime() }; N.skills[id].re = re; });
    return N.skills;
  }
  function metricasSkill(s) {
    const u = s.usos || [], n = u.length, rec = u.filter(x => Date.now() - x.em < 30 * 864e5).length, corr = s.correcoes || 0, ctx = new Set(u.map(x => x.ctx)).size;
    const acerto = n ? Math.max(0, Math.round((1 - corr / n) * 100)) : null, ult = n ? u[n - 1].em : s.criada;
    let nivel = n === 0 ? 0 : n < 3 ? 1 : n < 8 ? 2 : 3; if (nivel >= 3 && s.validacoes >= 1 && acerto >= 80) nivel = 4; if (nivel >= 4 && ctx >= 3 && (s.especializacoes.length || s.excecoes.length)) nivel = 5; if (nivel >= 5 && s.automatizavel) nivel = 6;
    const degradada = s.estado === 'ativa' && ((n >= 5 && acerto < 60) || (n && Date.now() - ult > 90 * 864e5));
    return { usos: n, recentes: rec, correcoes: corr, acerto, contextos: ctx, nivel, degradada, ult };
  }
  // Router: escolhe só as skills relevantes e verifica pré-requisitos.
  function rotearSkills(pedido, cmd) {
    const S = skillsK(), t = norm(pedido), sel = [];
    Object.values(S).filter(s => s.estado === 'ativa' || s.estado === 'em revisão').forEach(s => { let sc = 0; if (cmd && (s.comandos || []).includes(cmd.c)) sc += 3; if (s.re && s.re.test(t)) sc += 2; if (sc) sel.push({ s, sc }); });
    sel.sort((a, b) => b.sc - a.sc); const top = sel.slice(0, 3).map(x => x.s);
    const faltam = []; top.forEach(s => (s.prereq || []).forEach(p => { const q = S[p]; if (!q || q.estado === 'deprecada') faltam.push(p); }));
    const contexto = /contrato/.test(t) ? 'contratual' : /administrat/.test(t) ? 'administrativo' : /meta|interno/.test(t) ? 'interno' : /processo|intima|publica|prazo/.test(t) ? 'processual' : 'geral';
    return { skills: top, faltam: [...new Set(faltam)], contexto };
  }
  function registrarUsoSkill(pedido, cmd, dados) {
    try { const r = rotearSkills(pedido, cmd), w = nucleo().trabalho || {}; r.skills.forEach(s => { s.usos = (s.usos || []).concat([{ em: Date.now(), ctx: r.contexto, cmd: cmd ? cmd.c : 'livre', por: meuId() }]).slice(-300); });
      if (!r.skills.length && pedido.length > 12 && !cmd) { const N = nucleo(), L = N.lacunasSkill = N.lacunasSkill || []; const k = tokensK(pedido).filter(x => x[0] !== '~').slice(0, 3).join(' '); const ex = L.find(x => x.chave === k); if (ex) { ex.n++; ex.ult = Date.now(); } else L.push({ chave: k, exemplo: semTraco(pedido).slice(0, 80), n: 1, em: Date.now() }); }
      if (dados && r.skills.length) dados.skillsUsadas = r.skills.map(s => s.nome); return r; } catch (e) { return null; }
  }
  // Correção do usuário: conta contra a skill da última resposta; "não é necessário/não precisa… quando…" vira exceção.
  function correcaoSkill(cid, texto, ultResp) {
    const r = ultResp && ultResp.iaDados && ultResp.iaDados.skillsUsadas; if (!r || !r.length) return null; const S = skillsK(), s = Object.values(S).find(x => x.nome === r[0]); if (!s) return null;
    s.correcoes = (s.correcoes || 0) + 1; const n = norm(texto); let exc = null;
    if (/nao (e|eh) necessario|nao precisa|nesse tipo nao|neste caso nao/.test(n)) { exc = { regra: semTraco(texto).slice(0, 140), n: 1, em: Date.now(), por: meuId(), status: 'observada' }; const ex = s.excecoes.find(e => tokensK(e.regra).filter(w => tokensK(texto).includes(w)).length >= 3); if (ex) { ex.n++; if (ex.n >= 3) ex.status = 'aprendida'; exc = ex; } else s.excecoes.push(exc); }
    if (/nao funcionou|deu errado|nao serve|piorou/.test(n)) s.negativos.push({ em: Date.now(), contexto: (nucleo().trabalho[cid] || {}).pasta ? 'Pasta ' + nucleo().trabalho[cid].pasta : 'geral', texto: semTraco(texto).slice(0, 120) });
    salvar(); return { s, exc };
  }
  function novaVersaoSkill(s, motivo, mudanca) { const [a, b] = s.versao.split('.').map(Number), v = mudanca === 'maior' ? (a + 1) + '.0' : a + '.' + (b + 1); s.versoes.push({ v, em: Date.now(), motivo, por: meuId(), snapshot: JSON.stringify({ subskills: s.subskills, excecoes: s.excecoes, especializacoes: s.especializacoes, estado: s.estado }) }); s.versao = v; }
  function coletivoResposta(pedido, cmd, perf) {
    const k = cmd && cmd.c; if (!['coletivo', 'gate', 'benchmark', 'privacidade'].includes(k)) return null;
    const base = { modulos: ['Inteligência coletiva'], comando: 'Coletivo', pontos: [], acoes: [] }, adm = perf.papel === 'MASTER', g = glLer(); qualidadeGlobal();
    if (k === 'gate') { if (!adm) return { ...base, resposta: 'O Privacy Gate é exclusivo do Master.' }; const r = privacyGate((cmd && cmd.arg) || ''); return { ...base, resposta: r.ok ? 'Passou no Privacy Gate. Ainda assim só entra no conhecimento coletivo depois de aparecer em pelo menos ' + K_MIN + ' contas diferentes.' : 'Bloqueado no Privacy Gate. Não seria compartilhado.', secoes: [{ titulo: 'Etapas', itens: r.etapas.map(([e, x]) => e + ': ' + x).concat(['Amostra mínima: ' + K_MIN + ' contas distintas', 'Validação: 10 contas para uso nas respostas']) }] }; }
    if (k === 'privacidade') {
      const cfg = prefs.coletivo = prefs.coletivo || {}, me = cfg[CONTA()] = cfg[CONTA()] || { optout: false }, hc = hashK('sal:' + CONTA()), minhas = Object.values(g.padroes).filter(p => p.contas.includes(hc)).length;
      return { ...base, resposta: 'Esta conta ' + (me.optout ? 'não participa' : 'participa') + ' da inteligência coletiva. Só padrões abstratos que passam pelo Privacy Gate saem daqui; documentos, nomes, processos e valores nunca saem. A conta contribuiu para ' + minhas + ' padrão(ões).' + (adm ? '' : ' Só o Master altera.'),
        acoes: adm ? [{ tipo: 'coletivo_cfg', rotulo: me.optout ? 'Voltar a participar' : 'Não participar', op: me.optout ? 'optin' : 'optout' }, { tipo: 'coletivo_cfg', rotulo: 'Excluir contribuições desta conta', op: 'excluir' }] : [] };
    }
    if (k === 'benchmark') { const G = gemeoK(perf), b = benchmarkK(G); if (!b) return { ...base, resposta: 'Abra uma pasta para comparar.' }; if (b.insuf) return { ...base, resposta: 'Comparação anônima indisponível: preciso de dados agregados de pelo menos 10 contas (há ' + b.contas + '). Sem isso, qualquer comparação poderia expor outro escritório.' }; return { ...base, resposta: 'Pasta × padrão agregado.', secoes: [{ titulo: 'Comparação', itens: Object.entries(b.local).map(([kk, v]) => kk + ': ' + v + ' × mediana ' + (b.ag[kk] != null ? b.ag[kk] : '?')) }, { titulo: 'Limites', itens: ['População: ' + b.ag.contas + ' contas', 'Período: ' + (b.ag.periodo || 'últimos 90 dias')] }] }; }
    const L = Object.values(g.padroes), por = {}; ESTADOS_COL.forEach(e => { por[e] = L.filter(p => (p.estado || 'observado') === e); });
    return { ...base, resposta: 'Inteligência coletiva: ' + L.length + ' padrões abstratos, ' + (g.bloqueados || 0) + ' bloqueados pelo Privacy Gate. Origem nunca é exibida; só a quantidade de contas independentes. Conhecimento global não vira regra local: aparece como sugestão, e a regra do escritório prevalece em conflito.',
      secoes: ESTADOS_COL.filter(e => por[e].length).map(e => ({ titulo: e.charAt(0).toUpperCase() + e.slice(1), itens: por[e].slice(0, 5).map(p => p.descricao + ' · ' + p.contas.length + ' conta(s) · ' + (p.tipo === 'negativo' ? 'o que não funciona · ' : '') + 'desde ' + dataTK(p.criado) + ' · última confirmação ' + dataTK(p.ult || p.criado) + (p.excecoes.length ? ' · ' + p.excecoes.length + ' exceção(ões)' : '')) })).concat([{ titulo: 'Regras do gate', itens: ['Identificadores removidos (CPF, CNPJ, processo, e-mail, telefone, valor, endereço, no