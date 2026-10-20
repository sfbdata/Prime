/* BlueJus · Menu do usuário, modal "Conta do escritório", Idioma, Ajuda e itens em preparação.
   Desenhos "03 - EXPEDIENTE" e "07 - Meu Perfil" (VS 18.10.26), bj-contas.js e bj-idioma.js.
   Marcação em templates/menu_usuario/*. O menu do avatar fica aberto ao ligar o tema escuro
   (data-bs-auto-close="outside"); os demais itens fecham o menu antes de agir. */
(function () {
  'use strict';

  const $ = (sel, raiz) => (raiz || document).querySelector(sel);
  const modal = id => { const el = document.getElementById(id); return el && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(el) : null; };

  function fecharMenu() {
    document.querySelectorAll('[data-mu-toggle]').forEach(t => { const d = window.bootstrap && bootstrap.Dropdown.getInstance(t); if (d) d.hide(); });
  }

  let avisoT = null;
  function aviso(texto) {
    const el = document.getElementById('muAviso'); if (!el) return;
    el.textContent = texto; el.hidden = false; el.classList.remove('saindo');
    clearTimeout(avisoT); avisoT = setTimeout(() => { el.classList.add('saindo'); setTimeout(() => { el.hidden = true; }, 200); }, 3200);
  }
  window.bjAviso = window.bjAviso || aviso;

  /* ---------- tema escuro (chave liga/desliga; persiste em localStorage 'tema', lido pelo anti-flash do base) ---------- */
  function sincronizarTema() {
    const escuro = document.documentElement.getAttribute('data-bs-theme') === 'dark', b = document.getElementById('toggleTema');
    if (b) { b.setAttribute('aria-checked', escuro ? 'true' : 'false'); b.classList.toggle('ligado', escuro); }
  }
  function alternarTema() {
    const novo = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', novo);
    try { localStorage.setItem('tema', novo); } catch (e) {}
    sincronizarTema();
  }

  /* ---------- Conta do escritório ---------- */
  let contas = 'nada'; // nada | carregando | pronto
  function carregarContas(painel) {
    if (contas !== 'nada') return;
    contas = 'carregando';
    const url = document.getElementById('modalContaEscritorio').dataset.urlContas;
    // Sessão expirada vira redirecionamento para o login: não pode cair dentro do modal.
    fetch(url, { credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => (r.ok && r.type !== 'opaqueredirect' ? r.text() : Promise.reject(r.status)))
      .then(html => { painel.innerHTML = html; contas = 'pronto'; })
      .catch(() => { contas = 'nada'; painel.innerHTML = '<div class="mu-nota">Não foi possível carregar as contas agora. Recarregue a página e tente de novo.</div>'; });
  }
  function abrirAba(aba) {
    const raiz = document.getElementById('modalContaEscritorio'); if (!raiz) return;
    if (!$('[data-mu-aba="' + aba + '"]', raiz)) aba = 'trocar';
    raiz.querySelectorAll('[data-mu-aba]').forEach(b => { const on = b.dataset.muAba === aba; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    raiz.querySelectorAll('[data-mu-painel]').forEach(p => { p.hidden = p.dataset.muPainel !== aba; });
    if (aba === 'trocar') carregarContas($('[data-mu-painel="trocar"]', raiz));
  }
  function abrirConta(aba) { abrirAba(aba); const m = modal('modalContaEscritorio'); if (m) m.show(); }

  function emPreparacao(nome) {
    const el = $('[data-mu-preparacao-nome]'); if (el) el.textContent = nome;
    const m = modal('modalEmPreparacao'); if (m) m.show();
  }

  const AJUDA = 'Atalhos: clique direito numa linha para ações, Esc fecha janelas, ⋮ abre as opções de cada área.';

  document.addEventListener('click', e => {
    const t = e.target;
    if (t.closest('#toggleTema')) { e.preventDefault(); return alternarTema(); }
    const x = t.closest('[data-mu-fechar-menu]');
    if (x) {
      e.preventDefault();
      const tg = x.closest('.dropdown') && x.closest('.dropdown').querySelector('[data-bs-toggle="dropdown"]');
      const d = tg && window.bootstrap && bootstrap.Dropdown.getInstance(tg);
      return d ? d.hide() : fecharMenu();
    }
    const conta = t.closest('[data-mu-conta]');
    if (conta) { e.preventDefault(); fecharMenu(); return abrirConta(conta.dataset.muConta); }
    const aba = t.closest('[data-mu-aba]');
    if (aba) return abrirAba(aba.dataset.muAba);
    if (t.closest('[data-mu-idioma]')) { e.preventDefault(); fecharMenu(); const m = modal('modalIdioma'); if (m) m.show(); return; }
    if (t.closest('[data-mu-ajuda]')) { e.preventDefault(); fecharMenu(); return aviso(AJUDA); }
    const prep = t.closest('[data-em-preparacao]');
    if (prep) { e.preventDefault(); fecharMenu(); return emPreparacao(prep.dataset.emPreparacao); }
    if (t.closest('[data-mu-sair]') && !window.confirm('Sair do BlueJus?')) { e.preventDefault(); return; }
    // Link que abre outra aba e o instalador (tratado no base) não navegam esta página: fecham o menu.
    if (t.closest('.mu-menu a[target="_blank"], #linkInstalarApp')) fecharMenu();
  });

  document.addEventListener('submit', e => {
    const f = e.target.closest('[data-confirmar]');
    if (f && !window.confirm(f.dataset.confirmar)) e.preventDefault();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sincronizarTema); else sincronizarTema();
})();
