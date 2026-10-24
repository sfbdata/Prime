/* BlueJus · Página da meta (desenho 04B de 22/10). Template: templates/tarefa/show.html.twig.
   - Janelas (.md-modal): abrem pelo [data-md-abrir="id"] (clique, Enter ou espaço) e fecham no [data-md-fechar] e no
     Esc. Ao abrir, a janela recebe o evento 'md:abrir' com o elemento que a abriu (o Certificar usa, em meta-despacho.js).
     Aberta, o Tab fica preso dentro dela e a página não rola; ao fechar, o foco volta a quem a abriu.
   - Responsáveis: busca na lista, pílulas dos escolhidos e a contagem.
   - Anexo antigo do despacho: o clique simples abre no visualizador único (#previewDocModal); Ctrl, Cmd, Shift e o
     botão do meio seguem o link para outra aba.
   - Acompanhar (L83-84): alterna sem sair da página; marcação PESSOAL, sem confirmação nem recarga.
   - Avisos (flash) do sistema: embaixo no centro, empilhados. Só o de sucesso é o aviso do desenho (L176) e some em 3
     segundos (.is-toast); o de erro fica, com a cor de erro, até a pessoa fechar no X. */
(function () {
  'use strict';

  /* ── Janelas ── */
  var FOCAVEIS = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var origemDaJanela = null;

  function travarRolagem(travar) {
    [document.documentElement, document.body, document.querySelector('.app-main')].forEach(function (el) {
      if (el) el.style.overflow = travar ? 'hidden' : '';
    });
  }

  function abrirJanela(janela, origem) {
    origemDaJanela = origem || document.activeElement;
    janela.hidden = false;
    travarRolagem(true);
    janela.dispatchEvent(new CustomEvent('md:abrir', { detail: { origem: origem } }));
    var campo = janela.querySelector('input:not([type="hidden"]), textarea');
    if (campo) campo.focus();
  }

  function fecharJanela(janela) {
    if (!janela || janela.hidden) return;
    janela.hidden = true;
    if (document.querySelector('.md-modal:not([hidden])')) return;
    travarRolagem(false);
    if (origemDaJanela && typeof origemDaJanela.focus === 'function') origemDaJanela.focus();
    origemDaJanela = null;
  }

  /* O Tab e o Shift+Tab dão a volta dentro da janela aberta, sem sair para a página de trás. */
  function prenderTab(e) {
    var janela = document.querySelector('.md-modal:not([hidden])');
    if (!janela) return;
    var focaveis = Array.prototype.filter.call(janela.querySelectorAll(FOCAVEIS), function (el) {
      return el.getClientRects().length > 0;
    });
    if (!focaveis.length) { e.preventDefault(); return; }
    var primeiro = focaveis[0];
    var ultimo = focaveis[focaveis.length - 1];
    var dentro = janela.contains(document.activeElement);
    if (e.shiftKey && (!dentro || document.activeElement === primeiro)) {
      e.preventDefault();
      ultimo.focus();
    } else if (!e.shiftKey && (!dentro || document.activeElement === ultimo)) {
      e.preventDefault();
      primeiro.focus();
    }
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
      if (e.key === 'Tab') {
        prenderTab(e);
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

  /* ── Anexo antigo no visualizador único ── */
  function iniciarVisualizador() {
    var modal = document.getElementById('previewDocModal');
    if (!modal || !window.bootstrap) return;
    document.addEventListener('click', function (e) {
      var link = e.target.closest('a[data-md-visualizar]');
      if (!link || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      bootstrap.Modal.getOrCreateInstance(modal).show(link);
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

  /* ── Avisos do sistema: sucesso no aviso do desenho (L176), erro fica até fechar ── */
  function iniciarAvisosDoSistema() {
    document.querySelectorAll('.app-content-header .alert').forEach(function (aviso) {
      var sucesso = aviso.classList.contains('alert-success');
      var icone = document.createElement('i');
      icone.className = sucesso ? 'bi bi-check-circle' : 'bi bi-exclamation-circle';
      aviso.prepend(icone);
      if (!sucesso) return;
      aviso.classList.add('is-toast');
      setTimeout(function () { aviso.remove(); }, 3000);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    iniciarJanelas();
    iniciarResponsaveis();
    iniciarVisualizador();
    iniciarAcompanhar();
    iniciarAvisosDoSistema();
  });
})();
