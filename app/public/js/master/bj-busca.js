/*
 * Busca compartilhada das listas do painel do Master (pedido M3 do Farlei, 24/11): o mesmo jeito de comparar texto
 * em todas as listas, sem acento e sem diferença de maiúsculas ("João" acha "joao"), e o atraso da digitação para
 * a busca que vai ao servidor. IIFE no padrão dos outros arquivos de public/js/master/, exposta em window.BJBusca.
 *
 *   BJBusca.normalizar(texto)        texto em minúsculas, sem acento e com os espaços das pontas tirados
 *   BJBusca.termo(valor, minimo)     o termo normalizado, ou '' quando tem menos de `minimo` letras
 *   BJBusca.casa(texto, termo)       o texto contém o termo (termo já normalizado; termo vazio casa tudo)
 *   BJBusca.atrasar(funcao, ms)      chama a função só depois de `ms` sem nova chamada (digitação)
 */
(function () {
  'use strict';
  if (window.BJBusca) return;

  const normalizar = texto => String(texto == null ? '' : texto).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const termo = (valor, minimo) => { const t = normalizar(valor); return t.length >= (minimo || 1) ? t : ''; };
  const casa = (texto, t) => !t || normalizar(texto).includes(t);
  const atrasar = (funcao, ms) => {
    let espera = null;
    return function () { const args = arguments; clearTimeout(espera); espera = setTimeout(() => funcao.apply(null, args), ms); };
  };

  window.BJBusca = { normalizar, termo, casa, atrasar };
})();
