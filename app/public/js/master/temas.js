/* BlueJus · Painel do Master, card "Temas comemorativos" (spec docs/specs/temas-comemorativos.md; desenho
   docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, markup L352-360 e lógica L834-839).
   - Estado inicial: data-estado ({modo, manual, ativo, temas}), o mesmo formato que as rotas devolvem.
   - Modo: POST /master/temas/modo {modo, manual}; no modo "Escolher um tema", clicar no card fixa o tema (e
     clicar de novo no fixado solta). Interruptor: POST /master/temas/{id}/ligado {ligado}. Token master_temas.
   - Prévia: 30 s só neste navegador (localStorage bj-tema-previa = {id, ate}), com o contador no status e o
     "Parar prévia". Quem pinta a página é o public/js/tema-comemorativo.js, avisado pelo evento
     "bj-tema-comemorativo" (também depois de salvar, com o tema novo do servidor).
   Textos no catálogo (`temas.js`, spec idioma-do-sistema §8), lidos por BJ_T.t; o nome e o ícone do tema vêm do
   servidor. Nada de innerHTML: a grade vem do servidor e aqui só mudam classes, atributos e textContent. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-temas]');
  if (!raiz) return;

  const CHAVE_PREVIA = 'bj-tema-previa';
  const SEGUNDOS_PREVIA = 30;
  const URL_MODO = raiz.getAttribute('data-url-modo') || '';
  const URL_LIGAR = raiz.getAttribute('data-url-ligar') || '';
  const TOKEN = raiz.getAttribute('data-token') || '';
  let estado = JSON.parse(raiz.getAttribute('data-estado') || '{}');
  let ocupado = false;
  let relogio = null;
  let timerAviso = null;

  const elStatus = raiz.querySelector('[data-mp-tm-status]');
  const elDica = raiz.querySelector('[data-mp-tm-dica]');
  const elParar = raiz.querySelector('[data-mp-tm-parar]');
  const elAviso = raiz.querySelector('[data-mp-tm-aviso]');

  const tema = id => (estado.temas || []).find(t => t.id === id) || null;

  function lerPrevia() {
    let p = null;
    try { p = JSON.parse(localStorage.getItem(CHAVE_PREVIA) || 'null'); } catch (e) { p = null; }

    return p && typeof p.id === 'string' && Number(p.ate) > Date.now() && tema(p.id) ? p : null;
  }

  function avisarPagina(detalhe) {
    window.dispatchEvent(new CustomEvent('bj-tema-comemorativo', { detail: detalhe || {} }));
  }

  function avisar(texto) {
    elAviso.textContent = texto;
    elAviso.hidden = false;
    clearTimeout(timerAviso);
    timerAviso = setTimeout(() => { elAviso.hidden = true; }, 4000);
  }

  function textoDoStatus(previa) {
    if (previa) {
      const t = tema(previa.id);

      return BJ_T.t('temas.js.previa', { icone: t.ic, tema: t.r, s: Math.ceil((previa.ate - Date.now()) / 1000) });
    }
    const ativo = tema(estado.ativo);
    if (ativo) return BJ_T.t('temas.js.ativo', { icone: ativo.ic, tema: ativo.r });
    if (estado.modo === 'desligado') return BJ_T.t('temas.js.desligados');

    return BJ_T.t('temas.js.nenhum');
  }

  function render() {
    const previa = lerPrevia();
    const ativoId = previa ? previa.id : estado.ativo;
    elStatus.textContent = textoDoStatus(previa);
    elParar.hidden = !previa;
    elDica.hidden = estado.modo !== 'manual';
    raiz.querySelectorAll('.mp-tm-modo').forEach(b => {
      const on = b.getAttribute('data-modo') === estado.modo;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    raiz.querySelectorAll('.mp-tm-tema').forEach(card => {
      const id = card.getAttribute('data-tema');
      const t = tema(id);
      if (!t) return;
      card.classList.toggle('is-sel', estado.modo === 'manual' && estado.manual === id);
      card.querySelector('[data-mp-tm-ativo]').hidden = ativoId !== id;
      const chave = card.querySelector('[data-mp-tm-chave]');
      chave.classList.toggle('is-on', !!t.ligado);
      chave.setAttribute('aria-pressed', t.ligado ? 'true' : 'false');
    });
  }

  function pararRelogio() {
    clearInterval(relogio);
    relogio = null;
  }

  function pararPrevia() {
    pararRelogio();
    try { localStorage.removeItem(CHAVE_PREVIA); } catch (e) { /* nada a limpar */ }
    avisarPagina({});
    render();
  }

  function iniciarPrevia(id) {
    try {
      localStorage.setItem(CHAVE_PREVIA, JSON.stringify({ id, ate: Date.now() + SEGUNDOS_PREVIA * 1000 }));
    } catch (e) {
      avisar(BJ_T.t('temas.js.previa_indisponivel'));

      return;
    }
    avisarPagina({});
    render();
    pararRelogio();
    relogio = setInterval(() => {
      if (!lerPrevia()) { pararPrevia(); return; }
      render();
    }, 1000);
  }

  function enviar(url, corpo) {
    if (ocupado) return;
    ocupado = true;
    raiz.setAttribute('aria-busy', 'true');
    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
      credentials: 'same-origin'
    })
      .then(r => r.json().catch(() => ({})).then(dados => ({ ok: r.ok, dados })))
      .then(({ ok, dados }) => {
        if (!ok || !Array.isArray(dados.temas)) { avisar(dados.erro || BJ_T.t('temas.js.erro_alterar')); return; }
        estado = dados;
        avisarPagina({ tema: tema(estado.ativo) });
      })
      .catch(() => avisar(BJ_T.t('temas.js.erro_alterar')))
      .finally(() => { ocupado = false; raiz.removeAttribute('aria-busy'); render(); });
  }

  raiz.querySelectorAll('.mp-tm-modo').forEach(b => {
    b.addEventListener('click', () => {
      // Como o desenho: trocar o modo encerra a prévia.
      if (lerPrevia()) pararPrevia();
      enviar(URL_MODO, { modo: b.getAttribute('data-modo'), manual: estado.manual || null });
    });
  });

  raiz.querySelectorAll('.mp-tm-tema').forEach(card => {
    const id = card.getAttribute('data-tema');
    card.addEventListener('click', () => {
      if (estado.modo !== 'manual') return;
      enviar(URL_MODO, { modo: 'manual', manual: estado.manual === id ? null : id });
    });
    card.querySelector('[data-mp-tm-previa]').addEventListener('click', ev => {
      ev.stopPropagation();
      iniciarPrevia(id);
    });
    card.querySelector('[data-mp-tm-chave]').addEventListener('click', ev => {
      ev.stopPropagation();
      const t = tema(id);
      if (t) enviar(URL_LIGAR.replace('idtema', encodeURIComponent(id)), { ligado: !t.ligado });
    });
  });

  elParar.addEventListener('click', pararPrevia);

  // Prévia que ainda vale (página recarregada no meio dela): retoma o contador.
  if (lerPrevia()) {
    relogio = setInterval(() => {
      if (!lerPrevia()) { pararPrevia(); return; }
      render();
    }, 1000);
  }
  render();
})();
