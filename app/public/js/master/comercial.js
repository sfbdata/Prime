/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Automações"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup L.262-268 e lógica L.561-566;
   spec docs/specs/comercial-e-emails.md §3.5).
   - Carrega o estado na primeira vez que a seção "com" abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Ligar ou desligar e o atraso: POST JSON com X-CSRF-Token; a resposta traz o estado novo e a linha se redesenha.
   - "Simular novo cadastro" (B3, 27/11/2026): a janela do painel (BJMasterConf) pede o e-mail, com o do Master já
     preenchido; o POST envia de verdade a confirmação e as boas-vindas de teste, que aparecem em "Últimos disparos".
   - O botão do modelo abre a prévia só de leitura do e-mail (o editor é da fatia 2).
   Nenhuma regra mora só aqui: o servidor recusa chave desconhecida, atraso fora da lista e desligar o obrigatório. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mc]');
  if (!raiz) return;

  const URL_LISTAR = raiz.dataset.urlAutomacoes;
  const URL_ALTERAR = raiz.dataset.urlAlterar;
  const URL_SIMULAR = raiz.dataset.urlSimular;
  const EMAIL_DO_MASTER = raiz.dataset.emailMaster || '';
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
  const rotulo = v => /^\d+d$/.test(v) ? BJ_T.t('comercial.js.atraso_dias', { n: parseInt(v, 10) }) : /^\d+$/.test(v) ? v + '%' : v;

  // Linha de retorno fixa (M5.14): `tipo` 'ok' ou 'erro' (sem tipo = informação); fica até a próxima ação.
  function avisar(texto, tipo) {
    elMsg.textContent = texto;
    elMsg.hidden = false;
    elMsg.classList.toggle('is-ok', tipo === 'ok');
    elMsg.classList.toggle('is-erro', tipo === 'erro');
    elMsg.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
  }

  // Parte estável de um id (M5.13): só letras minúsculas, números e hífen.
  const slug = t => String(t == null ? '' : t).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

  function linha(a) {
    const dica = a.obrigatoria ? BJ_T.t('comercial.js.auto_obrigatoria') : BJ_T.t('comercial.js.auto_alternar');
    const id = 'mc-auto-' + slug(a.chave);
    const atrasos = a.opcoes.length > 1
      ? '<div class="mc-atrasos" role="group" aria-label="' + esc(BJ_T.t('comercial.js.auto_quando')) + '">' + a.opcoes.map(v =>
        '<button type="button" class="mc-atraso" id="' + id + '-atraso-' + slug(v) + '" name="' + id + '-atraso-' + slug(v) + '" aria-label="' + esc(BJ_T.t('comercial.js.auto_atraso_de', { titulo: a.titulo, atraso: rotulo(v) })) + '" data-mc-atraso="' + esc(v) + '" aria-pressed="' + (a.atraso === v) + '">' + esc(rotulo(v)) + '</button>').join('') + '</div>'
      : '';
    // O motivo do switch bloqueado fica escrito ao lado (M5.14), ligado ao botão por aria-describedby.
    const motivo = a.obrigatoria ? '<span class="mc-motivo" id="' + id + '-motivo">' + esc(dica) + '</span>' : '';
    return '<div class="mc-auto" data-mc-chave="' + esc(a.chave) + '">'
      + '<button type="button" class="mc-chave" role="switch" id="' + id + '-alternar" name="' + id + '-alternar" aria-checked="' + a.ligada + '" title="' + esc(dica) + '" aria-label="' + esc(a.titulo) + '"' + (a.obrigatoria ? ' disabled aria-describedby="' + id + '-motivo"' : '') + ' data-mc-alternar></button>'
      + '<span class="mc-auto-texto"><b class="mc-auto-titulo">' + esc(a.titulo) + '</b><span class="mc-auto-desc">' + esc(a.descricao) + '</span>' + motivo + '</span>'
      + atrasos
      + '<button type="button" class="mc-modelo" id="' + id + '-modelo" name="' + id + '-modelo" data-mc-modelo><i class="bi bi-envelope-paper" aria-hidden="true"></i>' + esc(a.modelo) + '</button>'
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
    elFila.innerHTML = dados.ultimos.length ? dados.ultimos.map(disparo).join('') : '<div class="mc-fila-vazio">' + esc(BJ_T.t('comercial.js.fila_vazia')) + '</div>';
  }

  function carregar() {
    return fetch(URL_LISTAR, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { carregado = true; desenhar(d); })
      .catch(() => avisar(BJ_T.t('comercial.js.auto_erro_carregar'), 'erro'));
  }

  function postar(url, corpo) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo || {}),
    }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(new Error(j.erro || BJ_T.t('comercial.js.erro_salvar'))))));
  }

  function alterar(chave, mudanca) {
    if (ocupado) return;
    const atual = (raiz._automacoes || []).find(a => a.chave === chave);
    if (!atual) return;
    ocupado = true;
    avisar(BJ_T.t('comercial.js.salvando'));
    postar(URL_ALTERAR.replace('xchavex', encodeURIComponent(chave)), Object.assign({ ligada: atual.ligada, atraso: atual.atraso }, mudanca))
      .then(() => {
        const chaveDoTexto = 'comercial.js.' + (mudanca.atraso !== undefined ? 'auto_ok_atraso' : mudanca.ligada ? 'auto_ok_ligada' : 'auto_ok_desligada');
        avisar(BJ_T.t(chaveDoTexto, { titulo: atual.titulo, atraso: rotulo(String(mudanca.atraso)) }), 'ok');
        return carregar();
      })
      .catch(e => avisar(e.message, 'erro'))
      .finally(() => { ocupado = false; });
  }

  function abrirPrevia(chave) {
    const a = (raiz._automacoes || []).find(x => x.chave === chave);
    if (!a) return;
    raiz.querySelector('[data-mc-previa-titulo]').textContent = a.modelo;
    raiz.querySelector('[data-mc-previa-quadro]').src = URL_PREVIA.replace('xchavex', encodeURIComponent(chave));
    elPrevia.hidden = false;
    avisar(BJ_T.t('comercial.js.auto_editando', { titulo: a.titulo }));
  }

  /* B3: o e-mail de destino na janela do painel (o do Master já preenchido, editável); Cancelar e Esc não enviam. O
     servidor valida o endereço e o teto de 30 por hora; a recusa vai à linha de retorno. */
  function simular() {
    if (!window.BJMasterConf) return;
    window.BJMasterConf.perguntar({
      titulo: BJ_T.t('comercial.js.simular_titulo'), icone: 'bi-play-circle', texto: BJ_T.t('comercial.js.simular_texto'),
      botao: BJ_T.t('comercial.js.simular_botao'), entrada: { rotulo: BJ_T.t('comercial.js.simular_rotulo'), valor: EMAIL_DO_MASTER, tipo: 'email' },
    }).then(email => {
      if (!email) return;
      avisar(BJ_T.t('comercial.js.simular_enviando'));
      postar(URL_SIMULAR, { email }).then(r => { avisar(r.mensagem, 'ok'); return carregar(); }).catch(err => avisar(err.message, 'erro'));
    });
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
    if (e.target.closest('[data-mc-simular]')) simular();
  });

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
})();
