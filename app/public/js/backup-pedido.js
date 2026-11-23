/* BlueJus · "Gerar backup" no Meu Perfil (docs/specs/backup-e-exportacao-de-dados.md, seção 6). O servidor decide tudo
   (um pedido aberto por vez, 1 por dia, CSRF); aqui só o clique, a mensagem na própria seção e o recarregamento. Sem innerHTML. */
(function () {
  'use strict';

  const secao = document.getElementById('mpBackup');
  if (!secao) return;
  const botao = secao.querySelector('[data-mp-bk-gerar]');
  const msg = secao.querySelector('[data-mp-bk-msg]');
  if (!botao || !msg) return;

  function mostrar(texto, erro) {
    msg.textContent = texto;
    msg.classList.toggle('is-erro', !!erro);
    msg.hidden = !texto;
  }

  botao.addEventListener('click', () => {
    botao.disabled = true;
    mostrar('', false);
    const corpo = new FormData();
    corpo.append('_token', secao.dataset.token);

    fetch(secao.dataset.urlPedir, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      body: corpo,
    }).then(r => r.json().catch(() => ({})).then(d => ({ ok: r.ok && d.ok === true, dados: d || {} })))
      .then(r => {
        if (!r.ok) { botao.disabled = false; mostrar(r.dados.erro || secao.dataset.msgErro, true); return; }
        mostrar(r.dados.aviso || '', false);
        setTimeout(() => window.location.reload(), 1200);
      }, () => { botao.disabled = false; mostrar(secao.dataset.msgErro, true); });
  });
})();
