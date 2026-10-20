/* BlueJus · Painel do Master, aba "Telas e recursos"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup nas linhas 299-309 e lógica nas 671-677;
   spec docs/specs/perfis-1.2-telas-e-recursos.md §3).
   - Estado inicial: data-recursos (as 19 linhas do catálogo com o estado efetivo, vindas do servidor).
   - Desligar: os dois prompt() do desenho (motivo; data AAAA-MM-DD, inválida vira "sem data"). Religar: um clique.
   - POST /master/recursos/{chave} (JSON, X-CSRF-Token master_recursos); a resposta traz o estado novo e a aba se
     redesenha sem recarregar. As duas linhas de "Contribua" dividem a chave e mudam juntas.
   Nenhuma decisão mora só aqui: o servidor recusa chave desconhecida e tela essencial. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-recursos]');
  if (!raiz) return;

  const LINHAS = JSON.parse(raiz.getAttribute('data-recursos') || '[]');
  const URL_BASE = raiz.getAttribute('data-url') || '';
  const TOKEN = raiz.getAttribute('data-token') || '';
  const ESTADO = {};
  LINHAS.forEach(l => { ESTADO[l.chave] = { ativo: l.ativo, motivo: l.motivo, ate: l.ate }; });

  const elGrupos = raiz.querySelector('[data-mp-rec-grupos]');
  const elResumo = raiz.querySelector('[data-mp-rec-resumo]');
  const elBusca = raiz.querySelector('[data-mp-rec-busca]');
  const elAviso = raiz.querySelector('[data-mp-rec-aviso]');
  let busca = '';
  let ocupado = false;
  let timerAviso = null;

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const dataBr = iso => String(iso).split('-').reverse().join('/');

  function avisar(texto) {
    elAviso.textContent = texto;
    elAviso.hidden = false;
    clearTimeout(timerAviso);
    timerAviso = setTimeout(() => { elAviso.hidden = true; }, 4000);
  }

  function info(e) {
    return (e.motivo ? e.motivo : 'Sem motivo informado') + (e.ate ? ' · volta em ' + dataBr(e.ate) : '');
  }

  function item(l) {
    const e = ESTADO[l.chave];
    const on = e.ativo;
    const desc = l.d || (l.tela ? 'Tela inteira' : 'Item do menu da foto');
    return '<button type="button" class="mp-rec-item' + (on ? '' : ' is-off') + '" data-chave="' + esc(l.chave) + '" aria-pressed="' + (on ? 'true' : 'false') + '">'
      + '<i class="bi ' + esc(l.ic) + ' mp-rec-item-ic"></i>'
      + '<span class="mp-rec-item-corpo"><span class="mp-rec-item-titulo">' + esc(l.t) + (l.base ? '<i class="bi bi-lock-fill" title="Essencial"></i>' : '') + '</span>'
      + '<span class="mp-rec-item-desc">' + esc(desc) + '</span>'
      + (on ? '' : '<span class="mp-rec-item-info">' + esc(info(e)) + '</span>') + '</span>'
      + '<span class="mp-rec-item-st">' + (on ? 'Ativo' : 'Desativado') + '</span>'
      + '<span class="mp-rec-chave"><span></span></span></button>';
  }

  function render() {
    const q = busca.toLowerCase();
    const grupos = {};
    LINHAS.filter(l => !q || (l.t + ' ' + l.g).toLowerCase().includes(q)).forEach(l => { (grupos[l.g] = grupos[l.g] || []).push(l); });
    const html = Object.keys(grupos).map(g => '<div class="mp-rec-grupo-titulo">' + esc(g) + ' · ' + grupos[g].length + '</div>'
      + '<div class="mp-rec-grade">' + grupos[g].map(item).join('') + '</div>').join('');
    elGrupos.innerHTML = html || '<div class="mp-rec-vazio">Nenhuma tela ou função encontrada.</div>';

    const off = LINHAS.filter(l => !ESTADO[l.chave].ativo).length;
    elResumo.textContent = off ? off + ' desativado(s) para os usuários' : 'Tudo ativo';
    elResumo.classList.toggle('is-off', off > 0);
  }

  function enviar(chave, corpo) {
    ocupado = true;
    raiz.querySelectorAll('.mp-rec-item').forEach(b => { b.disabled = true; });
    fetch(URL_BASE.replace('CHAVE', encodeURIComponent(chave)), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
      credentials: 'same-origin'
    })
      .then(r => r.json().catch(() => ({})).then(dados => ({ ok: r.ok, dados })))
      .then(({ ok, dados }) => {
        if (!ok) { avisar(dados.erro || 'Não foi possível alterar agora. Recarregue a página.'); return; }
        ESTADO[chave] = { ativo: dados.ativo, motivo: dados.motivo, ate: dados.ate };
      })
      .catch(() => avisar('Não foi possível alterar agora. Recarregue a página.'))
      .finally(() => { ocupado = false; render(); });
  }

  function alternar(chave) {
    const l = LINHAS.find(x => x.chave === chave);
    if (!l || ocupado) return;
    if (l.base) { avisar('Tela essencial: não pode ser desativada.'); return; }
    if (!ESTADO[chave].ativo) { enviar(chave, { ativo: true }); return; }

    const motivo = prompt('Motivo exibido aos usuários (opcional). Ex.: "Tela em preparação", "Revisão ética", "Correção de falha":', 'Tela em preparação');
    if (motivo === null) return;
    const ate = prompt('Reativar automaticamente em (AAAA-MM-DD), ou deixe em branco:', '') || '';
    enviar(chave, { ativo: false, motivo, ate: /^\d{4}-\d{2}-\d{2}$/.test(ate) ? ate : '' });
  }

  elGrupos.addEventListener('click', ev => {
    const botao = ev.target.closest('.mp-rec-item');
    if (botao) alternar(botao.getAttribute('data-chave'));
  });
  elBusca.addEventListener('input', () => { busca = elBusca.value; render(); });

  render();
})();
