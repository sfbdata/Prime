/* BlueJus · Página da meta (desenho 04B de 22/10). Template: templates/tarefa/show.html.twig.
   - Janelas (.md-modal): abrem pelo [data-md-abrir="id"] (clique, Enter ou espaço) e fecham no [data-md-fechar] e no
     Esc. Ao abrir, a janela recebe o evento 'md:abrir' com o elemento que a abriu (o Certificar usa, em meta-despacho.js).
   - Responsáveis: busca na lista, pílulas dos escolhidos e a contagem.
   - Edição da mensagem antiga (sem código de registro), só do autor.
   - Acompanhar (L83-84): alterna sem sair da página; marcação PESSOAL, sem confirmação nem recarga.
   - Avisos (flash) do sistema: saem no aviso do desenho (L176), embaixo no centro, e somem em 3 segundos. */
(function () {
  'use strict';

  /* ── Janelas ── */
  function abrirJanela(janela, origem) {
    janela.hidden = false;
    janela.dispatchEvent(new CustomEvent('md:abrir', { detail: { origem: origem } }));
    var campo = janela.querySelector('input:not([type="hidden"]), textarea');
    if (campo) campo.focus();
  }

  function fecharJanela(janela) {
    janela.hidden = true;
  }

  function iniciarJanelas() {
    document.addEventListener('click', function (e) {
      var gatilho = e.target.closest('[data-md-abrir]');
      if (gatilho) {
        var janela = document.getElementById(gatilho.dataset.mdAbrir);
        if (janela) abrirJanela(janela, gatilho);
        return;
      }
      var fechar = e.target.closest('[data-md-fechar]');
      if (fechar) fecharJanela(fechar.closest('.md-modal'));
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.md-modal:not([hidden])').forEach(fecharJanela);
        return;
      }
      var gatilho = e.target.closest && e.target.closest('[data-md-abrir][role="button"]');
      if (gatilho && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault();
        var janela = document.getElementById(gatilho.dataset.mdAbrir);
        if (janela) abrirJanela(janela, gatilho);
      }
    });
  }

  /* ── Responsáveis ── */
  function iniciaisDeNome(nome) {
    return nome.split(' ').filter(Boolean)
      .map(function (p) { return p[0].toUpperCase(); })
      .slice(0, 2).join('');
  }

  function atualizarEscolhidos(janela) {
    var lugar = janela.querySelector('#responsaveisSelecionados');
    var contagem = janela.querySelector('#contadorSelecionados');
    var marcados = janela.querySelectorAll('.md-pessoa-marca:checked');
    lugar.textContent = '';
    contagem.textContent = marcados.length;
    marcados.forEach(function (marca) {
      var nome = marca.closest('.md-pessoa').querySelector('.md-pessoa-nome').textContent.trim();
      var pilula = document.createElement('span');
      pilula.className = 'md-escolhido';
      var iniciais = document.createElement('span');
      iniciais.className = 'md-pessoa-iniciais';
      iniciais.textContent = iniciaisDeNome(nome);
      pilula.appendChild(iniciais);
      pilula.appendChild(document.createTextNode(nome));
      lugar.appendChild(pilula);
    });
  }

  function iniciarResponsaveis() {
    var janela = document.getElementById('modalEditarResponsaveis');
    if (!janela) return;
    var busca = janela.querySelector('#buscaResponsavel');
    janela.addEventListener('md:abrir', function () {
      busca.value = '';
      busca.dispatchEvent(new Event('input'));
      atualizarEscolhidos(janela);
    });
    janela.querySelector('#listaResponsaveis').addEventListener('change', function (e) {
      if (!e.target.classList.contains('md-pessoa-marca')) return;
      e.target.closest('.md-pessoa').classList.toggle('is-ativo', e.target.checked);
      atualizarEscolhidos(janela);
    });
    busca.addEventListener('input', function () {
      var q = busca.value.toLowerCase().trim();
      var visiveis = 0;
      janela.querySelectorAll('#listaResponsaveis .md-pessoa').forEach(function (pessoa) {
        var mostra = !q || (pessoa.dataset.nome || '').includes(q);
        pessoa.hidden = !mostra;
        if (mostra) visiveis++;
      });
      janela.querySelector('#respSemResultado').hidden = visiveis > 0;
    });
  }

  /* ── Edição da mensagem antiga (só o autor; espelha _detalhes_obs) ── */
  function botao(texto, classe) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = classe;
    b.textContent = texto;
    return b;
  }

  function editarMensagem(btnEditar) {
    var conteudoDiv = document.getElementById('tarefa-msg-conteudo-' + btnEditar.dataset.msgId);
    if (!conteudoDiv) return;
    var campo = document.createElement('textarea');
    campo.className = 'despacho-texto-campo';
    campo.rows = 3;
    campo.maxLength = 5000;
    campo.value = btnEditar.dataset.conteudo;
    var confirmar = botao(BJ_T.t('metas.detalhe.js.salvar'), 'despacho-botao despacho-botao--principal');
    var cancelar = botao(BJ_T.t('metas.detalhe.js.cancelar'), 'despacho-botao');
    var acoes = document.createElement('div');
    acoes.className = 'despacho-linha despacho-acoes';
    acoes.appendChild(confirmar);
    acoes.appendChild(cancelar);
    conteudoDiv.hidden = true;
    conteudoDiv.after(campo);
    campo.after(acoes);
    // Transforma o campo recém-criado em editor (o auto-init só pega o DOM inicial).
    if (window.EditorRico) {
      var quill = EditorRico.montar(campo);
      if (quill) quill.focus();
    }
    campo.focus();
    btnEditar.disabled = true;

    function encerrar() {
      campo.remove();
      acoes.remove();
      conteudoDiv.hidden = false;
      btnEditar.disabled = false;
    }

    cancelar.addEventListener('click', encerrar);
    confirmar.addEventListener('click', function () {
      var novo = campo.value.trim();
      if (novo === '') { alert(BJ_T.t('metas.detalhe.js.mensagem_vazia')); return; }
      var dados = new FormData();
      dados.append('_token', btnEditar.dataset.csrf);
      dados.append('conteudo', novo);
      confirmar.disabled = true;
      fetch(btnEditar.dataset.url, { method: 'POST', body: dados, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (resp) {
          if (!resp.ok) {
            confirmar.disabled = false;
            alert(resp.d.erro || resp.d.mensagem || BJ_T.t('metas.detalhe.js.erro_editar'));
            return;
          }
          // `conteudoHtml` vem SANITIZADO do servidor.
          conteudoDiv.innerHTML = '<span class="editor-rico-conteudo"><span class="ql-editor">' + (resp.d.conteudoHtml || '') + '</span></span>';
          btnEditar.dataset.conteudo = resp.d.conteudo;
          btnEditar.dataset.conteudoHtml = resp.d.conteudoHtml || '';
          var em = btnEditar.parentElement.querySelector('.despacho-em');
          if (em && !em.querySelector('.tarefa-msg-editado')) {
            var marca = document.createElement('span');
            marca.className = 'ms-1 fst-italic tarefa-msg-editado';
            marca.textContent = BJ_T.t('metas.detalhe.js.editado');
            em.appendChild(marca);
          }
          encerrar();
        })
        .catch(function () {
          confirmar.disabled = false;
          alert(BJ_T.t('metas.detalhe.js.erro_rede'));
        });
    });
  }

  function iniciarEdicao() {
    var lista = document.getElementById('timelineList');
    if (!lista) return;
    lista.addEventListener('click', function (e) {
      var btn = e.target.closest('.btn-editar-msg');
      if (btn) editarMensagem(btn);
    });
  }

  /* ── Acompanhar ── */
  function iniciarAcompanhar() {
    var botaoAc = document.getElementById('btnAcompanharMeta');
    if (!botaoAc) return;
    botaoAc.addEventListener('click', function () {
      if (botaoAc.disabled) return;
      botaoAc.disabled = true;
      var dados = new FormData();
      dados.append('_token', botaoAc.dataset.token);
      fetch(botaoAc.dataset.url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: dados })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.sucesso) return;
          botaoAc.classList.toggle('is-ativo', d.acompanhando);
          botaoAc.setAttribute('aria-pressed', d.acompanhando ? 'true' : 'false');
          botaoAc.querySelector('i').className = d.acompanhando ? 'bi bi-bookmark-fill' : 'bi bi-bookmark';
          botaoAc.querySelector('span').textContent = d.acompanhando ? BJ_T.t('metas.detalhe.js.acompanhando') : BJ_T.t('metas.detalhe.js.acompanhar');
        })
        .finally(function () { botaoAc.disabled = false; });
    });
  }

  /* ── Avisos do sistema no aviso do desenho (L176) ── */
  function iniciarAvisosDoSistema() {
    document.querySelectorAll('.app-content-header .alert').forEach(function (aviso) {
      var icone = document.createElement('i');
      icone.className = aviso.classList.contains('alert-success') ? 'bi bi-check-circle' : 'bi bi-exclamation-circle';
      aviso.prepend(icone);
      setTimeout(function () { aviso.remove(); }, 3000);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    iniciarJanelas();
    iniciarResponsaveis();
    iniciarEdicao();
    iniciarAcompanhar();
    iniciarAvisosDoSistema();
  });
})();
