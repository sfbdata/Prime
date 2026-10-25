/* BlueJus · aviso global "Questões jurídicas a serem verificadas" (layout/_globais/aviso_questoes_juridicas).
   Depois do clique em "ver as questões" o aviso some; volta só se surgir questão em aberto que o Master ainda não viu.
   Guarda no localStorage, por usuário, as chaves em aberto já vistas. Sem banco. localStorage pode estar bloqueado
   (modo privado, política do navegador): nesse caso o aviso segue como antes, sempre visível. Roda logo depois do
   aviso (script síncrono), para ele não piscar na tela. */
(() => {
  'use strict';
  const aviso = document.querySelector('[data-aviso-questoes-juridicas]');
  if (!aviso) return;

  const chave = 'bj_aviso_questoes_juridicas_' + (aviso.dataset.usuario || 'anonimo');
  const atuais = (aviso.dataset.chaves || '').split(',').filter(Boolean);

  function lerVistas() {
    try {
      const lista = JSON.parse(window.localStorage.getItem(chave) || '[]');
      return Array.isArray(lista) ? lista.filter(c => typeof c === 'string') : [];
    } catch (e) {
      return [];
    }
  }

  function gravarVistas(chaves) {
    try {
      window.localStorage.setItem(chave, JSON.stringify(chaves));
    } catch (e) { /* sem armazenamento: o aviso continua aparecendo */ }
  }

  const vistas = lerVistas();
  if (atuais.length && atuais.every(c => vistas.includes(c))) {
    aviso.remove();
    return;
  }

  const botao = aviso.querySelector('a.ae-aumentar');
  if (botao) {
    // Une às já vistas: o que ele já viu não reabre o aviso por si só.
    botao.addEventListener('click', () => gravarVistas(Array.from(new Set(vistas.concat(atuais)))));
  }
})();
