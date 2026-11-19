/* BlueJus · Formatos do idioma (spec docs/specs/idioma-do-sistema.md, entrega 2).
   Datas, horas e números no idioma da página (<html lang>), no lugar do 'pt-BR' fixo dos toLocale*.
   Em português a saída é a de hoje: 05/03/2026, 09:07, 1.234,50. Moeda continua real; só o formato muda.
   "Opções avançadas" (spec sistema-em-ingles §2), lidas do <html>: data-bj-formato-data (dmy, mdy, ymd) fixa a data,
   data-bj-formato-numero (br, us) fixa os números e data-bj-fuso põe a hora no fuso da pessoa. O fuso vale só para
   hora e dataHora (registros); a data pura fica como está, porque é o dia do prazo e da audiência. */
(function () {
  'use strict';
  if (window.BJFormato) return;

  const idioma = () => document.documentElement.lang || 'pt-BR';
  const opcao = nome => document.documentElement.getAttribute('data-bj-' + nome) || '';
  const NUMEROS = { br: 'pt-BR', us: 'en-US' };
  const fusoDaPessoa = () => (opcao('fuso') ? { timeZone: opcao('fuso') } : {});
  const comoData = v => (v instanceof Date ? v : new Date(v));
  const valida = d => !isNaN(d.getTime());

  /* Data com o formato fixo escolhido; extra = { timeZone } quando a data acompanha a hora no fuso da pessoa. */
  function escreverData(d, extra) {
    const fixo = opcao('formato-data');
    if (!fixo) return d.toLocaleDateString(idioma(), Object.assign({ day: '2-digit', month: '2-digit', year: 'numeric' }, extra));
    const p = {};
    new Intl.DateTimeFormat('en-US', Object.assign({ day: '2-digit', month: '2-digit', year: 'numeric' }, extra)).formatToParts(d).forEach(x => { p[x.type] = x.value; });
    if (fixo === 'mdy') return p.month + '/' + p.day + '/' + p.year;
    if (fixo === 'ymd') return p.year + '-' + p.month + '-' + p.day;
    return p.day + '/' + p.month + '/' + p.year;
  }

  function data(v) {
    const d = comoData(v);
    return valida(d) ? escreverData(d, {}) : '';
  }

  function hora(v) {
    const d = comoData(v);
    return valida(d) ? d.toLocaleTimeString(idioma(), Object.assign({ hour: '2-digit', minute: '2-digit' }, fusoDaPessoa())) : '';
  }

  function dataHora(v) {
    const d = comoData(v);
    return valida(d) ? escreverData(d, fusoDaPessoa()) + ' ' + hora(d) : '';
  }

  function numero(n, casas) {
    const c = casas || 0;
    return Number(n || 0).toLocaleString(NUMEROS[opcao('formato-numero')] || idioma(), { minimumFractionDigits: c, maximumFractionDigits: c });
  }

  window.BJFormato = { idioma, data, hora, dataHora, numero };
})();
