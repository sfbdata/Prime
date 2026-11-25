/* BlueJus · Comercial do /master: Base de contatos (os contatos salvos do Master).
   Abre e fecha pelo botão "Base de contatos" da Central (ou pelo evento `mc:base-abrir`); lista de 20 em 20 com busca
   no servidor, incluir, editar o nome, excluir (um ou vários, com a janela do painel), importar e exportar CSV.
   As linhas são montadas por DOM, com textContent. A situação (Ativo / Descadastrado) vem do servidor. */
(function () {
  'use strict';
  const raiz = document.querySelector('[data-mc]');
  const bloco = document.querySelector('[data-mc-bc]');
  if (!raiz || !bloco) return;

  const TOKEN = raiz.dataset.token || '';
  const BYTES_DO_CSV = 1048576;
  const $ = sel => bloco.querySelector(sel);
  const elLinhas = $('[data-mc-bc-linhas]'), elVazio = $('[data-mc-bc-vazio]'), elAviso = $('[data-mc-bc-aviso]');
  const elTitulos = $('[data-mc-bc-titulos]'), elMarcarTodos = $('[data-mc-bc-marcar-todos]');
  const elBusca = $('[data-mc-bc-busca]'), elCsv = $('[data-mc-bc-csv]'), elArquivo = $('[data-mc-bc-arquivo]');
  const elForm = $('[data-mc-bc-form]'), elNovoEmail = $('[data-mc-bc-novo-email]'), elNovoNome = $('[data-mc-bc-novo-nome]');
  const elExcluir = $('[data-mc-bc-excluir]'), elExcluirRotulo = $('[data-mc-bc-excluir-rotulo]');
  const elPagina = $('[data-mc-bc-pagina]'), elInfo = $('[data-mc-bc-pagina-info]');
  const elAnterior = $('[data-mc-bc-anterior]'), elProxima = $('[data-mc-bc-proxima]');
  let busca = '', pagina = 1, total = 0, porPagina = 20, espera = null;
  let itens = [];
  const marcados = new Set();

  const numero = n => (window.BJFormato ? window.BJFormato.numero(n) : String(n));
  const dataHora = iso => (window.BJFormato ? window.BJFormato.dataHora(iso) : new Date(iso).toLocaleString('pt-BR').slice(0, 16));
  const t = (chave, vars) => BJ_T.t('comercial.js.' + chave, vars);

  // Linha de retorno fixa: `tipo` 'ok', 'erro' ou 'info'; fica até a próxima ação.
  function avisar(texto, tipo) {
    elAviso.textContent = texto || '';
    elAviso.hidden = !texto;
    elAviso.classList.toggle('is-ok', tipo === 'ok');
    elAviso.classList.toggle('is-info', tipo === 'info');
    elAviso.classList.toggle('is-erro', tipo === 'erro');
    elAviso.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
  }

  function pedir(url, opcoes) {
    return fetch(url, Object.assign({ headers: { 'Accept': 'application/json' } }, opcoes)).then(r => r.json().catch(() => ({})).then(d => {
      if (!r.ok) throw new Error(d.erro || t('base_erro'));
      return d;
    }));
  }

  function postar(url, corpo) {
    return pedir(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': TOKEN }, body: JSON.stringify(corpo) });
  }

  function urlDoCsv() {
    return bloco.dataset.urlCsv + (busca ? '?' + new URLSearchParams({ q: busca }) : '');
  }

  /* ---------- Seleção ---------- */
  function atualizarSelecao() {
    const n = marcados.size;
    elExcluir.disabled = n === 0;
    elExcluirRotulo.textContent = n > 0 ? t('base_excluir_n', { n: numero(n) }) : elExcluirRotulo.dataset.base || elExcluirRotulo.textContent;
    elMarcarTodos.checked = itens.length > 0 && n === itens.length;
  }

  /* ---------- Lista ---------- */
  function celula(classe, texto) {
    const el = document.createElement('span');
    el.className = classe;
    el.textContent = texto;
    return el;
  }

  function botao(id, texto, rotulo, acao) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'mc-dsc-excluir'; b.textContent = texto; b.id = id; b.name = id;
    b.setAttribute('aria-label', rotulo);
    b.addEventListener('click', acao);

    return b;
  }

  /* `i` é a posição na página: o id do elemento não leva o e-mail (dado pessoal). */
  function linha(item, i) {
    const el = document.createElement('div');
    el.className = 'mc-bc-linha';
    const marca = document.createElement('input');
    marca.type = 'checkbox'; marca.className = 'mc-bc-marca'; marca.id = 'mc-bc-marca-' + i; marca.name = marca.id;
    marca.checked = marcados.has(item.id);
    marca.setAttribute('aria-label', t('base_marcar_de', { email: item.email }));
    marca.addEventListener('change', () => { if (marca.checked) marcados.add(item.id); else marcados.delete(item.id); atualizarSelecao(); });
    const email = celula('mc-bc-email', item.email); email.title = item.email;
    const nome = celula('mc-bc-nome', item.nome || ''); nome.title = item.nome || '';
    const origem = celula('mc-bc-sub', t(item.origem === 'manual' ? 'base_origem_manual' : 'base_origem_importacao'));
    const situacao = celula('mc-bc-situacao' + (item.situacao === 'descadastrado' ? ' is-descadastrado' : ''), t(item.situacao === 'descadastrado' ? 'base_situacao_descadastrado' : 'base_situacao_ativo'));
    const convite = celula('mc-bc-sub', item.ultimoConvite ? dataHora(item.ultimoConvite) : t('base_sem_convite'));
    const criado = celula('mc-bc-sub', dataHora(item.criadoEm));
    const acoes = document.createElement('span');
    acoes.className = 'mc-bc-acoes';
    const editar = botao('mc-bc-editar-' + i, t('base_editar'), t('base_editar_de', { email: item.email }), () => editarNome(item, nome, editar));
    const excluir = botao('mc-bc-excluir-' + i, t('base_excluir'), t('base_excluir_de', { email: item.email }), () => excluirContatos([item]));
    acoes.append(editar, excluir);
    el.append(marca, email, nome, origem, situacao, convite, criado, acoes);

    return el;
  }

  function desenhar() {
    elLinhas.replaceChildren(...itens.map(linha));
    const vazio = itens.length === 0;
    elVazio.textContent = busca.length >= 2 ? t('base_vazio_busca') : elVazio.dataset.padrao;
    elVazio.hidden = !vazio;
    elTitulos.hidden = vazio;
    const paginas = Math.max(1, Math.ceil(total / porPagina));
    elPagina.hidden = total <= porPagina;
    elInfo.textContent = t('base_pagina', { pagina: numero(pagina), total: numero(paginas), n: numero(total) });
    elAnterior.disabled = pagina <= 1;
    elProxima.disabled = pagina >= paginas;
    atualizarSelecao();
  }

  function carregar() {
    return pedir(bloco.dataset.urlLista + '?' + new URLSearchParams({ q: busca, pagina: String(pagina) })).then(r => {
      total = r.total; porPagina = r.porPagina || porPagina;
      if (r.itens.length === 0 && pagina > 1) { pagina = Math.max(1, Math.ceil(total / porPagina)); return carregar(); }
      itens = r.itens;
      const ids = new Set(itens.map(i => i.id));
      marcados.forEach(id => { if (!ids.has(id)) marcados.delete(id); });
      desenhar();
    }).catch(err => avisar(err.message, 'erro'));
  }

  /* ---------- Ações ---------- */
  function editarNome(item, celulaNome, botaoEditar) {
    if (celulaNome.querySelector('input')) return;
    const campo = document.createElement('input');
    campo.type = 'text'; campo.className = 'mc-bc-nome-campo'; campo.maxLength = 180; campo.value = item.nome || '';
    campo.id = botaoEditar.id.replace('editar', 'nome'); campo.name = campo.id;
    campo.setAttribute('aria-label', t('base_nome_de', { email: item.email }));
    celulaNome.replaceChildren(campo);
    campo.focus();
    botaoEditar.textContent = t('base_salvar');
    let enviado = false;
    const salvar = () => {
      if (enviado) return;
      enviado = true;
      avisar(t('salvando'), 'info');
      postar(bloco.dataset.urlEditar.replace('999999999', String(item.id)), { nome: campo.value }).then(() => { avisar(t('base_ok_nome'), 'ok'); carregar(); }).catch(err => { enviado = false; avisar(err.message, 'erro'); });
    };
    botaoEditar.onclick = salvar;
    campo.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); salvar(); }
      if (e.key === 'Escape') { desenhar(); }
    });
  }

  function excluirContatos(lista) {
    if (lista.length === 0) return;
    const um = lista.length === 1;
    window.BJMasterConf.perguntar({
      titulo: t(um ? 'base_titulo_excluir' : 'base_titulo_excluir_n'), icone: 'bi-trash3', cor: '#a3232b',
      texto: um ? t('base_confirmar_excluir', { email: lista[0].email }) : t('base_confirmar_excluir_n', { n: numero(lista.length) }),
      botao: t('botao_excluir'),
    }).then(ok => {
      if (!ok) return;
      avisar(t('salvando'), 'info');
      postar(bloco.dataset.urlExcluir, { ids: lista.map(i => i.id) }).then(r => {
        lista.forEach(i => marcados.delete(i.id));
        avisar(r.excluidos === 1 ? t('base_ok_excluido') : t('base_ok_excluidos', { n: numero(r.excluidos) }), 'ok');
        carregar();
      }).catch(err => avisar(err.message, 'erro'));
    });
  }

  function mostrarForm(aberto) {
    elForm.hidden = !aberto;
    if (aberto) { elNovoEmail.value = ''; elNovoNome.value = ''; elNovoEmail.focus(); }
  }

  function incluir() {
    const email = elNovoEmail.value.trim().toLowerCase();
    if (!email) { elNovoEmail.focus(); return; }
    avisar(t('salvando'), 'info');
    postar(bloco.dataset.urlIncluir, { email, nome: elNovoNome.value.trim() }).then(() => {
      avisar(t('base_ok_incluido', { email }), 'ok');
      mostrarForm(false);
      pagina = 1;
      carregar();
    }).catch(err => avisar(err.message, 'erro'));
  }

  function importar(arquivo) {
    if (!arquivo) return;
    if (arquivo.size > BYTES_DO_CSV) { avisar(t('base_arquivo_grande'), 'erro'); elArquivo.value = ''; return; }
    avisar(t('base_importando'), 'info');
    arquivo.text().then(csv => postar(bloco.dataset.urlImportar, { csv, arquivo: arquivo.name })).then(r => {
      avisar(t('base_ok_importado', { novos: numero(r.novos), atualizados: numero(r.atualizados), invalidos: numero(r.invalidos) }), 'ok');
      pagina = 1;
      carregar();
    }).catch(err => avisar(err.message, 'erro')).finally(() => { elArquivo.value = ''; });
  }

  /* ---------- Abrir e fechar ---------- */
  function mostrar(aberto) {
    bloco.hidden = !aberto;
    const gatilho = document.querySelector('[data-mc-cp-base]');
    if (gatilho) gatilho.setAttribute('aria-expanded', String(aberto));
    if (aberto) { avisar(''); marcados.clear(); carregar(); }
  }

  elVazio.dataset.padrao = elVazio.textContent;
  elExcluirRotulo.dataset.base = elExcluirRotulo.textContent;
  document.addEventListener('mc:base-abrir', () => mostrar(bloco.hidden));
  const gatilho = document.querySelector('[data-mc-cp-base]');
  if (gatilho) gatilho.addEventListener('click', () => document.dispatchEvent(new CustomEvent('mc:base-abrir')));
  $('[data-mc-bc-fechar]').addEventListener('click', () => mostrar(false));
  $('[data-mc-bc-incluir]').addEventListener('click', () => mostrarForm(elForm.hidden));
  $('[data-mc-bc-cancelar]').addEventListener('click', () => mostrarForm(false));
  $('[data-mc-bc-salvar]').addEventListener('click', incluir);
  [elNovoEmail, elNovoNome].forEach(c => c.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); incluir(); } }));
  $('[data-mc-bc-importar]').addEventListener('click', () => elArquivo.click());
  elArquivo.addEventListener('change', () => importar(elArquivo.files && elArquivo.files[0]));
  elExcluir.addEventListener('click', () => excluirContatos(itens.filter(i => marcados.has(i.id))));
  elMarcarTodos.addEventListener('change', () => {
    marcados.clear();
    if (elMarcarTodos.checked) itens.forEach(i => marcados.add(i.id));
    desenhar();
  });
  elAnterior.addEventListener('click', () => { if (pagina > 1) { pagina -= 1; carregar(); } });
  elProxima.addEventListener('click', () => { pagina += 1; carregar(); });
  elBusca.addEventListener('input', () => {
    clearTimeout(espera);
    espera = setTimeout(() => {
      busca = elBusca.value.trim();
      pagina = 1;
      elCsv.href = urlDoCsv();
      carregar();
    }, 250);
  });
})();
