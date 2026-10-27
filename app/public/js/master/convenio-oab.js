/* BlueJus · Painel do Master, painel recolhível "Convênio OAB por estado" da seção Contas
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L175-178 e lógica L1062-1066;
   spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P4b).
   - O painel.js chama BJConvenioOab.montar(raiz, ctx) ao montar a seção Contas: ctx traz a URL, o token e o `aoMudar`,
     chamado com os 27 estados depois de cada gravação (o painel atualiza o rótulo das contas).
   - O estado vem do servidor (GET) e cada mudança é um POST JSON com X-CSRF-Token; a resposta traz o estado novo. As
     regras (UF, modo, valor, data) são do servidor.
   - Escolha por prompt(), como no desenho: 1 grátis, 2 valor diferenciado, 3 sem convênio.
   - Textos no catálogo (master.js.convenio.*), lidos por BJ_T.t na hora de usar. Aviso por e-mail aos primários e
     receita não fazem parte desta entrega. */
(() => {
  'use strict';
  const PREFIXO = 'master.js.convenio.';
  const T = (chave, params) => window.BJ_T.t(PREFIXO + chave, params);
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ESC[m]);
  const UFN = { AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal', ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso', MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná', PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima', SC: 'Santa Catarina', SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins' };
  const dinheiro = v => (+v || 0).toLocaleString(window.BJFormato.idioma(), { style: 'currency', currency: 'BRL' });
  const dataBr = d => (d ? String(d).split('-').reverse().join('/') : '');
  const hoje = () => new Date().toISOString().slice(0, 10);
  const COR = { livre: '#1e7b45', dif: '#0a6fa0', sem: '#a3232b' };
  const BORDA = { livre: '#e1e8ed', dif: '#bcd6e6', sem: '#f1c4c9' };
  const FUNDO = { livre: '#fff', dif: '#f2f8fc', sem: '#fdf3f4' };
  const ICONE = { livre: 'bi-check-circle-fill', dif: 'bi-tag-fill', sem: 'bi-x-circle' };

  let raiz = null;
  let ctx = null;
  let E = null; // estados do servidor
  let aberto = false;
  let ocupado = false;

  const fetchJson = (url, opcoes) => fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
    .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('erro_salvar'))))));

  function postar(corpo) {
    if (ocupado) return;
    ocupado = true;
    fetchJson(ctx.url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': ctx.token }, body: JSON.stringify(corpo) })
      .then(d => { E = d.estados; if (ctx.aoMudar) ctx.aoMudar(E); })
      .catch(e => { window.alert(e.message || T('erro_salvar')); })
      .finally(() => { ocupado = false; desenhar(); });
  }

  function resumo() {
    const dif = E.filter(x => x.modo === 'dif').map(x => x.uf), sem = E.filter(x => x.modo === 'sem').map(x => x.uf);
    if (!dif.length && !sem.length) return { texto: T('resumo_todos'), cor: COR.livre };
    const partes = [T('resumo_gratis', { n: 27 - dif.length - sem.length })];
    if (dif.length) partes.push(T('resumo_dif', { n: dif.length, ufs: dif.join(', ') }));
    if (sem.length) partes.push(T('resumo_sem', { n: sem.length, ufs: sem.join(', ') }));
    return { texto: partes.join(' · '), cor: sem.length ? COR.sem : COR.dif };
  }

  function status(x) {
    const desde = x.desde ? T('desde', { data: dataBr(x.desde) }) : '';
    if (x.modo === 'sem') return T('st_sem', { valor: dinheiro(x.valor) }) + desde;
    if (x.modo === 'dif') return T('st_dif', { valor: dinheiro(x.valor) }) + desde;
    return T('st_livre');
  }

  function botaoDaUf(x) {
    return '<button type="button" class="mco-uf" data-mco-uf="' + x.uf + '" style="border-color:' + BORDA[x.modo] + ';background:' + FUNDO[x.modo] + '">'
      + '<b>' + x.uf + '</b><span class="mco-uf-txt"><span class="mco-uf-nome">' + esc(UFN[x.uf]) + ' <span class="mco-mudo">· ' + x.contas + '</span></span>'
      + '<span class="mco-uf-st" style="color:' + COR[x.modo] + '">' + esc(status(x)) + '</span></span>'
      + '<i class="bi ' + ICONE[x.modo] + '" style="color:' + COR[x.modo] + '" aria-hidden="true"></i></button>';
  }

  function desenhar() {
    if (!raiz || !E) return;
    const r = resumo();
    raiz.innerHTML = '<button type="button" class="mco-topo" data-mco-tg aria-expanded="' + (aberto ? 'true' : 'false') + '"><i class="bi bi-geo-alt" aria-hidden="true"></i><b>' + esc(T('titulo')) + '</b>'
      + '<span class="mco-resumo" style="color:' + r.cor + '">' + esc(r.texto) + '</span><i class="bi ' + (aberto ? 'bi-chevron-up' : 'bi-chevron-down') + ' mco-seta" aria-hidden="true"></i></button>'
      + (aberto ? '<div class="mco-corpo"><div class="mco-ajuda">' + esc(T('ajuda')) + '</div><div class="mco-grade">' + E.map(botaoDaUf).join('') + '</div></div>' : '');
  }

  // ---------- Entradas (prompt, como no desenho) ----------
  const numero = v => { const n = +String(v).replace(/\./g, '').replace(',', '.'); return n >= 0 ? n : null; };

  function pedirValorEData(perguntaValor, padrao) {
    const v = window.prompt(perguntaValor, padrao);
    if (v === null) return null;
    if (numero(v) === null) { window.alert(T('valor_invalido')); return null; }
    const d = window.prompt(T('p_desde'), hoje()) || hoje();
    return { valor: v, desde: d };
  }

  function escolher(uf) {
    const x = E.find(e => e.uf === uf);
    const padrao = x.modo === 'livre' ? '2' : '1';
    const op = window.prompt(T('p_menu', { uf, estado: UFN[uf], contas: x.contas }), padrao);
    if (op === null) return;
    if (op.trim() === '1') { if (x.modo !== 'livre') postar({ uf, modo: 'livre' }); return; }
    if (op.trim() === '2') {
      const r = pedirValorEData(T('p_valor_dif', { uf }), x.modo === 'dif' ? String(x.valor).replace('.', ',') : '9,90');
      if (r) postar({ uf, modo: 'dif', valor: r.valor, desde: r.desde });
      return;
    }
    if (op.trim() !== '3') return;
    const r = pedirValorEData(T('p_valor_sem', { uf, estado: UFN[uf] }), '29,90');
    if (!r) return;
    const motivo = window.prompt(T('p_motivo'), T('motivo_padrao')) || '';
    postar({ uf, modo: 'sem', valor: r.valor, desde: r.desde, motivo });
  }

  function aoClicar(e) {
    if (e.target.closest('[data-mco-tg]')) { aberto = !aberto; return desenhar(); }
    const b = e.target.closest('[data-mco-uf]');
    if (b) escolher(b.dataset.mcoUf);
  }

  window.BJConvenioOab = {
    // ctx: { url, token, aoMudar(estados) }
    montar(el, contexto) {
      raiz = el; ctx = contexto;
      el.classList.add('mco');
      el.addEventListener('click', aoClicar);
      return fetchJson(ctx.url)
        .then(d => { E = d.estados; desenhar(); if (ctx.aoMudar) ctx.aoMudar(E); })
        .catch(() => { el.textContent = T('erro_carregar'); });
    },
  };
})();
