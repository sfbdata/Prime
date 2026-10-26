/* BlueJus · Monitor de saúde (protótipo). Mede no navegador: tempo de carregamento, erros de JavaScript,
   disponibilidade (ping na própria origem) e eventos registrados pelas telas (falha de login, falha de upload).
   Sistema real: o backend expõe GET /master/saude (uptime, p95, erros, backups, CPU/memória) e este arquivo só complementa. */
(function () {
  if (window.BJMonitor) return;
  const K = 'bj-monitor', MAX = 600;
  const ler = () => { try { return JSON.parse(localStorage.getItem(K)) || []; } catch (e) { return []; } };
  const reg = (tipo, o) => { try { const L = ler(); L.unshift(Object.assign({ tipo, em: Date.now(), pag: decodeURIComponent(location.pathname.split('/').pop() || '').replace('.dc.html', '') }, o || {})); localStorage.setItem(K, JSON.stringify(L.slice(0, MAX))); } catch (e) {} };
  window.BJMonitor = { reg, ler, limpar: () => localStorage.removeItem(K) };
  addEventListener('error', e => reg('erro', { msg: String(e.message || 'Erro').slice(0, 160), src: String(e.filename || '').split('/').pop().slice(0, 60) }));
  addEventListener('unhandledrejection', e => reg('erro', { msg: String((e.reason && e.reason.message) || e.reason || 'Promessa rejeitada').slice(0, 160) }));
  addEventListener('load', () => setTimeout(() => {
    try { const n = performance.getEntriesByType('navigation')[0]; if (n) reg('carga', { ms: Math.round(n.loadEventEnd || n.duration) }); } catch (e) {}
    const t0 = performance.now(); fetch(location.href.split('#')[0], { method: 'HEAD', cache: 'no-store' }).then(r => reg('ping', { ok: r.ok, ms: Math.round(performance.now() - t0) })).catch(() => reg('ping', { ok: false, ms: 0 }));
  }, 300));
})();
