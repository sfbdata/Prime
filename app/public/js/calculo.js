/* BlueJus · Cálculo de atualização monetária (aba Cálculo).
   Desenho: docs/design/redesign-30-10-26/14 - Calculo - VS 30.10.26.dc.html.
   O cálculo é no SERVIDOR. Aqui só se guarda o formulário (localStorage 'bj-calc'), se envia
   ao POST de cálculo e se desenha a resposta. Nada de innerHTML: só textContent/createElement. */
(function () {
  'use strict';

  var raiz = document.getElementById('calculo');
  if (!raiz) return;

  var T = function (chave, params) { return window.BJ_T.t(chave, params); };
  var CHAVE = 'bj-calc';
  var main = raiz.querySelector('main.calc-main');
  var $ = function (id) { return document.getElementById(id); };

  var state = { f: carregarForm(), nv: { v: '', d: '', t: '' }, edI: null, ord: { k: 'd', dir: 1 }, errs: [], tit: '', aviso: '', res: null, pdfCarr: false, nvErro: '' };

  // ---------- utilitários ----------
  function el(tag, cls, texto) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (texto != null) e.textContent = texto;
    return e;
  }
  function icone(nome) { var i = el('i', 'bi ' + nome); i.setAttribute('aria-hidden', 'true'); return i; }
  function limpar(no) { while (no.firstChild) no.removeChild(no.firstChild); }
  function hoje() {
    try { return new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' }); } catch (e) { return new Date().toISOString().slice(0, 10); }
  }
  function moeda(x) {
    var d = String(x || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 13);
    if (!d) return '';
    return (parseInt(d, 10) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function num(x) {
    var v = parseFloat(String(x || '').replace(/\./g, '').replace(',', '.'));
    return isNaN(v) ? 0 : v;
  }
  function fmt(v) { return v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function dt(d) { return d ? d.split('-').reverse().join('/') : ''; }
  function vazio() {
    return { proc: '', credor: '', devedor: '', ate: hoje(), idx: 'oficial', proj: 'media', jur: 'legal', ini: 'valores', dJur: '', multa: '', hon: '', m523: false, h523: false, vals: [], abats: [] };
  }
  function carregarForm() {
    var f = {};
    try { f = JSON.parse(localStorage.getItem(CHAVE)) || {}; } catch (e) { f = {}; }
    var b = vazio();
    var r = Object.assign(b, f, { ate: f.ate || b.ate });
    if (!Array.isArray(r.vals)) r.vals = [];
    if (!Array.isArray(r.abats)) r.abats = [];
    return r;
  }
  function gravar(f) {
    state.f = f;
    try { localStorage.setItem(CHAVE, JSON.stringify(f)); } catch (e) { /* sem armazenamento: segue na memória */ }
    state.res = null; state.errs = []; state.aviso = '';
    desenharResultado(); desenharErros(); desenharAviso(); desenharPdfBtns();
  }
  function cp(f, mudanca) { return Object.assign({}, f, mudanca); }

  // ---------- campos de dados e acréscimos ----------
  function preencherCampos() {
    raiz.querySelectorAll('[data-f]').forEach(function (c) {
      var k = c.getAttribute('data-f');
      if (c.type === 'checkbox') c.checked = !!state.f[k]; else c.value = state.f[k] == null ? '' : state.f[k];
    });
    desenharDJur();
  }
  function desenharDJur() {
    var lab = $('calcDJurRot');
    var ini = state.f.ini;
    lab.hidden = ini === 'valores';
    lab.querySelector('.calc-djur-txt').textContent = ini === 'fixa' ? lab.getAttribute('data-rot-fixa') : lab.getAttribute('data-rot-citacao');
  }
  raiz.querySelectorAll('[data-f]').forEach(function (c) {
    var k = c.getAttribute('data-f');
    var ev = (c.tagName === 'SELECT' || c.type === 'checkbox') ? 'change' : 'input';
    c.addEventListener(ev, function () {
      var m = {};
      m[k] = c.type === 'checkbox' ? c.checked : c.value;
      gravar(cp(state.f, m));
      if (k === 'ini') desenharDJur();
    });
  });

  // ---------- novo valor ----------
  function desenharNv() {
    var ed = state.edI != null;
    $('calcNvV').value = state.nv.v;
    $('calcNvD').value = state.nv.d;
    $('calcNvT').value = state.nv.t;
    $('calcNv').classList.toggle('calc-nv--edicao', ed);
    var b = $('calcNvSalvar');
    b.querySelector('.calc-btn-txt').textContent = b.getAttribute(ed ? 'data-txt-salvar' : 'data-txt-add');
    b.querySelector('i').className = 'bi ' + (ed ? 'bi-floppy' : 'bi-plus-lg');
    $('calcNvCancelar').hidden = !ed;
    var er = $('calcNvErro');
    er.hidden = !state.nvErro;
    er.textContent = state.nvErro;
  }
  function nvDigitou() { state.nvErro = ''; state.errs = []; desenharErros(); desenharNv0(); }
  function desenharNv0() { var er = $('calcNvErro'); er.hidden = true; er.textContent = ''; }
  $('calcNvV').addEventListener('input', function (e) {
    var v = moeda(e.target.value); e.target.value = v; state.nv.v = v; nvDigitou();
  });
  $('calcNvD').addEventListener('input', function (e) { state.nv.d = e.target.value; nvDigitou(); });
  $('calcNvT').addEventListener('input', function (e) { state.nv.t = e.target.value; });

  function proximoTexto(tx) {
    var m = tx.match(/^(.*?)(\d+)(\s*\/\s*\d+)?\s*$/);
    return m ? m[1] + (parseInt(m[2], 10) + 1) + (m[3] || '') : tx;
  }
  function salvarValor() {
    var ed = state.edI != null;
    var v = moeda(state.nv.v);
    if (!num(v)) { state.nvErro = T('calculo.js.valor_zero'); desenharNv(); return; }
    if (!state.nv.d) { state.nvErro = T('calculo.js.sem_data'); desenharNv(); return; }
    var L = state.f.vals.filter(function (x) { return x.d || x.v; });
    var tx = state.nv.t.trim();
    var item = { v: v, d: state.nv.d, t: tx || T('calculo.js.parcela_padrao') };
    if (ed) L[state.edI] = item; else L.push(item);
    gravar(cp(state.f, { vals: L }));
    state.nvErro = '';
    if (ed) {
      state.edI = null; state.nv = { v: '', d: '', t: '' };
    } else {
      var p = state.nv.d.split('-').map(Number);
      var pm = new Date(p[0], p[1], 1);
      var ult = new Date(pm.getFullYear(), pm.getMonth() + 1, 0).getDate();
      pm.setDate(Math.min(p[2], ult));
      state.nv = { v: v, d: pm.toLocaleDateString('sv-SE'), t: proximoTexto(tx) };
    }
    desenharNv(); desenharVals();
  }
  $('calcNvSalvar').addEventListener('click', salvarValor);
  $('calcNvCancelar').addEventListener('click', function () {
    state.edI = null; state.nv = { v: '', d: '', t: '' }; state.nvErro = ''; desenharNv(); desenharVals();
  });
  ['calcNvV', 'calcNvD', 'calcNvT'].forEach(function (id) {
    $(id).addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); salvarValor(); }
      if (e.key === 'Escape' && state.edI != null) {
        state.edI = null; state.nv = { v: '', d: '', t: '' }; desenharNv(); desenharVals();
      }
    });
  });

  // ---------- gerar parcelas ----------
  $('calcBtnGerarTg').addEventListener('click', function () { $('calcGer').hidden = !$('calcGer').hidden; });
  $('calcGerVal').addEventListener('input', function (e) { e.target.value = moeda(e.target.value); });
  $('calcBtnGerar').addEventListener('click', function () {
    var q = Math.min(360, Math.max(1, parseInt($('calcGerQtd').value, 10) || 0));
    var val = $('calcGerVal').value, ini = $('calcGerIni').value;
    if (!ini || !val) { mostrarErros([{ campo: '', msg: T('calculo.js.ger_faltam') }], T('calculo.aviso.titulo_corrigir')); return; }
    var p = ini.split('-').map(Number);
    var desc = $('calcGerDesc').value || T('calculo.js.parcela_padrao');
    var novas = [];
    for (var i = 0; i < q; i++) {
      var d2 = new Date(p[0], p[1] - 1 + i, 1);
      var ult = new Date(d2.getFullYear(), d2.getMonth() + 1, 0).getDate();
      d2.setDate(Math.min(p[2], ult));
      novas.push({ d: d2.toLocaleDateString('sv-SE'), v: val, t: desc + ' ' + (i + 1) + '/' + q });
    }
    gravar(cp(state.f, { vals: state.f.vals.filter(function (v) { return v.d || v.v; }).concat(novas) }));
    $('calcGer').hidden = true;
    desenharVals();
  });

  // ---------- tabela de valores ----------
  function listaOrdenada() {
    var ord = state.ord;
    var VL = state.f.vals.map(function (v, i) { return Object.assign({ i: i }, v); }).filter(function (v) { return v.d || v.v; });
    VL.sort(function (a, b) {
      var c = ord.k === 'v' ? num(a.v) - num(b.v) : String(a[ord.k] || '').localeCompare(String(b[ord.k] || ''));
      return c * ord.dir;
    });
    return VL;
  }
  function desenharVals() {
    var VL = listaOrdenada();
    var corpo = $('calcValsCorpo');
    limpar(corpo);
    VL.forEach(function (v, j) {
      var valor = moeda(v.v) || v.v;
      var cls = 'calc-val-linha' + (state.edI === v.i ? ' calc-val-edicao' : (j % 2 ? ' calc-par' : ''));
      var lin = el('div', cls);
      lin.appendChild(el('span', 'calc-dir', valor));
      lin.appendChild(el('span', 'calc-dir', dt(v.d)));
      lin.appendChild(el('span', 'calc-val-t', v.t || T('calculo.js.parcela_padrao')));
      var ac = el('span', 'calc-val-acoes');
      var ed = el('button', 'calc-btn-ed'); ed.type = 'button'; ed.title = T('calculo.js.editar'); ed.appendChild(icone('bi-pencil-square'));
      ed.addEventListener('click', function () {
        state.edI = v.i; state.nv = { v: valor, d: v.d, t: v.t || '' }; state.nvErro = ''; desenharNv(); desenharVals();
      });
      var rm = el('button', 'calc-btn-rm'); rm.type = 'button'; rm.title = T('calculo.js.excluir'); rm.appendChild(icone('bi-x-lg'));
      rm.addEventListener('click', function () {
        if (!window.confirm(T('calculo.js.excluir_valor', { valor: valor }))) return;
        var L = state.f.vals.filter(function (_, k) { return k !== v.i; });
        var estavaEditando = state.edI === v.i;
        gravar(cp(state.f, { vals: L }));
        if (estavaEditando) { state.edI = null; state.nv = { v: '', d: '', t: '' }; desenharNv(); }
        desenharVals();
      });
      ac.appendChild(ed); ac.appendChild(rm);
      lin.appendChild(ac);
      corpo.appendChild(lin);
    });
    $('calcValsVazio').hidden = VL.length > 0;
    var soma = $('calcValsSoma');
    soma.hidden = VL.length <= 1;
    if (VL.length > 1) {
      var total = VL.reduce(function (a, v) { return a + num(moeda(v.v) || v.v); }, 0);
      $('calcSomaV').textContent = fmt(total);
      $('calcSomaN').textContent = T('calculo.js.soma', { n: VL.length });
    }
    raiz.querySelectorAll('#calcValsCab .calc-ord').forEach(function (b) {
      var k = b.getAttribute('data-ord');
      b.querySelector('i').className = 'bi ' + (state.ord.k === k ? (state.ord.dir > 0 ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand');
    });
  }
  raiz.querySelectorAll('#calcValsCab .calc-ord').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-ord');
      state.ord = { k: k, dir: state.ord.k === k ? -state.ord.dir : 1 };
      desenharVals();
    });
  });

  // ---------- abatimentos ----------
  function desenharAbats() {
    var corpo = $('calcAbatCorpo');
    limpar(corpo);
    state.f.abats.forEach(function (a, i) {
      var lin = el('div', 'calc-abat-linha');
      function atualizar(c, x) {
        var L = state.f.abats.slice();
        L[i] = Object.assign({}, L[i]); L[i][c] = x;
        gravar(cp(state.f, { abats: L }));
      }
      var d = el('input'); d.type = 'date'; d.value = a.d || '';
      d.addEventListener('input', function () { atualizar('d', d.value); });
      var v = el('input', 'calc-dir'); v.value = a.v || ''; v.setAttribute('inputmode', 'decimal'); v.placeholder = '0,00';
      v.addEventListener('input', function () { v.value = moeda(v.value); atualizar('v', v.value); });
      var t = el('input'); t.value = a.t || ''; t.placeholder = corpo.getAttribute('data-ph');
      t.addEventListener('input', function () { atualizar('t', t.value); });
      var rm = el('button', 'calc-btn-lixo'); rm.type = 'button'; rm.title = T('calculo.js.excluir'); rm.appendChild(icone('bi-trash3'));
      rm.addEventListener('click', function () {
        gravar(cp(state.f, { abats: state.f.abats.filter(function (_, j) { return j !== i; }) }));
        desenharAbats();
      });
      [d, v, t, rm].forEach(function (n) { lin.appendChild(n); });
      corpo.appendChild(lin);
    });
  }
  $('calcBtnAbat').addEventListener('click', function () {
    gravar(cp(state.f, { abats: state.f.abats.concat({ d: '', v: '', t: '' }) }));
    desenharAbats();
  });

  // ---------- erros e aviso ----------
  function destacarCampos() {
    raiz.querySelectorAll('.calc-erro-campo').forEach(function (e) { e.classList.remove('calc-erro-campo'); });
    state.errs.forEach(function (e) {
      if (!e.campo) return;
      var c = raiz.querySelector('[data-campo="' + e.campo + '"]');
      if (c) c.classList.add('calc-erro-campo');
    });
  }
  function irPara(campo) {
    if (campo === 'nvBtn') { salvarValor(); return; }
    var c = raiz.querySelector('[data-campo="' + campo + '"]');
    if (!c) return;
    var r = c.getBoundingClientRect();
    window.scrollTo({ top: window.scrollY + r.top - 120, behavior: 'smooth' });
    setTimeout(function () { if (c.focus) c.focus(); }, 350);
  }
  function mostrarErros(lista, titulo) {
    state.errs = lista; state.tit = titulo; desenharErros();
  }
  function desenharErros() {
    var caixa = $('calcErros');
    limpar(caixa);
    destacarCampos();
    if (!state.errs.length) { caixa.hidden = true; return; }
    caixa.hidden = false;
    var tit = el('b', 'calc-erros-tit');
    tit.appendChild(icone('bi-exclamation-octagon'));
    tit.appendChild(document.createTextNode(state.tit));
    caixa.appendChild(tit);
    state.errs.forEach(function (e) {
      var it = el('div', 'calc-erro-item');
      it.appendChild(el('span', null, e.msg));
      if (e.campo && e.irTxt) {
        var b = el('button', 'calc-erro-ir', e.irTxt); b.type = 'button';
        b.addEventListener('click', function () { irPara(e.campo); });
        it.appendChild(b);
      }
      caixa.appendChild(it);
    });
  }
  function desenharAviso() {
    var c = $('calcAviso');
    limpar(c);
    c.hidden = !state.aviso;
    if (state.aviso) { c.appendChild(icone('bi-graph-up-arrow')); c.appendChild(document.createTextNode(' ' + state.aviso)); }
  }

  // ---------- calcular ----------
  function calcular() {
    var nv = state.nv, f = state.f;
    var temLista = f.vals.some(function (v) { return v.d && num(v.v) > 0; });
    if (state.edI == null && nv.v && nv.d && num(nv.v) > 0) {
      f = cp(f, { vals: f.vals.concat({ v: nv.v, d: nv.d, t: nv.t || '' }) });
      state.nv = { v: '', d: '', t: '' };
      gravar(f); desenharNv(); desenharVals();
    } else if (state.edI == null && (nv.v || nv.d) && !temLista) {
      var e = nv.v
        ? { campo: 'nvD', msg: T('calculo.js.valor_sem_data', { valor: nv.v }), irTxt: T('calculo.js.ir_data') }
        : { campo: 'nvV', msg: T('calculo.js.data_sem_valor'), irTxt: T('calculo.js.ir_valor') };
      state.res = null; desenharResultado(); desenharPdfBtns();
      mostrarErros([e], T('calculo.aviso.titulo_corrigir'));
      return;
    }
    var botao = $('calcBtnCalcular');
    botao.disabled = true;
    fetch(raiz.getAttribute('data-url-calcular'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': raiz.getAttribute('data-token'), 'Accept': 'application/json' },
      body: JSON.stringify(state.f)
    }).then(function (r) { return r.json().catch(function () { return null; }); }).then(function (d) {
      botao.disabled = false;
      if (!d) { falhaCalculo(); return; }
      if (d.ok) {
        state.res = d;
        state.aviso = d.aviso || '';
        state.errs = d.atencao ? [{ campo: '', msg: d.atencao }] : [];
        state.tit = T('calculo.aviso.titulo_atencao');
        desenharResultado(); desenharAviso(); desenharErros(); desenharPdfBtns();
      } else if (Array.isArray(d.erros)) {
        state.res = null; state.aviso = '';
        desenharResultado(); desenharAviso(); desenharPdfBtns();
        mostrarErros(d.erros, T('calculo.aviso.titulo_corrigir'));
        var p = d.erros.find(function (x) { return x.campo; });
        if (p) irPara(p.campo);
      } else {
        falhaCalculo();
      }
    }).catch(function () { botao.disabled = false; falhaCalculo(); });
  }
  function falhaCalculo() {
    state.res = null; desenharResultado(); desenharPdfBtns();
    mostrarErros([{ campo: '', msg: T('calculo.js.falha_calculo') }], T('calculo.aviso.titulo_corrigir'));
  }
  $('calcBtnCalcular').addEventListener('click', calcular);
  $('calcBtnLimpar').addEventListener('click', function () {
    if (!window.confirm(T('calculo.js.limpar_confirma'))) return;
    try { localStorage.removeItem(CHAVE); } catch (e) { /* ignora */ }
    state.edI = null; state.nv = { v: '', d: '', t: '' }; state.nvErro = '';
    gravar(vazio());
    preencherCampos(); desenharNv(); desenharVals(); desenharAbats();
  });

  // ---------- demonstrativo ----------
  function desenharResultado() {
    var antigo = main.querySelector('section[data-screen-label="Demonstrativo"]');
    if (antigo) antigo.parentNode.removeChild(antigo);
    var d = state.res;
    if (!d) return;
    var sec = el('section', 'calc-sec calc-dem');
    sec.setAttribute('data-screen-label', 'Demonstrativo');
    sec.appendChild(el('div', 'calc-sec-cab', T('calculo.js.dem_titulo', { data: d.ate })));
    var rol = el('div', 'calc-rolagem');
    var corpo = el('div', 'calc-dem-corpo');
    var cab = el('div', 'calc-dem-cab');
    [['dem_col_data', 0], ['dem_col_desc', 0], ['dem_col_valor', 1], ['dem_col_fator', 1], ['dem_col_corrigido', 1], ['dem_col_juros_pct', 1], ['dem_col_juros', 1], ['dem_col_total', 1]].forEach(function (c) {
      cab.appendChild(el('span', c[1] ? 'calc-dir' : null, T('calculo.js.' + c[0])));
    });
    corpo.appendChild(cab);
    (d.linhas || []).forEach(function (l, i) {
      var lin = el('div', 'calc-dem-linha' + (i % 2 ? ' calc-impar' : ''));
      lin.appendChild(el('span', null, l.d));
      var t = el('span', 'calc-dem-t', l.t);
      if (l.proj) {
        var tag = el('span', 'calc-dem-tag', T('calculo.js.proj_tag'));
        tag.title = T('calculo.js.proj_tip');
        t.appendChild(tag);
      }
      lin.appendChild(t);
      [l.v, l.f, l.c, l.jp, l.j].forEach(function (x) { lin.appendChild(el('span', 'calc-dir', x)); });
      lin.appendChild(el('b', 'calc-dir', l.tot));
      corpo.appendChild(lin);
    });
    rol.appendChild(corpo);
    sec.appendChild(rol);
    var res = el('div', 'calc-resumo');
    (d.resumo || []).forEach(function (r) {
      var k = r.k === 2 ? ' calc-resumo-linha--2' : (r.k === 1 ? ' calc-resumo-linha--1' : '');
      var lin = el('div', 'calc-resumo-linha' + k);
      lin.appendChild(el('span', null, r.r));
      lin.appendChild(el('span', null, r.v));
      res.appendChild(lin);
    });
    sec.appendChild(res);
    sec.appendChild(el('div', 'calc-criterios', (d.criterios ? d.criterios + ' ' : '') + T('calculo.js.rodape_fixo')));
    main.appendChild(sec);
  }

  // ---------- PDF ----------
  function desenharPdfBtns() {
    var tem = !!state.res;
    ['calcBtnPdf', 'calcBtnPdf2'].forEach(function (id) {
      var b = $(id);
      b.hidden = !tem;
      b.querySelector('i').className = 'bi ' + (state.pdfCarr ? 'bi-hourglass-split' : 'bi-file-earmark-arrow-down');
      b.querySelector('.calc-btn-txt').textContent = state.pdfCarr ? T('calculo.js.gerando_pdf') : T('calculo.js.baixar_pdf');
    });
  }
  function baixarPdf() {
    if (!state.res || state.pdfCarr) return;
    var form = document.createElement('form');
    form.method = 'post'; form.action = raiz.getAttribute('data-url-pdf'); form.hidden = true;
    var t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = raiz.getAttribute('data-token');
    var dados = document.createElement('input'); dados.type = 'hidden'; dados.name = 'dados'; dados.value = JSON.stringify(state.f);
    form.appendChild(t); form.appendChild(dados);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    state.pdfCarr = true; desenharPdfBtns();
    setTimeout(function () { state.pdfCarr = false; desenharPdfBtns(); }, 1500);
  }
  $('calcBtnPdf').addEventListener('click', baixarPdf);
  $('calcBtnPdf2').addEventListener('click', baixarPdf);

  // ---------- índices ----------
  var selo = $('calcIndicesSelo');
  function aplicarSelo(estado, texto, dica) {
    selo.classList.toggle('calc-selo--erro', estado === 'erro');
    selo.classList.toggle('calc-selo--carregando', estado === 'carregando');
    selo.querySelector('i').className = 'bi ' + (estado === 'erro' ? 'bi-exclamation-triangle' : estado === 'carregando' ? 'bi-hourglass-split' : 'bi-check-circle');
    selo.querySelector('.calc-selo-txt').textContent = texto;
    if (dica != null) selo.title = dica;
  }
  var atualizando = false;
  function atualizarIndices() {
    if (atualizando) return;
    atualizando = true;
    aplicarSelo('carregando', T('calculo.js.carregando'));
    fetch(raiz.getAttribute('data-url-indices'), {
      method: 'POST',
      headers: { 'X-CSRF-Token': raiz.getAttribute('data-token'), 'Accept': 'application/json' }
    }).then(function (r) { return r.json().catch(function () { return null; }); }).then(function (d) {
      atualizando = false;
      if (d && d.status) aplicarSelo(d.status.estado, d.status.texto, d.status.dica);
      else aplicarSelo('erro', T('calculo.indices.indisponiveis'));
      if (d && d.ok === false && d.erro) mostrarErros([{ campo: '', msg: d.erro }], T('calculo.aviso.titulo_corrigir'));
      else if (!d) mostrarErros([{ campo: '', msg: T('calculo.js.falha_indices') }], T('calculo.aviso.titulo_corrigir'));
    }).catch(function () {
      atualizando = false;
      aplicarSelo('erro', T('calculo.indices.indisponiveis'));
      mostrarErros([{ campo: '', msg: T('calculo.js.falha_indices') }], T('calculo.aviso.titulo_corrigir'));
    });
  }
  $('calcBtnIndices').addEventListener('click', atualizarIndices);

  // ---------- início ----------
  preencherCampos();
  desenharNv();
  desenharVals();
  desenharAbats();
  desenharPdfBtns();
  if (raiz.getAttribute('data-desatualizado') === 'sim') atualizarIndices();
})();
