/* BlueJus · Painel do Master, aba "Configurações da I.A." (spec docs/specs/ia-anthropic-ligar.md §10).
   - Carrega na primeira vez que a aba abre (clique na aba ou #aba=ia no endereço).
   - Instalação: só diz se a chave da Anthropic está gravada e se a trava IA_HABILITADA está ligada. Esta tela nunca
     mostra nem envia a chave.
   - Chave geral: ligar pede confirmação (window.BJMasterConf, a confirmação interna do painel); desligar é direto.
   - Modelo: grava ao mudar o select; o preço por milhão de tokens vem do servidor.
   - Retenção: por quantos meses as respostas da IA ficam guardadas (1 a 120), grava ao mudar o campo.
   - Escritórios: uma linha por escritório ativo, com o botão Salvar da própria linha (liberado, cobrança, orçamento),
     e o consumo do mês (análises e gasto estimado em US$), com o selo "orçamento atingido" (incluso e adicional) ou
     "acima do grátis" (limite grátis). Quem decide o selo é o servidor.
   - POST JSON com X-CSRF-Token; a resposta traz o estado novo e a aba se redesenha. Recusa: a mensagem do servidor.
   - Textos no catálogo (master.js.ia.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar.
   Nenhuma regra mora só aqui: o servidor recusa modelo fora da lista, modo inválido e orçamento negativo. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-ia]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const qs = sel => raiz.querySelector(sel);
  const elMsg = qs('[data-mp-ia-msg]');
  const elChaveApi = qs('[data-mp-ia-chave-api]');
  const elTrava = qs('[data-mp-ia-trava]');
  const elGeral = qs('[data-mp-ia-chave-geral]');
  const elGeralSt = qs('[data-mp-ia-chave-geral-st]');
  const elModelo = qs('[data-mp-ia-modelo]');
  const elPreco = qs('[data-mp-ia-preco]');
  const elRetencao = qs('[data-mp-ia-retencao]');
  const elLinhas = qs('[data-mp-ia-linhas]');
  const elContagem = qs('[data-mp-ia-contagem]');
  // O catálogo e o BJ_T entram depois deste arquivo (fim da página): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const dinheiro = n => window.BJFormato.numero(n, 2);
  let estado = null;
  let carregado = false;
  let ocupado = false;

  function avisar(texto, erro) {
    elMsg.textContent = texto || '';
    elMsg.classList.toggle('is-erro', !!erro);
    elMsg.hidden = !texto;
  }

  function travar(sim) {
    ocupado = sim;
    elGeral.disabled = sim;
    elModelo.disabled = sim;
    elRetencao.disabled = sim;
    raiz.querySelectorAll('[data-mp-ia-linha] input, [data-mp-ia-linha] select, [data-mp-ia-linha] button').forEach(c => { c.disabled = sim; });
  }

  function el(tag, classe, texto) {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function desenharInstalacao(d) {
    elChaveApi.textContent = d.instalacao.chaveDaApiGravada ? T('master.js.ia.gravada') : T('master.js.ia.nao_gravada');
    elTrava.textContent = d.instalacao.travaDoServidorLigada ? T('master.js.ia.ligada') : T('master.js.ia.desligada');
    const ligada = !!d.ligadaParaTodos;
    elGeral.classList.toggle('is-off', !ligada);
    elGeral.setAttribute('aria-checked', ligada ? 'true' : 'false');
    elGeralSt.textContent = ligada ? T('master.js.ia.ligada_todos') : T('master.js.ia.desligada');
  }

  function desenharModelo(d) {
    elModelo.textContent = '';
    d.modelos.forEach(m => {
      const op = el('option', '', m.rotulo);
      op.value = m.valor;
      op.selected = m.valor === d.modelo;
      elModelo.appendChild(op);
    });
    const atual = d.modelos.find(m => m.valor === elModelo.value);
    elPreco.textContent = atual ? T('master.js.ia.preco', { entrada: dinheiro(atual.entrada), saida: dinheiro(atual.saida) }) : '';
  }

  function seletorDeModo(d, valor) {
    const sel = el('select', 'mp-ia-entrada');
    sel.setAttribute('aria-label', T('master.js.ia.cobranca'));
    sel.dataset.mpIaModo = '';
    d.modos.forEach(m => {
      const op = el('option', '', m.rotulo);
      op.value = m.valor;
      op.selected = m.valor === valor;
      sel.appendChild(op);
    });
    return sel;
  }

  function chaveDaLinha(liberada) {
    const b = el('button', 'mp-ia-chave-linha' + (liberada ? '' : ' is-off'));
    b.type = 'button';
    b.setAttribute('role', 'switch');
    b.setAttribute('aria-checked', liberada ? 'true' : 'false');
    b.dataset.mpIaLiberada = '';
    b.appendChild(el('span', 'mp-rec-chave')).appendChild(el('span'));
    b.appendChild(el('span', 'mp-ia-chave-linha-txt', liberada ? T('master.js.ia.liberado') : T('master.js.ia.bloqueado')));
    b.addEventListener('click', () => {
      const on = b.getAttribute('aria-checked') !== 'true';
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      b.classList.toggle('is-off', !on);
      b.lastChild.textContent = on ? T('master.js.ia.liberado') : T('master.js.ia.bloqueado');
    });
    return b;
  }

  function celulaDoGasto(e) {
    const cel = el('div', 'mp-ia-celula mp-hv');
    const bloco = cel.appendChild(el('div', 'mp-conta-st-bloco'));
    bloco.appendChild(el('span', 'mp-ia-num', dinheiro(Number(e.gastoNoMes))));
    let selo = null;
    if (e.orcamentoAtingido) selo = el('span', 'mp-conta-sit mp-ia-selo-atingido', T('master.js.ia.orcamento_atingido'));
    else if (e.acimaDoGratis) selo = el('span', 'mp-conta-sit mp-ia-selo-gratis', T('master.js.ia.acima_do_gratis'));
    if (selo) {
      selo.dataset.mpIaSelo = '';
      selo.insertBefore(el('i'), selo.firstChild);
      bloco.appendChild(selo);
    }
    return cel;
  }

  function linha(d, e) {
    const lin = el('div', 'mp-ia-lin mp-lin');
    lin.dataset.mpIaLinha = String(e.tenantId);
    lin.appendChild(el('div', 'mp-ia-nome mp-hv', e.nome));
    lin.appendChild(el('div', 'mp-ia-celula')).appendChild(chaveDaLinha(e.liberada));
    lin.appendChild(el('div', 'mp-ia-celula mp-ia-aceite mp-hv', e.aceiteEm ? window.BJFormato.data(e.aceiteEm + 'T12:00:00') : T('master.js.ia.sem_aceite')));
    lin.appendChild(el('div', 'mp-ia-celula')).appendChild(seletorDeModo(d, e.modoDeCobranca));
    const orc = el('input', 'mp-ia-entrada');
    Object.assign(orc, { type: 'number', min: '0', step: '0.01', inputMode: 'decimal', value: e.orcamento || '', placeholder: T('master.js.ia.sem_orcamento') });
    orc.setAttribute('aria-label', T('master.js.ia.orcamento'));
    orc.dataset.mpIaOrcamento = '';
    lin.appendChild(el('div', 'mp-ia-celula')).appendChild(orc);
    lin.appendChild(el('div', 'mp-ia-celula mp-ia-num mp-hv', String(e.analisesNoMes)));
    lin.appendChild(celulaDoGasto(e));
    const salvar = el('button', 'mp-btn-roxo mp-ia-salvar', T('master.js.ia.salvar'));
    salvar.type = 'button';
    salvar.addEventListener('click', () => salvarLinha(lin));
    lin.appendChild(el('div', 'mp-ia-celula')).appendChild(salvar);
    return lin;
  }

  function desenhar(d) {
    estado = d;
    desenharInstalacao(d);
    desenharModelo(d);
    elRetencao.value = String(d.mesesDeRetencao);
    elContagem.textContent = T('master.js.ia.contagem', { n: d.escritorios.length });
    elLinhas.textContent = '';
    if (!d.escritorios.length) elLinhas.appendChild(el('div', 'mp-carregando', T('master.js.ia.vazio')));
    d.escritorios.forEach(e => elLinhas.appendChild(linha(d, e)));
  }

  function carregar() {
    carregado = true;
    travar(true);
    fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); travar(false); })
      .catch(() => { carregado = false; avisar(T('master.js.ia.erro_carregar'), true); });
  }

  function postar(url, corpo, aoSalvar) {
    if (ocupado) return;
    travar(true);
    avisar('');
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.ia.erro_salvar'))))))
      .then(d => { desenhar(d); avisar(aoSalvar); })
      .catch(e => { if (estado) desenhar(estado); avisar(e.message || T('master.js.ia.erro_salvar'), true); })
      .finally(() => travar(false));
  }

  function salvarLinha(lin) {
    postar(raiz.dataset.urlEscritorio.replace(/\/0$/, '/' + lin.dataset.mpIaLinha), {
      liberada: lin.querySelector('[data-mp-ia-liberada]').getAttribute('aria-checked') === 'true',
      modoDeCobranca: lin.querySelector('[data-mp-ia-modo]').value,
      orcamento: lin.querySelector('[data-mp-ia-orcamento]').value.trim(),
    }, T('master.js.ia.salvo', { nome: lin.firstChild.textContent }));
  }

  elGeral.addEventListener('click', () => {
    if (!estado || ocupado) return;
    if (estado.ligadaParaTodos) { postar(raiz.dataset.urlChaveGeral, { ligada: false }, T('master.js.ia.desligou')); return; }
    window.BJMasterConf.abrir({
      titulo: T('master.js.ia.ligar_titulo'), icone: 'bi-stars', cor: '#7a3e8f', botao: T('master.js.ia.ligar_botao'),
      texto: T('master.js.ia.ligar_texto'),
      aoConfirmar: () => { window.BJMasterConf.fechar(); postar(raiz.dataset.urlChaveGeral, { ligada: true }, T('master.js.ia.ligou')); },
    });
  });

  elModelo.addEventListener('change', () => {
    if (!estado) return;
    postar(raiz.dataset.urlModelo, { modelo: elModelo.value }, T('master.js.ia.modelo_salvo'));
  });

  elRetencao.addEventListener('change', () => {
    if (!estado) return;
    postar(raiz.dataset.urlRetencao, { meses: elRetencao.value.trim() }, T('master.js.ia.retencao_salva'));
  });

  const aba = document.querySelector('.mp-aba[data-aba="ia"]');
  if (aba) aba.addEventListener('click', () => { if (!carregado) carregar(); });
  // Já aberta pelo endereço: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]aba=ia\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
  window.addEventListener('hashchange', () => { if (/[#&]aba=ia\b/.test(location.hash) && !carregado) carregar(); });
})();
