/* BlueJus · Painel do Master, bloco "Metas de divulgação" da Visão geral
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L158-170, menu L614 e lógica
   L1086-1100; spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P3).
   - O painel.js chama BJMetasDivulgacao.montar(raiz, ctx) ao montar a Visão geral: ctx traz os usuários por estado e
     por cidade (a base do mapa), as URLs e o token.
   - A configuração (mercado, meta, automática, metas e destaques) e os advogados por estado da OAB vêm do servidor
     (GET), que também faz a atualização automática do mercado; cada mudança é um POST JSON com X-CSRF-Token e a
     resposta traz o estado novo. Nenhuma regra mora só aqui: o servidor recusa número fora da regra, UF, cor e
     frequência inválidas.
   - As cidades de um estado vêm de /master/ibge/populacao/{UF} (o navegador nunca chama o IBGE).
   - O mapa ao lado é o mapa-brasil do painel num iframe da mesma origem; conversam por postMessage (bjHl, bjHlMun,
     bjAbrir, bjDest para lá; bjMapaHover e bjMapaPronto para cá). A passada do mouse no mapa só liga uma classe na
     linha, sem redesenhar o bloco.
   - Textos no catálogo (master.js.metas.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar. */
(() => {
  'use strict';
  const PREFIXO = 'master.js.metas.';
  const T = (chave, params) => window.BJ_T.t(PREFIXO + chave, params);
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ESC[m]);
  const fN = n => window.BJFormato.numero(+n || 0);
  const dec1 = v => Number(v).toLocaleString(window.BJFormato.idioma(), { minimumFractionDigits: 1, maximumFractionDigits: 1, useGrouping: false });
  const semAcento = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s*\(.*\)\s*/, '').trim();
  const UFN = { AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal', ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso', MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná', PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima', SC: 'Santa Catarina', SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins' };
  // Administrações do DF (a lista do IBGE traz só Brasília), como no desenho.
  const DF_REGIOES = ['Brasília (Plano Piloto)', 'Gama', 'Taguatinga', 'Brazlândia', 'Sobradinho', 'Planaltina', 'Paranoá', 'Núcleo Bandeirante', 'Ceilândia', 'Guará', 'Cruzeiro', 'Samambaia', 'Santa Maria', 'São Sebastião', 'Recanto das Emas', 'Lago Sul', 'Riacho Fundo', 'Lago Norte', 'Candangolândia', 'Águas Claras', 'Riacho Fundo II', 'Sudoeste/Octogonal', 'Varjão', 'Park Way', 'SCIA/Estrutural', 'Sobradinho II', 'Jardim Botânico', 'Itapoã', 'SIA', 'Vicente Pires', 'Fercal', 'Sol Nascente/Pôr do Sol', 'Arniqueira', 'Arapoanga', 'Água Quente'];
  const CORES = [['#f1c40f', 'amarelo'], ['#2e86de', 'azul'], ['#27ae60', 'verde'], ['#e67e22', 'laranja'], ['#c0392b', 'vermelho'], ['#8e44ad', 'roxo']];
  const FREQS = ['semanal', 'mensal', 'trimestral'];

  let raiz = null;
  let ctx = null;
  let M = null; // estado do servidor
  let aberta = null; // UF com as cidades abertas
  let todos = false; // 27 estados
  let cidadesTodas = false;
  let busca = '';
  let menu = null; // { u, nome, x, y }
  let ocupado = false;
  let perguntouFase = false;
  const municipios = {}; // UF => lista de nomes (ou 'carregando' / 'falhou')

  const fetchJson = (url, opcoes) => fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
    .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('erro_salvar'))))));

  const postar = corpo => {
    if (ocupado) return Promise.resolve();
    ocupado = true;
    return fetchJson(ctx.url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': ctx.token }, body: JSON.stringify(corpo) })
      .then(d => { M = d; })
      .catch(e => { window.alert(e.message || T('erro_salvar')); })
      .finally(() => { ocupado = false; desenhar(); enviarDestaques(); });
  };

  const frame = () => (raiz ? raiz.querySelector('iframe[data-mt-mapa]') : null);
  const aoMapa = msg => { const fr = frame(); if (fr && fr.contentWindow) fr.contentWindow.postMessage(msg, location.origin); };
  const enviarDestaques = () => { if (M) aoMapa({ bjDest: M.destaques || {} }); };

  // ---------- Cálculos (L1086-1100) ----------
  const usuariosPorUf = () => ctx.usuarios.A || {};
  const totalUsuarios = () => Object.values(usuariosPorUf()).reduce((a, b) => a + (+b || 0), 0);
  const advDe = u => +((M.advogadosPorUf || {})[u]) || 0;

  const linhasDeUf = () => Object.keys(UFN).map(u => {
    const adv = advDe(u), n = +usuariosPorUf()[u] || 0;
    return { u, nome: UFN[u], adv, n, pen: adv ? n / adv * 100 : 0 };
  }).sort((a, b) => b.n - a.n || b.adv - a.adv);

  const metaDaUf = x => +((M.metasPorUf || {})[x.u]) || Math.max(1, Math.round(M.alvo * x.adv / (M.mercado || 1))) || 1;

  function cidadesDaUf(x) {
    const CM = (M.metasPorCidade || {})[x.u] || {};
    const CI = Object.assign({}, (ctx.usuarios.C || {})[x.u] || {});
    Object.keys(CM).forEach(k => { if (!(k in CI)) CI[k] = 0; });
    let lista = municipios[x.u];
    if (x.u === 'DF') lista = DF_REGIOES;
    if (Array.isArray(lista)) lista.forEach(nm => { if (!(nm in CI)) CI[nm] = 0; });
    if (x.u === 'DF' && CI['Brasília'] != null) { CI['Brasília (Plano Piloto)'] = (CI['Brasília (Plano Piloto)'] || 0) + CI['Brasília']; delete CI['Brasília']; }
    return { CI, CM, carregada: Array.isArray(lista) };
  }

  const pctUf = f => (f < 0.1 ? '<' + dec1(0.1).replace('.0', '') + '%' : dec1(f).replace(/,0$/, f < 10 ? ',0' : '') + '%');

  // ---------- Marcação ----------
  function chips() {
    const m = M.mercado;
    const ops = [[1000, T('fase1')], [10000, T('fase2')], [Math.round(m * .001), '0,1%'], [Math.round(m * .005), '0,5%'], [Math.round(m * .01), '1%'], [Math.round(m * .05), '5%'], [Math.round(m * .1), '10%']];
    return ops.map(([v, r]) => '<button type="button" class="mmd-chip' + (M.alvo === v ? ' on' : '') + '" data-mmd-alvo="' + v + '" title="' + esc(T('chip_dica', { valor: fN(v) })) + '">' + esc(T('chip', { rotulo: r, valor: fN(v) })) + '</button>').join('');
  }

  function barraAuto() {
    const ult = M.ultimaAutomatica ? new Date(M.ultimaAutomatica) : null;
    const info = ult ? T('ultima', { ultima: window.BJFormato.data(ult), proxima: window.BJFormato.data(new Date(M.proximaAutomatica)) }) : T('nunca');
    return '<div class="mmd-auto">'
      + '<button type="button" class="mmd-tg" data-mmd-auto role="switch" aria-checked="' + (M.automatica ? 'true' : 'false') + '"><span class="mmd-trilho' + (M.automatica ? ' on' : '') + '"><span class="mmd-bola"></span></span>' + esc(T('auto')) + '</button>'
      + '<select class="mmd-freq" data-mmd-freq aria-label="' + esc(T('auto')) + '">' + FREQS.map(q => '<option value="' + q + '"' + (M.frequencia === q ? ' selected' : '') + '>' + esc(T('freq.' + q)) + '</option>').join('') + '</select>'
      + '<span class="mmd-info">' + esc(info) + '</span>'
      + '<button type="button" class="mmd-lnk" data-mmd-agora><i class="bi bi-arrow-repeat" aria-hidden="true"></i> ' + esc(T('agora')) + '</button>'
      + '<span class="mmd-sep"></span>'
      + '<button type="button" class="mmd-tg mmd-fase" data-mmd-fase role="switch" aria-checked="' + (M.avancarDeFase ? 'true' : 'false') + '"><i class="bi ' + (M.avancarDeFase ? 'bi-check-square-fill' : 'bi-square') + '" aria-hidden="true"></i>' + esc(T('fase')) + '</button>'
      + (M.mercadoManual ? '<button type="button" class="mmd-manual" data-mmd-voltar>' + esc(T('manual')) + '</button>' : '')
      + '</div>';
  }

  function progresso() {
    const usu = totalUsuarios(), pct = Math.min(100, usu / M.alvo * 100);
    const estados = linhasDeUf().filter(x => x.n > 0).length;
    return '<div><div class="mmd-prog-topo"><b>' + fN(usu) + '</b><span>' + esc(T('usuarios_de', { alvo: fN(M.alvo), pct: dec1(pct) + '%', faltam: fN(Math.max(0, M.alvo - usu)) })) + '</span></div>'
      + '<div class="mmd-barra"><div style="width:' + pct + '%"></div></div><div class="mmd-prog-nota">' + esc(T('estados', { n: estados })) + '</div></div>';
  }

  function linhaDeUf(x) {
    const { CI } = cidadesDaUf(x);
    const meta = metaDaUf(x), soma = Math.max(x.n, Object.values(CI).reduce((a, v) => a + (+v || 0), 0)), alc = soma;
    const dest = (M.destaques || {})[x.u], ab = aberta === x.u;
    const estilo = dest ? ' style="background:' + esc(dest) + '33;box-shadow:inset 3px 0 0 ' + esc(dest) + '"' : '';
    const corLinha = alc >= meta ? '#1e7b45' : alc ? '#0a6fa0' : '#7a8e9b';
    return '<div class="mmd-uf' + (ab ? ' ab' : '') + '" data-mmd-uf="' + x.u + '" title="' + esc(T('linha_dica')) + '"' + estilo + '>'
      + '<b style="color:' + (x.n ? '#0a6fa0' : '#9aabb7') + '">' + x.u + '</b>'
      + '<span class="mmd-uf-nome"><i class="bi ' + (ab ? 'bi-chevron-down' : 'bi-chevron-right') + '" aria-hidden="true"></i>' + esc(x.nome) + '</span>'
      + '<span class="mmd-uf-meta"><button type="button" data-mmd-meta-uf="' + x.u + '" title="' + esc(T('meta_uf_dica')) + '">' + esc(T('meta_n', { n: fN(meta) })) + '</button><span style="color:' + corLinha + '">' + esc(T('alcancado', { pct: dec1(alc / meta * 100) + '%' })) + '</span></span>'
      + '<b class="dir">' + fN(x.n) + '</b><span class="dir cinza">' + (x.adv ? fN(x.adv) : '-') + '</span><span class="dir">' + (x.adv ? x.pen.toFixed(2).replace('.', ',') + '%' : '-') + '</span></div>'
      + (ab ? painelDeCidades(x, meta) : '');
  }

  function painelDeCidades(x, metaUF) {
    const { CI, CM, carregada } = cidadesDaUf(x);
    const qC = busca.toLowerCase();
    const nCid = Math.max(1, Object.keys(CI).length), fixas = Object.values(CM).reduce((a, v) => a + (+v || 0), 0);
    const sug = Math.max(1, Math.round(Math.max(0, metaUF - fixas) / Math.max(1, nCid - Object.keys(CM).length)));
    const linhas = Object.entries(CI).map(([nm, n]) => {
      const fixa = +CM[nm] || 0, meta = fixa || sug, p = Math.min(100, n / meta * 100), fUF = n / metaUF * 100;
      return { nm, n, fixa, meta, p, fUF };
    }).filter(k => (qC ? k.nm.toLowerCase().includes(qC) : (cidadesTodas || k.n > 0 || k.fixa)))
      .sort((a, b) => b.n - a.n || a.nm.localeCompare(b.nm, 'pt'));
    const total = Object.keys(CI).length, visiveis = linhas.slice(0, cidadesTodas ? 9999 : 15);
    const carregando = !carregada && x.u !== 'DF' && municipios[x.u] !== 'falhou';
    return '<div class="mmd-cid" data-mmd-cid="' + x.u + '">'
      + '<div class="mmd-cid-cab"><span>' + esc(T('cid_cab.cidade')) + '</span><span class="dir">' + esc(T('cid_cab.bluejus')) + '</span><span>' + esc(T('cid_cab.pct_meta')) + '</span><span class="dir">' + esc(T('cid_cab.meta')) + '</span><span>' + esc(T('cid_cab.progresso')) + '</span></div>'
      + '<div class="mmd-cid-busca"><label><i class="bi bi-search" aria-hidden="true"></i><input type="text" value="' + esc(busca) + '" placeholder="' + esc(T('buscar')) + '" data-mmd-busca></label><span>' + esc(T('cid_total', { n: total })) + '</span></div>'
      + (carregando ? '<div class="mmd-cid-aviso"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> ' + esc(T('carregando')) + '</div>' : '')
      + (carregada && !qC && !cidadesTodas && !visiveis.length ? '<div class="mmd-cid-aviso">' + esc(T('sem_conta')) + '</div>' : '')
      + visiveis.map(k => '<div class="mmd-cid-lin" data-mmd-nome="' + esc(semAcento(k.nm)) + '">'
        + '<span class="mmd-cid-nome">' + esc(k.nm) + '</span><b class="dir">' + fN(k.n) + '</b>'
        + '<span class="mmd-mini"><span class="mmd-mini-barra"><span style="width:' + Math.min(100, k.fUF) + '%;background:#5b3f9e"></span></span><span class="mmd-mini-txt" style="width:38px">' + (k.n ? esc(pctUf(k.fUF)) : '-') + '</span></span>'
        + '<button type="button" class="mmd-cid-meta' + (k.fixa ? ' fixa' : '') + '" data-mmd-meta-cid="' + esc(k.nm) + '" title="' + esc(k.fixa ? T('meta_fixa_dica') : T('meta_sugerida_dica')) + '">' + fN(k.meta) + '</button>'
        + '<span class="mmd-mini"><span class="mmd-mini-barra"><span style="width:' + k.p + '%;background:' + (k.n >= k.meta ? '#1e7b45' : '#0a6fa0') + '"></span></span><span class="mmd-mini-txt" style="width:30px">' + k.p.toFixed(0) + '%</span></span></div>').join('')
      + (!cidadesTodas && !qC ? '<button type="button" class="mmd-lnk mmd-cid-mais" data-mmd-cid-todas>' + esc(T('todas_cidades', { n: fN(total) })) + '</button>' : '')
      + '<button type="button" class="mmd-add" data-mmd-add-cid="' + x.u + '"><i class="bi bi-plus-lg" aria-hidden="true"></i> ' + esc(T('outra')) + '</button></div>';
  }

  function tabela() {
    const ufs = linhasDeUf().slice(0, todos ? 27 : 10);
    return '<div><div class="mmd-uf-cab"><span>' + esc(T('cab.uf')) + '</span><span>' + esc(T('cab.estado')) + '</span><span>' + esc(T('cab.meta_estado')) + '</span><span class="dir">' + esc(T('cab.bluejus')) + '</span><span class="dir">' + esc(T('cab.oab')) + '</span><span class="dir">' + esc(T('cab.alcance')) + '</span></div>'
      + ufs.map(linhaDeUf).join('')
      + '<button type="button" class="mmd-lnk mmd-mais" data-mmd-mais>' + esc(todos ? T('mais_10') : T('mais_27')) + '</button>'
      + '<div class="mmd-nota">' + esc(T('nota')) + '</div></div>';
  }

  function menuDeContexto() {
    if (!menu) return '';
    const cur = (M.destaques || {})[menu.u] || '';
    return '<div class="mmd-menu-fundo" data-mmd-menu-fechar></div><div class="mmd-menu" style="left:' + menu.x + 'px;top:' + menu.y + 'px">'
      + '<div class="mmd-menu-tit">' + esc(T('destacar')) + ' · <b>' + esc(menu.nome) + '</b></div>'
      + CORES.map(([k, nome]) => '<button type="button" class="mmd-menu-it" data-mmd-cor="' + k + '"><span class="mmd-menu-cor" style="background:' + k + '"></span><span>' + esc(T('cor.' + nome)) + '</span><i class="bi ' + (cur === k ? 'bi-check2' : '') + '" aria-hidden="true"></i></button>').join('')
      + '<div class="mmd-menu-sep"></div>'
      + '<button type="button" class="mmd-menu-it" data-mmd-ver-cidades><i class="bi bi-zoom-in" aria-hidden="true"></i>' + esc(T('ver_cidades')) + '</button>'
      + (cur ? '<button type="button" class="mmd-menu-it perigo" data-mmd-cor=""><i class="bi bi-x-circle" aria-hidden="true"></i>' + esc(T('remover')) + '</button>' : '')
      + '</div>';
  }

  // O esqueleto (com o iframe do mapa) nasce uma vez: mexer no iframe no DOM recarrega o mapa. Só as regiões de texto
  // se redesenham.
  function esqueleto() {
    raiz.innerHTML = '<div data-mmd-cima></div><div class="mmd-grade"><div class="mmd-col" data-mmd-col></div>'
      + '<iframe data-mt-mapa="1" src="' + esc(ctx.urlMapa) + '" title="' + esc(T('mapa_titulo')) + '"></iframe></div>'
      + '<div data-mmd-menu></div>';
  }

  function desenhar() {
    if (!raiz || !M) return;
    if (!raiz.querySelector('[data-mmd-cima]')) esqueleto();
    const foco = document.activeElement && document.activeElement.matches && document.activeElement.matches('[data-mmd-busca]') ? document.activeElement.selectionStart : null;
    raiz.querySelector('[data-mmd-cima]').innerHTML = '<div class="mmd-topo"><i class="bi bi-bullseye" aria-hidden="true"></i><b>' + esc(T('titulo')) + '</b>'
      + '<span class="mmd-fonte">' + esc(T('mercado_pre')) + ' <b>' + fN(M.mercado) + '</b> ' + esc(T('mercado_pos')) + ' · ' + esc(M.fonte) + ' · ' + esc(String(M.referencia).split('-').reverse().join('/')) + '</span>'
      + '<a href="' + esc(M.urlDoQuadro) + '" target="_blank" rel="noopener">' + esc(T('quadro')) + '</a>'
      + '<button type="button" class="mmd-ajustar" data-mmd-ajustar><i class="bi bi-pencil" aria-hidden="true"></i> ' + esc(T('ajustar')) + '</button></div>'
      + barraAuto();
    raiz.querySelector('[data-mmd-col]').innerHTML = '<div class="mmd-chips">' + chips() + '</div>' + progresso() + '<div data-mmd-tabela>' + tabela() + '</div>';
    raiz.querySelector('[data-mmd-menu]').innerHTML = menuDeContexto();
    if (foco !== null) { const c = raiz.querySelector('[data-mmd-busca]'); if (c) { c.focus(); c.setSelectionRange(foco, foco); } }
    sugerirFase();
  }

  // Redesenha só a tabela (busca de cidade, abrir e fechar estado), sem recarregar o mapa nem tirar o foco da busca.
  function desenharTabela() {
    const el = raiz.querySelector('[data-mmd-tabela]');
    if (!el) return desenhar();
    const foco = document.activeElement && document.activeElement.matches && document.activeElement.matches('[data-mmd-busca]') ? document.activeElement.selectionStart : null;
    el.innerHTML = tabela();
    if (foco !== null) { const c = el.querySelector('[data-mmd-busca]'); if (c) { c.focus(); c.setSelectionRange(foco, foco); } }
  }

  // ---------- Avanço de fase (L1089) ----------
  function sugerirFase() {
    const usu = totalUsuarios();
    if (!M.avancarDeFase || usu < M.alvo || perguntouFase) return;
    perguntouFase = true;
    const prox = [1000, 10000, Math.round(M.mercado * .01), Math.round(M.mercado * .05), Math.round(M.mercado * .1)].find(v => v > M.alvo);
    if (!prox) return;
    setTimeout(() => { if (window.confirm(T('confirma_fase', { alvo: fN(M.alvo), proxima: fN(prox) }))) postar({ acao: 'alvo', valor: prox }); }, 300);
  }

  // ---------- Municípios do IBGE ----------
  function carregarMunicipios(uf) {
    if (municipios[uf] || uf === 'DF') return;
    municipios[uf] = 'carregando';
    fetchJson(ctx.urlPopulacao.replace(/XX$/, uf))
      .then(j => { municipios[uf] = Object.values(j.nomes || {}); })
      .catch(() => { municipios[uf] = 'falhou'; })
      .finally(() => { if (aberta === uf) desenharTabela(); });
  }

  function abrirUf(uf) {
    aberta = aberta === uf ? null : uf;
    busca = ''; cidadesTodas = false;
    aoMapa({ bjAbrir: aberta || '' });
    if (aberta) carregarMunicipios(aberta);
    desenharTabela();
  }

  // ---------- Entradas (prompt, como no desenho) ----------
  const soDigitos = v => +String(v).replace(/\D/g, '');

  function ajustar() {
    const m = window.prompt(T('p_mercado'), String(M.mercado));
    if (m === null) return;
    const a = window.prompt(T('p_alvo'), String(M.alvo));
    if (a === null) return;
    const nm = soDigitos(m), na = soDigitos(a);
    if (!(nm > 0) || !(na > 0)) { window.alert(T('p_invalido')); return; }
    postar({ acao: 'ajustar', mercado: nm, valor: na });
  }

  function metaDaUfDigitada(uf) {
    const atual = (M.metasPorUf || {})[uf];
    const v = window.prompt(T('p_meta_uf', { estado: UFN[uf] }), atual ? String(atual) : '');
    if (v === null) return;
    const n = soDigitos(v);
    postar({ acao: 'meta_uf', uf, valor: n > 0 ? n : null });
  }

  function metaDaCidadeDigitada(uf, cidade, sugerida) {
    const v = window.prompt(T('p_meta_cid', { cidade, uf }), sugerida ? String(sugerida) : '');
    if (v === null) return;
    const n = soDigitos(v);
    postar({ acao: 'meta_cidade', uf, cidade, valor: n > 0 ? n : null });
  }

  function outraCidade(uf) {
    const nm = (window.prompt(T('p_cidade', { estado: UFN[uf] }), '') || '').trim();
    if (!nm) return;
    const v = soDigitos(window.prompt(T('p_meta_nova', { cidade: nm }), '50') || '');
    if (!(v > 0)) return;
    aberta = uf;
    postar({ acao: 'meta_cidade', uf, cidade: nm, valor: v });
  }

  // ---------- Eventos ----------
  function aoClicar(e) {
    const t = e.target;
    const c = sel => t.closest(sel);
    // Com o menu aberto, o clique fora só fecha o menu (desenho L614).
    if (menu && !c('.mmd-menu')) { menu = null; desenhar(); return; }
    let el;
    if ((el = c('[data-mmd-alvo]'))) return void postar({ acao: 'alvo', valor: +el.dataset.mmdAlvo });
    if (c('[data-mmd-ajustar]')) return ajustar();
    if (c('[data-mmd-auto]')) return void postar({ acao: 'automatica', ligado: !M.automatica });
    if (c('[data-mmd-fase]')) return void postar({ acao: 'fase', ligado: !M.avancarDeFase });
    if (c('[data-mmd-agora]')) { perguntouFase = false; return void postar({ acao: 'atualizar_agora' }); }
    if (c('[data-mmd-voltar]')) return void postar({ acao: 'voltar_automatico' });
    if ((el = c('[data-mmd-meta-uf]'))) { e.stopPropagation(); return metaDaUfDigitada(el.dataset.mmdMetaUf); }
    if ((el = c('[data-mmd-meta-cid]'))) {
      return metaDaCidadeDigitada(aberta, el.dataset.mmdMetaCid, el.classList.contains('fixa') ? soDigitos(el.textContent) : 0);
    }
    if ((el = c('[data-mmd-add-cid]'))) return outraCidade(el.dataset.mmdAddCid);
    if (c('[data-mmd-cid-todas]')) { cidadesTodas = true; return desenharTabela(); }
    if (c('[data-mmd-mais]')) { todos = !todos; return desenharTabela(); }
    if ((el = c('[data-mmd-cor]'))) { const u = menu.u; menu = null; return void postar({ acao: 'destaque', uf: u, texto: el.dataset.mmdCor || null }); }
    if (c('[data-mmd-ver-cidades]')) { const u = menu.u; menu = null; aberta = null; desenhar(); return abrirUf(u); }
    if (c('[data-mmd-busca]')) return;
    if ((el = c('[data-mmd-uf]'))) return abrirUf(el.dataset.mmdUf);
  }

  function aoMenuDeContexto(e) {
    const el = e.target.closest('[data-mmd-uf]');
    if (!el) return;
    e.preventDefault(); e.stopPropagation();
    const u = el.dataset.mmdUf;
    menu = { u, nome: UFN[u], x: Math.min(e.clientX, innerWidth - 230), y: Math.min(e.clientY, innerHeight - 260) };
    desenhar();
  }

  function aoPassarMouse(e) {
    const el = e.target.closest && e.target.closest('[data-mmd-uf]');
    if (el && !el.contains(e.relatedTarget)) aoMapa({ bjHl: el.dataset.mmdUf });
    const lin = e.target.closest && e.target.closest('.mmd-cid-lin');
    if (lin && !lin.contains(e.relatedTarget)) aoMapa({ bjHlMun: lin.querySelector('.mmd-cid-nome').textContent });
  }

  function aoSairMouse(e) {
    const el = e.target.closest && e.target.closest('[data-mmd-uf]');
    if (el && !el.contains(e.relatedTarget)) aoMapa({ bjHl: '' });
    const lin = e.target.closest && e.target.closest('.mmd-cid-lin');
    if (lin && !lin.contains(e.relatedTarget)) aoMapa({ bjHlMun: '' });
  }

  // O mapa avisa a UF ou a cidade sob o mouse: a linha correspondente ganha a classe de destaque (L1098, mhUF/mhOn).
  function aoReceber(ev) {
    const fr = frame();
    if (!fr || ev.source !== fr.contentWindow || ev.origin !== location.origin || !ev.data || typeof ev.data !== 'object') return;
    if ('bjMapaPronto' in ev.data) return enviarDestaques();
    if (!('bjMapaHover' in ev.data)) return;
    raiz.querySelectorAll('.mmd-mh').forEach(l => l.classList.remove('mmd-mh'));
    const h = String(ev.data.bjMapaHover || '');
    if (h.startsWith('uf:')) { const l = raiz.querySelector('[data-mmd-uf="' + h.slice(3) + '"]'); if (l) l.classList.add('mmd-mh'); }
    if (h.startsWith('mun:')) { const alvo = semAcento(h.slice(4)); raiz.querySelectorAll('.mmd-cid-lin').forEach(l => { if (l.dataset.mmdNome === alvo) l.classList.add('mmd-mh'); }); }
  }

  let ligado = false;
  function ligar() {
    if (ligado) return;
    ligado = true;
    raiz.addEventListener('click', aoClicar);
    raiz.addEventListener('contextmenu', aoMenuDeContexto);
    raiz.addEventListener('mouseover', aoPassarMouse);
    raiz.addEventListener('mouseout', aoSairMouse);
    raiz.addEventListener('change', e => { if (e.target.matches('[data-mmd-freq]')) postar({ acao: 'frequencia', texto: e.target.value }); });
    raiz.addEventListener('input', e => { if (e.target.matches('[data-mmd-busca]')) { busca = e.target.value; cidadesTodas = false; desenharTabela(); } });
  }

  window.addEventListener('message', aoReceber);

  window.BJMetasDivulgacao = {
    // ctx: { url, token, urlMapa, urlPopulacao (termina em XX), usuarios: { A: {UF: n}, C: {UF: {cidade: n}} } }
    montar(el, contexto) {
      raiz = el; ctx = contexto; ligado = false; perguntouFase = false;
      el.classList.add('mmd');
      ligar();
      el.setAttribute('aria-busy', 'true');
      return fetchJson(ctx.url)
        .then(d => { M = d; el.removeAttribute('aria-busy'); desenhar(); })
        .catch(() => { el.removeAttribute('aria-busy'); el.textContent = T('erro_carregar'); });
    },
  };
})();
