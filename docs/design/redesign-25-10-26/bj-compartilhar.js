/* BlueJus · Botão discreto "Indicar o BlueJus" na tarja azul, antes do sininho.
   Sistema real (base.html.twig): <button class="bj-share" data-bj-share> antes do sino, chamando a janela de indicação. */
(function () {
  if (window.__bjShare) return; window.__bjShare = 1;
  const css = '.bjsh{width:34px;height:34px;flex:none;border:none;border-radius:3px;background:transparent;color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:16px;opacity:.9;transition:background .15s,opacity .15s}.bjsh:hover{background:rgba(255,255,255,.16);opacity:1}';
  function ponto(h) {
    const sino = h.querySelector('.bi-bell, .bi-bell-fill'); if (sino) { let el = sino; while (el.parentElement && el.parentElement !== h && getComputedStyle(el.parentElement).display !== 'flex') el = el.parentElement; return el.closest('button, a, div') || el; }
    const conta = h.querySelector('[title="Sua conta"], .bi-building'); if (conta) return conta.closest('[title="Sua conta"]') || conta.parentElement;
    return null;
  }
  function por() {
    const h = document.querySelector('header'); if (!h || h.querySelector('[data-bj-share]')) return;
    const alvo = ponto(h);
    if (!document.getElementById('bjsh-css')) { const s = document.createElement('style'); s.id = 'bjsh-css'; s.textContent = css; document.head.appendChild(s); }
    const b = document.createElement('button'); b.className = 'bjsh'; b.setAttribute('data-bj-share', ''); b.setAttribute('aria-label', 'Indicar o BlueJus'); b.title = 'Indicar o BlueJus'; b.setAttribute('data-tip', 'Indique o BlueJus e ganhe recompensa');
    b.innerHTML = '<i class="bi bi-share"></i>';
    b.onclick = e => { e.preventDefault(); if (window.BJContas && window.BJContas.indicar) window.BJContas.indicar(); else location.href = './08 - Contribua -  VS 21.10.26.dc.html#indique'; };
    if (alvo && alvo.parentElement) alvo.parentElement.insertBefore(b, alvo); else { b.style.marginLeft = 'auto'; h.appendChild(b); }
  }
  let n = 0; const tk = () => { por(); if (++n < 20) setTimeout(tk, 500); };
  if (document.readyState === 'loading') addEventListener('DOMContentLoaded', tk); else tk();
})();
