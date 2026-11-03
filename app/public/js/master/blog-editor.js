/* BlueJus · Editor de artigo dos blogs (spec docs/specs/painel-artigos-blogs.md, seções 2.2, 3 e 11).
   Página própria do Master (templates/blog/editor.html.twig). Sem desenho do Claude Design (D1).
   - O artigo chega em data-artigo (ArtigoParaEditorOutput) e as URLs em data-urls (id 0 nas rotas com id).
   - Salvar rascunho: POST JSON (criar ou salvar) com o token master_blog no header X-CSRF-Token. Erro 422 vem como
     {erros: {campo: [mensagens]}} e aparece junto ao campo. Trocar o slug de um artigo que já foi ao ar: o servidor
     recusa pedindo confirmação; a tela pergunta e reenvia com confirmarTrocaDeSlug = true. Depois de criar, a página
     vai para /master/blog/artigos/{id}.
   - Verificar e Pré-visualizar trabalham com a versão salva: com alteração na tela, salvam antes.
   - TinyMCE restrito (seção 3): Parágrafo, Seção (h2), Subseção (h3), negrito, itálico, link, listas, citação,
     desfazer/refazer e o menu Blocos BlueJus, que gera exatamente o HTML da tabela da seção 3 (div[data-bj]). Os
     blocos ficam não editáveis por fora e editáveis por dentro (contenteditable só na tela; sai ao salvar).
     Imagem (P3): botão de imagem do TinyMCE (plugin image), com o texto alternativo no diálogo; o envio vai ao
     servidor pelo images_upload_handler (POST imagens, multipart). O servidor passa o HTML pela lista branca de novo
     (ListaBrancaDoArtigo) e, ao salvar, apaga a imagem que não está mais no corpo nem na capa.
   - Imagem de compartilhamento (P3): enviada pelo mesmo endereço; imagemChave guarda o id da imagem do artigo.
     Só um artigo já salvo recebe imagem (o envio precisa do id).
   - Marcadores (P7): até 8, até 40 caracteres; sugere os já usados (GET marcadores?busca=) e, escolhendo uma
     sugestão ou digitando o mesmo marcador com outra grafia, fica a grafia que já existe.
   - Relacionados (até 3) e o bloco Leia também escolhem entre os artigos da lista (GET artigos, todas as páginas).
   - Texto que vem do artigo ou do servidor entra por textContent ou esc(). */
