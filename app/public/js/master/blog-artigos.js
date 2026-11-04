/* BlueJus · Painel do Master, seção "Artigos dos blogs" (spec docs/specs/painel-artigos-blogs.md, seção 2.1).
   Sem desenho do Claude Design (D1): segue o padrão das seções do painel (a Revisão de OAB é o modelo).
   - O painel.js chama BJBlogArtigos.montar(raiz, ctx) ao abrir a seção; ctx traz as URLs e o token (csrf master_blog).
   - A lista vem do GET /master/blog/artigos (busca, situacao, blogs, pagina); Duplicar e Excluir são POST JSON com
     X-CSRF-Token e, dando certo, a lista é lida de novo. Erro do servidor ({erro}) aparece na caixa de mensagem.
   - Editar abre a página própria do editor; Ver no site só aparece no artigo publicado.
   - Faixa da última publicação (P4): GET /master/blog/publicacoes (as 20 últimas gerações dos blogs). "No ar desde",
     "Gerando os blogs..." ou "A última publicação falhou", com o histórico aberto por "ver detalhes". Enquanto há
     geração na fila ou gerando, a faixa é lida de novo a cada 20 segundos (só com a seção aberta).
   - Título, slug e categoria são dado do artigo: sempre passam por esc(). */
(() => {
  'use strict';
  // Aspas pelo código (\x22 e \x27), como no painel.js.
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  const dataHora = v => (window.BJFormato ? window.BJFormato.dataHora(v) : String(v || '').replace('T', ' '));

  const SITUACOES = [['', 'Todas as situações'], ['rascunho', 'Rascunhos'], ['agendado', 'Agendados'], ['publicado', 'Publicados'], ['despublicado', 'Despublicados']];
  const BLOGS = [['', 'Todos os blogs'], ['normal', 'Blog BlueJus'], ['gratis', 'Blog BlueJus Grátis'], ['ambos', 'Os dois']];
  const ERRO_PADRAO = 'Não foi possível concluir agora. Tente de novo.';

  let raiz = null;
  let ctx = null;
  let R = null; // resposta do GET: {itens, total, pagina, porPagina}
  let falhou = false;
  let filtro = { busca: '', situacao: '', blogs: '', pagina: 1 };
  let msg = '';
  let erro = '';
  let ocupado = false;
  let espera = null;
  let P = null; // histórico das gerações: {itens}
  let historicoAberto = false;
  let vigia = null;

  // Troca o id 0 do modelo da rota (/master/blog/artigos/0 e /master/blog/artigos/0/acao) pelo id do artigo.
  const comId = (modelo, id) => modelo.replace(/\/0(\/|$)/, '/' + encodeURIComponent(id) + '$1');

  const pedir = (url, opcoes) => fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
    .then(r => r.json().catch(() => null).then(j => {
      const json = !r.redirected && j !== null && typeof j === 'object';
      if (json && r.ok) return j;
      return Promise.reject(new Error(json && j.erro ? j.erro : ERRO_PADRAO));
    }));

  function carregar() {
    const q = new URLSearchParams();
    if (filtro.busca) q.set('busca', filtro.busca);
    if (filtro.situacao) q.set('situacao', filtro.situacao);
    if (filtro.blogs) q.set('blogs', filtro.blogs);
    if (filtro.pagina > 1) q.set('pagina', String(filtro.pagina));
    const url = ctx.url + (q.toString() ? '?' + q.toString() : '');
    return pedir(url)
      .then(j => { R = j; falhou = false; })
      .catch(() => { falhou = true; })
      .finally(desenhar);
  }

  const ABERTAS = ['na_fila', 'gerando'];

  function carregarPublicacoes() {
    clearTimeout(vigia);
    if (!ctx.urlPublicacoes) return Promise.resolve();
    const minhaRaiz = raiz;
    return pedir(ctx.urlPublicacoes)
      .then(j => { P = j; })
      .catch(() => { P = null; })
      .finally(() => {
        if (minhaRaiz !== raiz || !raiz.isConnected) return;
        desenharFaixa();
        if (P && P.itens.some(p => ABERTAS.includes(p.situacao))) vigia = setTimeout(carregarPublicacoes, 20000);
      });
  }

  function postar(url, aoDarCerto) {
    if (ocupado) return;
    ocupado = true;
    pedir(url, { method: 'POST', body: '{}', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': ctx.token } })
      .then(j => { erro = ''; if (aoDarCerto) aoDarCerto(j); return carregar(); })
      .catch(e => { erro = e.message || ERRO_PADRAO; msg = ''; desenhar(); })
      .finally(() => { ocupado = false; });
  }

  /* ---------- marcação ---------- */
  const opcoes = (lista, atual) => lista.map(([v, rot]) => '<option value="' + v + '"' + (v === atual ? ' selected' : '') + '>' + esc(rot) + '</option>').join('');

  function esqueleto() {
    const cab = ['Título', 'Blog', 'Categoria', 'Situação', 'Data'].map(t => '<span>' + t + '</span>').join('') + '<span class="bja-dir">Ações</span>';
    raiz.innerHTML = '<div class="bja">'
      + '<div class="bja-topo"><div class="bja-topo-txt"><div class="bja-titulo">Artigos dos blogs</div>'
      + '<div class="bja-sub">Blog BlueJus (bluejus.com.br/blog) e Blog BlueJus Grátis (bluejus.com.br/gratis).</div></div>'
      + '<a class="bja-novo" href="' + esc(ctx.urlNovo) + '"><i class="bi bi-plus-lg"></i>Novo artigo</a></div>'
      + '<div class="bja-caixa">'
      + '<label class="bja-busca"><i class="bi bi-search"></i><input type="search" data-bja-busca placeholder="Buscar por título ou slug" aria-label="Buscar por título ou slug"></label>'
      + '<select class="bja-filtro" data-bja-situacao aria-label="Situação">' + opcoes(SITUACOES, filtro.situacao) + '</select>'
      + '<select class="bja-filtro" data-bja-blogs aria-label="Blog">' + opcoes(BLOGS, filtro.blogs) + '</select>'
      + '<span class="bja-espaco"></span><span class="bja-total" data-bja-total></span></div>'
      + '<div class="bja-faixa" data-bja-faixa hidden></div>'
      + '<div class="bja-msg" data-bja-msg role="status" hidden></div>'
      + '<div class="bja-tabela"><div class="bja-larga"><div class="bja-grade bja-cab">' + cab + '</div>'
      + '<div class="bja-vazio" data-bja-vazio hidden></div><div data-bja-linhas></div></div>'
      + '<div class="bja-pag" data-bja-pag hidden><button type="button" data-bja-ant>Anterior</button><span data-bja-pag-txt></span><button type="button" data-bja-prox>Próxima</button></div></div>'
      + '</div>';
    raiz.querySelector('[data-bja-busca]').value = filtro.busca;
  }

  function dataDe(a) {
    if (!a.data) return '';
    if (a.situacao === 'agendado') return 'Agendado para ' + dataHora(a.data);
    return 'Publicado em ' + dataHora(a.data);
  }

  function linha(a) {
    const publicado = a.situacao === 'publicado';
    return '<div class="bja-grade bja-linha">'
      + '<span class="bja-tit">' + (a.destaque ? '<span class="bja-destaque">DESTAQUE</span>' : '')
      + '<b title="' + esc(a.titulo) + '">' + esc(a.titulo) + '</b><span title="' + esc(a.slug) + '">' + esc(a.slug) + '</span>'
      + (a.alteracoesPendentes ? '<span class="bja-pendente"><i class="bi bi-exclamation-circle"></i>Alterações não publicadas</span>' : '') + '</span>'
      + '<span class="bja-cinza">' + esc(a.blogsRotulo) + '</span>'
      + '<span class="bja-cinza" title="' + esc(a.categoria) + '">' + (a.categoria ? esc(a.categoria) : '<span class="bja-data">Sem categoria</span>') + '</span>'
      + '<span><span class="bja-st bja-st-' + esc(a.situacao) + '">' + esc(a.situacaoRotulo) + '</span></span>'
      + '<span class="bja-data">' + esc(dataDe(a)) + '</span>'
      + '<span class="bja-acoes">'
      + '<a class="bja-acao" href="' + esc(comId(ctx.urlEditar, a.id)) + '" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>'
      + (publicado ? '<a class="bja-acao" href="' + esc(ctx.site + a.caminhoNoSite) + '" target="_blank" rel="noopener" title="Ver no site" aria-label="Ver no site"><i class="bi bi-box-arrow-up-right"></i></a>' : '')
      + '<button type="button" class="bja-acao" data-bja-duplicar="' + a.id + '" title="Duplicar" aria-label="Duplicar"><i class="bi bi-copy"></i></button>'
      + '<button type="button" class="bja-acao bja-acao-perigo" data-bja-excluir="' + a.id + '" title="Excluir" aria-label="Excluir"><i class="bi bi-trash3"></i></button>'
      + '</span></div>';
  }

  function desenhar() {
    if (!raiz) return;
    if (!raiz.querySelector('.bja')) esqueleto();
    const itens = R ? R.itens : [];
    const total = R ? R.total : 0;
    raiz.querySelector('[data-bja-linhas]').innerHTML = itens.map(linha).join('');
    const vazio = raiz.querySelector('[data-bja-vazio]');
    vazio.textContent = falhou ? 'Não foi possível carregar os artigos agora. Recarregue a página.'
      : !R ? 'Carregando...'
        : filtro.busca || filtro.situacao || filtro.blogs ? 'Nenhum artigo com estes filtros.' : 'Nenhum artigo ainda. Use Novo artigo para começar.';
    vazio.hidden = itens.length > 0;
    raiz.querySelector('[data-bja-total]').textContent = R ? (total === 1 ? '1 artigo' : total + ' artigos') : '';

    const elMsg = raiz.querySelector('[data-bja-msg]');
    elMsg.textContent = erro || msg;
    elMsg.classList.toggle('is-erro', !!erro);
    elMsg.hidden = !(erro || msg);

    const paginas = R ? Math.max(1, Math.ceil(total / (R.porPagina || 50))) : 1;
    raiz.querySelector('[data-bja-pag]').hidden = paginas <= 1;
    raiz.querySelector('[data-bja-pag-txt]').textContent = 'Página ' + filtro.pagina + ' de ' + paginas;
    raiz.querySelector('[data-bja-ant]').disabled = filtro.pagina <= 1;
    raiz.querySelector('[data-bja-prox]').disabled = filtro.pagina >= paginas;
  }

  /* ---------- faixa da última publicação ---------- */
  function faixaDe(itens) {
    if (itens.some(p => ABERTAS.includes(p.situacao))) return ['is-gerando', 'bi-arrow-repeat', 'Gerando os blogs...', 'ver histórico'];
    const ultima = itens[0];
    if (ultima.situacao === 'falhou') return ['is-falhou', 'bi-exclamation-triangle', 'A última publicação falhou:', 'ver detalhes'];
    return ['is-no-ar', 'bi-check-circle', 'No ar desde ' + dataHora(ultima.terminadaEm || ultima.pedidaEm), 'ver histórico'];
  }

  function linhaDoHistorico(p) {
    const artigos = p.artigos ? (p.artigos.blog || []).length + (p.artigos['blog-gratis'] || []).length : null;
    return '<div class="bja-hist-linha">'
      + '<span>' + esc(dataHora(p.pedidaEm)) + '</span>'
      + '<span>' + esc(p.motivoRotulo) + (p.pedidaPor ? ' (' + esc(p.pedidaPor) + ')' : '') + '</span>'
      + '<span><span class="bja-pub bja-pub-' + esc(p.situacao) + '">' + esc(p.situacaoRotulo) + '</span></span>'
      + '<span>' + (p.terminadaEm ? 'Terminou ' + esc(dataHora(p.terminadaEm)) : '') + '</span>'
      + '<span>' + (artigos === null ? '' : esc(artigos === 1 ? '1 artigo' : artigos + ' artigos')) + '</span>'
      + (p.log ? '<details class="bja-hist-log"><summary>Registro da geração' + (p.commit ? ' (código ' + esc(p.commit) + ')' : '') + '</summary><pre>' + esc(p.log) + '</pre></details>' : '')
      + '</div>';
  }

  function desenharFaixa() {
    const el = raiz.querySelector('[data-bja-faixa]');
    if (!el) return;
    const itens = P ? P.itens : [];
    el.hidden = itens.length === 0;
    if (!itens.length) { el.innerHTML = ''; return; }
    const [classe, icone, texto, link] = faixaDe(itens);
    el.className = 'bja-faixa ' + classe;
    el.innerHTML = '<div class="bja-faixa-txt"><i class="bi ' + icone + '"></i><span>' + esc(texto) + '</span>'
      + '<button type="button" class="bja-faixa-link" data-bja-historico aria-expanded="' + historicoAberto + '">' + esc(historicoAberto ? 'fechar histórico' : link) + '</button></div>'
      + (historicoAberto ? '<div class="bja-hist">' + itens.map(linhaDoHistorico).join('') + '</div>' : '');
  }

  /* ---------- ações ---------- */
  const artigoPorId = id => (R ? R.itens.find(a => String(a.id) === String(id)) : null);

  function duplicar(a) {
    postar(comId(ctx.urlDuplicar, a.id), () => { msg = 'Cópia de "' + a.titulo + '" criada como rascunho.'; });
  }

  function excluir(a) {
    const enviar = () => postar(comId(ctx.urlExcluir, a.id), () => { msg = 'Artigo "' + a.titulo + '" excluído.'; });
    if (!window.BJMasterConf) {
      if (window.confirm('Excluir o artigo "' + a.titulo + '"? Esta ação não pode ser desfeita.')) enviar();
      return;
    }
    window.BJMasterConf.abrir({
      titulo: 'Excluir artigo', icone: 'bi-trash3', cor: '#a3232b', botao: 'Excluir',
      texto: 'Excluir o artigo "' + a.titulo + '"? Esta ação não pode ser desfeita.',
      aoConfirmar: () => { window.BJMasterConf.fechar(); enviar(); },
    });
  }

  function ouvir() {
    raiz.addEventListener('click', e => {
      const b = e.target.closest('button');
      if (!b || !raiz.contains(b)) return;
      if (b.hasAttribute('data-bja-historico')) { historicoAberto = !historicoAberto; desenharFaixa(); return; }
      if (b.hasAttribute('data-bja-ant')) { filtro.pagina = Math.max(1, filtro.pagina - 1); carregar(); return; }
      if (b.hasAttribute('data-bja-prox')) { filtro.pagina += 1; carregar(); return; }
      const ad = b.dataset.bjaDuplicar && artigoPorId(b.dataset.bjaDuplicar);
      if (ad) { duplicar(ad); return; }
      const ae = b.dataset.bjaExcluir && artigoPorId(b.dataset.bjaExcluir);
      if (ae) excluir(ae);
    });
    raiz.addEventListener('change', e => {
      if (e.target.matches('[data-bja-situacao]')) filtro.situacao = e.target.value;
      else if (e.target.matches('[data-bja-blogs]')) filtro.blogs = e.target.value;
      else return;
      filtro.pagina = 1;
      carregar();
    });
    raiz.addEventListener('input', e => {
      if (!e.target.matches('[data-bja-busca]')) return;
      filtro.busca = e.target.value.trim();
      filtro.pagina = 1;
      clearTimeout(espera);
      espera = setTimeout(carregar, 300);
    });
  }

  window.BJBlogArtigos = {
    // raiz: elemento novo a cada montagem da seção (o painel troca o innerHTML); a lista é lida de novo.
    montar(el, contexto) {
      raiz = el;
      ctx = contexto;
      falhou = false;
      msg = '';
      erro = '';
      P = null;
      historicoAberto = false;
      ouvir();
      desenhar();
      carregar();
      carregarPublicacoes();
    },
  };
})();
