/* BlueJus · Temas comemorativos no layout (spec docs/specs/temas-comemorativos.md; desenho
   docs/design/perfis-1.2/vs-21-10-26/bj-tema.js, aplicar()).
   O parcial layout/_globais/tema_comemorativo.html.twig já desenha as cores, a fita, o selo e o botão redondo do
   tema ativo. Aqui:
   - os ícones flutuando no cabeçalho (#mainHeader) e o brilho do logo no tema animado;
   - fechar o selo (vira o botão redondo até o fim do dia; o "visto" fica em localStorage
     bj-tema-visto-{id}-{AAAA-MM-DD}) e reabrir pelo botão redondo;
   - a prévia do Master (data-catalogo só existe para ele): localStorage bj-tema-previa = {id, ate}. Valendo,
     troca o tema da página pelo da prévia; vencida, volta ao tema do servidor. Nada vai para o banco.
   - o evento "bj-tema-comemorativo" do card de controle (public/js/master/temas.js): {tema} com o tema novo do
     servidor depois de salvar, ou {} quando a prévia mudou.
   Nada de innerHTML: createElement + textContent. A chave "bj-tema" do localStorage é do tema escuro e não é usada. */
(() => {
  'use strict';
  const raiz = document.getElementById('bjtTema');
  if (!raiz) return;

  const CHAVE_PREVIA = 'bj-tema-previa';
  const ler = nome => { try { return JSON.parse(raiz.getAttribute(nome) || 'null'); } catch (e) { return null; } };
  let temaDoServidor = ler('data-tema');
  const catalogo = ler('data-catalogo');
  const reduzidas = raiz.getAttribute('data-reduzidas') === '1';
  const urlCss = raiz.getAttribute('data-css') || '';
  const hojeLocal = () => new Date().toLocaleDateString('sv-SE');
  let previaAtual = null;

  function previa() {
    if (!Array.isArray(catalogo)) return null;
    let p = null;
    try { p = JSON.parse(localStorage.getItem(CHAVE_PREVIA) || 'null'); } catch (e) { p = null; }
    if (!p || typeof p.id !== 'string' || !(Number(p.ate) > Date.now())) return null;

    return catalogo.find(t => t.id === p.id) || null;
  }

  function variaveis(t) {
    return 'html:root{--bjt-p:' + t.p + ';--bjt-d:' + t.d + ';--bjt-l:' + t.l + ';--bjt-fita:' + t.fita
      + ';--header-bg:' + t.p + ';--header-avatar-fg:' + t.p + ';--bs-primary:' + t.p + ';--bs-primary-rgb:' + t.pRgb
      + ';--bs-link-color:' + t.p + ';--bs-link-color-rgb:' + t.pRgb + ';--bs-link-hover-color:' + t.d
      + ';--bs-link-hover-color-rgb:' + t.dRgb + '}';
  }

  function limpar() {
    ['bjtCssArquivo', 'bjtCss', 'bjtFita', 'bjtSelo', 'bjtMini'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.remove();
    });
    document.querySelectorAll('.bjtDeco').forEach(el => el.remove());
    document.querySelectorAll('.bjt-logo-anim').forEach(el => el.classList.remove('bjt-logo-anim'));
  }

  /* Mesma marcação do parcial Twig, para a prévia e para o tema novo depois de salvar. */
  function montar(t) {
    const anim = t.anim && !reduzidas;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = urlCss;
    link.id = 'bjtCssArquivo';
    const estilo = document.createElement('style');
    estilo.id = 'bjtCss';
    estilo.textContent = variaveis(t);
    const fita = document.createElement('div');
    fita.id = 'bjtFita';
    fita.setAttribute('aria-hidden', 'true');
    if (anim) fita.className = 'bjt-anim';

    const selo = document.createElement('div');
    selo.id = 'bjtSelo';
    selo.setAttribute('role', 'status');
    const ic = document.createElement('span');
    ic.className = 'e' + (t.icTexto ? ' is-texto' : '');
    ic.textContent = t.ic;
    const texto = document.createElement('span');
    texto.className = 'bjt-selo-texto';
    const nome = document.createElement('b');
    nome.textContent = t.r;
    texto.append(nome, document.createTextNode(t.msg));
    const fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.title = 'Fechar';
    fechar.setAttribute('aria-label', 'Fechar');
    fechar.textContent = '×';
    selo.append(ic, texto, fechar);

    const mini = document.createElement('button');
    mini.type = 'button';
    mini.id = 'bjtMini';
    mini.className = (t.icTexto ? 'is-texto' : '') + (anim ? ' bjt-anim' : '');
    mini.title = t.r;
    mini.textContent = t.ic;
    mini.hidden = true;

    raiz.after(link, estilo, fita, selo, mini);
  }

  function decorarCabecalho(t) {
    const cab = document.getElementById('mainHeader');
    if (!cab || cab.querySelector('.bjtDeco')) return;
    const deco = document.createElement('span');
    deco.className = 'bjtDeco';
    deco.setAttribute('aria-hidden', 'true');
    [28, 44, 60, 76, 92].forEach((x, i) => {
      const el = document.createElement('i');
      if (t.icTexto) el.className = 'is-texto';
      el.textContent = t.ic;
      el.style.left = x + '%';
      el.style.top = (i % 2 ? 62 : 28) + '%';
      el.style.animationDelay = (i * 0.6) + 's';
      if (!t.icTexto) el.style.fontSize = (i % 2 ? 13 : 15) + 'px';
      deco.appendChild(el);
    });
    cab.insertBefore(deco, cab.firstChild);
  }

  function ligar(t) {
    decorarCabecalho(t);
    if (t.anim && !reduzidas) {
      document.querySelectorAll('.navtop-brand-logo').forEach(el => el.classList.add('bjt-logo-anim'));
    }

    const selo = document.getElementById('bjtSelo');
    const mini = document.getElementById('bjtMini');
    if (!selo || !mini) return;
    const chaveVisto = 'bj-tema-visto-' + t.id + '-' + hojeLocal();
    const mostrarMini = () => { selo.hidden = true; mini.hidden = false; };
    let visto = false;
    try { visto = !!localStorage.getItem(chaveVisto); } catch (e) { visto = false; }
    if (visto) mostrarMini();

    const botaoFechar = selo.querySelector('button');
    if (botaoFechar) {
      botaoFechar.addEventListener('click', () => {
        try { localStorage.setItem(chaveVisto, '1'); } catch (e) { /* sem armazenamento: fecha só nesta página */ }
        mostrarMini();
      });
    }
    mini.addEventListener('click', () => { mini.hidden = true; selo.hidden = false; });
  }

  function aplicar() {
    previaAtual = previa();
    const t = previaAtual || temaDoServidor;
    limpar();
    if (!t) return;
    montar(t);
    ligar(t);
  }

  previaAtual = previa();
  if (previaAtual) {
    aplicar();
  } else if (temaDoServidor) {
    ligar(temaDoServidor);
  }

  window.addEventListener('bj-tema-comemorativo', e => {
    const detalhe = (e && e.detail) || {};
    if (Object.prototype.hasOwnProperty.call(detalhe, 'tema')) temaDoServidor = detalhe.tema || null;
    aplicar();
  });

  if (Array.isArray(catalogo)) {
    window.addEventListener('storage', e => { if (e.key === CHAVE_PREVIA) aplicar(); });
    setInterval(() => {
      if (previaAtual && !previa()) {
        try { localStorage.removeItem(CHAVE_PREVIA); } catch (e) { /* nada a limpar */ }
        aplicar();
      }
    }, 1000);
  }
})();
