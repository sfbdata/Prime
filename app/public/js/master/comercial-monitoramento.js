/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Monitoramento", fatia 2b
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup L.282-286 e lógica L.586;
   spec docs/specs/comercial-e-emails.md §8.4 e §8.6).
   - A lista das 12 últimas campanhas vem do servidor: nome, "modelo · público[ · frequência]", data, total e estado.
   - Os 3 estados do dc têm o selo com as cores dele ("Na fila", "Agendada", "Recorrente"); os demais vão em texto.
   - As métricas são da fatia 3: a linha diz "Métricas: aguarda servidor", como o dc.
   - Recarrega quando a seção abre e depois de cada "Enviar campanha" aceito (evento `mc:campanha-confirmada`). */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mc-monitoramento]');
  if (!raiz) return;
  const lista = raiz.querySelector('[data-mc-camps]');
  const COM_SELO = ['na_fila', 'agendada', 'recorrente'];
  let carregado = false;

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  function linha(c) {
    const selo = COM_SELO.includes(c.estado) ? 'mc-camp-st-' + c.estado : 'mc-camp-st-texto';
    return '<div class="mc-camp">'
      + '<span class="mc-camp-nome"><b>' + esc(c.nome) + '</b><span class="mc-camp-sub">' + esc(c.sub) + '</span></span>'
      + '<span class="mc-camp-em">' + esc(c.em) + '</span>'
      + '<b class="mc-camp-total">' + esc(c.total) + '</b>'
      + '<span class="mc-camp-st ' + selo + '">' + esc(c.rotulo) + '</span>'
      + '<span class="mc-camp-met"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Métricas: aguarda servidor</span>'
      + '</div>';
  }

  function carregar() {
    return fetch(raiz.dataset.urlCampanhas, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => {
        carregado = true;
        const campanhas = d.campanhas || [];
        lista.innerHTML = campanhas.length ? campanhas.map(linha).join('') : '<div class="mc-camps-vazio">Nenhuma campanha ainda.</div>';
        lista.removeAttribute('aria-busy');
      })
      .catch(() => { lista.innerHTML = '<div class="mc-camps-vazio">Não foi possível carregar as campanhas. Recarregue a página.</div>'; });
  }

  document.addEventListener('mc:campanha-confirmada', carregar);
  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
})();
