/* BlueJus · Painel do Master, seção "Indicações do BlueJus" (#sec=ind)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, marcação L234-242 e lógica
   L683-689; spec docs/specs/indicar-bluejus.md).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Cabeçalho: "N enviadas · N hoje · N viraram conta" (enviadas, últimas 24h e viraram conta do servidor).
   - A chave pausa ou libera os convites de todos; os três campos gravam ao mudar (onChange do dc), sempre os três.
   - POST JSON com X-CSRF-Token; a resposta traz o estado novo e a tela se redesenha. Recusa: a mensagem do servidor
     aparece e o campo volta ao último valor gravado.
   - Textos no catálogo (master.js.indicacoes.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de
     usar; números por window.BJFormato.
   Nenhuma regra mora só aqui: o servidor recusa limite menor que 1 e valor que não seja inteiro. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mi]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elCaixa = raiz.querySelector('[data-mi-caixa]');
  const elResumo = raiz.querySelector('[data-mi-resumo]');
  const elChave = raiz.querySelector('[data-mi-pausa]');
  const elChaveTxt = raiz.querySelector('[data-mi-pausa-txt]');
  const elMsg = raiz.querySelector('[data-mi-msg]');
  const campos = Array.from(raiz.querySelectorAll('[data-mi-limite]'));
  const num = n => window.BJFormato.numero(n);
  // O catálogo e o BJ_T entram depois deste arquivo (fim do base): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  let estado = null;
  let carregado = false;
  let ocupado = false;

  function avisar(texto) {
    elMsg.textContent = texto || '';
    elMsg.hidden = !texto;
  }

  function travar(sim) {
    ocupado = sim;
    elChave.disabled = sim;
    campos.forEach(c => { c.disabled = sim; });
  }

  function desenhar(d) {
    estado = d;
    elResumo.textContent = T('master.js.indicacoes.resumo', { enviadas: num(d.enviadas), hoje: num(d.hoje), conta: num(d.viraramConta) });
    const pausado = !!d.limites.pausadoParaTodos;
    elChave.setAttribute('aria-checked', pausado ? 'true' : 'false');
    elChave.setAttribute('aria-label', pausado ? T('master.js.indicacoes.liberar_todos') : T('master.js.indicacoes.pausar_todos'));
    elChaveTxt.textContent = pausado ? T('master.js.indicacoes.pausados') : T('master.js.indicacoes.liberados');
    campos.forEach(c => { c.value = String(d.limites[c.dataset.miLimite]); });
    elCaixa.removeAttribute('aria-busy');
  }

  function carregar() {
    carregado = true;
    travar(true);
    return fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); travar(false); })
      .catch(() => { carregado = false; avisar(T('master.js.indicacoes.erro_carregar')); });
  }

  function postar(url, corpo) {
    if (ocupado) return;
    travar(true);
    avisar('');
    fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
    })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('master.js.indicacoes.erro_salvar'))))))
      .then(d => desenhar(d))
      .catch(e => { avisar(e.message || T('master.js.indicacoes.erro_salvar')); if (estado) desenhar(estado); })
      .finally(() => travar(false));
  }

  elChave.addEventListener('click', () => {
    if (!estado) return;
    postar(raiz.dataset.urlPausa, { pausar: !estado.limites.pausadoParaTodos });
  });

  campos.forEach(c => c.addEventListener('change', () => {
    if (!estado) return;
    const corpo = {};
    campos.forEach(x => { corpo[x.dataset.miLimite] = x.value.trim(); });
    postar(raiz.dataset.urlLimites, corpo);
  }));

  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'ind' && !carregado) carregar(); });
  }
  // Já aberta pelo hash: espera o fim da página, onde entram o catálogo, o BJ_T e o BJFormato.
  if (/[#&]sec=ind\b/.test(location.hash)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { if (!carregado) carregar(); });
    else carregar();
  }
})();
