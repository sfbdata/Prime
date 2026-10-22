/* Passagem de etapa e devolução da meta (desenhos 04 e 04B de Minhas Metas de 22/10; spec
   minhas-metas-esteira-2210.md, Fase 3).

   - Modal "Concluir etapa e passar adiante": abre pelos botões [data-mp-abrir] (o "Concluir etapa" do cartão e o
     "Concluir e passar para X" da lateral da meta) e pede os dados à rota tarefa_passagem. Na lista é o 04 (L131,
     L174-197: faixa da sequência, TIPO DE DEMANDA, seletor com foto); na meta é o 04B (L92-102: menu PASSAR PARA A
     ETAPA, seletor sem avatar). O template só traz as partes do seu desenho; aqui cada montagem pula a que falta.
   - Modal "Devolver para X" (04B L106, L165-167): motivo fixo e o que falta, com os erros do desenho.
   O servidor confere tudo de novo (PassarEtapaDaMetaUseCase, DevolverMetaUseCase). Depois de gravar, a tela recarrega
   e o aviso (toast) aparece pelo sessionStorage: o base.html.twig consome os flashes como alerta. */
(function () {
  'use strict';

  var CHAVE_DO_AVISO = 'bj-mp-aviso';

  /* Chave inteira no código: o CatalogoDeTraducaoTest confere cada uma no catálogo. */
  function t(chave, params) {
    return window.BJ_T ? BJ_T.t(chave, params || {}) : chave;
  }

  function elemento(tag, classe, texto) {
    var el = document.createElement(tag);
    if (classe) { el.className = classe; }
    if (texto !== undefined && texto !== null) { el.textContent = texto; }
    return el;
  }

  function icone(classe) {
    var i = elemento('i', 'bi ' + classe);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  /* O aviso do desenho (04 L131 fim: 3,5 s na lista; 04B: 3 s na meta), embaixo e no centro. */
  function avisar(texto, ms) {
    var aviso = elemento('div', 'mp-toast');
    aviso.setAttribute('role', 'status');
    aviso.appendChild(icone('bi-check-circle'));
    aviso.appendChild(document.createTextNode(texto));
    document.body.appendChild(aviso);
    setTimeout(function () { aviso.remove(); }, ms || 3000);
  }

  function avisarDepoisDeRecarregar(texto, ms) {
    try { sessionStorage.setItem(CHAVE_DO_AVISO, JSON.stringify({ texto: texto, ms: ms })); } catch (e) { /* sem storage, sem aviso */ }
    window.location.reload();
  }

  function mostrarAvisoGuardado() {
    var guardado = null;
    try {
      guardado = JSON.parse(sessionStorage.getItem(CHAVE_DO_AVISO) || 'null');
      sessionStorage.removeItem(CHAVE_DO_AVISO);
    } catch (e) { guardado = null; }
    if (guardado && guardado.texto) { avisar(guardado.texto, guardado.ms); }
  }

  /* A recusa das rotas da passagem vem em `erro`; a da pasta na lápide (PastaSomenteLeituraListener, 403) em `mensagem`. */
  function mensagemDeErro(resposta) {
    return (resposta && (resposta.erro || resposta.mensagem)) || t('metas.passagem.js.erro_rede');
  }

  function postar(url, campos) {
    var corpo = new FormData();
    Object.keys(campos).forEach(function (k) { corpo.append(k, campos[k]); });
    return fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }

  /* ── Modal da passagem ─────────────────────────────────────────────────── */
  var modal = document.getElementById('mpPassagem');
  var estado = null;

  function parte(nome) { return modal.querySelector('[data-mp="' + nome + '"]'); }

  function pos(k) { return estado.dados.etapas[k].posicao; }

  function sequencia() {
    var tipo = estado.dados.tipos.filter(function (x) { return x.valor === estado.tipo; })[0];
    return tipo ? tipo.sequencia : [];
  }

  function proximaPadrao() {
    var atual = pos(estado.dados.meta.etapa);
    return sequencia().filter(function (k) { return pos(k) > atual; })[0] || null;
  }

  function destino() { return estado.destino || proximaPadrao(); }

  function ehVolta() { var d = destino(); return !!d && pos(d) < pos(estado.dados.meta.etapa); }

  function montarFaixa() {
    var faixa = parte('seq');
    if (!faixa) { return; }
    var atual = estado.dados.meta.etapa;
    var dest = destino();
    faixa.replaceChildren();
    sequencia().forEach(function (k) {
      var e = estado.dados.etapas[k];
      var classe = k === atual ? 'is-atual' : k === dest ? 'is-proxima' : pos(k) < pos(atual) ? 'is-passada' : '';
      var item = elemento('span', 'mp-seq-etapa ' + classe);
      item.title = e.nome;
      item.appendChild(icone(k === atual ? 'bi-check-lg' : k === dest ? 'bi-arrow-right' : e.icone));
      item.appendChild(document.createTextNode(e.nome));
      faixa.appendChild(item);
    });
  }

  function montarCaixaProxima() {
    var dest = destino();
    var volta = ehVolta();
    var caixa = parte('caixaProxima');
    caixa.classList.toggle('is-volta', !!estado.destino && volta);
    caixa.classList.toggle('is-salta', !!estado.destino && !volta);
    parte('rotuloProxima').textContent = !estado.destino ? t('metas.passagem.js.proximo_passo') : volta ? t('metas.passagem.js.voltar_para') : t('metas.passagem.js.saltar_para');
    parte('proxima').textContent = dest ? estado.dados.etapas[dest].nome : t('metas.passagem.js.encerramento');
    var desc = parte('descProxima');
    if (desc) { desc.textContent = dest ? estado.dados.etapas[dest].descricao : t('metas.passagem.js.sem_proxima'); }
    montarSalto();
  }

  function montarSalto() {
    var salto = parte('salto');
    if (!salto) { return; }
    salto.hidden = !estado.destino;
    if (!estado.destino) { return; }
    var volta = ehVolta();
    var atual = pos(estado.dados.meta.etapa);
    salto.classList.toggle('is-volta', volta);
    salto.querySelector('.bi').className = 'bi ' + (volta ? 'bi-arrow-return-left' : 'bi-skip-forward');
    var nomes = sequencia().filter(function (k) { return pos(k) > atual && pos(k) < pos(estado.destino); })
      .map(function (k) { return estado.dados.etapas[k].nome; });
    salto.querySelector('span').textContent = volta
      ? t('metas.passagem.js.voltando', { etapa: estado.dados.etapas[estado.destino].nome })
      : t('metas.passagem.js.saltando', { nomes: nomes.join(', ') });
  }

  function opcoesDoMenu() {
    var atual = estado.dados.meta.etapa;
    var padrao = proximaPadrao();
    return sequencia().filter(function (k) { return k !== atual; }).map(function (k) {
      return { k: k, volta: pos(k) < pos(atual), salta: !!padrao && pos(k) > pos(padrao), px: k === padrao };
    });
  }

  function montarMenu() {
    var lista = parte('menuLista');
    if (!lista) { return; }
    var opcoes = opcoesDoMenu();
    parte('menuBotao').hidden = opcoes.length < 2;
    lista.replaceChildren();
    opcoes.forEach(function (o, i) {
      if (o.px && i > 0) { lista.appendChild(elemento('div', 'mp-menu-sep')); }
      var e = estado.dados.etapas[o.k];
      var item = elemento('button', 'mp-menu-item' + (o.volta ? ' is-volta' : ''));
      item.type = 'button';
      item.dataset.prox = o.px ? '1' : '';
      item.appendChild(icone(o.volta ? 'bi-arrow-return-left' : o.salta ? 'bi-skip-forward' : 'bi-arrow-right'));
      var nome = elemento('span', 'mp-menu-nome', e.nome);
      nome.appendChild(elemento('span', 'mp-menu-setor', e.setor));
      item.appendChild(nome);
      if (o.salta || o.volta) { item.appendChild(elemento('span', 'mp-menu-marca', o.salta ? t('metas.passagem.js.salta') : t('metas.passagem.js.volta'))); }
      item.addEventListener('click', function () {
        estado.destino = o.salta || o.volta ? o.k : '';
        alternarMenu(false);
        montarTudo();
      });
      lista.appendChild(item);
    });
  }

  /* Abre o menu já rolado até a próxima etapa (04B L169). */
  function alternarMenu(abrir) {
    var menu = parte('menu');
    if (!menu) { return; }
    menu.hidden = !abrir;
    parte('menuBotao').setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (!abrir) { return; }
    var lista = parte('menuLista');
    var proxima = lista.querySelector('[data-prox="1"]');
    if (proxima) { lista.scrollTop += proxima.getBoundingClientRect().top - lista.getBoundingClientRect().top; }
  }

  function montarTipos() {
    var tipos = parte('tipos');
    if (!tipos) { return; }
    tipos.replaceChildren();
    estado.dados.tipos.forEach(function (tp) {
      var botao = elemento('button', 'mp-tipo-botao' + (tp.valor === estado.tipo ? ' is-ativo' : ''), tp.rotulo);
      botao.type = 'button';
      botao.setAttribute('aria-pressed', tp.valor === estado.tipo ? 'true' : 'false');
      botao.addEventListener('click', function () {
        estado.tipo = tp.valor;
        if (estado.destino && sequencia().indexOf(estado.destino) < 0) { estado.destino = ''; }
        if (estado.destino === proximaPadrao()) { estado.destino = ''; }
        montarTudo();
      });
      tipos.appendChild(botao);
    });
  }

  /* A equipe que casa com o digitado, quem é do setor da etapa destino primeiro (04 L195), até 8. */
  function membrosFiltrados() {
    var dest = destino();
    var setor = dest ? estado.dados.etapas[dest].setor.split('(')[0].toLowerCase().trim() : '';
    var q = parte('resp').value.toLowerCase().replace(/^@/, '').trim();
    var doSetor = function (m) { return setor !== '' && m.funcao.toLowerCase().indexOf(setor) >= 0 ? 1 : 0; };
    return estado.dados.membros
      .filter(function (m) { return !q || (m.nome + ' ' + m.funcao).toLowerCase().indexOf(q) >= 0; })
      .sort(function (a, b) { return doSetor(b) - doSetor(a); })
      .slice(0, 8);
  }

  /* 04 L131 e L195: a foto do perfil sobre o círculo azul; sem foto, as iniciais. */
  function membroComFoto(m) {
    var botao = elemento('button', 'mp-membro');
    var foto = elemento('span', 'mp-membro-foto', m.foto ? '' : m.iniciais);
    foto.style.backgroundImage = m.foto ? 'url(' + JSON.stringify(m.foto) + ')' : 'none';
    botao.appendChild(foto);
    var texto = elemento('span', 'mp-membro-texto');
    texto.appendChild(elemento('b', null, m.nome));
    texto.appendChild(elemento('span', null, m.funcao));
    botao.appendChild(texto);
    return botao;
  }

  /* 04B L97: sem avatar, o nome em negrito e a função embaixo. */
  function membroEmColuna(m) {
    var botao = elemento('button', 'mp-membro is-coluna');
    botao.appendChild(elemento('b', null, m.nome));
    botao.appendChild(elemento('span', null, m.funcao));
    return botao;
  }

  function montarMembros() {
    var caixa = parte('membros');
    var lista = membrosFiltrados();
    caixa.replaceChildren();
    lista.forEach(function (m) {
      var botao = estado.contexto === 'meta' ? membroEmColuna(m) : membroComFoto(m);
      botao.type = 'button';
      botao.addEventListener('mousedown', function (ev) {
        ev.preventDefault();
        estado.respId = m.id;
        parte('resp').value = m.nome;
        caixa.hidden = true;
      });
      caixa.appendChild(botao);
    });
    caixa.hidden = !estado.listaAberta || lista.length === 0;
  }

  function montarCampos() {
    var dest = destino();
    parte('comProxima').hidden = !dest;
    if (estado.contexto !== 'meta') {
      parte('resp').placeholder = dest ? t('metas.passagem.js.responsavel_placeholder', { setor: estado.dados.etapas[dest].setor }) : '';
    }
    var semProxima = estado.contexto === 'lista' ? t('metas.passagem.js.botao_encerrar') : t('metas.passagem.js.botao_concluir_meta');
    parte('okTexto').textContent = !dest ? semProxima
      : ehVolta() ? t('metas.passagem.js.botao_voltar', { etapa: estado.dados.etapas[dest].nome })
        : t('metas.passagem.js.botao_passar', { etapa: estado.dados.etapas[dest].nome });
  }

  function montarTudo() {
    montarFaixa();
    montarCaixaProxima();
    montarMenu();
    montarTipos();
    montarCampos();
    montarMembros();
  }

  function erroDaPassagem(texto) {
    var erro = parte('erro');
    erro.textContent = texto || '';
    erro.hidden = !texto;
  }

  function abrirPassagem(url) {
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) { avisar(mensagemDeErro(res.j)); return; }
        estado = { dados: res.j, tipo: res.j.meta.tipo, destino: '', respId: null, listaAberta: false, contexto: modal.dataset.contexto };
        var meta = res.j.meta;
        var pasta = parte('pasta');
        if (pasta) { pasta.textContent = meta.cliente ? t('metas.passagem.js.pasta', { pasta: meta.pasta, cliente: meta.cliente }) : t('metas.passagem.js.pasta_sem_cliente', { pasta: meta.pasta }); }
        parte('atual').textContent = res.j.etapas[meta.etapa].nome;
        if (parte('titulo')) { parte('titulo').textContent = meta.titulo; }
        parte('resp').value = '';
        parte('prazo').value = res.j.prazo;
        parte('pend').value = '';
        if (parte('feito')) { parte('feito').value = ''; }
        erroDaPassagem('');
        alternarMenu(false);
        montarTudo();
        modal.hidden = false;
      })
      .catch(function () { avisar(t('metas.passagem.js.erro_rede')); });
  }

  function confirmarPassagem() {
    var dest = destino();
    if (dest && parte('resp').value.trim() !== '' && !estado.respId) { erroDaPassagem(t('metas.passagem.js.erro_responsavel')); return; }
    var ok = parte('ok');
    ok.disabled = true;
    postar(estado.dados.url, {
      _token: estado.dados.token,
      tipo: estado.tipo,
      destino: estado.destino,
      prazo: dest ? parte('prazo').value : '',
      pendencias: dest ? parte('pend').value : '',
      feito: parte('feito') ? parte('feito').value : '',
      responsavel: dest && estado.respId ? String(estado.respId) : ''
    }).then(function (res) {
      ok.disabled = false;
      if (!res.ok) { erroDaPassagem(mensagemDeErro(res)); return; }
      var lista = estado.contexto === 'lista';
      var texto = res.proxima ? t('metas.passagem.js.toast_passou', { pasta: res.pasta, etapa: res.proxima })
        : lista ? t('metas.passagem.js.toast_encerrou', { pasta: res.pasta }) : t('metas.passagem.js.toast_meta_concluida');
      avisarDepoisDeRecarregar(texto, lista ? 3500 : 3000);
    }).catch(function () { ok.disabled = false; erroDaPassagem(t('metas.passagem.js.erro_rede')); });
  }

  function iniciarPassagem() {
    if (!modal) { return; }
    document.addEventListener('click', function (ev) {
      var botao = ev.target.closest('[data-mp-abrir]');
      if (!botao) { return; }
      ev.preventDefault();
      ev.stopPropagation();
      abrirPassagem(botao.dataset.mpAbrir);
    }, true);
    modal.querySelectorAll('[data-mp-fechar]').forEach(function (b) { b.addEventListener('click', function () { modal.hidden = true; }); });
    if (parte('menuBotao')) {
      parte('menuBotao').addEventListener('click', function (ev) { ev.stopPropagation(); alternarMenu(parte('menu').hidden); });
      parte('menuFundo').addEventListener('click', function () { alternarMenu(false); });
    }
    var resp = parte('resp');
    resp.addEventListener('focus', function () { estado.listaAberta = true; montarMembros(); });
    resp.addEventListener('input', function () { estado.respId = null; estado.listaAberta = true; montarMembros(); });
    resp.addEventListener('blur', function () { setTimeout(function () { estado.listaAberta = false; parte('membros').hidden = true; }, 160); });
    parte('ok').addEventListener('click', confirmarPassagem);
  }

  /* ── Devolução ─────────────────────────────────────────────────────────── */
  var devolucao = document.getElementById('mpDevolucao');
  var motivo = '';

  function erroDaDevolucao(texto) {
    var erro = devolucao.querySelector('[data-mp="erro"]');
    erro.textContent = texto || '';
    erro.hidden = !texto;
  }

  function marcarMotivo(escolhido) {
    motivo = escolhido;
    devolucao.querySelectorAll('[data-motivo]').forEach(function (b) {
      var ativo = b.dataset.motivo === escolhido;
      b.classList.toggle('is-ativo', ativo);
      b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
    });
  }

  function confirmarDevolucao() {
    var falta = devolucao.querySelector('[data-mp="falta"]').value.trim();
    if (!motivo) { erroDaDevolucao(t('metas.passagem.js.erro_motivo')); return; }
    if (falta.length < 5) { erroDaDevolucao(t('metas.passagem.js.erro_falta')); return; }
    var ok = devolucao.querySelector('[data-mp="ok"]');
    ok.disabled = true;
    postar(devolucao.dataset.url, { _token: devolucao.dataset.token, motivo: motivo, o_que_falta: falta }).then(function (res) {
      ok.disabled = false;
      if (!res.ok) { erroDaDevolucao(mensagemDeErro(res)); return; }
      /* Só diz que avisou quando o aviso foi mesmo mandado (quem saiu do escritório ou não vê a pasta não recebe). */
      var nome = { nome: String(res.para || '').split(' ')[0] };
      avisarDepoisDeRecarregar(res.avisado ? t('metas.passagem.js.toast_devolvida', nome) : t('metas.passagem.js.toast_devolvida_sem_aviso', nome), 3000);
    }).catch(function () { ok.disabled = false; erroDaDevolucao(t('metas.passagem.js.erro_rede')); });
  }

  function iniciarDevolucao() {
    if (!devolucao) { return; }
    document.querySelectorAll('[data-mp-devolver]').forEach(function (b) {
      b.addEventListener('click', function () {
        marcarMotivo('');
        devolucao.querySelector('[data-mp="falta"]').value = '';
        erroDaDevolucao('');
        devolucao.hidden = false;
      });
    });
    devolucao.querySelectorAll('[data-mp-fechar]').forEach(function (b) { b.addEventListener('click', function () { devolucao.hidden = true; }); });
    devolucao.querySelectorAll('[data-motivo]').forEach(function (b) {
      b.addEventListener('click', function () { marcarMotivo(b.dataset.motivo); erroDaDevolucao(''); });
    });
    devolucao.querySelector('[data-mp="falta"]').addEventListener('input', function () { erroDaDevolucao(''); });
    devolucao.querySelector('[data-mp="ok"]').addEventListener('click', confirmarDevolucao);
  }

  function iniciar() {
    mostrarAvisoGuardado();
    iniciarPassagem();
    iniciarDevolucao();
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') { return; }
      if (modal) { modal.hidden = true; }
      if (devolucao) { devolucao.hidden = true; }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
