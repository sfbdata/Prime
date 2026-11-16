/* BlueJus · Dicas padronizadas (substitui a dica preta do navegador).
   Qualquer elemento com title="..." passa a mostrar a dica no padrão do sistema: fundo azul-claro, letra azul-escura,
   posicionada sem sair da tela e sem cobrir o próprio botão. */
(function () {
  if (window.__bjDica) return; window.__bjDica = true;
  const AZ = '#0a6fa0';
  let tip, alvo = null, timer = null;
  function criar() {
    tip = document.createElement('div');
    tip.setAttribute('role', 'tooltip');
    tip.style.cssText = 'position:fixed;z-index:2147483647;pointer-events:none;max-width:min(300px,calc(100vw - 16px));padding:6px 10px;border-radius:4px;background:#e5f1f7;color:#0b4f73;border:1px solid #b9d6e8;font:600 12px/1.4 Arial,Helvetica,sans-serif;box-shadow:0 4px 14px rgba(10,60,90,.14);white-space:pre-line;overflow-wrap:anywhere;opacity:0;transform:translateY(2px);transition:opacity .12s,transform .12s;left:0;top:0';
    document.body.appendChild(tip);
  }
  function texto(el) { const t = el.getAttribute('title'); if (t) { el.setAttribute('data-bj-tip', t); el.removeAttribute('title'); } return el.getAttribute('data-bj-tip') || ''; }
  function posicionar() {
    if (!alvo || !tip) return;
    const r = alvo.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight, m = 8;
    let x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
    if (y + h > innerHeight - m) y = r.top - h - 8;
    if (y < m) y = Math.min(innerHeight - h - m, r.bottom + 8);
    x = Math.max(m, Math.min(innerWidth - w - m, x));
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  function mostrar(el) {
    const t = texto(el); if (!t) return;
    if (!tip) criar();
    alvo = el; tip.textContent = t; tip.style.opacity = '0';
    requestAnimationFrame(() => { posicionar(); tip.style.opacity = '1'; tip.style.transform = 'translateY(0)'; });
  }
  function esconder() { clearTimeout(timer); alvo = null; if (tip) { tip.style.opacity = '0'; tip.style.transform = 'translateY(2px)'; } }
  document.addEventListener('mouseover', e => {
    const el = e.target.closest && e.target.closest('[title],[data-bj-tip]');
    if (!el || el === alvo) return;
    if (el.closest('svg') && el.tagName !== 'svg' && !el.hasAttribute('data-bj-tip') && el.tagName.toLowerCase() === 'title') return;
    if (el.closest('[data-tip-proprio]')) return;
    clearTimeout(timer); texto(el);
    timer = setTimeout(() => mostrar(el), 280);
  }, true);
  document.addEventListener('mouseout', e => { if (!alvo && !timer) return; const para = e.relatedTarget; if (alvo && para && alvo.contains(para)) return; const el = e.target.closest && e.target.closest('[data-bj-tip]'); if (el && para && el.contains(para)) return; esconder(); }, true);
  ['mousedown', 'keydown', 'wheel', 'scroll'].forEach(ev => addEventListener(ev, esconder, { capture: true, passive: true }));
  document.addEventListener('contextmenu', esconder, true);
})();
