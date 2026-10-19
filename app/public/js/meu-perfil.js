/* BlueJus · Meu Perfil (desenho "07 - Meu Perfil - VS 18.10.26"; spec docs/specs/meu-perfil-pagina.md).
   Modal "Editar informações" (vínculo troca a ajuda e as datas), copiar, preferências liga/desliga e a
   revisão mensal. O aviso (toast) é o do menu do usuário: window.bjAviso (public/js/menu-usuario.js). */
(function () {
  'use strict';

  const raiz = document.getElementById('meuPerfil');
  if (!raiz) return;
  const aviso = t => (window.bjAviso ? window.bjAviso(t) : null);
  const modal = id => { const el = document.getElementById(id); return el && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(el) : null; };

  /* ---------- modal Editar informações ---------- */
  const form = document.getElementById('formDadosPessoais');
  function vinculoAtual() {
    const marcado = form && form.querySelector('[data-mp-vinculo] input[type="radio"]:checked');
    return marcado ? marcado.value : 'clt';
  }
  function aplicarVinculo() {
    if (!form) return;
    const v = vinculoAtual(), marcado = form.querySelector('[data-mp-vinculo] input[type="radio"]:checked');
    form.querySelectorAll('.mp-vinculo-op').forEach(op => op.classList.toggle('on', op.querySelector('input').checked));
    form.querySelectorAll('[data-mp-para]').forEach(c => { c.hidden = !c.dataset.mpPara.split(' ').includes(v); });
    const ajuda = form.querySelector('[data-mp-ajuda-texto]');
    if (ajuda && marcado) ajuda.textContent = marcado.dataset.ajuda || '';
    const rotulo = form.querySelector('[data-mp-rotulo-inicio]');
    if (rotulo && marcado && marcado.dataset.rotuloInicio) rotulo.textContent = marcado.dataset.rotuloInicio;
  }
  if (form) { form.addEventListener('change', e => { if (e.target.matches('[data-mp-vinculo] input')) aplicarVinculo(); }); aplicarVinculo(); }

  /* ---------- preferências ---------- */
  function alternarPreferencia(botao) {
    const ligado = botao.getAttribute('aria-checked') !== 'true';
    botao.setAttribute('aria-checked', ligado ? 'true' : 'false');
    fetch(raiz.dataset.urlPreferencia, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ chave: botao.dataset.mpPref, ligado }),
    }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(j))))
      .catch(j => { botao.setAttribute('aria-checked', ligado ? 'false' : 'true'); aviso((j && j.erro) || 'Não foi possível gravar a preferência agora.'); });
  }

  /* ---------- revisão mensal (uma vez por mês; "já mostrei" fica no navegador) ---------- */
  const mes = new Date().toISOString().slice(0, 7);
  function revisaoPendente() {
    if ((raiz.dataset.revisadoEm || '').slice(0, 7) === mes) return false;
    try { const k = 'bj-perfil-revisao:' + raiz.dataset.usuario; if (localStorage.getItem(k) === mes) return false; localStorage.setItem(k, mes); } catch (e) { return false; }
    return true;
  }
  function confirmarRevisao() {
    fetch(raiz.dataset.urlRevisao, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject()))
      .then(() => { const m = modal('modalRevisao'); if (m) m.hide(); aviso('Obrigado! Cadastro confirmado para este mês.'); })
      .catch(() => aviso('Não foi possível confirmar agora. Tente de novo.'));
  }

  document.addEventListener('click', e => {
    const t = e.target;
    if (t.closest('[data-mp-editar]')) { const m = modal('modalDadosPessoais'); if (m) m.show(); return; }
    const copiar = t.closest('[data-mp-copiar]');
    if (copiar) {
      e.preventDefault();
      (navigator.clipboard ? navigator.clipboard.writeText(copiar.dataset.mpCopiar) : Promise.reject()).then(() => aviso('Copiado'), () => {});
      return;
    }
    const pref = t.closest('[data-mp-pref]');
    if (pref) return alternarPreferencia(pref);
    if (t.closest('[data-mp-revisao-ok]')) return confirmarRevisao();
    if (t.closest('[data-mp-revisao-ir]')) {
      const r = modal('modalRevisao'); if (r) r.hide();
      const ed = modal('modalDadosPessoais'); if (ed) setTimeout(() => ed.show(), 300);
    }
  });

  if (revisaoPendente()) setTimeout(() => { if (!document.querySelector('.modal.show')) { const m = modal('modalRevisao'); if (m) m.show(); } }, 1200);
})();
