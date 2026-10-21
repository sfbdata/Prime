/* BlueJus · Catálogo do idioma no JS (spec docs/specs/idioma-do-sistema.md, entrega 3).
   O Twig escreve as chaves pedidas em <script type="application/json" data-bj-t> ({{ catalogo_js('prefixo') }}),
   já no idioma da página; aqui só se lê e troca {x}. Chave ausente devolve a própria chave e avisa no console. */
(function () {
  'use strict';
  if (window.BJ_T) return;

  let textos = null;

  function carregar() {
    textos = {};
    document.querySelectorAll('script[type="application/json"][data-bj-t]').forEach(function (el) {
      try { Object.assign(textos, JSON.parse(el.textContent || '{}')); } catch (e) { console.warn('BJ_T: catálogo inválido', e); }
    });
  }

  function t(chave, params) {
    if (textos === null) carregar();
    if (!Object.prototype.hasOwnProperty.call(textos, chave)) {
      console.warn('BJ_T: chave sem texto: ' + chave);
      return chave;
    }
    const p = params || {};
    return textos[chave].replace(/\{(\w+)\}/g, function (todo, nome) {
      return Object.prototype.hasOwnProperty.call(p, nome) ? String(p[nome]) : todo;
    });
  }

  // Depois de uma troca de parte da página que traga outro catálogo.
  function recarregar() { textos = null; }

  window.BJ_T = { t: t, recarregar: recarregar };
})();
