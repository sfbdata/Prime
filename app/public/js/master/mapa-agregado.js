/* BlueJus · carga do agregado do mapa (Painel do Master). O iframe NÃO confia na query string: ela só escolhe a
   visão (quem=pes|esc, usu=geral|prim). As contagens vêm de /master/dados/mapa e são validadas aqui (UF conhecida,
   números inteiros, nomes de cidade como texto curto sem < > " ' `). Também expõe escH, o escape único dos mapas. */
(() => {
  const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
  const escH = t => String(t == null ? '' : t).replace(/[&<>"'`]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[m]));
  const inteiro = v => { const n = Number(v); return Number.isInteger(n) && n >= 0 ? n : 0; };
  const nome = v => (typeof v === 'string' ? v.replace(/[<>"'`]/g, '').replace(/\s+/g, ' ').trim().slice(0, 80) : '');
  function validar(g) {
    const A = {}, C = {};
    if (g && typeof g.A === 'object' && g.A) Object.entries(g.A).forEach(([uf, n]) => { if (UFS.includes(uf) && inteiro(n)) A[uf] = inteiro(n); });
    if (g && typeof g.C === 'object' && g.C) Object.entries(g.C).forEach(([uf, cs]) => {
      if (!UFS.includes(uf) || !cs || typeof cs !== 'object') return;
      Object.entries(cs).forEach(([cidade, n]) => { const c = nome(cidade); if (c && inteiro(n)) { C[uf] = C[uf] || {}; C[uf][c] = (C[uf][c] || 0) + inteiro(n); } });
    });
    return { A, C };
  }
  function carregar(url) {
    const qs = new URLSearchParams(location.search), quem = qs.get('quem') === 'esc' ? 'esc' : 'pes', usu = qs.get('usu') === 'prim' ? 'prim' : 'geral';
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(m => validar(quem === 'esc' ? (m.escritorios || {}) : ((m.pessoas || {})[usu] || {})))
      .catch(() => ({ A: {}, C: {} }));
  }
  window.BJAgregado = { carregar, escH, validar };
})();
