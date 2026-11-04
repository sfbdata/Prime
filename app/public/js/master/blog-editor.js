/* BlueJus · Editor de artigo dos blogs (spec docs/specs/painel-artigos-blogs.md, seções 2.2, 3 e 11).
   Página própria do Master (templates/blog/editor.html.twig). Sem desenho do Claude Design (D1).
   - O artigo chega em data-artigo (ArtigoParaEditorOutput) e as URLs em data-urls (id 0 nas rotas com id).
   - Salvar rascunho: POST JSON (criar ou salvar) com o token master_blog no header X-CSRF-Token. Erro 422 vem como
     {erros: {campo: [mensagens]}} e aparece junto ao campo. Trocar o slug de um artigo que já foi ao ar: o servidor
     recusa pedindo confirmação; a tela pergunta e reenvia com confirmarTrocaDeSlug = true. Depois de criar, a página
     vai para /master/blog/artigos/{id}.
   - Verificar e Pré-visualizar trabalham com a versão salva: com alteração na tela, salvam antes.
   - Publicar agora e Despublicar (P4): POST publicar/despublicar; o servidor muda a situação e pede a geração dos blogs
     ao builder. Publicar salva antes o que mudou na tela e mostra os erros do zod por campo (422). Despublicar confirma.
   - TinyMCE restrito (seção 3): Parágrafo, Seção (h2), Subseção (h3), negrito, itálico, link, listas, citação,
     desfazer/refazer e o menu Blocos BlueJus, que gera exatamente o HTML da tabela da seção 3 (div[data-bj]). Os
     blocos ficam não editáveis por fora e editáveis por dentro (contenteditable só na tela; sai ao salvar).
     Do editor da pasta vêm Revisar texto, Localizar (Ctrl+H), Diminuir/Aumentar recuo (só em lista: sublista) e
     Inserir símbolo; ver a seção "Revisar texto, Localizar, recuo da lista e símbolos" abaixo.
     Inserir tabela (plugin table): tabela simples, a primeira linha é o cabeçalho (thead > th) e vira a linha de
     cabeçalho da tabela GFM no Markdown; dentro da tabela, as ações da pasta (inserir e remover linha e coluna,
     remover tabela) na barra de contexto. Sem mesclar, estilo, largura nem atributo.
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
  let jaFoiAoAr = !!A.jaFoiAoAr;
  let situacao = A.situacao || 'rascunho';
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
    'table { border-collapse: collapse; margin: 1.2em 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; }',
    'th, td { border: 1px solid #c5d2db; padding: 6px 10px; text-align: left; vertical-align: top; min-width: 48px; }',
    'th { background: #f7f9fb; font-weight: bold; }',
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

  /* ---------- Revisar texto, Localizar, recuo da lista e símbolos (os do editor da pasta) ----------
     Os mesmos botões, dicas e ícones (Bootstrap Icons) do editor da pasta (public/js/editor-rico.js). O motor da
     revisão, os dicionários e os símbolos vêm do módulo comum public/js/revisao-de-texto.js; as janelas usam as
     classes do editor da pasta (css/editor-rico.css: editor-rico-janela, editor-rico-revisao*, editor-rico-simbolos),
     abertas dentro da casca .editor-rico que envolve o TinyMCE no template.
     - Revisar texto: lista os problemas (Ortografia, Palavra repetida, Espaço duplo); cada "Corrigir" troca só os
       nós de texto (a marcação fica) dentro de um undoManager.transact, então cada correção é um passo de desfazer.
     - Localizar: o plugin searchreplace do TinyMCE, aberto pelo botão próprio e por Ctrl+H.
     - Recuo: só dentro de lista, e vira sublista (ul/ol dentro do li, pelo plugin lists). Fora de lista o botão fica
       desabilitado: recuo de parágrafo seria style, que o valid_elements e a lista branca do servidor descartam.
     - Símbolo: a grade de símbolos da pasta; o escolhido entra como texto no cursor. */
  const Revisao = window.RevisaoDeTexto || null;
  const casca = qs('[data-be-casca]');
  const svgBi = caminhos => '<svg width="24" height="24" viewBox="-2 -2 20 20" focusable="false">'
    + caminhos.map(d => '<path d="' + d + '"/>').join('') + '</svg>';
  const ICONES_BI = {
    'bj-spellcheck': svgBi([
      'M8.217 11.068c1.216 0 1.948-.869 1.948-2.31v-.702c0-1.44-.727-2.305-1.929-2.305-.742 0-1.328.347-1.499.889h-.063V3.983h-1.29V11h1.27v-.791h.064c.21.532.776.86 1.499.86zm-.43-1.025c-.66 0-1.113-.518-1.113-1.28V8.12c0-.825.42-1.343 1.098-1.343.684 0 1.075.518 1.075 1.416v.45c0 .888-.386 1.401-1.06 1.401zm-5.583 1.035c.767 0 1.201-.356 1.406-.737h.059V11h1.216V7.519c0-1.314-.947-1.783-2.11-1.783C1.355 5.736.75 6.42.69 7.27h1.216c.064-.323.313-.552.84-.552s.864.249.864.771v.464H2.346C1.145 7.953.5 8.568.5 9.496c0 .977.693 1.582 1.704 1.582m.42-.947c-.44 0-.845-.235-.845-.718 0-.395.269-.684.84-.684h.991v.538c0 .503-.444.864-.986.864m8.897.567c-.577-.4-.9-1.088-.9-1.983v-.65c0-1.42.894-2.338 2.305-2.338 1.352 0 2.119.82 2.139 1.806h-1.187c-.04-.351-.283-.776-.918-.776-.674 0-1.045.517-1.045 1.328v.625c0 .468.121.834.343 1.067z',
      'M14.469 9.414a.75.75 0 0 1 .117 1.055l-4 5a.75.75 0 0 1-1.116.061l-2.5-2.5a.75.75 0 1 1 1.06-1.06l1.908 1.907 3.476-4.346a.75.75 0 0 1 1.055-.117',
    ]),
    'bj-search': svgBi([
      'M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0',
    ]),
    'bj-text-indent-right': svgBi([
      'M2 3.5a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5m10.646 2.146a.5.5 0 0 1 .708.708L11.707 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2a.5.5 0 0 1 0-.708zM2 6.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m0 3a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m0 3a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5',
    ]),
    'bj-text-indent-left': svgBi([
      'M2 3.5a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5m.646 2.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L4.293 8 2.646 6.354a.5.5 0 0 1 0-.708M7 6.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m0 3a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m-5 3a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5',
    ]),
    // O botão de símbolos da pasta mostra o próprio "§" (editor-rico-icone-simbolo).
    'bj-simbolo': '<svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><text x="12" y="17.5" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700">§</text></svg>',
    // Tabela: bi-table e os ícones das ações da tabela da pasta (ACOES_DA_TABELA do editor-rico.js).
    'bj-table': svgBi([
      'M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm15 2h-4v3h4zm0 4h-4v3h4zm0 4h-4v3h3a1 1 0 0 0 1-1zm-5 3v-3H6v3zm-5 0v-3H1v2a1 1 0 0 0 1 1zm-4-4h4V8H1zm0-4h4V4H1zm5-3v3h4V4zm4 4H6v3h4z',
    ]),
    'bj-arrow-bar-up': svgBi([
      'M8 10a.5.5 0 0 0 .5-.5V3.707l2.146 2.147a.5.5 0 0 0 .708-.708l-3-3a.5.5 0 0 0-.708 0l-3 3a.5.5 0 1 0 .708.708L7.5 3.707V9.5a.5.5 0 0 0 .5.5m-7 2.5a.5.5 0 0 1 .5-.5h13a.5.5 0 0 1 0 1h-13a.5.5 0 0 1-.5-.5',
    ]),
    'bj-arrow-bar-down': svgBi([
      'M1 3.5a.5.5 0 0 1 .5-.5h13a.5.5 0 0 1 0 1h-13a.5.5 0 0 1-.5-.5M8 6a.5.5 0 0 1 .5.5v5.793l2.146-2.147a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 .708-.708L7.5 12.293V6.5A.5.5 0 0 1 8 6',
    ]),
    'bj-arrow-bar-left': svgBi([
      'M12.5 15a.5.5 0 0 1-.5-.5v-13a.5.5 0 0 1 1 0v13a.5.5 0 0 1-.5.5M10 8a.5.5 0 0 1-.5.5H3.707l2.147 2.146a.5.5 0 0 1-.708.708l-3-3a.5.5 0 0 1 0-.708l3-3a.5.5 0 1 1 .708.708L3.707 7.5H9.5a.5.5 0 0 1 .5.5',
    ]),
    'bj-arrow-bar-right': svgBi([
      'M6 8a.5.5 0 0 0 .5.5h5.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L12.293 7.5H6.5A.5.5 0 0 0 6 8m-2.5 7a.5.5 0 0 1-.5-.5v-13a.5.5 0 0 1 1 0v13a.5.5 0 0 1-.5.5',
    ]),
    'bj-dash-square': svgBi([
      'M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z',
      'M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8',
    ]),
    'bj-dash-square-dotted': svgBi([
      'M2.5 0q-.25 0-.487.048l.194.98A1.5 1.5 0 0 1 2.5 1h.458V0zm2.292 0h-.917v1h.917zm1.833 0h-.917v1h.917zm1.833 0h-.916v1h.916zm1.834 0h-.917v1h.917zm1.833 0h-.917v1h.917zM13.5 0h-.458v1h.458q.151 0 .293.029l.194-.981A2.5 2.5 0 0 0 13.5 0m2.079 1.11a2.5 2.5 0 0 0-.69-.689l-.556.831q.248.167.415.415l.83-.556zM1.11.421a2.5 2.5 0 0 0-.689.69l.831.556c.11-.164.251-.305.415-.415zM16 2.5q0-.25-.048-.487l-.98.194q.027.141.028.293v.458h1zM.048 2.013A2.5 2.5 0 0 0 0 2.5v.458h1V2.5q0-.151.029-.293zM0 3.875v.917h1v-.917zm16 .917v-.917h-1v.917zM0 5.708v.917h1v-.917zm16 .917v-.917h-1v.917zM0 7.542v.916h1v-.916zm15 .916h1v-.916h-1zM0 9.375v.917h1v-.917zm16 .917v-.917h-1v.917zm-16 .916v.917h1v-.917zm16 .917v-.917h-1v.917zm-16 .917v.458q0 .25.048.487l.98-.194A1.5 1.5 0 0 1 1 13.5v-.458zm16 .458v-.458h-1v.458q0 .151-.029.293l.981.194Q16 13.75 16 13.5M.421 14.89c.183.272.417.506.69.689l.556-.831a1.5 1.5 0 0 1-.415-.415zm14.469.689c.272-.183.506-.417.689-.69l-.831-.556c-.11.164-.251.305-.415.415l.556.83zm-12.877.373Q2.25 16 2.5 16h.458v-1H2.5q-.151 0-.293-.029zM13.5 16q.25 0 .487-.048l-.194-.98A1.5 1.5 0 0 1 13.5 15h-.458v1zm-9.625 0h.917v-1h-.917zm1.833 0h.917v-1h-.917zm1.834 0h.916v-1h-.916zm1.833 0h.917v-1h-.917zm1.833 0h.917v-1h-.917zM4.5 7.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1z',
    ]),
    'bj-trash3': svgBi([
      'M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5.03a.5.5 0 0 1 .47-.53Zm5.058 0a.5.5 0 0 1 .47.53l-.5 8.5a.5.5 0 1 1-.998-.06l.5-8.5a.5.5 0 0 1 .528-.47M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5',
    ]),
  };
  const DICAS = {
    revisar: 'Revisar texto',
    localizar: 'Localizar e substituir (Ctrl+H)',
    diminuirRecuo: 'Diminuir recuo (Shift+Tab)',
    aumentarRecuo: 'Aumentar recuo (Tab)',
    simbolo: 'Inserir símbolo',
    tabela: 'Inserir tabela',
  };
  // As ações da tabela da pasta (editor-rico.js, ACOES_DA_TABELA): botão, ícone, texto e o comando do plugin table.
  const ACOES_DA_TABELA = [
    ['bjtabelalinhaacima', 'bj-arrow-bar-up', 'Inserir linha acima', 'mceTableInsertRowBefore'],
    ['bjtabelalinhaabaixo', 'bj-arrow-bar-down', 'Inserir linha abaixo', 'mceTableInsertRowAfter'],
    ['bjtabelacolunaesquerda', 'bj-arrow-bar-left', 'Inserir coluna à esquerda', 'mceTableInsertColBefore'],
    ['bjtabelacolunadireita', 'bj-arrow-bar-right', 'Inserir coluna à direita', 'mceTableInsertColAfter'],
    ['bjtabelaremoverlinha', 'bj-dash-square', 'Remover linha', 'mceTableDeleteRow'],
    ['bjtabelaremovercoluna', 'bj-dash-square-dotted', 'Remover coluna', 'mceTableDeleteCol'],
    ['bjtabelaremover', 'bj-trash3', 'Remover tabela', 'mceTableDelete'],
  ];
  let janela = null;

  const elemento = (tag, classe, texto) => {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  };
  // Clicar na janela não tira a seleção do texto (o cursor fica onde estava, como na pasta).
  const semRoubarFoco = botao => { botao.addEventListener('mousedown', e => e.preventDefault()); return botao; };
  const botaoDaBarra = dica => (editor ? editor.getContainer().querySelector('.tox-tbtn[aria-label="' + dica + '"]') : null);

  function fecharJanela() {
    if (!janela) return;
    janela.elemento.remove();
    document.removeEventListener('mousedown', janela.foraDela, true);
    janela = null;
  }

  // A posição segue a da pasta (abrirJanela): logo abaixo do botão, sem passar da largura da casca.
  function abrirJanela(dica, conteudo) {
    fecharJanela();
    const el = elemento('div', 'editor-rico-janela');
    el.setAttribute('role', 'dialog');
    el.appendChild(conteudo);
    casca.appendChild(el);
    const botao = botaoDaBarra(dica);
    const caixa = casca.getBoundingClientRect();
    const b = botao ? botao.getBoundingClientRect() : caixa;
    let esquerda = b.left - caixa.left;
    if (esquerda + el.offsetWidth > caixa.width) esquerda = Math.max(0, caixa.width - el.offsetWidth);
    el.style.left = esquerda + 'px';
    el.style.top = (b.bottom - caixa.top + 4) + 'px';
    const foraDela = e => { if (!el.contains(e.target) && !(botao && botao.contains(e.target))) fecharJanela(); };
    document.addEventListener('mousedown', foraDela, true);
    janela = { elemento: el, dica, foraDela };
  }

  // Clicar de novo no botão da janela aberta fecha a janela (como na pasta).
  function alternarJanela(dica, montar) {
    if (janela && janela.dica === dica) { fecharJanela(); editor.focus(); return; }
    abrirJanela(dica, montar());
  }

  const BLOCO_DE_TEXTO = /^(P|H[1-6]|LI|UL|OL|BLOCKQUOTE|DIV|BR|TABLE|TR|TH|TD)$/;

  /* O texto do corpo como a revisão o lê: cada bloco numa linha ("\n", para "palavra repetida" não juntar fim e
     começo de parágrafos) e, para cada nó de texto editável, onde ele começa nesse texto. */
  function textoDoCorpo() {
    const partes = [];
    const segmentos = [];
    let total = 0;
    const quebra = () => { if (total > 0 && partes[partes.length - 1] !== '\n') { partes.push('\n'); total += 1; } };
    const andar = no => {
      for (let f = no.firstChild; f; f = f.nextSibling) {
        if (f.nodeType === 3) {
          if (!f.parentNode.isContentEditable) continue;
          segmentos.push({ no: f, inicio: total, tamanho: f.data.length });
          partes.push(f.data);
          total += f.data.length;
        } else if (f.nodeType === 1 && f.getAttribute('data-mce-bogus') !== 'all') {
          const bloco = BLOCO_DE_TEXTO.test(f.nodeName);
          if (bloco) quebra();
          andar(f);
          if (bloco) quebra();
        }
      }
    };
    andar(editor.getBody());
    return { texto: partes.join(''), segmentos };
  }

  /* Troca os trechos ([{inicio, tamanho, para}], índices de textoDoCorpo) direto nos nós de texto: a marcação em
     volta (negrito, link, bloco) fica como estava. Do fim para o começo, para os índices não andarem. Trecho que
     atravessa nós: o texto novo entra no primeiro e o resto do trecho sai dos seguintes. */
  function trocarTrechos(trechos, segmentos) {
    trechos.slice().sort((a, b) => b.inicio - a.inicio).forEach(t => {
      const fim = t.inicio + t.tamanho;
      let primeiro = true;
      segmentos.forEach(s => {
        if (s.inicio >= fim || s.inicio + s.tamanho <= t.inicio) return;
        const de = Math.max(0, t.inicio - s.inicio);
        const ate = Math.min(s.tamanho, fim - s.inicio);
        s.no.data = s.no.data.slice(0, de) + (primeiro ? t.para : '') + s.no.data.slice(ate);
        primeiro = false;
      });
    });
  }

  // Cada clique é um passo de desfazer ("Corrigir tudo" também é um só); cada problema relê o texto
  // (a troca anterior pode ter mudado as posições).
  function corrigirProblemas(problemas) {
    editor.undoManager.transact(() => {
      problemas.forEach(problema => {
        const lido = textoDoCorpo();
        trocarTrechos(problema.trechos.call(problema, lido.texto), lido.segmentos);
      });
    });
    editor.nodeChanged();
    sujo = true;
  }
  const corrigirProblema = problema => corrigirProblemas([problema]);

  // A janela da pasta (conteudoDaRevisao), com os mesmos textos.
  function conteudoDaRevisao() {
    const caixa = elemento('div', 'editor-rico-revisao');
    caixa.appendChild(elemento('div', 'editor-rico-janela-titulo', 'Revisão do texto'));
    const problemas = Revisao.problemasDoTexto(textoDoCorpo().texto);

    if (problemas.length === 0) {
      const ok = elemento('div', 'editor-rico-revisao-ok');
      ok.innerHTML = '<i class="bi bi-check-circle-fill" aria-hidden="true"></i>';
      ok.appendChild(document.createTextNode('Nenhum problema encontrado.'));
      caixa.appendChild(ok);
      return caixa;
    }

    problemas.forEach(p => {
      const item = semRoubarFoco(elemento('button', 'editor-rico-revisao-item'));
      item.type = 'button';
      const corpo = elemento('span', 'editor-rico-revisao-corpo');
      corpo.appendChild(elemento('span', 'editor-rico-revisao-tipo', p.tipo));
      const troca = elemento('span');
      troca.appendChild(elemento('s', 'editor-rico-revisao-de', p.de));
      troca.insertAdjacentHTML('beforeend', ' <i class="bi bi-arrow-right editor-rico-revisao-seta" aria-hidden="true"></i> ');
      troca.appendChild(elemento('b', 'editor-rico-revisao-para', p.para));
      corpo.appendChild(troca);
      item.appendChild(corpo);
      item.appendChild(elemento('span', 'editor-rico-revisao-acao', 'Corrigir'));
      item.addEventListener('click', () => {
        corrigirProblema(p);
        abrirJanela(DICAS.revisar, conteudoDaRevisao());
      });
      caixa.appendChild(item);
    });

    const todos = semRoubarFoco(elemento('button', 'editor-rico-busca-botao editor-rico-busca-botao-principal editor-rico-revisao-todos',
      'Corrigir tudo (' + problemas.length + ')'));
    todos.type = 'button';
    todos.addEventListener('click', () => {
      corrigirProblemas(problemas);
      abrirJanela(DICAS.revisar, conteudoDaRevisao());
    });
    caixa.appendChild(todos);
    return caixa;
  }

  // A grade de símbolos da pasta (abrirSimbolos): o escolhido entra como texto no cursor.
  function conteudoDosSimbolos() {
    const caixa = elemento('div');
    caixa.appendChild(elemento('div', 'editor-rico-janela-titulo', 'Símbolos'));
    const grade = elemento('div', 'editor-rico-simbolos');
    Revisao.SIMBOLOS.forEach(s => {
      const b = semRoubarFoco(elemento('button', '', s));
      b.type = 'button';
      b.title = 'Inserir ' + s;
      b.setAttribute('aria-label', 'Inserir ' + s);
      b.addEventListener('click', () => {
        fecharJanela();
        editor.focus();
        editor.insertContent(esc(s));
        sujo = true;
      });
      grade.appendChild(b);
    });
    caixa.appendChild(grade);
    return caixa;
  }

  /* Recuo só em item de lista: aumentar pede um item anterior (a sublista entra dentro dele); diminuir, um item
     que já está numa sublista. Assim o recuo nunca sai da lista nem vira style. */
  function itensSelecionados(ed) {
    const blocos = ed.selection.getSelectedBlocks();
    return blocos.length && blocos.every(b => b.nodeName === 'LI') ? blocos : null;
  }
  const podeAumentar = ed => { const itens = itensSelecionados(ed); return !!itens && !!itens[0].previousElementSibling; };
  const podeDiminuir = ed => {
    const itens = itensSelecionados(ed);
    return !!itens && itens.every(li => li.parentNode && li.parentNode.parentNode && li.parentNode.parentNode.nodeName === 'LI');
  };

  function configurarBotoesDaPasta(ed) {
    Object.keys(ICONES_BI).forEach(nome => ed.ui.registry.addIcon(nome, ICONES_BI[nome]));

    ed.ui.registry.addButton('bjrevisar', {
      icon: 'bj-spellcheck',
      tooltip: DICAS.revisar,
      onAction: () => { if (Revisao) alternarJanela(DICAS.revisar, conteudoDaRevisao); },
    });
    ed.ui.registry.addButton('bjlocalizar', {
      icon: 'bj-search',
      tooltip: DICAS.localizar,
      onAction: () => { fecharJanela(); ed.execCommand('SearchReplace'); },
    });
    ed.addShortcut('meta+h', DICAS.localizar, () => { fecharJanela(); ed.execCommand('SearchReplace'); });
    // O plugin searchreplace também liga o Ctrl+F; a pasta só tem o Ctrl+H, e o Ctrl+F fica com o navegador.
    ed.on('init', () => ed.shortcuts.remove('meta+f'));

    [['bjdiminuirrecuo', 'bj-text-indent-right', DICAS.diminuirRecuo, 'Outdent', podeDiminuir],
      ['bjaumentarrecuo', 'bj-text-indent-left', DICAS.aumentarRecuo, 'Indent', podeAumentar]].forEach(([nome, icone, dica, comando, pode]) => {
      ed.ui.registry.addButton(nome, {
        icon: icone,
        tooltip: dica,
        onAction: () => { if (pode(ed)) ed.execCommand(comando); },
        onSetup: api => {
          const atualizar = () => api.setEnabled(pode(ed));
          atualizar();
          ed.on('NodeChange', atualizar);
          return () => ed.off('NodeChange', atualizar);
        },
      });
    });

    // Tab e Shift+Tab na lista seguem a mesma regra dos botões (o plugin lists trataria a tecla sem trava e
    // deixaria um item vazio por cima da sublista). Entra antes do plugin (prepend) e o barra quando não pode.
    ed.on('keydown', e => {
      if (e.key !== 'Tab' || !itensSelecionados(ed)) return;
      if (!(e.shiftKey ? podeDiminuir(ed) : podeAumentar(ed))) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }, true);

    ed.ui.registry.addButton('bjsimbolo', {
      icon: 'bj-simbolo',
      tooltip: DICAS.simbolo,
      onAction: () => { if (Revisao) alternarJanela(DICAS.simbolo, conteudoDosSimbolos); },
    });

    /* Inserir tabela: fora de tabela, a grade linhas × colunas do plugin table (fancymenuitem inserttable); a tabela
       nasce com a primeira linha como cabeçalho (thead > tr > th, table_header_type sectionCells), que é a linha de
       cabeçalho da tabela no Markdown. A tabela só fica solta no corpo: dentro de tabela, item de lista, citação ou
       bloco BlueJus o botão fica desabilitado (a lista branca do servidor a transformaria em texto). Dentro da
       tabela, as ações aparecem na barra de contexto (table_toolbar), com os ícones e textos da pasta. */
    const podeInserirTabela = () => !ed.dom.getParent(ed.selection.getNode(), 'table,li,blockquote,div[data-bj]', ed.getBody());
    ed.ui.registry.addMenuButton('bjtabela', {
      icon: 'bj-table',
      tooltip: DICAS.tabela,
      fetch: devolver => devolver([{
        type: 'fancymenuitem',
        fancytype: 'inserttable',
        onAction: d => {
          if (!podeInserirTabela()) return;
          fecharJanela();
          ed.execCommand('mceInsertTable', false, { rows: d.numRows, columns: d.numColumns, options: { headerRows: 1 } });
          sujo = true;
        },
      }]),
      onSetup: api => {
        const atualizar = () => api.setEnabled(podeInserirTabela());
        atualizar();
        ed.on('NodeChange', atualizar);
        return () => ed.off('NodeChange', atualizar);
      },
    });
    ACOES_DA_TABELA.forEach(([nome, icone, texto, comando]) => {
      ed.ui.registry.addButton(nome, {
        icon: icone,
        tooltip: texto,
        onAction: () => { ed.execCommand(comando); sujo = true; },
      });
    });

    // Clique no texto ou Esc fecham a janela aberta (o clique dentro do iframe não chega ao document).
    ed.on('mousedown', fecharJanela);
    ed.on('keydown', e => { if (e.key === 'Escape' && janela) { e.preventDefault(); fecharJanela(); } });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && janela) fecharJanela(); });
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

    configurarBotoesDaPasta(ed);

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
      plugins: 'lists link autolink image searchreplace table',
      menubar: false,
      contextmenu: false,
      browser_spellcheck: true,
      toolbar: 'bjp bjh2 bjh3 | bold italic | link image | bullist numlist bjdiminuirrecuo bjaumentarrecuo blockquote bjsimbolo bjtabela | undo redo | bjrevisar bjlocalizar | bjblocos',
      toolbar_mode: 'wrap',
      height: 620,
      elementpath: false,
      valid_elements: 'p[contenteditable],h2,h3,strong/b,em/i,a[href],ul[contenteditable],ol[contenteditable],li,blockquote,img[src|alt],br,div[data-bj|data-artigo|contenteditable],table,thead,tbody,tr,th,td',
      entity_encoding: 'raw',
      convert_urls: false,
      relative_urls: false,
      remove_script_host: false,
      link_title: false,
      link_target_list: false,
      link_default_protocol: 'https',
      paste_data_images: false,
      /* Tabela simples: cabeçalho e linhas, sem aparência. Sem propriedades, abas avançadas, barras de redimensionar,
         largura, colgroup, estilo nem atributo; a alça de redimensionar fica só na imagem. */
      table_toolbar: ACOES_DA_TABELA.map(a => a[0]).join(' '),
      table_header_type: 'sectionCells',
      table_appearance_options: false,
      table_advtab: false,
      table_cell_advtab: false,
      table_row_advtab: false,
      table_resize_bars: false,
      table_sizing_mode: 'responsive',
      table_default_styles: {},
      table_default_attributes: {},
      table_use_colgroups: false,
      table_style_by_css: false,
      object_resizing: 'img',
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

  /* ---------- salvar, verificar, pré-visualizar, publicar, despublicar, excluir ---------- */
  const BOTOES = ['[data-be-salvar]', '[data-be-publicar]', '[data-be-despublicar]', '[data-be-verificar]', '[data-be-previa]', '[data-be-excluir]', '[data-be-capa-enviar]'];
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

  // Situação depois de Publicar ou Despublicar: a pílula, o aviso de alterações e os botões do cartão Publicação.
  function mostrarSituacao(j) {
    situacao = j.situacao;
    jaFoiAoAr = situacao === 'publicado' || situacao === 'despublicado';
    const pilula = qs('[data-be-situacao]');
    pilula.textContent = j.situacaoRotulo;
    pilula.className = 'be-st be-st-' + situacao;
    qs('[data-be-pendente]').hidden = !j.alteracoesPendentes;
    qs('[data-be-publicar-rotulo]').textContent = situacao === 'publicado' ? 'Publicar alterações' : 'Publicar agora';
    qs('[data-be-despublicar]').hidden = situacao !== 'publicado';
  }

  // Publicar agora: salva antes o que mudou na tela; a recusa do zod volta por campo (422).
  async function publicar() {
    if (!id || ocupado) return;
    await esperarEnvios();
    if (sujo && !(await salvar())) return;
    ocupado = true;
    travar(true);
    try {
      const res = await postar(comId(URLS.publicar, id), {});
      if (res.ok && res.j.ok) {
        limparErros();
        mostrarSituacao(res.j);
        mostrarMsg('Artigo publicado. Os blogs estão sendo gerados de novo, e o site muda em poucos minutos.', 'ok');
        return;
      }
      if (res.status === 422 && res.j.erros) { mostrarErros(res.j.erros, 'O artigo não foi publicado. Falta corrigir:'); return; }
      mostrarMsg(res.j.erro || ERRO_PADRAO, 'erro');
    } catch (e) {
      mostrarMsg(ERRO_PADRAO, 'erro');
    } finally {
      ocupado = false;
      travar(false);
    }
  }

  async function despublicar() {
    if (!id || ocupado) return;
    const sim = await confirmar({
      titulo: 'Despublicar artigo', icone: 'bi-eye-slash', cor: '#a3232b', botao: 'Despublicar',
      texto: 'Tirar o artigo "' + (valor('titulo') || slugSalvo) + '" do site? O endereço deixa de funcionar quando os blogs forem gerados de novo, em poucos minutos. O texto fica guardado aqui.',
    });
    if (!sim) return;
    ocupado = true;
    travar(true);
    try {
      const res = await postar(comId(URLS.despublicar, id), {});
      if (res.ok && res.j.ok) {
        mostrarSituacao(res.j);
        mostrarMsg('Artigo despublicado. Ele sai do site quando os blogs terminarem de ser gerados.', 'ok');
        return;
      }
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
  qs('[data-be-publicar]').addEventListener('click', publicar);
  qs('[data-be-despublicar]').addEventListener('click', despublicar);

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
