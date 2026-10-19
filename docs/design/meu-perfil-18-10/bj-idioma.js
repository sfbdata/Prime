/* BlueJus · Idioma do sistema (protótipo)
   No protótipo: tradução automática de toda a interface (textos, placeholders, notas) com cache por idioma.
   No sistema real: Symfony Translation (translations/messages.<locale>.yaml + trans no Twig), locale salvo no perfil do usuário. */
(function () {
  if (window.BJIdioma) return;
  const K = 'bj-idioma', LANGS = [
    ['pt-BR', 'Português (Brasil)', 'PT'], ['en', 'English', 'EN'], ['es', 'Español', 'ES'], ['fr', 'Français', 'FR'],
    ['it', 'Italiano', 'IT'], ['de', 'Deutsch', 'DE'], ['ru', 'Русский', 'RU'], ['zh', '中文', 'ZH'], ['ja', '日本語', 'JA'], ['ar', 'العربية', 'AR']
  ];
  const atual = () => localStorage.getItem(K) || 'pt-BR';
  const nomeDe = c => (LANGS.find(l => l[0] === c) || LANGS[0])[1];
  const lang = atual();
  document.documentElement.lang = lang;
  const api = { LANGS, get: atual, nome: nomeDe, set, abrir };
  window.BJIdioma = api;

  function set(c) { if (c === atual()) return; localStorage.setItem(K, c); location.reload(); }

  function abrir() {
    let ov = document.getElementById('bji-ov'); if (ov) ov.remove();
    ov = document.createElement('div'); ov.id = 'bji-ov';
    ov.style.cssText = 'position:fixed;inset:0;z-index:100050;background:rgba(18,36,47,.35);display:flex;align-items:center;justify-content:center;padding:12px;font-family:Arial,Helvetica,sans-serif;';
    const cur = atual();
    ov.innerHTML = '<div data-bji-skip style="width:min(460px,100%);background:#fff;border-radius:4px;box-shadow:0 20px 50px rgba(18,36,47,.25);overflow:hidden;">'
      + '<div style="background:#0a6fa0;color:#fff;padding:13px 18px;display:flex;align-items:center;gap:10px;"><b style="flex:1;font-size:15px;">Idioma do sistema</b><button data-x style="width:30px;height:30px;border:none;border-radius:3px;background:rgba(255,255,255,.15);color:#fff;cursor:pointer;font-size:16px;">×</button></div>'
      + '<div style="padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">'
      + LANGS.map(l => '<button data-l="' + l[0] + '" style="display:flex;align-items:center;gap:10px;height:42px;padding:0 12px;border:1px solid ' + (l[0] === cur ? '#0a6fa0' : '#d3dde4') + ';border-radius:3px;background:' + (l[0] === cur ? '#e5f1f7' : '#fff') + ';cursor:pointer;font:inherit;font-size:14px;color:#12242f;text-align:left;"><span style="width:28px;height:20px;border-radius:2px;background:#0a6fa0;color:#fff;font-size:10.5px;font-weight:bold;display:flex;align-items:center;justify-content:center;flex:none;">' + l[2] + '</span><span style="flex:1;">' + l[1] + '</span>' + (l[0] === cur ? '<span style="color:#0a6fa0;font-weight:bold;">✓</span>' : '') + '</button>').join('')
      + '</div><div style="padding:0 18px 16px;font-size:12px;color:#5b7383;line-height:1.5;">Vale só para você e muda todas as telas, menus, avisos e o Chat I.A. Nomes de pessoas, números de processo, CPF e citações de lei continuam como estão. Datas e valores seguem o formato do idioma escolhido.</div></div>';
    document.body.appendChild(ov);
    ov.onclick = e => { if (e.target === ov || e.target.closest('[data-x]')) ov.remove(); const b = e.target.closest('[data-l]'); if (b) set(b.dataset.l); };
  }

  if (lang === 'pt-BR') return;
  if (lang === 'ar') document.documentElement.dir = 'rtl';

  /* ---------- tradução automática da interface ---------- */
  const CK = 'bj-i18n-' + lang; let M = {}; try { M = JSON.parse(localStorage.getItem(CK)) || {}; } catch (e) {}
  const ORIG = new WeakMap(), ATTR = ['placeholder', 'title', 'data-tip', 'aria-label'];
  const pular = el => !el || el.closest('script,style,textarea,code,pre,[data-bji-skip],[contenteditable="true"]');
  const traduzivel = t => { const s = t.trim(); return s.length > 1 && /[A-Za-zÀ-ÿ]{2}/.test(s) && !/^[\w.+-]+@[\w-]+\.[\w.]+$/.test(s) && !/^\d[\d./-]*$/.test(s); };
  let fila = new Set(), timer = null, ocupado = false;
  function aplicarNo(n) {
    if (n.nodeType === 3) { const p = n.parentElement; if (pular(p)) return; let o = ORIG.get(n); const v = n.nodeValue; if (o === undefined || (v !== M[o.trim()] && v.trim() !== (M[o.trim()] || '').trim())) { o = v; ORIG.set(n, o); } const k = o.trim(); if (!traduzivel(k)) return; if (M[k]) { const t = o.replace(k, M[k]); if (n.nodeValue !== t) n.nodeValue = t; } else fila.add(k); return; }
    if (n.nodeType !== 1 || pular(n)) return;
    ATTR.forEach(a => { if (!n.hasAttribute(a)) return; const ka = 'bjiO' + a.replace(/-/g, ''); let o = n.dataset[ka]; const v = n.getAttribute(a); if (!o || (v !== M[o] && v !== o)) { o = v; n.dataset[ka] = o; } const k = o.trim(); if (!traduzivel(k)) return; if (M[k]) { if (v !== M[k]) n.setAttribute(a, M[k]); } else fila.add(k); });
    if (n.tagName === 'INPUT' && /^(button|submit)$/i.test(n.type) && traduzivel(n.value)) { const k = n.value.trim(); if (M[k]) n.value = M[k]; else fila.add(k); }
    for (let c = n.firstChild; c; c = c.nextSibling) aplicarNo(c);
  }
  function varrer() { aplicarNo(document.body); if (fila.size) pedir(); }
  async function pedir() {
    if (ocupado || !fila.size) return; if (!window.claude || !window.claude.complete) { selo('Tradução automática disponível no sistema publicado'); return; }
    ocupado = true; selo('Traduzindo para ' + nomeDe(lang) + '…');
    const lote = [...fila].slice(0, 70); lote.forEach(s => fila.delete(s));
    const prompt = 'Translate each Brazilian Portuguese UI string of a legal-office management software (BlueJus) into ' + nomeDe(lang) + ' (' + lang + '). Keep it short like a UI label, keep capitalization style (ALL CAPS stays ALL CAPS), keep placeholders like {x}, numbers, people names, company names, case numbers, CPF/CNPJ, e-mails and law citations (e.g. "Lei nº 8.906/1994") unchanged. Brand "BlueJus" unchanged. Return ONLY a JSON object mapping each original string to its translation.\n\n' + JSON.stringify(lote);
    try { const r = await window.claude.complete(prompt), j = JSON.parse((r.match(/\{[\s\S]*\}/) || ['{}'])[0]); Object.keys(j).forEach(k => { if (typeof j[k] === 'string' && j[k].trim()) M[k] = j[k]; }); lote.forEach(k => { if (!M[k]) M[k] = k; }); try { localStorage.setItem(CK, JSON.stringify(M)); } catch (e) {} }
    catch (e) { lote.forEach(k => fila.add(k)); }
    ocupado = false; aplicarNo(document.body); if (fila.size) setTimeout(pedir, 150); else selo('');
  }
  function selo(t) { let s = document.getElementById('bji-selo'); if (!t) { if (s) s.remove(); return; } if (!s) { s = document.createElement('div'); s.id = 'bji-selo'; s.setAttribute('data-bji-skip', ''); s.style.cssText = 'position:fixed;z-index:100040;left:16px;bottom:16px;background:#0a6fa0;color:#fff;font:bold 12.5px Arial,Helvetica,sans-serif;padding:8px 12px;border-radius:4px;box-shadow:0 6px 18px rgba(0,0,0,.2);pointer-events:none;'; document.body.appendChild(s); } s.textContent = t; }
  const obs = new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(varrer, 120); });
  const ini = () => { varrer(); obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTR }); };
  if (document.body) ini(); else addEventListener('DOMContentLoaded', ini);

  /* datas e números no formato do idioma */
  const LOC = { en: 'en-US', es: 'es-ES', fr: 'fr-FR', it: 'it-IT', de: 'de-DE', ru: 'ru-RU', zh: 'zh-CN', ja: 'ja-JP', ar: 'ar' }[lang];
  if (LOC) { const d0 = Date.prototype.toLocaleDateString, d1 = Date.prototype.toLocaleString, t0 = Date.prototype.toLocaleTimeString, n0 = Number.prototype.toLocaleString;
    Date.prototype.toLocaleDateString = function (l, o) { return d0.call(this, l === 'pt-BR' ? LOC : l, o); };
    Date.prototype.toLocaleString = function (l, o) { return d1.call(this, l === 'pt-BR' ? LOC : l, o); };
    Date.prototype.toLocaleTimeString = function (l, o) { return t0.call(this, l === 'pt-BR' ? LOC : l, o); };
    Number.prototype.toLocaleString = function (l, o) { return n0.call(this, l === 'pt-BR' ? LOC : l, o); }; }
})();
