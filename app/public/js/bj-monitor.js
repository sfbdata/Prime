/* BlueJus · Monitor de saúde. Mede no navegador do usuário logado: tempo de carregamento, erros de JavaScript,
   disponibilidade (HEAD na própria origem) e eventos que as telas registram (BJMonitor.reg('upload', {ok:false})).
   Cada medida vai ao servidor em POST /monitor/evento (spec docs/specs/master-desempenho.md, D2); o Master vê o
   resultado na seção Desempenho. Dado pessoal: nenhum além do usuário logado, que o servidor já conhece; a página
   vai só como caminho (sem query string), a mensagem de erro corta em 160 caracteres. Falha de envio é ignorada:
   o monitor nunca atrapalha a tela. Desenho: docs/design/redesign-25-10-26/bj-monitor.js. */
(function () {
  'use strict';
  if (window.BJMonitor) return;
  var tag = document.currentScript;
  var URL_EVENTO = tag && tag.dataset.url;
  var TOKEN = (tag && tag.dataset.token) || '';
  if (!URL_EVENTO) return;

  var MAX_POR_PAGINA = 20; // teto por página aberta: erro em laço não vira enxurrada
  var enviados = 0;

  function enviar(tipo, dados) {
    if (enviados >= MAX_POR_PAGINA) return;
    enviados++;
    var corpo = Object.assign({ tipo: tipo, pag: location.pathname }, dados || {});
    try {
      fetch(URL_EVENTO, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN },
        body: JSON.stringify(corpo),
        credentials: 'same-origin',
        keepalive: true
      }).catch(function () {});
    } catch (e) { /* o monitor nunca atrapalha a tela */ }
  }

  window.BJMonitor = { reg: enviar };

  addEventListener('error', function (e) {
    enviar('erro', { msg: String(e.message || 'Erro').slice(0, 160), src: String(e.filename || '').split('/').pop().slice(0, 60) });
  });
  addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    enviar('erro', { msg: String((r && r.message) || r || 'Promessa rejeitada').slice(0, 160) });
  });
  addEventListener('load', function () {
    setTimeout(function () {
      try {
        var n = performance.getEntriesByType('navigation')[0];
        if (n) enviar('carga', { ms: Math.round(n.loadEventEnd || n.duration) });
      } catch (e) { /* sem Navigation Timing */ }
      var t0 = performance.now();
      fetch(location.pathname, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { enviar('ping', { ok: r.ok, ms: Math.round(performance.now() - t0) }); })
        .catch(function () { enviar('ping', { ok: false, ms: 0 }); });
    }, 300);
  });
})();
