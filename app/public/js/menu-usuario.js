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
  /* Abas carregadas sob demanda: trocar (escritorio_contas) e conta (escritorio_equipe, só primário/Master). */
  const estadoAba = { trocar: 'nada', conta: 'nada' }; // nada | carregando | pronto
  const urlAba = { trocar: 'urlContas', conta: 'urlEquipe' };
  function carregarAba(aba, painel, forcar) {
    if (!painel || (!forcar && estadoAba[aba] !== 'nada') || estadoAba[aba] === 'carregando') return;
    estadoAba[aba] = 'carregando';
    const url = document.getElementById('modalContaEscritorio').dataset[urlAba[aba]];
    // Sessão expirada vira redirecionamento para o login: não pode cair dentro do modal.
    fetch(url, { credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'text/html' } })
      .then(r => (r.ok && r.type !== 'opaqueredirect' ? r.text() : Promise.reject(r.status)))
      .then(html => { painel.innerHTML = html; estadoAba[aba] = 'pronto'; })
      .catch(() => { estadoAba[aba] = 'nada'; painel.innerHTML = '<div class="mu-nota">Não foi possível carregar agora. Recarregue a página e tente de novo.</div>'; });
  }
  function abrirAba(aba) {
    const raiz = document.getElementById('modalContaEscritorio'); if (!raiz) return;
    if (!$('[data-mu-aba="' + aba + '"]', raiz)) aba = 'trocar';
    raiz.querySelectorAll('[data-mu-aba]').forEach(b => { const on = b.dataset.muAba === aba; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    raiz.querySelectorAll('[data-mu-painel]').forEach(p => { p.hidden = p.dataset.muPainel !== aba; });
    if (urlAba[aba]) carregarAba(aba, $('[data-mu-painel="' + aba + '"]', raiz));
  }

  /* ---------- equipe da conta: menu da pessoa (⋮ ou botão direito), desenho bj-contas.js abrirMnu ---------- */
  const SIT = { aprovado: 'aprovado', em_analise: 'em análise', suspenso: 'suspenso' };
  let focoAntesDoMenu = null;
  function fecharMenuMembro() {
    const m = document.getElementById('muMenuMembro'); if (!m) return;
    m.remove();
    if (focoAntesDoMenu && document.contains(focoAntesDoMenu)) focoAntesDoMenu.focus();
    focoAntesDoMenu = null;
  }
  function itemMenu(op, icone, texto, classe) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'mu-mm-item' + (classe ? ' ' + classe : ''); b.dataset.mmOp = op; b.setAttribute('role', 'menuitem');
    const i = document.createElement('span'); i.className = 'mu-mm-ic'; i.textContent = icone;
    b.append(i, document.createTextNode(texto));
    return b;
  }
  function abrirMenuMembro(linha, x, y) {
    fecharMenuMembro();
    const sit = linha.dataset.situacao, m = document.createElement('div');
    m.id = 'muMenuMembro'; m.className = 'mu-menu-membro'; m.setAttribute('role', 'menu'); m.dataset.membro = linha.dataset.membro; m.dataset.semX = '1';
    const topo = document.createElement('div'); topo.className = 'mu-mm-topo';
    topo.textContent = linha.dataset.nome + (linha.dataset.primario === '1' ? ' · primário' : ' · ' + (SIT[sit] || sit));
    m.append(topo);
    if (linha.dataset.acoes === '1') {
      if (sit !== 'aprovado') m.append(itemMenu('aprovado', '✓', sit === 'suspenso' ? 'Reativar (aprovar)' : 'Aprovar', 'verde'));
      if (sit !== 'em_analise') m.append(itemMenu('em_analise', '↺', 'Voltar para análise'));
      if (sit !== 'suspenso') m.append(itemMenu('suspenso', '⏸', 'Suspender acesso', 'ambar'));
      const sep = document.createElement('div'); sep.className = 'mu-mm-sep'; m.append(sep);
      m.append(itemMenu('demitir', '✕', 'Demitir (remover da conta)', 'vermelho'));
    } else {
      const v = document.createElement('div'); v.className = 'mu-mm-vazio'; v.textContent = 'Sem ações disponíveis'; m.append(v);
    }
    m.style.left = Math.min(x, innerWidth - 250) + 'px'; m.style.top = Math.min(y, innerHeight - 230) + 'px';
    focoAntesDoMenu = document.activeElement;
    document.body.append(m);
    const primeiro = m.querySelector('[data-mm-op]'); if (primeiro) primeiro.focus();
  }
  function executarMembro(op, menu) {
    const raiz = document.querySelector('.mu-minha-conta'); if (!raiz) return;
    const linha = raiz.querySelector('[data-membro="' + CSS.escape(menu.dataset.membro) + '"]'); if (!linha) return;
    const nome = linha.dataset.nome, demitir = op === 'demitir';
    if (demitir && !window.confirm('Demitir ' + nome + '? A pessoa perde o acesso a esta conta. É a mesma ação de "Remover do escritório" da tela de equipe.')) return;
    if (op === 'suspenso' && !window.confirm('Suspender o acesso de ' + nome + '? Os dados e o histórico continuam guardados.')) return;
    const url = (demitir ? raiz.dataset.urlDemitir : raiz.dataset.urlSituacao).replace(/\/0\//, '/' + encodeURIComponent(menu.dataset.membro) + '/');
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: demitir ? '{}' : JSON.stringify({ situacao: op }) })
      .then(r => r.json().then(j => (r.ok ? j : Promise.reject(j))))
      .then(j => { aviso(j.mensagem || 'Feito'); carregarAba('conta', document.querySelector('[data-mu-painel="conta"]'), true); })
      .catch(j => aviso((j && j.erro) || 'Não foi possível concluir agora.'));
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
    const op = t.closest('[data-mm-op]');
    if (op) { const menu = op.closest('#muMenuMembro'); fecharMenuMembro(); return executarMembro(op.dataset.mmOp, menu); }
    const botaoMembro = t.closest('[data-membro-menu]');
    if (botaoMembro) { e.preventDefault(); e.stopPropagation(); const r = botaoMembro.getBoundingClientRect(); return abrirMenuMembro(botaoMembro.closest('[data-membro]'), r.right - 240, r.bottom + 4); }
    if (!t.closest('#muMenuMembro')) fecharMenuMembro();
    if (t.closest('[data-mu-sair]') && !window.confirm('Sair do BlueJus?')) { e.preventDefault(); return; }
    // Link que abre outra aba e o instalador (tratado no base) não navegam esta página: fecham o menu.
    if (t.closest('.mu-menu a[target="_blank"], #linkInstalarApp')) fecharMenu();
  });

  document.addEventListener('contextmenu', e => {
    const linha = e.target.closest('.mu-minha-conta [data-membro]');
    if (!linha) return;
    e.preventDefault(); abrirMenuMembro(linha, e.clientX, e.clientY);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharMenuMembro(); });

  document.addEventListener('submit', e => {
    const f = e.target.closest('[data-confirmar]');
    if (f && !window.confirm(f.dataset.confirmar)) e.preventDefault();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sincronizarTema); else sincronizarTema();
})();
