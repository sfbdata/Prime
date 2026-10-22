/* BlueJus · Página da meta (desenho 04B de 22/10). Template: templates/tarefa/show.html.twig.
   - Janelas (.md-modal): abrem pelo [data-md-abrir="id"] (clique, Enter ou espaço) e fecham no [data-md-fechar] e no
     Esc. Ao abrir, a janela recebe o evento 'md:abrir' com o elemento que a abriu (o Certificar usa, em meta-despacho.js).
   - Responsáveis: busca na lista, pílulas dos escolhidos e a contagem.
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
    iniciarAcompanhar();
    iniciarAvisosDoSistema();
  });
})();
