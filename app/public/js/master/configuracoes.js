/* BlueJus · Painel do Master, seção "Configurações globais" (#sec=config)
   (desenho docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html, marcação L282-285 e lógica L955-959;
   spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P2).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Os grupos e campos vêm do servidor (catálogo); o formulário guarda um rascunho e só grava no "Salvar".
   - "Restaurar padrão" (com confirmação) joga os padrões no rascunho; "Descartar" volta ao gravado; "Salvar" fica
     desligado enquanto nada mudou.
   - POST JSON com X-CSRF-Token; a resposta traz o estado novo. Recusa: a mensagem do servidor aparece e o rascunho fica.
   - Textos no catálogo (master.js.config.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar.
   Nenhuma regra mora só aqui: o servidor valida intervalo, opção, e-mail e data. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mcg]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elGrade = raiz.querySelector('[data-mcg-grade]');
  const elStatus = raiz.querySelector('[data-mcg-status]');
  const btnPadrao = raiz.querySelector('[data-mcg-padrao]');
  const btnDescartar = raiz.querySelector('[data-mcg-descartar]');
  const btnSalvar = raiz.querySelector('[data-mcg-salvar]');
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const PREFIXO = 'master.js.config.';
  const T = (chave, params) => window.BJ_T.t(PREFIXO + chave, params);
  let estado = null;
  let rascunho = null;
  let carregado = false;
  let ocupado = false;
  let salvou = false;
  let erro = '';

  const mudou = () => !!estado && Object.keys(estado.valores).some(k => estado.valores[k] !== rascunho[k]);

  function textos() {
    raiz.querySelectorAll('[data-mcg-t]').forEach(el => { el.textContent = T(el.dataset.mcgT); });
  }

  function campo(c) {
    const rotulo = document.createElement('label');
    rotulo.className = 'mcg-campo';
    rotulo.append(T('campo.' + c.chave));
    let entrada;
    if (c.tipo === 'select') {
      entrada = document.createElement('select');
      c.opcoes.forEach(o => {
        const op = document.createElement('option');
        op.value = o;
        op.textContent = T('opcao.' + c.chave + '.' + o);
        entrada.append(op);
      });
    } else {
      entrada = document.createElement('input');
      entrada.type = c.tipo;
      if (c.tipo === 'number') { entrada.min = c.min; entrada.max = c.max; }
      if (c.tipo === 'text' || c.tipo === 'email') entrada.maxLength = c.max;
    }
    entrada.className = 'mcg-entrada';
    entrada.dataset.mcgCampo = c.chave;
    entrada.value = rascunho[c.chave];
    entrada.addEventListener('input', () => { rascunho[c.chave] = entrada.value; salvou = false; erro = ''; atualizar(); });
    rotulo.append(entrada);
    if (c.ajuda) {
      const aj = document.createElement('span');
      aj.className = 'mcg-ajuda';
      aj.textContent = T('ajuda.' + c.chave);
      rotulo.append(aj);
    }
    return rotulo;
  }

  function desenhar() {
    elGrade.replaceChildren();
    estado.grupos.forEach(g => {
      const caixa = document.createElement('div');
      caixa.className = 'mcg-grupo';
      const titulo = document.createElement('div');
      titulo.className = 'mcg-grupo-titulo';
      titulo.textContent = T('grupo.' + g.grupo);
      const corpo = document.createElement('div');
      corpo.className = 'mcg-campos';
      g.campos.forEach(c => corpo.append(campo(c)));
      caixa.append(titulo, corpo);
      elGrade.append(caixa);
    });
    elGrade.removeAttribute('aria-busy');
    atualizar();
  }

  // Valores nos campos já desenhados (o "Restaurar padrão" e o "Descartar" trocam o rascunho inteiro).
  function sincronizar() {
    elGrade.querySelectorAll('[data-mcg-campo]').forEach(el => { el.value = rascunho[el.dataset.mcgCampo]; });
  }

  function atualizar() {
    const mud = mudou();
    elStatus.className = 'mcg-status' + (erro ? ' mcg-st-erro' : mud ? ' mcg-st-mud' : '');
    elStatus.textContent = erro || (mud ? T('nao_salvas') : salvou ? T('salvas') : estado && estado.alteradoEm ? T('ultima', { data: window.BJFormato.dataHora(estado.alteradoEm) }) : '');
    btnDescartar.hidden = !mud;
    btnSalvar.disabled = !mud || ocupado;
  }

  function receber(d) {
    estado = d;
    rascunho = Object.assign({}, d.valores);
  }

  function carregar() {
    carregado = true;
    return fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { receber(d); textos(); desenhar(); })
      .catch(() => { carregado = false; erro = T('erro_carregar'); atualizar(); });
  }

  btnPadrao.addEventListener('click', () => {
    if (!estado || !window.confirm(T('confirmar_padrao'))) return;
    rascunho = Object.assign({}, estado.padroes);
    salvou = false; erro = '';
    sincronizar(); atualizar();
  });

  btnDescartar.addEventListener('click', () => {
    if (!estado) return;
    rascunho = Object.assign({}, estado.valores);
    erro = '';
    sincronizar(); atualizar();
  });

  btnSalvar.addEventListener('click', () => {
    if (!estado || ocupado || !mudou()) return;
    ocupado = true; erro = ''; atualizar();
    fetch(raiz.dataset.urlSalvar, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify({ valores: rascunho }),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('erro_salvar'))))))
      .then(d => { receber(d); salvou = true; sincronizar(); })
      .catch(e => { erro = e.message || T('erro_salvar'); })
      .finally(() => { ocupado = false; atualizar(); });
  });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'config' && !carregado) carregar(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=config\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
})();
