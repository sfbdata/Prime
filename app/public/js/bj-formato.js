/* BlueJus · Formatos do idioma (spec docs/specs/idioma-do-sistema.md, entrega 2).
   Datas, horas e números no idioma da página (<html lang>), no lugar do 'pt-BR' fixo dos toLocale*.
   Em português a saída é a de hoje: 05/03/2026, 09:07, 1.234,50. Moeda continua real; só o formato muda. */
(function () {
  'use strict';
  if (window.BJFormato) return;

  const idioma = () => document.documentElement.lang || 'pt-BR';
  const comoData = v => (v instanceof Date ? v : new Date(v));
  const valida = d => !isNaN(d.getTime());

  function data(v) {
    const d = comoData(v);
    return valida(d) ? d.toLocaleDateString(idioma(), { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
  }

  function hora(v) {
    const d = comoData(v);
    return valida(d) ? d.toLocaleTimeString(idioma(), { hour: '2-digit', minute: '2-digit' }) : '';
  }

  function dataHora(v) {
    const d = comoData(v);
    return valida(d) ? data(d) + ' ' + hora(d) : '';
  }

  function numero(n, casas) {
    const c = casas || 0;
    return Number(n || 0).toLocaleString(idioma(), { minimumFractionDigits: c, maximumFractionDigits: c });
  }

  window.BJFormato = { idioma, data, hora, dataHora, numero };
})();
