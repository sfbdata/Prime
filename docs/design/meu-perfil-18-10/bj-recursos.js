/* BlueJus · Telas e recursos (liga/desliga pelo Master)
   Protótipo: estado em localStorage 'bj-recursos'. Sistema real: tabela recurso_estado (id, ativo, publico, motivo, ate, por, em)
   lida pelo backend; rotas de telas desativadas retornam a página "em manutenção" e os itens de menu nem são renderizados. */
(function () {
  if (window.BJRecursos) return;
  const K = 'bj-recursos';
  const REG = [
    { g: 'Telas', id: 'dashboard', t: 'Dashboard', ic: 'bi-speedometer2', arq: '01 - Dashboard' },
    { g: 'Telas', id: 'expedientes', t: 'Pasta (Expedientes)', ic: 'bi-folder2-open', arq: '02 - EXPEDIENTES' },
    { g: 'Telas', id: 'lista', t: 'Expediente · lista de pastas', ic: 'bi-list-ul', arq: '03 - EXPEDIENTE Lista', base: 1 },
    { g: 'Telas', id: 'metas', t: 'Minhas Metas', ic: 'bi-bullseye', arq: '04 - Minhas Metas' },
    { g: 'Telas', id: 'trabalho', t: 'Trabalho Externo', ic: 'bi-briefcase', arq: '06 - Trabalho Externo' },
    { g: 'Telas', id: 'peticionar', t: 'Peticionar', ic: 'bi-file-earmark-text', arq: '07 - Peticionar' },
    { g: 'Telas', id: 'meuperfil', t: 'Meu Perfil', ic: 'bi-person', arq: '07 - Meu Perfil', base: 1 },
    { g: 'Telas', id: 'contribua', t: 'Contribua', ic: 'bi-heart', arq: '08 - Contribua' },
    { g: 'Telas', id: 'pushpub', t: 'Push compartilhado (link público)', ic: 'bi-link-45deg', arq: 'Push Compartilhado' },
    { g: 'Telas', id: 'vagaspub', t: 'Vagas públicas (link)', ic: 'bi-megaphone', arq: 'Vagas Publicas' },
    { g: 'Menu do usuário', id: 'instalar', t: 'Instalar aplicativo', ic: 'bi-download' },
    { g: 'Menu do usuário', id: 'idioma', t: 'Idioma', ic: 'bi-translate' },
    { g: 'Menu do usuário', id: 'tema', t: 'Tema escuro', ic: 'bi-moon-stars' },
    { g: 'Menu do usuário', id: 'novidades', t: 'Novidades', ic: 'bi-stars' },
    { g: 'Menu do usuário', id: 'ajuda', t: 'Ajuda e atalhos', ic: 'bi-question-circle' },
    { g: 'Menu do usuário', id: 'privacidade', t: 'Política de Privacidade', ic: 'bi-shield-lock' },
    { g: 'Menu do usuário', id: 'contribua', t: 'Contribua (atalho)', ic: 'bi-heart', so: 1 },
    { g: 'Funções', id: 'chat', t: 'Chat I.A', ic: 'bi-chat-dots', d: 'Botão flutuante de conversas e assistente' },
    { g: 'Funções', id: 'assistente', t: 'Assistente de pastas', ic: 'bi-stars', d: 'Agente que lê e organiza pastas' }
  ];
  let S = {}; try { S = JSON.parse(localStorage.getItem(K)) || {}; } catch (e) {}
  const master = () => { const u = (window.BJContas && window.BJContas.usuario && window.BJContas.usuario()) || window.BLUEJUS_USUARIO_ATUAL || {}; const e = String(u.email || '').toLowerCase(); return e ? e === 'farlei.rocha@gmail.com' || !!u.master : true; };
  const vencido = r => r && r.ate && Date.now() > Date.parse(r.ate + 'T23:59:59');
  const ativo = id => { const r = S[id]; return !r || r.on !== false || vencido(r); };
  const grava = () => { localStorage.setItem(K, JSON.stringify(S)); aplicar(); };
  function set(id, on, o) { const it = REG.find(x => x.id === id); if (it && it.base && !on) return { ok: false, motivo: 'Esta tela é essencial e não pode ser desativada.' }; S[id] = Object.assign({}, S[id], o || {}, { on, por: 'Master', em: new Date().toISOString() }); if (on) { delete S[id].motivo; delete S[id].ate; } grava(); return { ok: true }; }
  function aplicar() {
    let st = document.getElementById('bjr-css'); if (!st) { st = document.createElement('style'); st.id = 'bjr-css'; (document.head || document.documentElement).appendChild(st); }
    const off = Object.keys(S).filter(id => !ativo(id));
    st.textContent = master() ? off.map(id => '[data-recurso="' + id + '"]{opacity:.45 !important;outline:1px dashed #c0392b;outline-offset:-2px}').join('') : off.map(id => '[data-recurso="' + id + '"]{display:none !important}').join('');
    guardaTela();
  }
  function telaAtual() { const p = decodeURIComponent(location.pathname.split('/').pop() || ''); return REG.find(x => x.arq && p.indexOf(x.arq) === 0); }
  function guardaTela() {
    const t = telaAtual(), ov = document.getElementById('bjr-ov'); if (!t || ativo(t.id)) { if (ov) ov.remove(); return; }
    if (master()) { if (!document.getElementById('bjr-faixa') && document.body) { const f = document.createElement('div'); f.id = 'bjr-faixa'; f.style.cssText = 'position:fixed;z-index:2147483100;left:50%;top:8px;transform:translateX(-50%);background:#c0392b;color:#fff;font:bold 12.5px Arial,Helvetica,sans-serif;padding:7px 14px;border-radius:4px;box-shadow:0 6px 18px rgba(0,0,0,.2);'; f.textContent = 'Esta tela está desativada para os usuários. Só o Master está vendo.'; document.body.appendChild(f); } return; }
    if (ov || !document.body) return; const r = S[t.id] || {}, o = document.createElement('div'); o.id = 'bjr-ov';
    o.style.cssText = 'position:fixed;inset:0;z-index:2147483200;background:#f3f6f8;display:flex;align-items:center;justify-content:center;padding:20px;font-family:Arial,Helvetica,sans-serif;color:#12242f;';
    o.innerHTML = '<div style="max-width:440px;text-align:center;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:30px 26px;box-shadow:0 10px 30px rgba(18,36,47,.08);"><div style="width:62px;height:62px;margin:0 auto 12px;border-radius:50%;background:#e5f1f7;color:#0a6fa0;display:flex;align-items:center;justify-content:center;font-size:28px;"><i class="bi bi-tools"></i></div><div style="font-size:19px;font-weight:bold;margin-bottom:6px;">' + t.t + ' em preparação</div><div style="font-size:14px;color:#4a6373;line-height:1.55;">' + (r.motivo ? r.motivo.replace(/</g, '&lt;') : 'Estamos finalizando esta tela para ela chegar com tudo funcionando. Volte em breve.') + (r.ate ? '<br><span style="font-size:12.5px;color:#7a8e9b;">Previsão: ' + r.ate.split('-').reverse().join('/') + '</span>' : '') + '</div><a href="./03 - EXPEDIENTE Lista 1.1.dc.html" style="display:inline-flex;align-items:center;gap:7px;margin-top:18px;height:38px;padding:0 16px;border-radius:3px;background:#0a6fa0;color:#fff;font-weight:bold;font-size:14px;text-decoration:none;">Voltar ao Expediente</a></div>';
    document.body.appendChild(o);
  }
  window.BJRecursos = { REG, ativo, set, estado: id => Object.assign({}, S[id]), master, aplicar };
  aplicar(); if (!document.body) addEventListener('DOMContentLoaded', aplicar);
  addEventListener('storage', e => { if (e.key === K) { try { S = JSON.parse(e.newValue) || {}; } catch (x) { S = {}; } aplicar(); } });
})();
