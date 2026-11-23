/* BlueJus · "Ferramentas do escritório" no Meu Perfil (docs/specs/multiproduto-contratacao-e-voto.md, §9). O servidor
   decide tudo (só o dono, trava do Master, assembleia em andamento, última ferramenta, CSRF); aqui só o clique, a
   mensagem de recusa na própria seção e o recarregamento (o menu muda junto). Ao ligar, a página volta com o aviso dos
   Perfis. Sem innerHTML. */
(function () {
  'use strict';

  const secao = document.getElementById('mpFerramentas');
  if (!secao) return;
  const msg = secao.querySelector('[data-mp-fe-msg]');
  if (!msg) return;
  const botoes = Array.from(secao.querySelectorAll('[data-mp-fe-alternar]'));

  function mostrar(texto) {
    msg.textContent = texto;
    msg.classList.toggle('is-erro', !!texto);
    msg.hidden = !texto;
  }

  function travar(sim) {
    botoes.forEach(b => { b.disabled = sim; });
  }

  function recarregar(ligou) {
    const url = new URL(window.location.href);
    url.searchParams.delete('ferramenta_ligada');
    if (ligou) url.searchParams.set('ferramenta_ligada', '1');
    url.hash = 'mpFerramentas';
    // Só o hash mudando não recarrega: aí força o reload.
    if (url.search === window.location.search) {
      window.location.hash = 'mpFerramentas';
      window.location.reload();
      return;
    }
    window.location.assign(url.toString());
  }

  botoes.forEach(botao => {
    botao.addEventListener('click', () => {
      const ligar = botao.dataset.ligar === '1';
      travar(true);
      mostrar('');
      fetch(botao.dataset.url, {
        method: 'POST', credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json', Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': secao.dataset.token,
        },
        body: JSON.stringify({ ligar: ligar }),
      }).then(r => r.json().catch(() => ({})).then(d => ({ ok: r.ok && d.ok === true, dados: d || {} })))
        .then(r => {
          if (!r.ok) { travar(false); mostrar(r.dados.erro || secao.dataset.msgErro); return; }
          recarregar(ligar);
        }, () => { travar(false); mostrar(secao.dataset.msgErro); });
    });
  });
})();