(() => {
  'use strict';
  const raiz = document.getElementById('blog-editor');
  if (!raiz) return;

  const URLS = JSON.parse(raiz.dataset.urls || '{}');
  const TOKEN = raiz.dataset.token || '';
  const A = JSON.parse(raiz.dataset.artigo || '{}');
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  const qs = sel => raiz.querySelector(sel);
  const campo = nome => raiz.querySelector('[data-campo="' + nome + '"]');
  const valor = nome => (campo(nome) ? campo(nome).value.trim() : '');
  const comId = (modelo, id) => modelo.replace(/\/0(\/|$)/, '/' + encodeURIComponent(id) + '$1');
  // A mesma regra do slug do servidor: sem acento, minúsculas, hífen no lugar do resto.
  const slugDe = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120).replace(/-+$/, '');
  const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const tamanho = t => Array.from(String(t || '')).length;

  const ERRO_PADRAO = 'Não foi possível concluir agora. Tente de novo.';
  const MAX_MARCADORES = 8;
  const MAX_CARACTERES_MARCADOR = 40;
  const MAX_RELACIONADOS = 3;
  const MAX_BYTES_IMAGEM = 5 * 1024 * 1024;
  const TIPOS_DE_IMAGEM = ['image/jpeg', 'image/png', 'image/webp'];

  let id = A.id || null;
  let slugSalvo = A.slug || '';
  const jaFoiAoAr = !!A.jaFoiAoAr;
  let sujo = false;
  let saindo = false;
  let ocupado = false;
  let editor = null;
  const S = {
    marcadores: (A.marcadores || []).slice(),
    relacionados: (A.relacionados || []).map(r => ({ slug: r.slug, titulo: r.titulo })),
    capa: A.imagemChave ? String(A.imagemChave) : null,
  };

  /* ---------- mensagens e erros ---------- */
  const elMsg = qs('[data-be-msg]');
  function mostrarMsg(texto, tipo, lista) {
    elMsg.textContent = texto;
    if (lista && lista.length) {
      const ul = document.createElement('ul');
      lista.forEach(t => { const li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
      elMsg.appendChild(ul);
    }
    elMsg.classList.toggle('is-erro', tipo === 'erro');
    elMsg.classList.toggle('is-ok', tipo === 'ok');
    elMsg.hidden = !texto;
    if (texto && tipo === 'erro') elMsg.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  // Campo do servidor => lugar do erro na tela (marcadores[2] => marcadores; revisorNome => revisor; corpoHtml => corpo).
  const lugarDoErro = chave => {
    const base = String(chave).replace(/\[.*$/, '').replace(/\..*$/, '');
    if (base === 'corpoHtml') return 'corpo';
    if (base === 'imagem') return 'imagemChave';
    if (base === 'revisorNome' || base === 'revisorOab') return 'revisor';
    return base;
  };

  function limparErros() {
    raiz.querySelectorAll('[data-erro]').forEach(el => { el.textContent = ''; });
    raiz.querySelectorAll('.tem-erro').forEach(el => el.classList.remove('tem-erro'));
  }

  function mostrarErros(erros, titulo) {
    limparErros();
    const todas = [];
    Object.keys(erros || {}).forEach(chave => {
      const msgs = [].concat(erros[chave]).map(String);
      todas.push(...msgs);
      const lugar = qs('[data-erro="' + lugarDoErro(chave) + '"]');
      if (!lugar) return;
      lugar.textContent = (lugar.textContent ? lugar.textContent + ' ' : '') + msgs.join(' ');
      const bloco = lugar.closest('[data-be-campo]');
      if (bloco) bloco.classList.add('tem-erro');
    });
    mostrarMsg(titulo, 'erro', todas);
  }

  function erroNoCampo(lugar, texto) {
    const el = qs('[data-erro="' + lugar + '"]');
    if (el) el.textContent = texto;
  }

  /* ---------- confirmação (componentes mp-conf do painel) ---------- */
  const conf = qs('[data-be-conf]');
  let fecharConf = null;
  function confirmar(op) {
    return new Promise(resolve => {
      conf.querySelector('[data-be-conf-titulo]').textContent = op.titulo;
      conf.querySelector('[data-be-conf-icone]').className = 'bi ' + op.icone;
      conf.querySelector('[data-be-conf-texto]').textContent = op.texto;
      const ok = conf.querySelector('[data-be-conf-ok]');
      ok.textContent = op.botao;
      conf.style.setProperty('--cor', op.cor || '#0a6fa0');
      fecharConf = resposta => { conf.hidden = true; fecharConf = null; resolve(resposta); };
      conf.hidden = false;
      ok.focus();
    });
  }
  conf.querySelector('[data-be-conf-ok]').addEventListener('click', () => { if (fecharConf) fecharConf(true); });
  conf.querySelector('[data-be-conf-cancelar]').addEventListener('click', () => { if (fecharConf) fecharConf(false); });
  conf.addEventListener('click', e => { if (e.target === conf && fecharConf) fecharConf(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && fecharConf) fecharConf(false); });

  /* ---------- contadores e prévia do Google ---------- */
  function atualizarContadores() {
    raiz.querySelectorAll('[data-be-contador]').forEach(el => {
      const n = tamanho(valor(el.dataset.beContador));
      const max = Number(el.dataset.max);
      el.textContent = n + '/' + max;
      el.classList.toggle('is-passou', n > max);
    });
  }

  function atualizarGoogle() {
    const prefixo = valor('blogs') === 'gratis' ? '/gratis' : '/blog';
    qs('[data-be-google-url]').textContent = 'bluejus.com.br' + prefixo + '/' + (valor('categoria') || '{categoria}') + '/' + (valor('slug') || '{slug}');
    qs('[data-be-google-tit]').textContent = valor('tituloSeo') || valor('titulo') || 'Título SEO do artigo';
    qs('[data-be-google-desc]').textContent = valor('descricao') || 'A meta description aparece aqui, abaixo do título.';
  }

  qs('[data-be-gerar-slug]').addEventListener('click', () => {
    const slug = slugDe(valor('titulo'));
    if (!slug) { erroNoCampo('slug', 'Escreva o título antes de gerar o slug.'); return; }
    campo('slug').value = slug;
    erroNoCampo('slug', '');
    sujo = true;
    atualizarGoogle();
  });

  /* ---------- referências ---------- */
  const elRefs = qs('[data-be-refs]');
  function linhaDeReferencia(r) {
    const div = document.createElement('div');
    div.className = 'be-ref';
    div.setAttribute('data-be-ref', '');
    [['texto', 'Texto da referência'], ['url', 'https://'], ['rotulo', 'Rótulo']].forEach(([nome, dica]) => {
      const input = document.createElement('input');
      input.type = 'text';
      input.dataset.ref = nome;
      input.placeholder = dica;
      input.setAttribute('aria-label', nome === 'url' ? 'Endereço (URL)' : dica);
      input.value = r[nome] || '';
      div.appendChild(input);
    });
    const remover = document.createElement('button');
    remover.type = 'button';
    remover.className = 'be-ref-remover';
    remover.title = 'Remover referência';
    remover.setAttribute('aria-label', 'Remover referência');
    remover.innerHTML = '<i class="bi bi-x-lg"></i>';
    remover.addEventListener('click', () => { div.remove(); sujo = true; atualizarRefsVazio(); });
    div.appendChild(remover);
    return div;
  }
  function atualizarRefsVazio() {
    const vazio = !elRefs.children.length;
    qs('[data-be-refs-vazio]').hidden = !vazio;
    qs('[data-be-ref-cab]').hidden = vazio;
  }
  const lerReferencias = () => Array.from(elRefs.querySelectorAll('[data-be-ref]')).map(div => ({
    texto: div.querySelector('[data-ref="texto"]').value.trim(),
    url: div.querySelector('[data-ref="url"]').value.trim(),
    rotulo: div.querySelector('[data-ref="rotulo"]').value.trim(),
  })).filter(r => r.texto || r.url || r.rotulo);
  (A.referencias || []).forEach(r => elRefs.appendChild(linhaDeReferencia(r)));
  atualizarRefsVazio();
  qs('[data-be-ref-add]').addEventListener('click', () => {
    const linha = linhaDeReferencia({});
    elRefs.appendChild(linha);
    atualizarRefsVazio();
    linha.querySelector('input').focus();
  });

  /* ---------- chips (marcadores e relacionados) ---------- */
  function desenharChips(el, itens, rotulo, aoRemover) {
    el.innerHTML = '';
    itens.forEach((item, i) => {
      const chip = document.createElement('span');
      chip.className = 'be-chip';
      const txt = document.createElement('span');
      txt.textContent = rotulo(item);
      txt.title = rotulo(item);
      const x = document.createElement('button');
      x.type = 'button';
      x.title = 'Remover';
      x.setAttribute('aria-label', 'Remover ' + rotulo(item));
      x.innerHTML = '<i class="bi bi-x"></i>';
      x.addEventListener('click', () => { aoRemover(i); sujo = true; });
      chip.append(txt, x);
      el.appendChild(chip);
    });
  }

  function desenharSugestoes(el, itens, aoEscolher) {
    el.innerHTML = '';
    itens.forEach(item => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'be-sugestao';
      const t = document.createElement('span');
      t.textContent = item.texto;
      b.appendChild(t);
      if (item.extra) { const s = document.createElement('small'); s.textContent = item.extra; b.appendChild(s); }
      b.addEventListener('click', () => aoEscolher(item));
      el.appendChild(b);
    });
    el.hidden = !itens.length;
  }

  /* ---------- marcadores ---------- */
  const elMarcadores = qs('[data-be-marcadores]');
  const inMarcador = qs('[data-be-marcador-input]');
  const elSugMarcador = qs('[data-be-marcador-sugestoes]');
  let sugestoesDeMarcador = [];
  let esperaMarcador = null;

  function desenharMarcadores() {
    desenharChips(elMarcadores, S.marcadores, m => m, i => { S.marcadores.splice(i, 1); desenharMarcadores(); });
    inMarcador.disabled = S.marcadores.length >= MAX_MARCADORES;
    inMarcador.placeholder = inMarcador.disabled ? 'Limite de 8 marcadores' : 'Digite e tecle Enter';
  }

  function adicionarMarcador(texto) {
    const t = String(texto || '').replace(/\s+/g, ' ').trim();
    erroNoCampo('marcadores', '');
    if (!t) return;
    const slug = slugDe(t);
    if (!slug) { erroNoCampo('marcadores', 'O marcador precisa ter letras ou números.'); return; }
    if (S.marcadores.some(m => slugDe(m) === slug)) { inMarcador.value = ''; elSugMarcador.hidden = true; return; }
    if (S.marcadores.length >= MAX_MARCADORES) { erroNoCampo('marcadores', 'Use até 8 marcadores.'); return; }
    if (tamanho(t) > MAX_CARACTERES_MARCADOR) { erroNoCampo('marcadores', 'O marcador "' + t + '" passa de 40 caracteres.'); return; }
    // Mesmo marcador já usado em outro artigo com outra grafia: fica a que já existe ("Inss" vira "INSS").
    const existente = sugestoesDeMarcador.find(s => slugDe(s.marcador) === slug);
    S.marcadores.push(existente ? existente.marcador : t);
    sujo = true;
    inMarcador.value = '';
    elSugMarcador.hidden = true;
    desenharMarcadores();
  }

  function buscarMarcadores() {
    const busca = inMarcador.value.trim();
    const url = URLS.marcadores + (busca ? '?busca=' + encodeURIComponent(busca) : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(lista => {
        sugestoesDeMarcador = Array.isArray(lista) ? lista : [];
        if (document.activeElement !== inMarcador) return;
        const escolhidos = S.marcadores.map(slugDe);
        desenharSugestoes(elSugMarcador, sugestoesDeMarcador
          .filter(s => !escolhidos.includes(slugDe(s.marcador)))
          .map(s => ({ texto: s.marcador, extra: s.usos === 1 ? '1 artigo' : s.usos + ' artigos' })),
        item => { adicionarMarcador(item.texto); inMarcador.focus(); });
      })
      .catch(() => { elSugMarcador.hidden = true; });
  }

  inMarcador.addEventListener('input', () => { clearTimeout(esperaMarcador); esperaMarcador = setTimeout(buscarMarcadores, 250); });
  inMarcador.addEventListener('focus', buscarMarcadores);
  inMarcador.addEventListener('blur', () => setTimeout(() => { elSugMarcador.hidden = true; }, 150));
  inMarcador.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); adicionarMarcador(inMarcador.value); }
  });
  // Clique na sugestão sem tirar o foco do campo (o blur fecharia a lista antes do clique).
  elSugMarcador.addEventListener('mousedown', e => e.preventDefault());
  desenharMarcadores();

  /* ---------- catálogo de artigos (relacionados e Leia também) ---------- */
  let catalogo = null;
  function carregarCatalogo() {
    if (catalogo) return catalogo;
    catalogo = (async () => {
      const todos = [];
      for (let pagina = 1; pagina <= 40; pagina++) {
        const r = await fetch(URLS.listar + '?pagina=' + pagina, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error(String(r.status));
        const j = await r.json();
        todos.push(...j.itens);
        if (j.itens.length < (j.porPagina || 50)) break;
      }
      return todos;
    })();
    catalogo.catch(() => { catalogo = null; });
    return catalogo;
  }
  const outrosArtigos = lista => lista.filter(a => a.slug !== valor('slug') && a.slug !== slugSalvo);

  /* ---------- relacionados ---------- */
  const elRelacionados = qs('[data-be-relacionados]');
  const inRelacionado = qs('[data-be-relacionado-input]');
  const elSugRelacionado = qs('[data-be-relacionado-sugestoes]');

  function desenharRelacionados() {
    desenharChips(elRelacionados, S.relacionados, r => r.titulo || r.slug, i => { S.relacionados.splice(i, 1); desenharRelacionados(); });
    inRelacionado.disabled = S.relacionados.length >= MAX_RELACIONADOS;
    inRelacionado.placeholder = inRelacionado.disabled ? 'Limite de 3 artigos' : 'Buscar artigo pelo título';
  }

  function buscarRelacionados() {
    const busca = norm(inRelacionado.value.trim());
    carregarCatalogo().then(lista => {
      if (document.activeElement !== inRelacionado) return;
      const escolhidos = S.relacionados.map(r => r.slug);
      const achados = outrosArtigos(lista)
        .filter(a => !escolhidos.includes(a.slug) && (!busca || norm(a.titulo).includes(busca) || a.slug.includes(busca)))
        .slice(0, 10)
        .map(a => ({ texto: a.titulo, extra: a.situacaoRotulo, slug: a.slug }));
      desenharSugestoes(elSugRelacionado, achados, item => {
        erroNoCampo('relacionados', '');
        if (S.relacionados.length >= MAX_RELACIONADOS) { erroNoCampo('relacionados', 'Escolha até 3 artigos relacionados.'); return; }
        S.relacionados.push({ slug: item.slug, titulo: item.texto });
        sujo = true;
        inRelacionado.value = '';
        elSugRelacionado.hidden = true;
        desenharRelacionados();
      });
    }).catch(() => erroNoCampo('relacionados', 'Não foi possível carregar os artigos. Recarregue a página.'));
  }

  inRelacionado.addEventListener('input', buscarRelacionados);
  inRelacionado.addEventListener('focus', buscarRelacionados);
  inRelacionado.addEventListener('blur', () => setTimeout(() => { elSugRelacionado.hidden = true; }, 150));
  inRelacionado.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  elSugRelacionado.addEventListener('mousedown', e => e.preventDefault());
  desenharRelacionados();

  /* ---------- imagens (P3): envio comum ao corpo e à capa ---------- */
  // Devolve {id, url}; recusa com a mensagem do servidor (422 vem em erros.imagem).
  function enviarImagem(arquivo, nome) {
    if (!id) return Promise.reject(new Error('Salve o rascunho antes de enviar imagens.'));
    if (arquivo.type && !TIPOS_DE_IMAGEM.includes(arquivo.type)) return Promise.reject(new Error('Envie uma imagem JPG, PNG ou WEBP.'));
    if (arquivo.size > MAX_BYTES_IMAGEM) return Promise.reject(new Error('A imagem passa de 5 MB.'));
    const corpo = new FormData();
    corpo.append('arquivo', arquivo, nome || arquivo.name || 'imagem');
    return fetch(comId(URLS.imagens, id), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: corpo,
    }).then(r => r.json().catch(() => null).then(j => {
      if (r.ok && !r.redirected && j && j.id) return j;
      const recusa = j && j.erros && j.erros.imagem ? [].concat(j.erros.imagem)[0] : (j && j.erro);
      throw new Error(recusa || ERRO_PADRAO);
    }));
  }

  const elCapa = qs('[data-be-capa-previa]');
  const imgCapa = qs('[data-be-capa-img]');
  const elCapaVazio = qs('[data-be-capa-vazio]');
  const btCapa = qs('[data-be-capa-enviar]');
  const btCapaRemover = qs('[data-be-capa-remover]');
  const inCapa = qs('[data-be-capa-arquivo]');
  // O envio da capa em andamento: salvar, verificar e pré-visualizar esperam por ele (senão o salvar vai sem a capa).
  let envioDaCapa = null;

  function desenharCapa() {
    elCapa.hidden = !S.capa;
    elCapaVazio.hidden = !!S.capa;
    btCapaRemover.hidden = !S.capa;
    if (S.capa) imgCapa.src = comId(URLS.imagem, S.capa);
    else imgCapa.removeAttribute('src');
    imgCapa.alt = valor('imagemAlt');
    qs('[data-be-capa-enviar-rotulo]').textContent = S.capa ? 'Trocar imagem' : 'Enviar imagem';
  }

  btCapa.addEventListener('click', () => { if (id) inCapa.click(); });
  inCapa.addEventListener('change', () => {
    const arquivo = inCapa.files && inCapa.files[0];
    inCapa.value = '';
    if (!arquivo) return;
    erroNoCampo('imagemChave', '');
    btCapa.disabled = true;
    envioDaCapa = enviarImagem(arquivo).then(j => {
      S.capa = String(j.id);
      sujo = true;
      desenharCapa();
      if (!valor('imagemAlt')) {
        erroNoCampo('imagemAlt', 'Descreva a imagem no texto alternativo.');
        campo('imagemAlt').focus();
      }
    }).catch(e => erroNoCampo('imagemChave', e.message || ERRO_PADRAO))
      .finally(() => { envioDaCapa = null; btCapa.disabled = !id || ocupado; });
  });
  btCapaRemover.addEventListener('click', () => {
    S.capa = null;
    sujo = true;
    erroNoCampo('imagemChave', '');
    desenharCapa();
  });
  campo('imagemAlt').addEventListener('input', () => { imgCapa.alt = valor('imagemAlt'); });
  desenharCapa();

  /* ---------- TinyMCE restrito (seção 3) ---------- */
  // O HTML de cada bloco é exatamente o da tabela da seção 3; o conversor do servidor transforma em diretiva.
  const BLOCOS = {
    aviso: '<div data-bj="aviso"><p>Escreva aqui o aviso.</p></div>',
    etapas: '<div data-bj="etapas"><ol><li><strong>Primeira etapa.</strong> Explique o que fazer.</li><li><strong>Segunda etapa.</strong> Explique o que fazer.</li></ol></div>',
    checklist: '<div data-bj="checklist"><ul><li>Primeiro item</li><li>Segundo item</li></ul></div>',
    ctaMeio: '<div data-bj="cta-meio"></div>',
  };
  const BLOCOS_COM_TEXTO = ['aviso', 'etapas', 'checklist'];

  const ESTILO_DO_CORPO = [
    'body { font-family: Georgia, "Times New Roman", serif; font-size: 17px; line-height: 1.65; color: #1d2b36; max-width: 760px; margin: 16px auto; padding: 0 16px; }',
    'h2 { font-family: Arial, Helvetica, sans-serif; font-size: 24px; margin: 1.4em 0 .5em; }',
    'h3 { font-family: Arial, Helvetica, sans-serif; font-size: 19px; margin: 1.2em 0 .4em; }',
    'a { color: #0a6fa0; }',
    'blockquote { margin: 1em 0; padding: .2em 1em; border-left: 4px solid #c5d2db; color: #4a6373; }',
    'div[data-bj] { position: relative; margin: 1.2em 0; padding: 26px 16px 10px; border-radius: 4px; border: 1px dashed #9fb3c0; background: #f7f9fb; font-family: Arial, Helvetica, sans-serif; font-size: 15px; }',
    'div[data-bj]::before { position: absolute; top: 6px; left: 12px; font-size: 11px; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; color: #5b7383; }',
    'div[data-bj="aviso"] { background: #fff6dc; border-color: #f0dba3; }',
    'div[data-bj="aviso"]::before { content: "Caixa de atenção"; color: #8a6200; }',
    'div[data-bj="etapas"]::before { content: "Etapas"; }',
    'div[data-bj="checklist"]::before { content: "Checklist"; }',
    'div[data-bj="cta-meio"] { background: #e5f1f7; border-color: #bcdcec; min-height: 20px; }',
    'div[data-bj="cta-meio"]::before { content: "Chamada para criar conta"; color: #0a6fa0; }',
    'div[data-bj="leia-tambem"] { min-height: 20px; }',
    'div[data-bj="leia-tambem"]::before { content: "Leia também: " attr(data-artigo); color: #0a6fa0; text-transform: none; font-size: 13px; }',
    'div[data-bj] [contenteditable="true"] { outline: none; }',
  ].join('\n');

  // Bloco no editor: não editável por fora; os filhos dos blocos de texto ficam editáveis por dentro.
  function marcarBloco(no) {
    no.attr('contenteditable', 'false');
    if (!BLOCOS_COM_TEXTO.includes(no.attr('data-bj'))) return;
    for (let filho = no.firstChild; filho; filho = filho.next) {
      if (filho.type === 1) filho.attr('contenteditable', 'true');
    }
  }

  function inserirBloco(html) {
    editor.insertContent(html);
    sujo = true;
  }

  function escolherLeiaTambem() {
    carregarCatalogo().then(lista => {
      const itens = outrosArtigos(lista).map(a => ({ value: a.slug, text: a.titulo + ' (' + a.situacaoRotulo + ')' }));
      if (!itens.length) {
        editor.notificationManager.open({ text: 'Ainda não há outro artigo para indicar.', type: 'info', timeout: 4000 });
        return;
      }
      editor.windowManager.open({
        title: 'Leia também',
        body: { type: 'panel', items: [
          { type: 'selectbox', name: 'artigo', label: 'Artigo indicado', items: itens },
          { type: 'htmlpanel', html: '<p>O bloco só aparece no site quando o artigo escolhido estiver publicado.</p>' },
        ] },
        buttons: [{ type: 'cancel', text: 'Cancelar' }, { type: 'submit', text: 'Inserir', primary: true }],
        onSubmit: api => {
          const slug = api.getData().artigo;
          api.close();
          if (slug) inserirBloco('<div data-bj="leia-tambem" data-artigo="' + esc(slug) + '"></div>');
        },
      });
    }).catch(() => editor.notificationManager.open({ text: 'Não foi possível carregar os artigos. Recarregue a página.', type: 'error', timeout: 5000 }));
  }

  function configurar(ed) {
    ed.on('PreInit', () => {
      ed.parser.addAttributeFilter('data-bj', nos => nos.forEach(marcarBloco));
      // contenteditable é só da tela: não vai para o HTML salvo.
      ed.serializer.addAttributeFilter('contenteditable', nos => nos.forEach(no => no.attr('contenteditable', null)));
    });

    [['bjp', 'Parágrafo', 'p'], ['bjh2', 'Seção', 'h2'], ['bjh3', 'Subseção', 'h3']].forEach(([nome, texto, tag]) => {
      ed.ui.registry.addToggleButton(nome, {
        text: texto,
        tooltip: texto,
        onAction: () => ed.execCommand('FormatBlock', false, tag),
        onSetup: api => {
          const ligacao = ed.formatter.formatChanged(tag, ativo => api.setActive(ativo));
          return () => ligacao.unbind();
        },
      });
    });

    ed.ui.registry.addMenuButton('bjblocos', {
      text: 'Blocos BlueJus',
      fetch: devolver => devolver([
        { type: 'menuitem', text: 'Caixa de atenção', onAction: () => inserirBloco(BLOCOS.aviso) },
        { type: 'menuitem', text: 'Etapas', onAction: () => inserirBloco(BLOCOS.etapas) },
        { type: 'menuitem', text: 'Checklist', onAction: () => inserirBloco(BLOCOS.checklist) },
        { type: 'menuitem', text: 'Chamada para criar conta', onAction: () => inserirBloco(BLOCOS.ctaMeio) },
        { type: 'menuitem', text: 'Leia também...', onAction: escolherLeiaTambem },
      ]),
    });

    ed.on('input change undo redo', () => { sujo = true; });
  }

  // Colar do Word ou de site: sai estilo, classe, id e o resto; o valid_elements já descarta as tags de fora.
  function limparColagem(ed, args) {
    args.node.querySelectorAll('*').forEach(el => {
      Array.from(el.attributes).forEach(at => {
        if (!['href', 'data-bj', 'data-artigo'].includes(at.name)) el.removeAttribute(at.name);
      });
    });
  }

  if (window.tinymce) {
    window.tinymce.init({
      target: qs('[data-be-corpo]'),
      base_url: URLS.tinymce,
      suffix: '.min',
      license_key: 'gpl',
      promotion: false,
      branding: false,
      language: 'pt_BR',
      plugins: 'lists link autolink image',
      menubar: false,
      contextmenu: false,
      browser_spellcheck: true,
      toolbar: 'bjp bjh2 bjh3 | bold italic | link image | bullist numlist blockquote | undo redo | bjblocos',
      toolbar_mode: 'wrap',
      height: 620,
      elementpath: false,
      valid_elements: 'p[contenteditable],h2,h3,strong/b,em/i,a[href],ul[contenteditable],ol[contenteditable],li,blockquote,img[src|alt],br,div[data-bj|data-artigo|contenteditable]',
      entity_encoding: 'raw',
      convert_urls: false,
      relative_urls: false,
      remove_script_host: false,
      link_title: false,
      link_target_list: false,
      link_default_protocol: 'https',
      paste_data_images: false,
      /* Imagem (P3): o diálogo pede o texto alternativo; a aba Enviar manda o arquivo pelo images_upload_handler.
         Sem largura, altura, título nem legenda: o blog só guarda src e alt. */
      image_description: true,
      image_dimensions: false,
      image_title: false,
      image_caption: false,
      image_advtab: false,
      image_uploadtab: true,
      automatic_uploads: true,
      images_file_types: 'jpeg,jpg,png,webp',
      images_reuse_filename: false,
      images_upload_handler: blobInfo => enviarImagem(blobInfo.blob(), blobInfo.filename())
        .then(j => { sujo = true; return j.url; })
        .catch(e => Promise.reject({ message: e.message || ERRO_PADRAO, remove: true })),
      paste_webkit_styles: 'none',
      paste_remove_styles_if_webkit: true,
      paste_postprocess: limparColagem,
      content_style: ESTILO_DO_CORPO,
      setup: configurar,
    }).then(eds => { editor = eds && eds[0] ? eds[0] : null; });
  }

  const corpoHtml = () => (editor ? editor.getContent() : qs('[data-be-corpo]').value);

  /* ---------- salvar, verificar, pré-visualizar, excluir ---------- */
  const BOTOES = ['[data-be-salvar]', '[data-be-verificar]', '[data-be-previa]', '[data-be-excluir]', '[data-be-capa-enviar]'];
  function travar(sim) {
    BOTOES.forEach(sel => { qs(sel).disabled = sim || (sel !== '[data-be-salvar]' && !id); });
    if (envioDaCapa) btCapa.disabled = true;
  }

  // Imagem ainda subindo (capa ou corpo): espera, senão o HTML ou a capa vão sem ela.
  async function esperarEnvios() {
    if (envioDaCapa) await envioDaCapa.catch(() => null);
    if (editor && id) await editor.uploadImages();
  }

  function dados(confirmarTrocaDeSlug) {
    return {
      slug: valor('slug'),
      blogs: valor('blogs'),
      titulo: valor('titulo'),
      tituloCurto: valor('tituloCurto'),
      tituloSeo: valor('tituloSeo'),
      descricao: valor('descricao'),
      linhaFina: valor('linhaFina'),
      resumo: valor('resumo'),
      categoria: valor('categoria'),
      autor: valor('autor'),
      revisorNome: valor('revisorNome'),
      revisorOab: valor('revisorOab'),
      leitura: valor('leitura'),
      destaque: campo('destaque').checked,
      referencias: lerReferencias(),
      relacionados: S.relacionados.map(r => r.slug),
      marcadores: S.marcadores.slice(),
      corpoHtml: corpoHtml(),
      // A capa: o id da imagem enviada para este artigo (ou nada) e o texto alternativo.
      imagemChave: S.capa,
      imagemAlt: valor('imagemAlt') || null,
      confirmarTrocaDeSlug: !!confirmarTrocaDeSlug,
    };
  }

  function postar(url, corpo) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo),
    }).then(r => r.json().catch(() => null).then(j => ({ status: r.status, ok: r.ok && !r.redirected && j !== null, j: j || {} })));
  }

  // O servidor recusou só pelo slug de um artigo que já foi ao ar, trocado sem confirmação.
  const pedeConfirmacaoDoSlug = res => res.status === 422 && jaFoiAoAr && valor('slug') !== slugSalvo
    && res.j.erros && Object.keys(res.j.erros).length === 1 && res.j.erros.slug;

  async function salvar() {
    if (ocupado) return false;
    ocupado = true;
    travar(true);
    limparErros();
    try {
      await esperarEnvios();
      let res = await postar(id ? comId(URLS.salvar, id) : URLS.criar, dados(false));
      if (pedeConfirmacaoDoSlug(res)) {
        const sim = await confirmar({
          titulo: 'Trocar o endereço do artigo', icone: 'bi-link-45deg', cor: '#a3232b', botao: 'Trocar o slug',
          texto: 'Este artigo já foi ao ar em /' + slugSalvo + '. Trocar o slug quebra o link antigo (quem salvou ou compartilhou o endereço cai numa página que não existe). Trocar mesmo assim?',
        });
        if (!sim) { mostrarErros(res.j.erros, 'O rascunho não foi salvo.'); return false; }
        res = await postar(id ? comId(URLS.salvar, id) : URLS.criar, dados(true));
      }
      if (res.ok && res.j.ok) {
        sujo = false;
        if (editor) editor.setDirty(false);
        if (!id) {
          saindo = true;
          window.location.href = comId(URLS.editar, res.j.id);
          return true;
        }
        slugSalvo = valor('slug');
        if (res.j.leitura) campo('leitura').value = res.j.leitura;
        qs('[data-be-pendente]').hidden = !res.j.alteracoesPendentes;
        mostrarMsg(res.j.alteracoesPendentes ? 'Rascunho salvo. O site só muda quando o artigo for publicado de novo.' : 'Rascunho salvo.', 'ok');
        return true;
      }
      if (res.status === 422 && res.j.erros) { mostrarErros(res.j.erros, 'O rascunho não foi salvo. Confira os campos marcados.'); return false; }
      mostrarMsg(res.j.erro || ERRO_PADRAO, 'erro');
      return false;
    } catch (e) {
      mostrarMsg(ERRO_PADRAO, 'erro');
      return false;
    } finally {
      ocupado = false;
      travar(false);
    }
  }

  async function verificar() {
    if (!id || ocupado) return;
    await esperarEnvios();
    if (sujo && !(await salvar())) return;
    ocupado = true;
    travar(true);
    try {
      const res = await postar(comId(URLS.verificar, id), {});
      if (!res.ok) { mostrarMsg(res.j.erro || ERRO_PADRAO, 'erro'); return; }
      const erros = res.j.erros || {};
      if (!Object.keys(erros).length) { limparErros(); mostrarMsg('Tudo certo: o artigo está pronto para ir ao ar.', 'ok'); return; }
      mostrarErros(erros, 'Falta corrigir antes de ir ao ar:');
    } catch (e) {
      mostrarMsg(ERRO_PADRAO, 'erro');
    } finally {
      ocupado = false;
      travar(false);
    }
  }

  async function previa() {
    if (!id || ocupado) return;
    // A janela abre já no clique (bloqueador de pop-up); o endereço entra depois de salvar.
    const janela = window.open('', '_blank');
    await esperarEnvios();
    if (sujo && !(await salvar())) { if (janela) janela.close(); return; }
    const url = comId(URLS.previa, id);
    if (janela) { janela.opener = null; janela.location.href = url; } else { window.open(url, '_blank', 'noopener'); }
  }

  async function excluir() {
    if (!id || ocupado) return;
    const sim = await confirmar({
      titulo: 'Excluir artigo', icone: 'bi-trash3', cor: '#a3232b', botao: 'Excluir',
      texto: 'Excluir o artigo "' + (valor('titulo') || slugSalvo) + '"? Esta ação não pode ser desfeita.',
    });
    if (!sim) return;
    ocupado = true;
    travar(true);
    try {
      const res = await postar(comId(URLS.excluir, id), {});
      if (res.ok && res.j.ok) { saindo = true; window.location.href = URLS.painel; return; }
      mostrarMsg(res.j.erro || ERRO_PADRAO, 'erro');
    } catch (e) {
      mostrarMsg(ERRO_PADRAO, 'erro');
    } finally {
      ocupado = false;
      travar(false);
    }
  }

  qs('[data-be-salvar]').addEventListener('click', salvar);
  qs('[data-be-verificar]').addEventListener('click', verificar);
  qs('[data-be-previa]').addEventListener('click', previa);
  qs('[data-be-excluir]').addEventListener('click', excluir);

  /* ---------- alterações na tela ---------- */
  const fora = el => el.matches('[data-be-marcador-input], [data-be-relacionado-input]');
  ['input', 'change'].forEach(tipo => raiz.addEventListener(tipo, e => {
    if (!e.target.matches('input, select, textarea') || fora(e.target)) return;
    sujo = true;
    atualizarContadores();
    atualizarGoogle();
  }));
  window.addEventListener('beforeunload', e => {
    if (!sujo || saindo) return;
    e.preventDefault();
    e.returnValue = '';
  });

  atualizarContadores();
  atualizarGoogle();
})();
