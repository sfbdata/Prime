/* BlueJus · Painel do Master, aba "Telas e recursos"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup nas linhas 299-309 e lógica nas 671-677;
   spec docs/specs/perfis-1.2-telas-e-recursos.md §3).
   - Estado inicial: data-recursos (as 19 linhas do catálogo com o estado efetivo, vindas do servidor).
   - Desligar: os dois prompt() do desenho (motivo; data AAAA-MM-DD, inválida vira "sem data"). Religar: um clique.
   - POST /master/recursos/{chave} (JSON, X-CSRF-Token master_recursos); a resposta traz o estado novo e a aba se
     redesenha sem recarregar. As duas linhas de "Contribua" dividem a chave e mudam juntas.
   - Textos no catálogo (master.js.recursos.*, entregue pelo painel com catalogo_js); datas por window.BJFormato. O
     nome, o grupo e a descrição de cada linha vêm do servidor.
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

  // As aspas pelo código (\x22, \x27): o literal delas desalinha a leitura das strings do detector de texto fixo.
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  const T = (chave, params) => window.BJ_T.t(chave, params);
  const dataBr = iso => { const p = String(iso).split('-'); return p.length === 3 ? window.BJFormato.data(new Date(+p[0], +p[1] - 1, +p[2])) : String(iso); };

  function avisar(texto) {
    elAviso.textContent = texto;
    elAviso.hidden = false;
    clearTimeout(timerAviso);
    timerAviso = setTimeout(() => { elAviso.hidden = true; }, 4000);
  }

  function info(e) {
    return (e.motivo ? e.motivo : T('master.js.recursos.sem_motivo')) + (e.ate ? ' · ' + T('master.js.recursos.volta_em', { data: dataBr(e.ate) }) : '');
  }

  function item(l) {
    const e = ESTADO[l.chave];
    const on = e.ativo;
    const desc = l.d || (l.tela ? T('master.js.recursos.tela_inteira') : T('master.js.recursos.item_menu'));
    return '<button type="button" class="mp-rec-item' + (on ? '' : ' is-off') + '" data-chave="' + esc(l.chave) + '" aria-pressed="' + (on ? 'true' : 'false') + '">'
      + '<i class="bi ' + esc(l.ic) + ' mp-rec-item-ic"></i>'
      + '<span class="mp-rec-item-corpo"><span class="mp-rec-item-titulo">' + esc(l.t) + (l.base ? '<i class="bi bi-lock-fill" title="' + esc(T('master.js.recursos.essencial')) + '"></i>' : '') + '</span>'
      + '<span class="mp-rec-item-desc">' + esc(desc) + '</span>'
      + (on ? '' : '<span class="mp-rec-item-info">' + esc(info(e)) + '</span>') + '</span>'
      + '<span class="mp-rec-item-st">' + esc(on ? T('master.js.recursos.ativo') : T('master.js.recursos.desativado')) + '</span>'
      + '<span class="mp-rec-chave"><span></span></span></button>';
  }

  function render() {
    const q = busca.toLowerCase();
    const grupos = {};
    LINHAS.filter(l => !q || (l.t + ' ' + l.g).toLowerCase().includes(q)).forEach(l => { (grupos[l.g] = grupos[l.g] || []).push(l); });
    const html = Object.keys(grupos).map(g => '<div class="mp-rec-grupo-titulo">' + esc(g) + ' · ' + grupos[g].length + '</div>'
      + '<div class="mp-rec-grade">' + grupos[g].map(item).join('') + '</div>').join('');
    elGrupos.innerHTML = html || '<div class="mp-rec-vazio">' + esc(T('master.js.recursos.vazio')) + '</div>';

    const off = LINHAS.filter(l => !ESTADO[l.chave].ativo).length;
    elResumo.textContent = off ? T('master.js.recursos.desativados', { n: off }) : T('master.js.recursos.tudo_ativo');
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
        if (!ok) { avisar(dados.erro || T('master.js.recursos.erro')); return; }
        ESTADO[chave] = { ativo: dados.ativo, motivo: dados.motivo, ate: dados.ate };
      })
      .catch(() => avisar(T('master.js.recursos.erro')))
      .finally(() => { ocupado = false; render(); });
  }

  function alternar(chave) {
    const l = LINHAS.find(x => x.chave === chave);
    if (!l || ocupado) return;
    if (l.base) { avisar(T('master.js.recursos.essencial_recusa')); return; }
    if (!ESTADO[chave].ativo) { enviar(chave, { ativo: true }); return; }

    const motivo = prompt(T('master.js.recursos.pergunta_motivo'), T('master.js.recursos.motivo_padrao'));
    if (motivo === null) return;
    const ate = prompt(T('master.js.recursos.pergunta_data'), '') || '';
    enviar(chave, { ativo: false, motivo, ate: /^\d{4}-\d{2}-\d{2}$/.test(ate) ? ate : '' });
  }

  elGrupos.addEventListener('click', ev => {
    const botao = ev.target.closest('.mp-rec-item');
    if (botao) alternar(botao.getAttribute('data-chave'));
  });
  elBusca.addEventListener('input', () => { busca = elBusca.value; render(); });

  render();
})();
