/* BlueJus · Janela da trava "Sua I.A. gratuita acabou" (spec ia-liberada-por-indicacao, seção 6).
   Marcação em templates/inteligencia/_janela_saldo_esgotado.html.twig. Expõe window.BJIaSaldo.recusou(dados):
   devolve true (e abre a janela) quando a recusa da I.A. traz o motivo "saldo_da_pessoa_esgotado". O motivo vem em
   `motivo` (controllers da pasta, Central) ou em `codigo` (Chat I.A.). Texto do servidor entra só por textContent.
   Link e texto do convite são os da janela "Indicar o BlueJus" (GET indicacao_janela), buscados ao abrir para que
   copiar e abrir o WhatsApp rodem dentro do gesto do clique. */
(function () {
  'use strict';

  if (window.BJIaSaldo) { return; }

  var MOTIVO = 'saldo_da_pessoa_esgotado';
  var estado = null;

  function janela() { return document.querySelector('[data-ia-saldo-janela]'); }
  function q(j, sel) { return j.querySelector(sel); }

  function aviso(j, texto) {
    var el = q(j, '[data-ia-saldo-aviso]');
    el.textContent = texto || '';
    el.hidden = !texto;
  }

  function pedir(j, url, opcoes) {
    var cab = { Accept: 'application/json' };
    if (opcoes && opcoes.method === 'POST') { cab['Content-Type'] = 'application/json'; cab['X-CSRF-Token'] = j.dataset.token; }
    return fetch(url, Object.assign({ credentials: 'same-origin', redirect: 'manual', headers: cab }, opcoes || {}))
      .then(function (r) {
        if (r.type === 'opaqueredirect') { return Promise.reject(new Error('sessao')); }
        return r.json().then(function (d) { return { status: r.status, dados: d }; });
      });
  }

  function trocarTextos(j, alternativo) {
    ['[data-ia-saldo-titulo]', '[data-ia-saldo-texto-janela]'].forEach(function (sel) {
      var el = q(j, sel);
      if (el.dataset.original === undefined) { el.dataset.original = el.textContent; }
      el.textContent = alternativo ? el.dataset.alternativo : el.dataset.original;
    });
  }

  function carregar(j) {
    estado = null;
    ['[data-ia-saldo-copiar]', '[data-ia-saldo-whatsapp]'].forEach(function (s) { q(j, s).disabled = true; });
    return pedir(j, j.dataset.urlJanela).then(function (r) {
      if (r.status !== 200) { throw new Error('janela'); }
      estado = r.dados;
      var bloqueio = (estado.pendencias && estado.pendencias.length) ? (estado.textoDasPendencias || '') : (estado.trava || '');
      if (bloqueio) { aviso(j, bloqueio); return; }
      ['[data-ia-saldo-copiar]', '[data-ia-saldo-whatsapp]'].forEach(function (s) { q(j, s).disabled = false; });
    }).catch(function () { aviso(j, j.dataset.textoFalhou); });
  }

  function abrir(alternativo) {
    var j = janela();
    if (!j) { return false; }
    if (j.parentNode !== document.body) { document.body.appendChild(j); }
    trocarTextos(j, !!alternativo);
    aviso(j, '');
    // Sem a janela "Indicar o BlueJus" na página (Chat I.A.), o botão de e-mail não tem para onde ir.
    q(j, '[data-ia-saldo-email]').hidden = !document.getElementById('modalIndicacao');
    j.hidden = false;
    carregar(j);
    var primeiro = q(j, '[data-ia-saldo-copiar]');
    if (primeiro) { primeiro.focus(); }
    return true;
  }

  function fechar() {
    var j = janela();
    if (j) { j.hidden = true; }
  }

  function registrar(j, url) {
    pedir(j, url, { method: 'POST', body: '{}' }).catch(function () { /* o registro é só estatística */ });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var j = janela();
    if (t.closest('[data-ia-saldo-abrir]')) { e.preventDefault(); abrir(true); return; }
    if (!j || j.hidden || !j.contains(t)) { return; }
    if (t === j || t.closest('[data-ia-saldo-fechar]')) { fechar(); return; }
    if (t.closest('[data-ia-saldo-email]')) { fechar(); return; }   // o data-ind-abrir do botão abre a outra janela
    if (!estado) { return; }
    if (t.closest('[data-ia-saldo-copiar]')) {
      if (navigator.clipboard) { navigator.clipboard.writeText(estado.link).catch(function () {}); }
      aviso(j, j.dataset.textoCopiado);
      registrar(j, j.dataset.urlLink);
      return;
    }
    if (t.closest('[data-ia-saldo-whatsapp]')) {
      window.open('https://wa.me/?text=' + encodeURIComponent(estado.mensagem), '_blank', 'noopener');
      registrar(j, j.dataset.urlWhatsapp);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { fechar(); }
  });

  window.BJIaSaldo = {
    recusou: function (dados) {
      var motivo = dados && (dados.motivo || dados.codigo);
      if (motivo !== MOTIVO) { return false; }
      return abrir(false);
    }
  };
})();
