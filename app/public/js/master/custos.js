/* BlueJus · Painel do Master, seção "Custos" (#sec=custos)
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L260-270 e lógica L917-937;
   spec docs/specs/master-custos.md, PR 2: leitura).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash; trocar o mês pede
     os dados de novo ao servidor. Os valores chegam em centavos e as contas (receita, margem, projeção) vêm prontas.
   - Texto do fornecedor e da observação é digitado pelo Master: tudo entra por escape (esc), nunca como HTML.
   - Textos no catálogo (master.custos.js.*, entregue pelo parcial com catalogo_js), lidos na hora de usar;
     números por window.BJFormato. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-cst]');
  if (!raiz) return;

  const URL_DADOS = raiz.dataset.urlDados;
  const $ = sel => raiz.querySelector(sel);
  const elAviso = $('[data-cst-aviso]');
  const elKpis = $('[data-cst-kpis]');
  const elMes = $('[data-cst-mes]');
  // Aspas pelo código (\x22 e \x27): um literal de aspas aqui desalinha o detector de texto fixo (SemTextoFixoNosExtraidos).
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const F = () => window.BJFormato;
  const NEGATIVO = 'cst-tom-critico';
  let carregado = false;
  let pedido = 0;

  const reais = centavos => T('master.custos.js.moeda', { valor: F().numero(centavos / 100, 2) });
  const tomDaMargem = centavos => (centavos < 0 ? NEGATIVO : 'cst-tom-ok');

  function avisar(texto) {
    elAviso.textContent = texto || '';
    elAviso.hidden = !texto;
  }

  function cartao(rotulo, valor, detalhe, tom) {
    return '<div class="cst-kpi"><span class="cst-kpi-rotulo">' + esc(rotulo) + '</span>'
      + '<b class="cst-kpi-valor ' + (tom || '') + '">' + esc(valor) + '</b>'
      + '<span class="cst-kpi-detalhe">' + esc(detalhe) + '</span></div>';
  }

  function desenharKpis(k) {
    const gb = F().numero(k.gbArmazenados, 1);
    elKpis.innerHTML = [
      cartao(T('master.custos.js.kpi.custo'), reais(k.custoCentavos), T('master.custos.js.lancamentos_n', { n: F().numero(k.lancamentos) })),
      cartao(T('master.custos.js.kpi.receita'), reais(k.receitaCentavos), T('master.custos.js.receita_desc')),
      cartao(T('master.custos.js.kpi.margem'), reais(k.margemCentavos),
        k.margemPercentual === null ? T('master.custos.js.sem_receita') : T('master.custos.js.da_receita', { n: F().numero(k.margemPercentual) }), tomDaMargem(k.margemCentavos)),
      cartao(T('master.custos.js.kpi.por_conta'), reais(k.custoPorContaCentavos), T('master.custos.js.contas_usuarios', { contas: F().numero(k.contas), usuario: reais(k.custoPorUsuarioCentavos) })),
      cartao(T('master.custos.js.kpi.por_gb'), k.custoPorGbCentavos === null ? T('master.custos.js.nao_lancado') : reais(k.custoPorGbCentavos), T('master.custos.js.gb_armazenados', { n: gb })),
    ].join('');
    elKpis.removeAttribute('aria-busy');
  }

  function desenharCategorias(lista) {
    $('[data-cst-categorias]').innerHTML = lista.map(c => {
      const lancada = c.lancamentos > 0;
      return '<div class="cst-cat"><span class="cst-cat-nome">' + esc(T('master.custos.js.categoria.' + c.categoria)) + '</span>'
        + '<span class="cst-cat-trilho"><span class="cst-cat-barra" style="width:' + (Number(c.percentual) || 0) + '%"></span></span>'
        + '<b class="cst-cat-valor' + (lancada ? '' : ' cst-nao-lancado') + '">' + esc(lancada ? reais(c.totalCentavos) : T('master.custos.js.nao_lancado')) + '</b></div>';
    }).join('');
  }

  function desenharPlanos(lista) {
    $('[data-cst-planos]').innerHTML = lista.map(p => '<div class="cst-tab cst-tab-planos">'
      + '<span>' + esc(p.nome) + '</span><span>' + esc(F().numero(p.contas)) + '</span><span>' + esc(reais(p.precoCentavos)) + '</span>'
      + '<span>' + esc(p.custoCentavos === null ? T('master.custos.js.sem_valor') : reais(p.custoCentavos)) + '</span>'
      + '<b class="' + (p.margemCentavos === null ? '' : tomDaMargem(p.margemCentavos)) + '">' + esc(p.margemCentavos === null ? T('master.custos.js.sem_valor') : reais(p.margemCentavos)) + '</b></div>').join('');
  }

  function desenharProjecao(lista) {
    $('[data-cst-projecao]').innerHTML = lista.map(p => '<div class="cst-tab cst-tab-proj">'
      + '<span>' + esc(T('master.custos.js.meses', { n: F().numero(p.meses) })) + '</span><span>' + esc(reais(p.custoCentavos)) + '</span><span>' + esc(reais(p.receitaCentavos)) + '</span>'
      + '<b class="' + tomDaMargem(p.margemCentavos) + '">' + esc(reais(p.margemCentavos)) + '</b></div>').join('');
  }

  function desenharLancamentos(lista) {
    $('[data-cst-vazio]').hidden = lista.length > 0;
    $('[data-cst-lancamentos]').innerHTML = lista.map(l => '<div class="cst-lin">'
      + '<b>' + esc(T('master.custos.js.categoria.' + l.categoria)) + '</b>'
      + '<span class="cst-lin-forn">' + esc(l.fornecedor || T('master.custos.js.sem_valor')) + '</span>'
      + '<b>' + esc(reais(l.valorCentavos)) + '</b>'
      + '<span class="cst-lin-origem ' + esc(ORIGEM_TOM[l.origem] || '') + '">' + esc(T('master.custos.js.origem.' + l.origem)) + '</span>'
      + '<span class="cst-lin-obs">' + esc(l.observacao || '') + '</span></div>').join('');
  }

  // Medido verde, importado azul, estimado âmbar (desenho: `ORI`).
  const ORIGEM_TOM = { medido: 'cst-tom-ok', importado: 'cst-tom-azul', estimado: 'cst-tom-ambar' };

  async function carregar() {
    const meu = ++pedido;
    elKpis.setAttribute('aria-busy', 'true');
    try {
      const r = await fetch(URL_DADOS + (elMes.value ? '?mes=' + encodeURIComponent(elMes.value) : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!r.ok) throw new Error(String(r.status));
      const d = await r.json();
      if (meu !== pedido) return;
      avisar('');
      elMes.value = d.mes;
      desenharKpis(d.kpis);
      desenharCategorias(d.categorias);
      desenharPlanos(d.planos);
      desenharProjecao(d.projecao);
      desenharLancamentos(d.lancamentos);
    } catch (e) {
      if (meu === pedido) avisar(T('master.custos.js.erro_carregar'));
    }
  }

  function abrir() {
    carregado = true;
    carregar();
  }

  elMes.addEventListener('change', () => { if (elMes.value) carregar(); });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'custos' && !carregado) abrir(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=custos\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) abrir(); });
    else abrir();
  }
})();
