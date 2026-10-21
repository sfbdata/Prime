/* BlueJus · Painel do Master, seção "Indicações do BlueJus" (#sec=ind)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, marcação L234-242 e lógica
   L683-689; spec docs/specs/indicar-bluejus.md).
   - Carrega na primeira vez que a seção abre (evento `mp:secao` do painel) ou já aberta pelo hash.
   - Cabeçalho: "N enviadas · N hoje · N viraram conta" (enviadas, últimas 24h e viraram conta do servidor).
   - A chave pausa ou libera os convites de todos; os três campos gravam ao mudar (onChange do dc), sempre os três.
   - POST JSON com X-CSRF-Token; a resposta traz o estado novo e a tela se redesenha. Recusa: a mensagem do servidor
     aparece e o campo volta ao último valor gravado.
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
  const num = n => Number(n || 0).toLocaleString('pt-BR');
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
    elResumo.textContent = num(d.enviadas) + ' enviadas · ' + num(d.hoje) + ' hoje · ' + num(d.viraramConta) + ' viraram conta';
    const pausado = !!d.limites.pausadoParaTodos;
    elChave.setAttribute('aria-checked', pausado ? 'true' : 'false');
    elChave.setAttribute('aria-label', pausado ? 'Liberar os convites para todos' : 'Pausar os convites para todos');
    elChaveTxt.textContent = pausado ? 'Convites pausados para todos' : 'Convites liberados';
    campos.forEach(c => { c.value = String(d.limites[c.dataset.miLimite]); });
    elCaixa.removeAttribute('aria-busy');
  }

  function carregar() {
    carregado = true;
    travar(true);
    return fetch(raiz.dataset.urlDados, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => { desenhar(d); travar(false); })
      .catch(() => { carregado = false; avisar('Não foi possível carregar as indicações. Recarregue a página.'); });
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
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || 'Não foi possível salvar.')))))
      .then(d => desenhar(d))
      .catch(e => { avisar(e.message || 'Não foi possível salvar.'); if (estado) desenhar(estado); })
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
  if (/[#&]sec=ind\b/.test(location.hash)) carregar();
})();
