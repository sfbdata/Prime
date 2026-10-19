/* BlueJus · Assistente de pastas (protótipo da janela lateral do agente).
   Camadas: FERRAMENTAS (registry com risco e permissão) → PLANEJADOR (Claude ou regras) → PLANO + CONFIRMAÇÃO → EXECUÇÃO em lotes com progresso → AUDITORIA + DESFAZER.
   No BlueJus real, as ferramentas viram endpoints do servidor (ver ESPEC_AGENTE_PASTAS.md); esta tela só troca a "ponte" local pelas chamadas HTTP. */
(function () {
  if (window.BJAgente) return;
  const ALT = 'bj-agente-alt', LOG = 'bj-agente-log', AZ = '#0a6fa0', DESFAZER_MIN = 10;
  const ler = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) || d; } catch (e) { return d; } };
  const grava = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
  const P = () => window.BJPastasPonte;
  const norm = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const SIT = ['ATIVO', 'SUSPENSO', 'ARQUIVADO', 'CANCELADO'], PRIO = ['NORMAL', 'ALTA', 'URGENTE'];
  const eu = () => { try { return (window.BJCentral && window.BJCentral.eu().nome) || 'Você'; } catch (e) { return 'Você'; } };
  const podeAlterar = () => { try { const C = window.BJCentral; if (C && C.ehPrimario) return !!(C.ehPrimario() || (C.ehMaster && C.ehMaster())); } catch (e) {} return true; };

  /* ---------- FERRAMENTAS ---------- */
  const respNome = i => i < 0 ? 'Sem responsável' : (P().resps()[i] || '?');
  function filtrar(f) {
    f = f || {}; const R = P().resps();
    return P().ler().filter(d => {
      if (f.nups && f.nups.length && !f.nups.includes(d.nup)) return false;
      if (f.cliente && !norm(d.cliente).includes(norm(f.cliente))) return false;
      if (f.acao && !norm(d.acao).includes(norm(f.acao))) return false;
      if (f.responsavel !== undefined && f.responsavel !== null && f.responsavel !== '') { if (norm(f.responsavel) === 'sem responsavel' ? d.resp >= 0 : !norm(respNome(d.resp)).includes(norm(f.responsavel))) return false; }
      if (f.marcador !== undefined && f.marcador !== null && f.marcador !== '') { if (norm(f.marcador) === 'sem marcador' ? !!d.marc : !norm(d.marc).includes(norm(f.marcador))) return false; }
      if (f.situacao && d.sit !== String(f.situacao).toUpperCase()) return false;
      if (f.prioridade && d.prio !== String(f.prioridade).toUpperCase()) return false;
      if (f.semMetas && d.metas > 0) return false;
      if (f.desde && d.data < f.desde) return false;
      if (f.ate && d.data > f.ate) return false;
      return true;
    });
  }
  const TOOLS = {
    buscar_pastas: { rot: 'Buscar pastas', risco: 'leitura', ic: 'bi-search' },
    contar_pastas: { rot: 'Contar pastas', risco: 'leitura', ic: 'bi-123' },
    definir_responsavel: { rot: 'Trocar responsável', risco: 'alteracao', ic: 'bi-person-badge', campo: 'resp', val: v => { const R = P().resps(), i = R.findIndex(n => norm(n).includes(norm(v))); if (norm(v) === 'sem responsavel') return -1; return i < 0 ? undefined : i; }, mostra: v => respNome(v) },
    definir_marcador: { rot: 'Aplicar marcador', risco: 'alteracao', ic: 'bi-tag', campo: 'marc', val: v => { if (!v || norm(v) === 'sem marcador') return ''; const m = P().marcadores().find(n => norm(n).includes(norm(v))); return m === undefined ? undefined : m; }, mostra: v => v || 'Sem marcador' },
    definir_situacao: { rot: 'Alterar situação', risco: 'alteracao', ic: 'bi-flag', campo: 'sit', val: v => { const s = SIT.find(x => norm(x).startsWith(norm(v).slice(0, 4))); return s; }, mostra: v => v },
    definir_prioridade: { rot: 'Alterar prioridade', risco: 'alteracao', ic: 'bi-flag-fill', campo: 'prio', val: v => PRIO.find(x => norm(x).startsWith(norm(v).slice(0, 3))), mostra: v => v },
    arquivar_pastas: { rot: 'Arquivar pastas', risco: 'sensivel', ic: 'bi-archive', campo: 'sit', fixo: 'ARQUIVADO', mostra: () => 'ARQUIVADO' },
    prazos_pastas: { rot: 'Prazos das pastas', risco: 'leitura', ic: 'bi-calendar-event' },
    ler_documentos: { rot: 'Ler documentos', risco: 'leitura', ic: 'bi-file-earmark-text', indisponivel: 'Leitura de documentos precisa do servidor do BlueJus (ferramenta listar_documentos / ler_documento).' },
    anexar_documento: { rot: 'Anexar documento', risco: 'alteracao', ic: 'bi-paperclip', indisponivel: 'Anexar arquivos precisa do servidor do BlueJus (ferramenta anexar_documento).' },
    excluir_documento: { rot: 'Excluir documento', risco: 'sensivel', ic: 'bi-trash3', indisponivel: 'Excluir documentos precisa do servidor do BlueJus e sempre pede confirmação dupla.' }
  };

  /* ---------- PLANEJADOR ---------- */
  function regras(txt) {
    const t = norm(txt), f = {}, R = P().resps(), M = P().marcadores();
    const nups = (txt.match(/\b\d{3,4}\b/g) || []).map(Number).filter(n => n > 100 && n < 100000); if (nups.length && /pasta/.test(t)) f.nups = nups;
    if (/sem responsavel/.test(t)) f.responsavel = 'sem responsavel';
    if (/sem marcador/.test(t)) f.marcador = 'sem marcador';
    if (/sem meta/.test(t)) f.semMetas = true;
    SIT.forEach(s => { if (new RegExp('\\b(' + norm(s).slice(0, 6) + '\\w*)\\b').test(t) && !/arquiv(e|ar)\b/.test(t)) f.situacao = s; });
    if (/urgente/.test(t) && !/prioridade (para )?urgente/.test(t)) f.prioridade = 'URGENTE';
    const cli = txt.match(/(?:cliente|do|da)\s+([A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wÁÉÍÓÚÂÊÔÃÕÇáéíóúâêôãõç]+(?:\s+[A-ZÁÉÍÓÚÂÊÔÃÕÇ][\wÁÉÍÓÚÂÊÔÃÕÇáéíóúâêôãõç]+)*)/); if (cli && !R.some(r => norm(r).includes(norm(cli[1]))) && !/^Pasta/.test(cli[1])) f.cliente = cli[1];
    let tool = 'buscar_pastas', valor = null;
    const para = t.match(/para (?:o |a )?([a-z ]+?)(?:$|[.,])/);
    if (/(prazo|venc|vence)/.test(t) && !/(troc|mud|aplic|arquiv)/.test(t)) { tool = 'prazos_pastas'; f.periodo = /hoje/.test(t) ? 'hoje' : /amanha/.test(t) ? 'amanha' : /(venceu|vencid|atrasad)/.test(t) ? 'vencidos' : /(mes|30 dias)/.test(t) ? 'mes' : /(\d{1,3}) dias/.test(t) ? t.match(/(\d{1,3}) dias/)[1] : 'semana'; }
    else if (/(documento|anex|pdf|procura[cç])/.test(t)) tool = /exclu|apag/.test(t) ? 'excluir_documento' : /anex|inclu/.test(t) ? 'anexar_documento' : 'ler_documentos';
    else if (/arquiv(e|ar|em)\b/.test(t)) tool = 'arquivar_pastas';
    else if (/(troc|mud|pass|transfir|redistribu|atribu)\w* .*respons|respons\w* .*para/.test(t) && para) { tool = 'definir_responsavel'; valor = para[1]; }
    else if (/marcador/.test(t) && /(aplic|coloc|marc|defin|mud)/.test(t) && (para || /marcador ([a-z0-9. ]+?) (nas|em|na|no|nos)\b/.test(t))) { tool = 'definir_marcador'; const mv = t.match(/marcador ([a-z0-9. ]+?) (nas|em|na|no|nos)\b/); valor = mv ? mv[1] : para[1]; }
    else if (/prioridade/.test(t) && para) { tool = 'definir_prioridade'; valor = para[1]; delete f.prioridade; }
    else if (/(situa|status)/.test(t) && para) { tool = 'definir_situacao'; valor = para[1]; delete f.situacao; }
    else if (/quant|cont/.test(t)) tool = 'contar_pastas';
    const resp = R.find(r => norm(r).split(' ').some(p => p.length > 3 && new RegExp('\\b' + p + '\\b').test(t) && (!valor || !norm(valor).includes(p))));
    if (resp && f.responsavel === undefined) f.responsavel = resp;
    const mk = M.find(m => t.includes(norm(m).replace(/^\d+\.\s*/, ''))); if (mk && f.marcador === undefined && !(tool === 'definir_marcador' && valor && norm(mk).includes(norm(valor)))) f.marcador = mk;
    return { tool, filtro: f, valor, origem: 'regras' };
  }
  async function planejar(txt) {
    const base = regras(txt);
    if (!window.claude || !window.claude.complete) return base;
    const prompt = 'Você é o planejador do Assistente de pastas do BlueJus (sistema jurídico). Converta o pedido em UM comando JSON, sem texto extra.\n' +
      'Ferramentas: ' + Object.keys(TOOLS).join(', ') + '.\nResponsáveis: ' + P().resps().join('; ') + '; "Sem responsável".\nMarcadores: ' + P().marcadores().join('; ') + '; "Sem marcador".\nSituações: ' + SIT.join(', ') + '. Prioridades: ' + PRIO.join(', ') + '.\n' +
      'Para prazos use tool prazos_pastas e filtro.periodo = hoje | amanha | semana | mes | vencidos | número de dias.\nFormato: {"tool":"...","filtro":{"periodo":"","nups":[],"cliente":"","acao":"","responsavel":"","marcador":"","situacao":"","prioridade":"","semMetas":false,"desde":"AAAA-MM-DD","ate":"AAAA-MM-DD"},"valor":"novo valor quando a ferramenta altera algo","resumo":"frase curta em português do que será feito"}. Omita campos vazios. Hoje é ' + new Date().toISOString().slice(0, 10) + '.\nPedido: ' + txt;
    try {
      const r = await window.claude.complete({ messages: [{ role: 'user', content: prompt }] });
      const j = JSON.parse(String(r).slice(String(r).indexOf('{'), String(r).lastIndexOf('}') + 1));
      if (j && TOOLS[j.tool]) return Object.assign({ filtro: {} }, j, { origem: 'claude' });
    } catch (e) {}
    return base;
  }

  /* ---------- UI ---------- */
  let raiz, corpo, entrada, estado = { aberto: false, ocupado: false };
  const h = (tag, st, html) => { const e = document.createElement(tag); if (st) e.style.cssText = st; if (html != null) e.innerHTML = html; return e; };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const btn = (txt, cor, fn, ic) => { const b = h('button', 'height:32px;padding:0 13px;border:none;border-radius:4px;font:700 12.5px Arial;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:filter .15s;' + (cor === 'pri' ? 'background:' + AZ + ';color:#fff' : cor === 'perigo' ? 'background:#b3262e;color:#fff' : 'background:#eef2f5;color:#34505f'), (ic ? '<i class="bi ' + ic + '"></i>' : '') + esc(txt)); b.onmouseenter = () => b.style.filter = 'brightness(.94)'; b.onmouseleave = () => b.style.filter = ''; b.onclick = fn; return b; };
  function bolha(quem, html) {
    const ia = quem === 'ia', w = h('div', 'display:flex;gap:8px;align-items:flex-start;' + (ia ? '' : 'flex-direction:row-reverse'));
    w.appendChild(h('span', 'flex-shrink:0;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;' + (ia ? 'background:#e5f1f7;color:' + AZ : 'background:' + AZ + ';color:#fff'), ia ? '<i class="bi bi-stars"></i>' : '<i class="bi bi-person-fill"></i>'));
    const b = h('div', 'max-width:86%;padding:9px 11px;border-radius:4px;font-size:13px;line-height:1.5;color:#12242f;' + (ia ? 'background:#fff;border:1px solid #dde5eb' : 'background:#e5f1f7;border:1px solid #c9dfec'), html);
    w.appendChild(b); corpo.appendChild(w); corpo.scrollTop = corpo.scrollHeight; return b;
  }
  function prazosBloco(f) {
    const per = String(f.periodo || 'semana'), hoje = new Date(); hoje.setHours(0, 0, 0, 0); const dia = s => { const d = new Date(s + 'T00:00'); return Math.round((d - hoje) / 864e5); };
    const fimSem = (() => { const d = new Date(hoje); d.setDate(d.getDate() + (7 - (d.getDay() || 7))); return Math.round((d - hoje) / 864e5); })();
    const lim = per === 'hoje' ? [0, 0] : per === 'amanha' ? [1, 1] : per === 'vencidos' ? [-9999, -1] : per === 'mes' ? [0, 30] : /^\d+$/.test(per) ? [0, +per] : [0, fimSem];
    const rot = per === 'hoje' ? 'para hoje' : per === 'amanha' ? 'para amanhã' : per === 'vencidos' ? 'vencidos' : per === 'mes' ? 'nos próximos 30 dias' : /^\d+$/.test(per) ? 'nos próximos ' + per + ' dias' : 'nesta semana (até domingo)';
    let S = {}; try { S = JSON.parse(localStorage.getItem('bj-prazos-pasta-v1') || '{}'); } catch (x) {}
    const base = filtrar(Object.assign({}, f, { periodo: undefined })), cli = {}; base.forEach(d => cli[d.nup] = d);
    const lidas = Object.keys(S).filter(k => /^\d+$/.test(k)), itens = [];
    lidas.forEach(k => { if (!cli[k] && base.length !== P().ler().length) return; (S[k] || []).forEach(p => { const n = dia(p.data); if (n >= lim[0] && n <= lim[1]) itens.push(Object.assign({ nup: +k, n, cliente: cli[k] ? cli[k].cliente : '' }, p)); }); });
    itens.sort((x, y) => x.n - y.n);
    const pastas = [...new Set(itens.map(x => x.nup))], w = h('div');
    w.innerHTML = '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#7b93a2;margin-bottom:4px"><i class="bi bi-calendar-event"></i> PRAZOS DAS PASTAS · SÓ LEITURA</div>' + (itens.length ? '<b>' + pastas.length + '</b> pasta(s) com <b>' + itens.length + '</b> prazo(s) ' + rot + '.' : 'Nenhum prazo ' + rot + '.');
    const fx = n => n < 0 ? 'Vencido há ' + -n + (n === -1 ? ' dia' : ' dias') : n === 0 ? 'Hoje' : n === 1 ? 'Amanhã' : 'Faltam ' + n + ' dias', cor = n => n <= 0 ? ['#a3232b', '#fdecec'] : n <= 3 ? ['#a3232b', '#fdecec'] : n <= 7 ? ['#8a5a12', '#fdf3e2'] : ['#34505f', '#eef2f5'];
    const ul = h('div', 'margin-top:8px;display:flex;flex-direction:column;gap:4px');
    itens.slice(0, 12).forEach(x => { const [c1, c2] = cor(x.n), dt = x.data.split('-').reverse().join('/'); const r = h('button', 'display:flex;flex-direction:column;gap:2px;width:100%;text-align:left;border:1px solid #eef2f5;background:#fafcfd;border-radius:3px;padding:6px 8px;cursor:pointer;font:12.5px Arial;color:#12242f', '<span style="display:flex;gap:8px;align-items:center"><b style="color:' + AZ + '">' + x.nup + '</b><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(x.titulo) + '</span><span style="font-size:11px;font-weight:700;color:' + c1 + ';background:' + c2 + ';padding:1px 6px;border-radius:3px;white-space:nowrap">' + fx(x.n) + '</span></span><span style="font-size:11px;color:#7b93a2">' + esc((x.cliente ? x.cliente + ' · ' : '') + (x.resp ? x.resp + ' · ' : '') + dt + ' · fonte: ' + x.origem) + '</span>'); r.onmouseenter = () => r.style.background = '#e5f1f7'; r.onmouseleave = () => r.style.background = '#fafcfd'; r.title = 'Mostrar a pasta ' + x.nup + ' na lista'; r.onclick = () => P().abrir(x.nup); ul.appendChild(r); });
    if (itens.length) { w.appendChild(ul); const ac = h('div', 'display:flex;gap:6px;margin-top:8px;flex-wrap:wrap'); ac.appendChild(btn('Mostrar na lista', 'pri', () => P().filtrar(pastas), 'bi-funnel')); w.appendChild(ac); }
    const nota = h('div', 'margin-top:8px;font-size:11.5px;color:#5b7383;line-height:1.45', '<i class="bi bi-info-circle"></i> Fonte: datas registradas dentro de cada pasta: Próximos prazos, Push processual (termo final calculado pela movimentação) e prazo das Metas abertas. ' + lidas.length + ' pasta(s) com prazos lidos; as demais ainda não têm prazo registrado.'); w.appendChild(nota);
    return w;
  }
  function listaPastas(L, max) {
    max = max || 8; const ul = h('div', 'margin-top:8px;display:flex;flex-direction:column;gap:3px');
    L.slice(0, max).forEach(d => { const r = h('button', 'display:flex;gap:8px;align-items:center;width:100%;text-align:left;border:1px solid #eef2f5;background:#fafcfd;border-radius:3px;padding:5px 8px;cursor:pointer;font:12.5px Arial;color:#12242f', '<b style="color:' + AZ + ';font-variant-numeric:tabular-nums">' + d.nup + '</b><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(d.cliente) + '</span><span style="font-size:11px;color:#7b93a2;white-space:nowrap">' + esc(respNome(d.resp).split(' ')[0]) + '</span>'); r.onmouseenter = () => r.style.background = '#e5f1f7'; r.onmouseleave = () => r.style.background = '#fafcfd'; r.title = 'Mostrar a pasta ' + d.nup + ' na lista'; r.onclick = () => P().abrir(d.nup); ul.appendChild(r); });
    if (L.length > max) ul.appendChild(h('div', 'font-size:12px;color:#5b7383;padding:3px 2px', '+ ' + (L.length - max) + ' pasta(s)'));
    return ul;
  }
  const filtroTxt = f => { const p = []; if (f.nups && f.nups.length) p.push('pastas ' + f.nups.join(', ')); if (f.cliente) p.push('cliente "' + f.cliente + '"'); if (f.acao) p.push('ação "' + f.acao + '"'); if (f.responsavel) p.push('responsável ' + f.responsavel); if (f.marcador) p.push('marcador ' + f.marcador); if (f.situacao) p.push('situação ' + f.situacao); if (f.prioridade) p.push('prioridade ' + f.prioridade); if (f.semMetas) p.push('sem metas'); if (f.desde || f.ate) p.push('período ' + (f.desde || '…') + ' a ' + (f.ate || '…')); return p.length ? p.join(' · ') : 'todas as pastas'; };

  async function pedir(txt) {
    if (!P()) return bolha('ia', 'A lista de pastas ainda está carregando. Tente de novo em instantes.');
    if (estado.ocupado) return; estado.ocupado = true;
    bolha('eu', esc(txt));
    const pens = bolha('ia', '<span style="color:#5b7383"><i class="bi bi-hourglass-split"></i> Montando o plano…</span>');
    const pl = await planejar(txt); estado.ocupado = false;
    const T = TOOLS[pl.tool], f = pl.filtro || {};
    if (T.indisponivel) { pens.innerHTML = '<b>' + esc(T.rot) + '</b><br>' + esc(T.indisponivel) + '<div style="margin-top:6px;font-size:12px;color:#5b7383">Nesta tela consigo buscar, contar e alterar responsável, marcador, situação e prioridade das pastas.</div>'; return; }
    if (pl.tool === 'prazos_pastas') { pens.innerHTML = ''; pens.appendChild(prazosBloco(f)); auditar({ tool: pl.tool, filtro: f, resultado: 'consulta' }); return; }
    const L = filtrar(f);
    if (T.risco === 'leitura') {
      pens.innerHTML = '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#7b93a2;margin-bottom:4px"><i class="bi ' + T.ic + '"></i> ' + esc(T.rot).toUpperCase() + ' · SÓ LEITURA</div>Encontrei <b>' + L.length + '</b> pasta(s) com ' + esc(filtroTxt(f)) + '.';
      if (L.length) { pens.appendChild(listaPastas(L)); const ac = h('div', 'display:flex;gap:6px;margin-top:8px;flex-wrap:wrap'); ac.appendChild(btn('Mostrar na lista', 'pri', () => P().filtrar(L.map(d => d.nup)), 'bi-funnel')); pens.appendChild(ac); }
      auditar({ tool: pl.tool, filtro: f, n: L.length, resultado: 'consulta' }); return;
    }
    if (!podeAlterar()) { pens.innerHTML = 'Alterações em lote são permitidas só ao usuário primário da conta. Peça a ele ou use a busca.'; return; }
    const novo = T.fixo !== undefined ? T.fixo : T.val(pl.valor || '');
    if (novo === undefined) { pens.innerHTML = 'Não entendi o novo valor <b>"' + esc(pl.valor || '') + '"</b>. Exemplos: "trocar o responsável para Samuel", "aplicar o marcador Despachar".'; return; }
    const alvo = L.filter(d => d[T.campo] !== novo);
    pens.innerHTML = '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:' + (T.risco === 'sensivel' ? '#a3232b' : '#c76a12') + ';margin-bottom:4px"><i class="bi ' + T.ic + '"></i> PLANO · ' + esc(T.rot).toUpperCase() + '</div>' +
      'Filtro: ' + esc(filtroTxt(f)) + '<br>Vou alterar <b>' + alvo.length + '</b> de ' + L.length + ' pasta(s): <b>' + esc(T.campo === 'resp' ? 'responsável' : T.campo === 'marc' ? 'marcador' : T.campo === 'sit' ? 'situação' : 'prioridade') + ' → ' + esc(T.mostra(novo)) + '</b>.' + (L.length - alvo.length ? '<br><span style="color:#7b93a2;font-size:12px">' + (L.length - alvo.length) + ' já estão assim e ficam como estão.</span>' : '');
    if (!alvo.length) return;
    pens.appendChild(listaPastas(alvo));
    const ac = h('div', 'display:flex;gap:6px;margin-top:10px;flex-wrap:wrap'), lim = alvo.length > 50 || T.risco === 'sensivel';
    const conf = lim ? h('label', 'display:flex;align-items:center;gap:6px;flex-basis:100%;font-size:12px;color:#a3232b;cursor:pointer', '<input type="checkbox"> Confirmo a alteração de ' + alvo.length + ' pasta(s)') : null;
    if (conf) ac.appendChild(conf);
    ac.appendChild(btn('Executar', T.risco === 'sensivel' ? 'perigo' : 'pri', () => { if (conf && !conf.querySelector('input').checked) { conf.style.fontWeight = '700'; return; } ac.remove(); executar(pens, T, pl, f, alvo, novo); }, 'bi-play-fill'));
    ac.appendChild(btn('Cancelar', '', () => { ac.remove(); pens.appendChild(h('div', 'margin-top:6px;font-size:12px;color:#7b93a2', 'Cancelado. Nada foi alterado.')); }));
    pens.appendChild(ac);
  }
  function executar(caixa, T, pl, f, alvo, novo) {
    const prog = h('div', 'margin-top:10px'), bar = h('div', 'height:6px;border-radius:3px;background:#eef2f5;overflow:hidden'), fill = h('div', 'height:100%;width:0;background:' + AZ + ';transition:width .15s'), lab = h('div', 'font-size:11.5px;color:#5b7383;margin-top:4px', '0 de ' + alvo.length);
    bar.appendChild(fill); prog.appendChild(bar); prog.appendChild(lab); caixa.appendChild(prog);
    const A = ler(ALT, {}), antes = {}; let i = 0; const lote = Math.max(5, Math.ceil(alvo.length / 25));
    const passo = () => {
      alvo.slice(i, i + lote).forEach(d => { antes[d.nup] = { [T.campo]: d[T.campo] }; A[d.nup] = Object.assign({}, A[d.nup] || {}, { [T.campo]: novo }); });
      i = Math.min(alvo.length, i + lote); fill.style.width = (i / alvo.length * 100) + '%'; lab.textContent = i + ' de ' + alvo.length + ' pastas';
      if (i < alvo.length) return setTimeout(passo, 40);
      grava(ALT, A); P().recarregar();
      const ok = filtrar({ nups: alvo.map(d => d.nup) }).filter(d => d[T.campo] === novo).length;
      const reg = auditar({ tool: pl.tool, filtro: f, n: alvo.length, campo: T.campo, novo, antes, resultado: ok === alvo.length ? 'ok' : 'parcial (' + ok + ')' });
      lab.innerHTML = '<span style="color:#1e7b45;font-weight:700"><i class="bi bi-check-circle-fill"></i> Concluído: ' + ok + ' de ' + alvo.length + ' pasta(s) conferidas.</span>';
      const ac = h('div', 'display:flex;gap:6px;margin-top:8px;flex-wrap:wrap');
      ac.appendChild(btn('Mostrar alteradas', 'pri', () => P().filtrar(alvo.map(d => d.nup)), 'bi-funnel'));
      const dz = btn('Desfazer (' + DESFAZER_MIN + ' min)', '', () => { desfazer(reg.id); dz.remove(); caixa.appendChild(h('div', 'margin-top:6px;font-size:12px;color:#1e7b45', 'Alteração desfeita. As pastas voltaram ao estado anterior.')); }, 'bi-arrow-counterclockwise');
      ac.appendChild(dz); caixa.appendChild(ac); setTimeout(() => dz.remove(), DESFAZER_MIN * 6e4);
    };
    passo();
  }
  function auditar(x) { const L = ler(LOG, []), r = Object.assign({ id: 'a' + Date.now(), em: Date.now(), quem: eu() }, x); L.unshift(r); grava(LOG, L.slice(0, 300)); return r; }
  function desfazer(id) {
    const L = ler(LOG, []), r = L.find(x => x.id === id); if (!r || !r.antes || Date.now() - r.em > DESFAZER_MIN * 6e4) return false;
    const A = ler(ALT, {}); Object.entries(r.antes).forEach(([n, v]) => { A[n] = Object.assign({}, A[n] || {}, v); }); grava(ALT, A); r.desfeito = Date.now(); grava(LOG, L); auditar({ tool: 'desfazer', n: Object.keys(r.antes).length, resultado: 'ok', ref: id }); P().recarregar(); return true;
  }
  function verHistorico() {
    const L = ler(LOG, []).slice(0, 12);
    const b = bolha('ia', '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:#7b93a2;margin-bottom:6px"><i class="bi bi-clock-history"></i> AUDITORIA DO ASSISTENTE</div>' + (L.length ? '' : 'Nenhuma ação registrada ainda.'));
    L.forEach(r => { const T = TOOLS[r.tool] || { rot: r.tool === 'desfazer' ? 'Desfazer' : r.tool }; b.appendChild(h('div', 'display:flex;gap:8px;padding:5px 0;border-top:1px solid #eef2f5;font-size:12px', '<span style="color:#7b93a2;white-space:nowrap;font-variant-numeric:tabular-nums">' + new Date(r.em).toLocaleString('pt-BR').slice(0, 17) + '</span><span style="flex:1"><b>' + esc(T.rot) + '</b> · ' + r.n + ' pasta(s) · ' + esc(r.quem) + (r.novo !== undefined ? ' · → ' + esc(T.mostra ? T.mostra(r.novo) : r.novo) : '') + (r.desfeito ? ' · <i>desfeito</i>' : '') + '</span>')); });
  }

  function montar() {
    if (raiz) return;
    raiz = h('div', 'position:fixed;top:0;right:0;bottom:0;z-index:2147483000;width:min(400px,100vw);display:none;flex-direction:column;background:#f6f8fa;border-left:1px solid #d3dde4;box-shadow:-10px 0 30px rgba(16,42,58,.12);font-family:Arial,Helvetica,sans-serif;transform:translateX(100%);transition:transform .25s cubic-bezier(.22,.8,.3,1)');
    const top = h('div', 'display:flex;align-items:center;gap:10px;padding:12px 14px;background:' + AZ + ';color:#fff', '<i class="bi bi-stars" style="font-size:17px"></i><div style="flex:1;line-height:1.15"><b style="font-size:14.5px">Assistente de pastas</b><div style="font-size:11.5px;opacity:.85">Busca, organiza e altera pastas com confirmação</div></div>');
    const hist = h('button', 'width:30px;height:30px;border:none;border-radius:4px;background:rgba(255,255,255,.16);color:#fff;cursor:pointer', '<i class="bi bi-clock-history"></i>'); hist.title = 'Auditoria'; hist.onclick = verHistorico;
    const x = h('button', 'width:30px;height:30px;border:none;border-radius:4px;background:rgba(255,255,255,.16);color:#fff;cursor:pointer', '<i class="bi bi-x-lg"></i>'); x.title = 'Fechar'; x.onclick = () => alternar(false);
    top.appendChild(hist); top.appendChild(x); raiz.appendChild(top);
    corpo = h('div', 'flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px'); raiz.appendChild(corpo);
    new MutationObserver(() => requestAnimationFrame(() => { corpo.scrollTop = corpo.scrollHeight; })).observe(corpo, { childList: true, subtree: true, characterData: true });
    const ex = ['Quais pastas têm prazo esta semana?', 'Quais pastas estão sem responsável?', 'Trocar o responsável das pastas da Mariana para Samuel', 'Aplicar o marcador Despachar nas pastas urgentes', 'Quantas pastas estão suspensas?'];
    const b0 = bolha('ia', 'Olá! Descreva o que precisa nas pastas. Antes de alterar qualquer coisa eu mostro o plano e espero a sua confirmação.<div style="margin-top:8px;display:flex;flex-direction:column;gap:4px"></div>');
    ex.forEach(t => { const c = h('button', 'text-align:left;border:1px solid #d6e7f2;background:#f2f8fc;color:#0b4f73;border-radius:3px;padding:6px 9px;font:12.5px Arial;cursor:pointer', esc(t)); c.onclick = () => { entrada.value = t; entrada.focus(); }; b0.lastElementChild.appendChild(c); });
    const pe = h('div', 'display:flex;gap:8px;align-items:flex-end;padding:10px 12px;border-top:1px solid #dde5eb;background:#fff');
    entrada = h('textarea', 'flex:1;min-height:38px;max-height:120px;resize:none;border:1px solid #d3dde4;border-radius:4px;padding:9px 10px;font:13.5px Arial;color:#12242f;outline:none'); entrada.rows = 1; entrada.placeholder = 'Ex.: arquivar as pastas canceladas';
    entrada.onkeydown = e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); const v = entrada.value.trim(); if (v) { entrada.value = ''; pedir(v); } } };
    const env = h('button', 'width:38px;height:38px;border:none;border-radius:4px;background:' + AZ + ';color:#fff;cursor:pointer;font-size:15px', '<i class="bi bi-arrow-up"></i>'); env.title = 'Enviar (Enter)'; env.onclick = () => { const v = entrada.value.trim(); if (v) { entrada.value = ''; pedir(v); } };
    pe.appendChild(entrada); pe.appendChild(env); raiz.appendChild(pe);
    document.body.appendChild(raiz);
    const lan = h('button', 'position:fixed;right:18px;bottom:90px;z-index:2147482990;height:40px;padding:0 14px;border:none;border-radius:4px;background:#fff;color:' + AZ + ';box-shadow:0 4px 16px rgba(16,42,58,.18);font:700 13px Arial;cursor:pointer;display:flex;align-items:center;gap:7px;transition:transform .15s,opacity .6s ease,background .15s,color .15s', '<i class="bi bi-stars"></i>Assistente de pastas'); lan.setAttribute('data-recurso', 'assistente');
    let lanT = null; const lanApagar = () => { clearTimeout(lanT); lanT = setTimeout(() => { if (!lan.matches(':hover') && (!raiz || raiz.style.display === 'none')) lan.style.opacity = '.35'; }, 4000); }; lanApagar();
    lan.onmouseenter = () => { clearTimeout(lanT); lan.style.opacity = '1'; lan.style.background = AZ; lan.style.color = '#fff'; lan.style.transform = 'scale(1.04)'; }; lan.onmouseleave = () => { lan.style.background = '#fff'; lan.style.color = AZ; lan.style.transform = ''; lanApagar(); }; lan.onfocus = lan.onmouseenter; lan.onblur = lan.onmouseleave;
    lan.onclick = () => { const abrindo = !raiz || raiz.style.display === 'none'; alternar(); if (abrindo) window.dispatchEvent(new CustomEvent('bj-ai-fx', { detail: { nome: 'Assistente de pastas' } })); }; document.body.appendChild(lan); estado.lan = lan;
    addEventListener('keydown', e => { if (e.key === 'Escape' && estado.aberto) alternar(false); });
  }
  function alternar(v) { montar(); estado.aberto = v === undefined ? !estado.aberto : v; if (estado.aberto) { raiz.style.display = 'flex'; requestAnimationFrame(() => raiz.style.transform = 'translateX(0)'); setTimeout(() => entrada.focus(), 200); } else { raiz.style.transform = 'translateX(100%)'; setTimeout(() => { if (!estado.aberto) raiz.style.display = 'none'; }, 260); } estado.lan.style.display = estado.aberto ? 'none' : 'flex'; }
  window.BJAgente = { abrir: () => alternar(true), fechar: () => alternar(false), pedir, TOOLS, filtrar, planejar, regras, desfazer };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar); else montar();
})();
