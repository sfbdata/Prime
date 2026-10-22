/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Datas comemorativas" (F4)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, markup L335-339 e lógica
   L678-682; spec docs/specs/comercial-e-emails.md §13).
   - Carrega na primeira vez que a seção "com" abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Cada linha: o interruptor, o selo (dia da semana e dd/mm), o ícone, o nome com "em N dias · envio dd/mm às 9h"
     (laranja a 7 dias ou menos), a antecedência (No dia, 1 dia antes, 3 dias antes), o "Para quem" e o "Ver mensagem".
   - Mudar qualquer um: POST JSON com X-CSRF-Token (o `master_comercial` da raiz [data-mc]) com o estado inteiro da
     linha; depois recarrega a lista (a ordem e o "N ativas" vêm do servidor).
   - "Ver mensagem" abre o modelo `data-<id>` na Central de envios (evento `mc:modelo-abrir`, dc L680 `cmM`) com o
     aviso "Prévia da mensagem de X. Use Duplicar para personalizar o texto.".
   Nenhuma regra mora só aqui: o servidor recusa chave desconhecida, antecedência e público fora da lista. */
(() => {
  'use strict';
  const caixa = document.querySelector('[data-mc-datas]');
  const raiz = caixa ? caixa.closest('[data-mc]') : null;
  if (!caixa || !raiz) return;

  const URL_LISTA = caixa.dataset.urlLista;
  const URL_ALTERAR = caixa.dataset.urlAlterar;
  const TOKEN = raiz.dataset.token || '';
  const elLista = caixa.querySelector('[data-mc-dt-lista]');
  const elMsg = caixa.querySelector('[data-mc-dt-msg]');
  // dc L681: as três antecedências; dc L679: os três públicos.
  const ANTECEDENCIAS = [[0, BJ_T.t('comercial.js.datas_ant_0')], [1, BJ_T.t('comercial.js.datas_ant_1')], [3, BJ_T.t('comercial.js.datas_ant_3')]];
  const PUBLICOS = [['todos', BJ_T.t('comercial.js.datas_pub_todos')], ['prim', BJ_T.t('comercial.js.datas_pub_prim')], ['leads', BJ_T.t('comercial.js.datas_pub_leads')]];
  // O `weekday: 'short'` do pt-BR sem o ponto (dc L679), de domingo (0) a sábado.
  const SEMANA = [
    BJ_T.t('comercial.js.datas_sem_0'), BJ_T.t('comercial.js.datas_sem_1'), BJ_T.t('comercial.js.datas_sem_2'), BJ_T.t('comercial.js.datas_sem_3'),
    BJ_T.t('comercial.js.datas_sem_4'), BJ_T.t('comercial.js.datas_sem_5'), BJ_T.t('comercial.js.datas_sem_6'),
  ];
  let datas = [];
  let carregado = false;
  let ocupado = false;

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const icone = ic => (/^bi-[a-z0-9-]{1,40}$/.test(ic || '') ? ic : 'bi-calendar');
  // "2026-12-25" vira "25/12" (dc L679, `day: '2-digit', month: '2-digit'`).
  const ddmm = iso => { const p = String(iso || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] : ''; };

  function avisar(texto) {
    elMsg.textContent = texto;
    elMsg.hidden = !texto;
  }

  // dc L680: "hoje", "amanhã" ou "em N dias".
  function falta(n) {
    if (n === 0) return BJ_T.t('comercial.js.datas_hoje');
    if (n === 1) return BJ_T.t('comercial.js.datas_amanha');
    return BJ_T.t('comercial.js.datas_em_dias', { n });
  }

  function linha(d) {
    const ants = ANTECEDENCIAS.map(([v, r]) => '<button type="button" class="mc-atraso" data-mc-dt-ant="' + v + '" aria-pressed="' + (d.antecedencia === v) + '">' + esc(r) + '</button>').join('');
    const pubs = PUBLICOS.map(([v, r]) => '<option value="' + v + '"' + (d.publico === v ? ' selected' : '') + '>' + esc(r) + '</option>').join('');
    return '<div class="mc-dt-linha' + (d.ligada ? '' : ' is-desligada') + '" data-mc-dt-chave="' + esc(d.chave) + '">'
      + '<button type="button" class="mc-chave" role="switch" aria-checked="' + d.ligada + '" title="' + esc(BJ_T.t('comercial.js.datas_alternar')) + '" aria-label="' + esc(d.nome) + '" data-mc-dt-alternar></button>'
      + '<span class="mc-dt-selo"><span class="mc-dt-sem">' + esc(SEMANA[d.diaDaSemana] || '') + '</span><b class="mc-dt-dia">' + esc(ddmm(d.data)) + '</b></span>'
      + '<i class="bi ' + icone(d.icone) + ' mc-dt-icone" aria-hidden="true"></i>'
      + '<span class="mc-dt-texto"><b class="mc-dt-nome">' + esc(d.nome) + '</b><span class="mc-dt-quando' + (d.perto ? ' is-perto' : '') + '">'
      + esc(falta(d.diasQueFaltam)) + ' · ' + esc(BJ_T.t('comercial.js.datas_envio', { data: ddmm(d.envio) })) + '</span></span>'
      + '<div class="mc-atrasos" role="group" aria-label="' + esc(BJ_T.t('comercial.js.datas_quando')) + '">' + ants + '</div>'
      + '<select class="mc-dt-publico" title="' + esc(BJ_T.t('comercial.js.datas_para_quem')) + '" aria-label="' + esc(BJ_T.t('comercial.js.datas_para_quem')) + '" data-mc-dt-publico>' + pubs + '</select>'
      + '<button type="button" class="mc-dt-ver" data-mc-dt-ver><i class="bi bi-envelope-paper" aria-hidden="true"></i>' + esc(BJ_T.t('comercial.js.datas_ver')) + '</button>'
      + '</div>';
  }

  function desenhar(r) {
    datas = r.datas || [];
    elLista.innerHTML = datas.map(linha).join('');
    elLista.removeAttribute('aria-busy');
    caixa.querySelector('[data-mc-dt-n]').textContent = String(r.ativas || 0);
    caixa.querySelector('[data-mc-dt-desligada]').hidden = r.chaveLigada === true;
  }

  function carregar() {
    return fetch(URL_LISTA, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(r => { carregado = true; desenhar(r); })
      .catch(() => avisar(BJ_T.t('comercial.js.datas_erro_carregar')));
  }

  function alterar(chave, mudanca) {
    const atual = datas.find(d => d.chave === chave);
    if (!atual || ocupado) return;
    ocupado = true;
    const corpo = Object.assign({ ligada: atual.ligada, antecedencia: atual.antecedencia, publico: atual.publico }, mudanca);
    fetch(URL_ALTERAR.replace('xchavex', encodeURIComponent(chave)), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
    }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(new Error(j.erro || BJ_T.t('comercial.js.erro_salvar'))))))
      .then(() => { avisar(''); return carregar(); })
      .catch(e => { avisar(e.message); return carregar(); })
      .finally(() => { ocupado = false; });
  }

  function verMensagem(d) {
    document.dispatchEvent(new CustomEvent('mc:modelo-abrir', {
      detail: { chave: 'data-' + d.chave, aviso: BJ_T.t('comercial.js.datas_previa', { nome: d.nome }) },
    }));
  }

  caixa.addEventListener('click', e => {
    const el = e.target.closest('[data-mc-dt-chave]');
    const d = el ? datas.find(x => x.chave === el.dataset.mcDtChave) : null;
    if (!d) return;
    if (e.target.closest('[data-mc-dt-alternar]')) { alterar(d.chave, { ligada: !d.ligada }); return; }
    const ant = e.target.closest('[data-mc-dt-ant]');
    if (ant) { alterar(d.chave, { antecedencia: parseInt(ant.dataset.mcDtAnt, 10) }); return; }
    if (e.target.closest('[data-mc-dt-ver]')) verMensagem(d);
  });
  caixa.addEventListener('change', e => {
    const sel = e.target.closest('[data-mc-dt-publico]');
    const el = sel ? sel.closest('[data-mc-dt-chave]') : null;
    if (el) alterar(el.dataset.mcDtChave, { publico: sel.value });
  });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
})();
