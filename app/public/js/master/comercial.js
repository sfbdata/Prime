/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Automações"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup L.262-268 e lógica L.561-566;
   spec docs/specs/comercial-e-emails.md §3.5).
   - Carrega o estado na primeira vez que a seção "com" abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Ligar ou desligar e o atraso: POST JSON com X-CSRF-Token; a resposta traz o estado novo e a linha se redesenha.
   - "Simular novo cadastro": POST; nada é enviado (só aparece em "Últimos disparos", como no dc).
   - O botão do modelo abre a prévia só de leitura do e-mail (o editor é da fatia 2).
   Nenhuma regra mora só aqui: o servidor recusa chave desconhecida, atraso fora da lista e desligar o obrigatório. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mc]');
  if (!raiz) return;

  const URL_LISTAR = raiz.dataset.urlAutomacoes;
  const URL_ALTERAR = raiz.dataset.urlAlterar;
  const URL_SIMULAR = raiz.dataset.urlSimular;
  const URL_PREVIA = raiz.dataset.urlPrevia;
  const TOKEN = raiz.dataset.token || '';
  const elAutos = raiz.querySelector('[data-mc-autos]');
  const elFila = raiz.querySelector('[data-mc-fila]');
  const elMsg = raiz.querySelector('[data-mc-msg]');
  const elPrevia = raiz.querySelector('[data-mc-previa]');
  let carregado = false;
  let ocupado = false;

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  // Os rótulos do dc (L.564): 24h · 3 dias · 80%; "imediato" quando não há escolha.
  const rotulo = v => /^\d+d$/.test(v) ? parseInt(v, 10) + ' dias' : /^\d+$/.test(v) ? v + '%' : v;

  function avisar(texto) {
    elMsg.textContent = texto;
    elMsg.hidden = false;
  }

  function linha(a) {
    const dica = a.obrigatoria ? 'Obrigatório: sem ele ninguém consegue criar conta' : 'Ligar ou desligar';
    const atrasos = a.opcoes.length > 1
      ? '<div class="mc-atrasos" role="group" aria-label="Quando enviar">' + a.opcoes.map(v =>
        '<button type="button" class="mc-atraso" data-mc-atraso="' + esc(v) + '" aria-pressed="' + (a.atraso === v) + '">' + esc(rotulo(v)) + '</button>').join('') + '</div>'
      : '';
    return '<div class="mc-auto" data-mc-chave="' + esc(a.chave) + '">'
      + '<button type="button" class="mc-chave" role="switch" aria-checked="' + a.ligada + '" title="' + esc(dica) + '" aria-label="' + esc(a.titulo) + '"' + (a.obrigatoria ? ' disabled' : '') + ' data-mc-alternar></button>'
      + '<span class="mc-auto-texto"><b class="mc-auto-titulo">' + esc(a.titulo) + '</b><span class="mc-auto-desc">' + esc(a.descricao) + '</span></span>'
      + atrasos
      + '<button type="button" class="mc-modelo" data-mc-modelo><i class="bi bi-envelope-paper" aria-hidden="true"></i>' + esc(a.modelo) + '</button>'
      + '</div>';
  }

  function disparo(d) {
    return '<div class="mc-disparo"><span class="mc-disparo-gat">' + esc(d.gatilho) + '</span>'
      + '<span class="mc-disparo-assunto">' + esc(d.assunto) + '</span>'
      + '<span class="mc-disparo-para">' + esc(d.destinatario) + '</span>'
      + '<span class="mc-disparo-em">' + esc(d.em) + '</span></div>';
  }

  function desenhar(dados) {
    raiz._automacoes = dados.automacoes;
    elAutos.innerHTML = dados.automacoes.map(linha).join('');
    elAutos.removeAttribute('aria-busy');
    elFila.innerHTML = dados.ultimos.length ? dados.ultimos.map(disparo).join('') : '<div class="mc-fila-vazio">Nenhum disparo ainda.</div>';
  }

  function carregar() {
    return fetch(URL_LISTAR, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { carregado = true; desenhar(d); })
      .catch(() => avisar('Não foi possível carregar as automações. Recarregue a página.'));
  }

  function postar(url, corpo) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo || {}),
    }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(new Error(j.erro || 'Não foi possível salvar.')))));
  }

  function alterar(chave, mudanca) {
    if (ocupado) return;
    const atual = (raiz._automacoes || []).find(a => a.chave === chave);
    if (!atual) return;
    ocupado = true;
    postar(URL_ALTERAR.replace('__chave__', encodeURIComponent(chave)), Object.assign({ ligada: atual.ligada, atraso: atual.atraso }, mudanca))
      .then(() => carregar())
      .catch(e => avisar(e.message))
      .finally(() => { ocupado = false; });
  }

  function abrirPrevia(chave) {
    const a = (raiz._automacoes || []).find(x => x.chave === chave);
    if (!a) return;
    raiz.querySelector('[data-mc-previa-titulo]').textContent = a.modelo;
    raiz.querySelector('[data-mc-previa-quadro]').src = URL_PREVIA.replace('__chave__', encodeURIComponent(chave));
    elPrevia.hidden = false;
    avisar('Editando o modelo da automação "' + a.titulo + '". Duplique para ajustar o texto.');
  }

  raiz.addEventListener('click', e => {
    const linhaEl = e.target.closest('[data-mc-chave]');
    const chave = linhaEl ? linhaEl.dataset.mcChave : null;
    const atual = chave ? (raiz._automacoes || []).find(a => a.chave === chave) : null;
    if (e.target.closest('[data-mc-alternar]') && atual) { alterar(chave, { ligada: !atual.ligada }); return; }
    const atraso = e.target.closest('[data-mc-atraso]');
    if (atraso && atual) { alterar(chave, { atraso: atraso.dataset.mcAtraso }); return; }
    if (e.target.closest('[data-mc-modelo]') && chave) { abrirPrevia(chave); return; }
    if (e.target.closest('[data-mc-previa-fechar]')) { elPrevia.hidden = true; return; }
    if (e.target.closest('[data-mc-simular]')) {
      postar(URL_SIMULAR).then(r => { avisar(r.mensagem); return carregar(); }).catch(err => avisar(err.message));
    }
  });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
})();
