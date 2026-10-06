/* ==========================================================================
   Explorador de documentos da Pasta (aba "Documentos") — comportamento
   Desenho: 02 - EXPEDIENTES 1.2.3 (dc L2020-2297 e L4670-4960).
   Decisão: docs/specs/trilha-b-documentos-arquitetura.md (lote L1).

   Lê os dados de `#pexDados` (JSON montado por ExploradorDeDocumentosOutput)
   e renderiza SÓ o nível aberto (ou os resultados da busca) em DocumentFragment:
   a pasta de produção com 1.128 documentos não pode ter 1.128 linhas no HTML.

   Depende de: Bootstrap 5 (Modal/Dropdown), SortableJS (opcional, só no modo
   Manual), `window.enviarArquivoComProgresso` (helper do template, também do
   Peticionar) e do `#previewDocModal` já ligado pelo visualizador-documento.js.

   Storage — SÓ preferência de visualização em localStorage (`pex:classificar`,
   `pex:colunas`, `pex:modo`); a pasta aberta em sessionStorage
   (`pex:pasta:<id>:caminho`). Nada de flag de aba: o retorno é pelo fragmento
   `#documentos`, que o pasta-show.js abre.
   ========================================================================== */
(function () {
    'use strict';

    const raiz = document.getElementById('pexExplorador');
    if (!raiz) return;

    const elDados = document.getElementById('pexDados');
    let dados;
    try { dados = JSON.parse(elDados ? elDados.textContent : '{}'); } catch (e) { dados = null; }
    if (!dados || !Array.isArray(dados.pastas) || !Array.isArray(dados.arquivos)) return;

    const pastaId = raiz.dataset.pastaId;
    const cfg = {
        urlUpload:           raiz.dataset.urlUpload,
        csrfUpload:          raiz.dataset.csrfUpload,
        urlCriarSecao:       raiz.dataset.urlCriarSecao,
        csrfCriarSecao:      raiz.dataset.csrfCriarSecao,
        urlReordenarSecoes:  raiz.dataset.urlReordenarSecoes,
        csrfReordenarSecoes: raiz.dataset.csrfReordenarSecoes,
        urlReordenarDocs:    raiz.dataset.urlReordenarDocs,
        csrfReordenarDocs:   raiz.dataset.csrfReordenarDocs,
        urlRenomearTpl:      raiz.dataset.urlRenomearTpl,
        urlExcluirTpl:       raiz.dataset.urlExcluirTpl,
        urlMoverTpl:         raiz.dataset.urlMoverTpl,
        urlEditarDocTpl:     raiz.dataset.urlEditarDocTpl,
        urlExcluirDocTpl:    raiz.dataset.urlExcluirDocTpl,
    };

    const el = {
        busca:          document.getElementById('pexBusca'),
        buscaLimpar:    document.getElementById('pexBuscaLimpar'),
        buscaInfo:      document.getElementById('pexBuscaInfo'),
        contagem:       document.getElementById('pexContagem'),
        corpo:          document.getElementById('pexCorpo'),
        trilha:         document.getElementById('pexTrilha'),
        trilhaNiveis:   document.getElementById('pexTrilhaNiveis'),
        subir:          document.getElementById('pexSubir'),
        cabecalho:      document.getElementById('pexCabecalho'),
        lista:          document.getElementById('pexLista'),
        vazio:          document.getElementById('pexVazio'),
        vazioIcone:     document.getElementById('pexVazioIcone'),
        vazioTexto:     document.getElementById('pexVazioTexto'),
        rodape:         document.getElementById('pexRodape'),
        btnAnexar:      document.getElementById('pexAnexar'),
        fileInput:      document.getElementById('pexFileInput'),
        btnNovaPasta:   document.getElementById('pexNovaPasta'),
        btnOrganizar:   document.getElementById('pexOrganizar'),
        menuOrganizar:  document.getElementById('pexOrganizarMenu'),
        classificar:    document.getElementById('pexClassificar'),
        btnRestaurar:   document.getElementById('pexRestaurar'),
        btnVisualizar:  document.getElementById('pexVisualizar'),
        menuVisualizar: document.getElementById('pexVisualizarMenu'),
        uploadBar:      document.getElementById('pexUploadBar'),
        uploadNome:     document.getElementById('pexUploadNome'),
        uploadProg:     document.getElementById('pexUploadProgresso'),
        uploadCont:     document.getElementById('pexUploadContador'),
    };
    if (!el.lista) return;

    // ------------------------------------------------------------ estado ----
    const pastas   = dados.pastas;            // mutável em memória
    const arquivos = dados.arquivos;
    let totalArquivos = Number(dados.totalArquivos) || arquivos.length;

    const CHAVE_CAMINHO     = 'pex:pasta:' + pastaId + ':caminho';
    const CHAVE_CLASSIFICAR = 'pex:classificar';
    const CHAVE_COLUNAS     = 'pex:colunas';
    const CLASSIFICACOES    = ['manual', 'nome', 'tipo', 'tamanho', 'data', 'categoria'];
    // Primeiro clique de cada coluna: data e tamanho começam DECRESCENTES (mais recente / maior
    // primeiro), nome, tipo e categoria em A–Z — regra do desenho (dc L4862) e do fm antigo.
    const SENTIDO_INICIAL   = { nome: false, tipo: false, categoria: false, tamanho: true, data: true };

    let caminho = [];          // [] = raiz; senão a cadeia de ids (números) até a pasta aberta
    let busca   = '';
    let buscaTimer = null;     // debounce da digitação na busca
    let classificar = lerClassificar();   // { chave, desc }
    let colunas     = lerColunas();       // { categoria: bool }

    function lerClassificar() {
        let v = null;
        try { v = localStorage.getItem(CHAVE_CLASSIFICAR); } catch (e) { /* storage bloqueado */ }
        if (!v) return { chave: 'manual', desc: false };
        const desc  = /_desc$/.test(v);
        const chave = v.replace(/_desc$/, '');
        return CLASSIFICACOES.indexOf(chave) !== -1 ? { chave: chave, desc: desc } : { chave: 'manual', desc: false };
    }
    function gravarClassificar() {
        try { localStorage.setItem(CHAVE_CLASSIFICAR, classificar.chave + (classificar.desc ? '_desc' : '')); } catch (e) { /* silencioso */ }
    }
    function lerColunas() {
        try {
            const v = JSON.parse(localStorage.getItem(CHAVE_COLUNAS) || 'null');
            if (v && typeof v === 'object') return { categoria: v.categoria === true };
        } catch (e) { /* formato inválido */ }
        return { categoria: false };
    }
    function gravarColunas() {
        try { localStorage.setItem(CHAVE_COLUNAS, JSON.stringify(colunas)); } catch (e) { /* silencioso */ }
    }
    function gravarCaminho() {
        try { sessionStorage.setItem(CHAVE_CAMINHO, JSON.stringify(caminho)); } catch (e) { /* silencioso */ }
    }

    // ------------------------------------------------------------- utils ----
    function h(tag, attrs, filhos) {
        const n = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                const v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'class') n.className = v;
                else if (k === 'text') n.textContent = v;
                else n.setAttribute(k, v === true ? '' : String(v));
            });
        }
        (filhos || []).forEach(function (f) {
            if (f === null || f === undefined || f === false) return;
            n.appendChild(typeof f === 'string' ? document.createTextNode(f) : f);
        });
        return n;
    }
    function icone(classes) { return h('i', { class: 'bi ' + classes, 'aria-hidden': 'true' }); }
    function pluralizar(n, singular, plural) { return n + ' ' + (n === 1 ? singular : plural); }
    function formatarBytes(b) {
        b = Number(b) || 0;
        if (b <= 0) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1).replace('.', ',') + ' KB';
        if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
        return (b / 1024 / 1024 / 1024).toFixed(2).replace('.', ',') + ' GB';
    }
    function formatarData(s) {
        const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }
    function extensaoDe(nome) {
        const m = String(nome || '').match(/\.([^.\/\\]+)$/);
        return m ? m[1] : '';
    }
    function nomeSemExtensao(nome) {
        const ext = extensaoDe(nome);
        return ext ? String(nome).slice(0, -(ext.length + 1)) : String(nome || '');
    }

    /* ---- Tipo pela extensão (dc `expTipo`, L4675-4680): rótulo, ícone e cor. ---- */
    const TIPO_ALIAS = { DOCM: 'DOCX', DOTX: 'DOCX', XLSM: 'XLSX', XLS: 'XLSX', XLSB: 'XLSX', JPEG: 'JPG', JFIF: 'JPG', WEBP: 'PNG', GIF: 'PNG', BMP: 'PNG', SVG: 'PNG' };
    const TIPOS = {
        PDF:  ['bi-file-earmark-pdf-fill',   'pdf',    'Documento PDF'],
        DOCX: ['bi-file-earmark-word-fill',  'word',   'Documento do Word'],
        DOC:  ['bi-file-earmark-word-fill',  'word',   'Documento do Word'],
        ODT:  ['bi-file-earmark-word-fill',  'word',   'Documento ODT'],
        RTF:  ['bi-file-earmark-richtext-fill', 'word', 'Documento RTF'],
        TXT:  ['bi-file-earmark-text-fill',  'txt',    'Texto'],
        XLSX: ['bi-file-earmark-excel-fill', 'excel',  'Planilha do Excel'],
        CSV:  ['bi-filetype-csv',            'excel',  'Planilha CSV'],
        ODS:  ['bi-file-earmark-spreadsheet-fill', 'excel', 'Planilha ODS'],
        PPTX: ['bi-file-earmark-slides-fill', 'slides', 'Apresentação'],
        PPT:  ['bi-file-earmark-slides-fill', 'slides', 'Apresentação'],
        ODP:  ['bi-file-earmark-slides-fill', 'slides', 'Apresentação'],
        ZIP:  ['bi-file-earmark-zip-fill',   'zip',    'Pasta compactada'],
        RAR:  ['bi-file-earmark-zip-fill',   'zip',    'Pasta compactada'],
        '7Z': ['bi-file-earmark-zip-fill',   'zip',    'Pasta compactada'],
        JPG:  ['bi-file-earmark-image-fill', 'img',    'Imagem JPEG'],
        PNG:  ['bi-file-earmark-image-fill', 'img',    'Imagem PNG'],
        TIF:  ['bi-file-earmark-image-fill', 'img',    'Imagem TIFF'],
        TIFF: ['bi-file-earmark-image-fill', 'img',    'Imagem TIFF'],
        HEIC: ['bi-file-earmark-image-fill', 'img',    'Imagem HEIC'],
        MP3:  ['bi-file-earmark-music-fill', 'midia',  'Áudio'],
        WAV:  ['bi-file-earmark-music-fill', 'midia',  'Áudio'],
        OGG:  ['bi-file-earmark-music-fill', 'midia',  'Áudio'],
        OPUS: ['bi-file-earmark-music-fill', 'midia',  'Áudio (WhatsApp)'],
        M4A:  ['bi-file-earmark-music-fill', 'midia',  'Áudio'],
        MP4:  ['bi-file-earmark-play-fill',  'midia',  'Vídeo'],
        MOV:  ['bi-file-earmark-play-fill',  'midia',  'Vídeo'],
        WEBM: ['bi-file-earmark-play-fill',  'midia',  'Vídeo'],
        EML:  ['bi-envelope-paper-fill',     'email',  'E-mail'],
        MSG:  ['bi-envelope-paper-fill',     'email',  'E-mail do Outlook'],
        P7S:  ['bi-patch-check-fill',        'assin',  'Assinatura digital'],
        P7M:  ['bi-patch-check-fill',        'assin',  'Documento assinado'],
        JSON: ['bi-filetype-json',           'txt',    'Arquivo JSON'],
        XML:  ['bi-filetype-xml',            'txt',    'Arquivo XML'],
        HTML: ['bi-filetype-html',           'txt',    'Página HTML'],
        KML:  ['bi-geo-alt-fill',            'txt',    'Mapa KML'],
    };
    function tipoDe(nome) {
        const ext = extensaoDe(nome).toUpperCase();
        if (TIPOS[ext]) return TIPOS[ext];
        const alias = TIPO_ALIAS[ext];
        if (alias && TIPOS[alias]) { const b = TIPOS[alias]; return [b[0], b[1], b[2] + ' (' + ext + ')']; }
        return ['bi-file-earmark-fill', 'outro', ext ? 'Arquivo ' + ext : 'Arquivo'];
    }
    const TIPO_PASTA = ['bi-folder-fill', 'pasta', 'Pasta de arquivos'];

    /* ---- Busca (dc L4719-4721, L3071-3077): dobra acentos; `_ - .` contam como espaço. ---- */
    function normalizar(t) {
        return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[_\-.]+/g, ' ').replace(/\s+/g, ' ').trim();
    }
    function casa(nome, q) {
        return normalizar(nome).indexOf(q) !== -1 || normalizar(nomeSemExtensao(nome)).endsWith(q);
    }
    // Devolve [antes, trecho, depois] do nome original para o <mark>, com os índices alinhados
    // caractere a caractere (cada caractere do nome dobra para um só caractere normalizado).
    function realce(nome, q) {
        const n = String(nome || '');
        const t = String(q || '').trim();
        if (!t) return [n, '', ''];
        const dobra = function (c) { return c.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[_\-.]/g, ' '); };
        const N = Array.from(n).map(dobra).join('');
        const Q = Array.from(t).map(dobra).join('').replace(/\s+/g, ' ');
        const p = N.indexOf(Q);
        if (p < 0 || N.length !== n.length) return [n, '', ''];
        return [n.slice(0, p), n.slice(p, p + Q.length), n.slice(p + Q.length)];
    }

    // --------------------------------------------------------- árvore -------
    function pastaPorId(id) {
        id = Number(id);
        for (let i = 0; i < pastas.length; i++) if (pastas[i].id === id) return pastas[i];
        return null;
    }
    function paiDe(id) { const p = pastaPorId(id); return p && p.paiId != null ? Number(p.paiId) : null; }
    function pastaAtualId() { return caminho.length ? caminho[caminho.length - 1] : null; }
    function nomePasta(id) { const p = pastaPorId(id); return p ? p.nome : ''; }

    function cadeiaAte(id) {
        const cadeia = [];
        let atual = id == null ? null : Number(id);
        let voltas = 0;
        while (atual != null && pastaPorId(atual) && voltas < 100) { cadeia.unshift(atual); atual = paiDe(atual); voltas++; }
        return cadeia;
    }
    function caminhoLegivel(id) {
        if (id == null) return 'Documentos';
        return ['Documentos'].concat(cadeiaAte(id).map(nomePasta)).join(' › ');
    }
    function descendentes(id, profundidade) {
        profundidade = profundidade || 0;
        if (profundidade >= 100) return [];
        const filhas = pastas.filter(function (p) { return p.paiId != null && Number(p.paiId) === Number(id); });
        return filhas.reduce(function (acc, p) { return acc.concat([p.id], descendentes(p.id, profundidade + 1)); }, []);
    }
    // Contagem recursiva calculada em memória — fica certa depois de mover/criar/excluir sem reload.
    function contarArvore(id) {
        const sub = descendentes(id);
        const ids = [Number(id)].concat(sub);
        const n = arquivos.filter(function (a) { return a.secaoId != null && ids.indexOf(Number(a.secaoId)) !== -1; }).length;
        return { subpastas: sub.length, arquivos: n };
    }

    // ------------------------------------------------------- ordenação ------
    function cmpNome(a, b) { return String(a.nome).localeCompare(String(b.nome), 'pt-BR', { sensitivity: 'base', numeric: true }); }
    function rotuloTipo(it) { return it.tipo === 'pasta' ? TIPO_PASTA[2] : tipoDe(it.nome)[2]; }
    function comparador() {
        const dir = classificar.desc ? -1 : 1;
        let f;
        switch (classificar.chave) {
            case 'nome':      f = cmpNome; break;
            case 'tipo':      f = function (a, b) { return rotuloTipo(a).localeCompare(rotuloTipo(b), 'pt-BR') || cmpNome(a, b); }; break;
            case 'tamanho':   f = function (a, b) { return ((a.tamanho || 0) - (b.tamanho || 0)) || cmpNome(a, b); }; break;
            case 'data':      f = function (a, b) { return String(a.carregadoEm || '').localeCompare(String(b.carregadoEm || '')) || cmpNome(a, b); }; break;
            case 'categoria': f = function (a, b) { return String(a.categoriaRotulo || '').localeCompare(String(b.categoriaRotulo || ''), 'pt-BR') || cmpNome(a, b); }; break;
            // Manual: `ordem` e, no empate, NOME — como o fm antigo. Em produção 20.909 dos 20.954
            // documentos têm ordem=0, então o padrão real é A–Z; desempatar por id seria
            // "ordem de upload" com cara de alfabética.
            default:          f = function (a, b) { return ((a.ordem || 0) - (b.ordem || 0)) || cmpNome(a, b); };
        }
        return function (a, b) { return f(a, b) * dir; };
    }

    // Itens do nível aberto (ou os resultados da busca, em TODAS as subpastas — função do
    // sistema, §16.3), pastas sempre antes dos arquivos.
    function itensVisiveis() {
        const atual = pastaAtualId();
        const q = normalizar(busca);
        let ps, as;
        if (q) {
            ps = pastas.filter(function (p) { return casa(p.nome, q); });
            as = arquivos.filter(function (a) { return casa(a.nome, q); });
        } else {
            ps = pastas.filter(function (p) { return (p.paiId == null ? null : Number(p.paiId)) === atual; });
            as = arquivos.filter(function (a) { return (a.secaoId == null ? null : Number(a.secaoId)) === atual; });
        }
        const cmp = comparador();
        ps = ps.map(function (p) { return { tipo: 'pasta', id: p.id, nome: p.nome, ordem: p.ordem, dado: p }; }).sort(cmp);
        as = as.map(function (a) { return { tipo: 'arquivo', id: a.id, nome: a.nome, ordem: a.ordem, tamanho: a.tamanho, carregadoEm: a.carregadoEm, categoriaRotulo: a.categoriaRotulo, dado: a }; }).sort(cmp);
        return ps.concat(as);
    }

    // ------------------------------------------------------------ render ----
    let itensRenderizados = [];

    function renderizar() {
        const buscando = normalizar(busca) !== '';
        const itens = itensVisiveis();
        itensRenderizados = itens;

        raiz.classList.toggle('pex--cat', colunas.categoria);
        renderizarCabecalho();
        renderizarTrilha(buscando);
        renderizarMenus();

        const frag = document.createDocumentFragment();
        itens.forEach(function (it) { frag.appendChild(it.tipo === 'pasta' ? linhaPasta(it.dado, buscando) : linhaArquivo(it.dado, buscando)); });
        el.lista.textContent = '';
        el.lista.appendChild(frag);
        el.lista.hidden = itens.length === 0;

        // Busca: contagem (dc L4838) e vazio próprio (dc L2185).
        if (buscando) {
            el.buscaInfo.textContent = pluralizar(itens.length, 'resultado', 'resultados') + ' para "' + busca.trim() + '"';
            el.buscaInfo.hidden = false;
        } else {
            el.buscaInfo.hidden = true;
        }
        if (itens.length === 0) {
            el.vazioIcone.className = 'bi ' + (buscando ? 'bi-search' : 'bi-folder2-open');
            el.vazio.classList.toggle('pex-vazio--busca', buscando);
            el.vazioTexto.textContent = buscando
                ? 'Nenhum arquivo ou pasta com esse nome.'
                : (caminho.length ? 'Pasta vazia. Arraste arquivos para cá ou clique em Anexar.' : 'Nenhum arquivo ainda. Arraste arquivos para cá ou clique em Anexar.');
            el.vazio.hidden = false;
        } else {
            el.vazio.hidden = true;
        }

        renderizarRodape();
        if (el.contagem) el.contagem.textContent = String(totalArquivos);
        ligarSortable();
    }

    function renderizarCabecalho() {
        // A coluna Categoria entra/sai do cabeçalho conforme o Organizar (S-2).
        let colCat = el.cabecalho.querySelector('.pex-col-cat');
        if (colunas.categoria && !colCat) {
            colCat = h('div', { class: 'pex-col pex-col-cat', 'aria-sort': 'none' }, [
                h('button', { type: 'button', 'data-pex-classificar': 'categoria', title: 'Classificar por categoria' }, [h('span', { text: 'Categoria' }), h('i', { class: 'bi pex-seta', 'aria-hidden': 'true' })]),
            ]);
            el.cabecalho.insertBefore(colCat, el.cabecalho.querySelector('.pex-col-tam'));
        } else if (!colunas.categoria && colCat) {
            colCat.remove();
        }
        el.cabecalho.querySelectorAll('.pex-col').forEach(function (col) {
            const btn = col.querySelector('[data-pex-classificar]');
            if (!btn) return;
            const ativa = btn.dataset.pexClassificar === classificar.chave;
            col.setAttribute('aria-sort', ativa ? (classificar.desc ? 'descending' : 'ascending') : 'none');
            const seta = btn.querySelector('.pex-seta');
            if (seta) seta.className = 'bi pex-seta' + (ativa ? (classificar.desc ? ' bi-chevron-down' : ' bi-chevron-up') : '');
        });
    }

    function renderizarTrilha(buscando) {
        if (buscando || caminho.length === 0) { el.trilha.hidden = true; return; }
        el.trilha.hidden = false;
        el.trilhaNiveis.textContent = '';
        el.trilhaNiveis.appendChild(h('button', { type: 'button', class: 'pex-nivel', 'data-pex-nivel': '-1', text: 'Documentos', title: 'Documentos' }));
        caminho.forEach(function (id, i) {
            const atual = i === caminho.length - 1;
            el.trilhaNiveis.appendChild(h('i', { class: 'bi bi-chevron-right pex-trilha-sep', 'aria-hidden': 'true' }));
            el.trilhaNiveis.appendChild(h('button', {
                type: 'button',
                class: 'pex-nivel' + (atual ? ' pex-nivel--atual' : ''),
                'data-pex-nivel': String(i),
                'aria-current': atual ? 'location' : null,
                text: nomePasta(id),
                title: nomePasta(id),
            }));
        });
    }

    // Rodapé (dc L4868): raiz = "N arquivos e M pastas · T no total, contando subpastas";
    // dentro = "Nome · N itens nesta pasta" (ou "vazia").
    function renderizarRodape() {
        const atual = pastaAtualId();
        if (atual == null) {
            const nArq = arquivos.filter(function (a) { return a.secaoId == null; }).length;
            const nPas = pastas.filter(function (p) { return p.paiId == null; }).length;
            el.rodape.textContent = pluralizar(nArq, 'arquivo', 'arquivos') + ' e ' + pluralizar(nPas, 'pasta', 'pastas')
                + ' · ' + totalArquivos + ' no total, contando subpastas';
            return;
        }
        const nItens = pastas.filter(function (p) { return Number(p.paiId) === atual; }).length
            + arquivos.filter(function (a) { return Number(a.secaoId) === atual; }).length;
        el.rodape.textContent = nomePasta(atual) + ' · ' + (nItens ? pluralizar(nItens, 'item', 'itens') + ' nesta pasta' : 'vazia');
    }

    function renderizarMenus() {
        el.classificar.querySelectorAll('[data-pex-classificar]').forEach(function (b) {
            const chave = b.dataset.pexClassificar;
            const on = chave === classificar.chave;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            if (chave === 'categoria') b.hidden = !colunas.categoria;
            const seta = b.querySelector('.pex-seta');
            // Pílulas usam bi-arrow-* (dc L3152); o cabeçalho das colunas usa bi-chevron-* (dc L4862).
            if (seta) seta.className = 'bi pex-seta' + (on && chave !== 'manual' ? (classificar.desc ? ' bi-arrow-down' : ' bi-arrow-up') : '');
        });
        const chk = el.menuOrganizar.querySelector('[data-pex-coluna="categoria"]');
        if (chk) chk.setAttribute('aria-checked', colunas.categoria ? 'true' : 'false');
    }

    function nomeComRealce(nome, buscando) {
        if (!buscando) return [nome];
        const r = realce(nome, busca);
        if (!r[1]) return [nome];
        return [r[0], h('mark', { text: r[1] }), r[2]];
    }
    function celulaLocal(secaoOuPaiId) {
        return h('span', { class: 'pex-local', text: caminhoLegivel(secaoOuPaiId == null ? null : Number(secaoOuPaiId)) });
    }

    function linhaPasta(p, buscando) {
        const contagem = contarArvore(p.id);
        const txt = [h('span', { class: 'pex-nome' }, nomeComRealce(p.nome, buscando))];
        if (buscando) txt.push(celulaLocal(p.paiId));
        const linha = h('div', {
            class: 'pex-item pex-item--pasta',
            'data-pex-tipo': 'pasta',
            'data-pex-id': String(p.id),
            'data-pex-subpastas': String(contagem.subpastas),
            'data-pex-arquivos': String(contagem.arquivos),
            role: 'button',
            tabindex: '0',
            title: p.nome + ' · ' + pluralizar(contagem.arquivos, 'arquivo', 'arquivos'),
            'aria-label': 'Abrir pasta ' + p.nome,
            draggable: classificar.chave === 'manual' ? null : 'true',
        }, [
            h('span', { class: 'pex-cel pex-cel-nome' }, [
                h('span', { class: 'pex-ico' }, [icone(TIPO_PASTA[0] + ' pex-ico-pasta')]),
                h('span', { class: 'pex-txt' }, txt),
            ]),
            h('span', { class: 'pex-cel pex-cel-tipo', text: TIPO_PASTA[2] }),
            colunas.categoria ? h('span', { class: 'pex-cel pex-cel-cat' }) : null,
            h('span', { class: 'pex-cel pex-cel-tam' }),
            h('span', { class: 'pex-cel pex-cel-data' }),
            h('span', { class: 'pex-cel pex-cel-acoes' }, [menuPasta()]),
        ]);
        return linha;
    }

    function menuPasta() {
        return h('div', { class: 'dropdown' }, [
            h('button', { type: 'button', class: 'pex-menu', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'Ações da pasta' }, [icone('bi-three-dots-vertical')]),
            h('ul', { class: 'dropdown-menu dropdown-menu-end' }, [
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item', 'data-pex-acao': 'abrir' }, [icone('bi-folder2-open me-2'), 'Abrir'])]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item', 'data-pex-acao': 'renomear' }, [icone('bi-pencil me-2'), 'Renomear'])]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item', 'data-pex-acao': 'mover' }, [icone('bi-folder-symlink me-2'), 'Mover para…'])]),
                h('li', null, [h('hr', { class: 'dropdown-divider' })]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item text-danger', 'data-pex-acao': 'excluir' }, [icone('bi-trash me-2'), 'Excluir'])]),
            ]),
        ]);
    }

    function linhaArquivo(a, buscando) {
        const t = tipoDe(a.nome);
        const txt = [
            /* O nome continua um <a> de verdade para a URL de visualização: Ctrl/Cmd/meio-clique
               abrem em outra aba, e sem JS o link ainda leva ao arquivo. O clique simples vira o
               pré-visualizador, interceptado pela classe .pex-arq-preview. */
            h('a', {
                href: a.viewUrl, target: '_blank', rel: 'noopener noreferrer',
                class: 'pex-nome pex-arq-preview',
                'data-url': a.viewUrl, 'data-nome': a.nome, 'data-mime': a.mime || '',
                title: a.nome,
            }, nomeComRealce(a.nome, buscando)),
        ];
        // "Nº · descrição" (§16.2): função só do sistema; no L2 vai para o modo Conteúdo e o painel.
        const sub = (a.numero ? 'Nº ' + a.numero : '') + (a.numero && a.descricao ? ' · ' : '') + (a.descricao || '');
        if (sub) txt.push(h('span', { class: 'pex-sub', text: sub, title: sub }));
        if (buscando) txt.push(celulaLocal(a.secaoId));

        return h('div', {
            class: 'pex-item pex-item--arquivo',
            'data-pex-tipo': 'arquivo',
            'data-pex-id': String(a.id),
            title: a.nome,
            draggable: classificar.chave === 'manual' ? null : 'true',
        }, [
            h('span', { class: 'pex-cel pex-cel-nome' }, [
                h('span', { class: 'pex-ico' }, [icone(t[0] + ' pex-ico-' + t[1])]),
                h('span', { class: 'pex-txt' }, txt),
            ]),
            h('span', { class: 'pex-cel pex-cel-tipo', text: t[2], title: t[2] }),
            colunas.categoria ? h('span', { class: 'pex-cel pex-cel-cat', text: a.categoriaRotulo || '', title: a.categoriaRotulo || '' }) : null,
            h('span', { class: 'pex-cel pex-cel-tam', text: formatarBytes(a.tamanho) }),
            h('span', { class: 'pex-cel pex-cel-data', text: formatarData(a.carregadoEm) }),
            h('span', { class: 'pex-cel pex-cel-acoes' }, [menuArquivo(a)]),
        ]);
    }

    function menuArquivo(a) {
        return h('div', { class: 'dropdown' }, [
            h('button', { type: 'button', class: 'pex-menu', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'Ações do arquivo' }, [icone('bi-three-dots-vertical')]),
            h('ul', { class: 'dropdown-menu dropdown-menu-end' }, [
                h('li', null, [h('a', { class: 'dropdown-item', href: a.downloadUrl, target: '_blank', rel: 'noopener' }, [icone('bi-download me-2'), 'Baixar'])]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item pex-arq-preview', 'data-url': a.viewUrl, 'data-nome': a.nome, 'data-mime': a.mime || '' }, [icone('bi-eye me-2'), 'Visualizar'])]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item', 'data-pex-acao': 'editar' }, [icone('bi-pencil me-2'), 'Editar'])]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item', 'data-pex-acao': 'mover' }, [icone('bi-folder-symlink me-2'), 'Mover para…'])]),
                h('li', null, [h('hr', { class: 'dropdown-divider' })]),
                h('li', null, [h('button', { type: 'button', class: 'dropdown-item text-danger', 'data-pex-acao': 'excluir' }, [icone('bi-trash me-2'), 'Excluir'])]),
            ]),
        ]);
    }

    // --------------------------------------------------------- navegação ----
    function entrar(id) {
        caminho = cadeiaAte(id);
        clearTimeout(buscaTimer);
        busca = '';
        if (el.busca) el.busca.value = '';
        atualizarBotaoLimpar();
        gravarCaminho();
        renderizar();
        el.lista.focus({ preventScroll: true });
    }
    function irParaNivel(nivel) {
        caminho = nivel < 0 ? [] : caminho.slice(0, nivel + 1);
        gravarCaminho();
        renderizar();
    }
    function subir() { if (caminho.length) irParaNivel(caminho.length - 2); }
    function voltarRaiz() { irParaNivel(-1); }

    // ============================================================ EVENTOS ====

    // Clique na lista: abrir pasta, pré-visualizar, menu ⋮
    el.lista.addEventListener('click', function (e) {
        const prev = e.target.closest('.pex-arq-preview');
        if (prev) {
            // O nome é um <a> de verdade: com modificador, deixa o navegador abrir em outra aba.
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            abrirPreview(prev);
            return;
        }
        const acao = e.target.closest('[data-pex-acao]');
        const item = e.target.closest('.pex-item');
        if (acao && item) {
            e.preventDefault();
            executarAcao(acao.dataset.pexAcao, item);
            return;
        }
        if (!item || e.target.closest('.dropdown')) return;
        if (item.dataset.pexTipo === 'pasta') entrar(Number(item.dataset.pexId));
    });
    el.lista.addEventListener('dblclick', function (e) {
        const item = e.target.closest('.pex-item--arquivo');
        if (!item || e.target.closest('.dropdown') || e.target.closest('a')) return;
        const gatilho = item.querySelector('.pex-arq-preview');
        if (gatilho) abrirPreview(gatilho);
    });
    el.lista.addEventListener('keydown', function (e) {
        const item = e.target.closest('.pex-item--pasta');
        if (item && e.target === item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); entrar(Number(item.dataset.pexId)); }
    });

    function abrirPreview(gatilho) {
        const modal = document.getElementById('previewDocModal');
        if (!modal || !window.bootstrap) { window.open(gatilho.dataset.url, '_blank', 'noopener'); return; }
        bootstrap.Modal.getOrCreateInstance(modal).show(gatilho);
    }

    function executarAcao(acao, item) {
        const id = Number(item.dataset.pexId);
        if (item.dataset.pexTipo === 'pasta') {
            const p = pastaPorId(id);
            if (!p) return;
            if (acao === 'abrir') entrar(id);
            else if (acao === 'renomear') renomearPasta(p);
            else if (acao === 'mover') escolherDestinoPasta(p);
            else if (acao === 'excluir') excluirPasta(p);
            return;
        }
        const a = arquivoPorId(id);
        if (!a) return;
        if (acao === 'editar') abrirEditar(a);
        else if (acao === 'mover') escolherDestinoArquivo(a);
        else if (acao === 'excluir') excluirArquivo(a);
    }
    function arquivoPorId(id) {
        id = Number(id);
        for (let i = 0; i < arquivos.length; i++) if (arquivos[i].id === id) return arquivos[i];
        return null;
    }

    // Trilha
    el.trilhaNiveis.addEventListener('click', function (e) {
        const b = e.target.closest('[data-pex-nivel]');
        if (b) irParaNivel(parseInt(b.dataset.pexNivel, 10));
    });
    if (el.subir) el.subir.addEventListener('click', subir);

    // Teclado: Backspace / Alt+← / Alt+↑ sobem um nível (dc L4910); fora de campos de texto.
    raiz.addEventListener('keydown', function (e) {
        const tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
        // O checklist tem campos e botões próprios: Backspace lá é do checklist, não da navegação.
        if (e.target && e.target.closest && e.target.closest('#pexChecklist')) return;
        if ((e.key === 'Backspace' || (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowUp'))) && caminho.length) {
            e.preventDefault();
            subir();
        }
    });

    // Busca
    function atualizarBotaoLimpar() { if (el.buscaLimpar) el.buscaLimpar.hidden = busca.trim() === ''; }
    // Digitação com debounce de 120 ms: na pasta de 1.128 docs cada tecla refaz a lista inteira.
    function aplicarBusca(valor) {
        clearTimeout(buscaTimer);
        buscaTimer = null;
        busca = valor;
        if (el.busca) el.busca.value = valor;
        atualizarBotaoLimpar();
        renderizar();
    }
    if (el.busca) {
        el.busca.addEventListener('input', function () {
            clearTimeout(buscaTimer);
            buscaTimer = setTimeout(function () { aplicarBusca(el.busca.value); }, 120);
        });
        el.busca.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && (busca !== '' || el.busca.value !== '')) { e.preventDefault(); e.stopPropagation(); aplicarBusca(''); }
        });
    }
    if (el.buscaLimpar) {
        el.buscaLimpar.addEventListener('click', function () { aplicarBusca(''); el.busca.focus(); });
    }

    // Classificar pelo cabeçalho ou pelas pílulas do Organizar — um estado só.
    function classificarPor(chave) {
        if (CLASSIFICACOES.indexOf(chave) === -1) return;
        if (chave === 'manual') classificar = { chave: 'manual', desc: false };
        else if (classificar.chave === chave) classificar = { chave: chave, desc: !classificar.desc };
        else classificar = { chave: chave, desc: !!SENTIDO_INICIAL[chave] };
        gravarClassificar();
        renderizar();
    }
    el.cabecalho.addEventListener('click', function (e) {
        const b = e.target.closest('[data-pex-classificar]');
        if (b) classificarPor(b.dataset.pexClassificar);
    });
    el.classificar.addEventListener('click', function (e) {
        const b = e.target.closest('[data-pex-classificar]');
        if (b) classificarPor(b.dataset.pexClassificar);
    });
    el.menuOrganizar.addEventListener('click', function (e) {
        const col = e.target.closest('[data-pex-coluna]');
        if (!col) return;
        if (col.dataset.pexColuna === 'categoria') {
            colunas.categoria = !colunas.categoria;
            // Coluna desligada não pode continuar mandando na ordem.
            if (!colunas.categoria && classificar.chave === 'categoria') classificar = { chave: 'manual', desc: false };
            gravarColunas();
            gravarClassificar();
            renderizar();
        }
    });
    if (el.btnRestaurar) {
        el.btnRestaurar.addEventListener('click', function () {
            classificar = { chave: 'manual', desc: false };
            colunas = { categoria: false };
            gravarClassificar();
            gravarColunas();
            fecharPopovers();
            renderizar();
        });
    }

    // Popovers Organizar / Visualizar
    const popovers = [
        { btn: el.btnOrganizar, menu: el.menuOrganizar },
        { btn: el.btnVisualizar, menu: el.menuVisualizar },
    ].filter(function (p) { return p.btn && p.menu; });

    function fecharPopovers(exceto) {
        popovers.forEach(function (p) {
            if (p.menu === exceto) return;
            p.menu.hidden = true;
            p.btn.setAttribute('aria-expanded', 'false');
        });
    }
    popovers.forEach(function (p) {
        p.btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const abrir = p.menu.hidden;
            fecharPopovers();
            if (!abrir) return;
            p.menu.hidden = false;
            p.menu.classList.remove('pex-popover--esq');
            p.btn.setAttribute('aria-expanded', 'true');
            // Ancorado à direita do botão; se sair da janela pela esquerda, ancora à esquerda.
            const r = p.menu.getBoundingClientRect();
            if (r.left < 8) p.menu.classList.add('pex-popover--esq');
        });
        p.menu.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', function () { fecharPopovers(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharPopovers(); });
    if (el.menuVisualizar) {
        el.menuVisualizar.addEventListener('click', function (e) {
            // Só "Detalhes" existe até o L2; marcar o rádio e fechar é a ação real que ele tem.
            const b = e.target.closest('[data-pex-modo]');
            if (!b) return;
            el.menuVisualizar.querySelectorAll('[data-pex-modo]').forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
            try { localStorage.setItem('pex:modo', b.dataset.pexModo); } catch (err) { /* silencioso */ }
            fecharPopovers();
        });
    }

    // ------------------------------------------------- HTTP (form-data) -----
    function postForm(url, campos) {
        const fd = new FormData();
        Object.keys(campos).forEach(function (k) { if (campos[k] !== undefined && campos[k] !== null) fd.append(k, campos[k]); });
        return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); });
    }
    function persistirOrdem(url, csrf, ids) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ _token: csrf, ids: ids }),
        }).catch(function () { /* silencioso; um reload corrige a ordem */ });
    }

    // ------------------------------------------------------ CRUD de pastas --
    if (el.btnNovaPasta) {
        el.btnNovaPasta.addEventListener('click', function () {
            pedirTexto('Nova pasta', '', 'Nome da pasta…').then(function (nome) {
                if (nome == null) return;
                const campos = { _token: cfg.csrfCriarSecao, nome: nome };
                if (caminho.length) campos.paiId = String(pastaAtualId());
                postForm(cfg.urlCriarSecao, campos).then(function (res) {
                    if (!res.ok) throw new Error(res.j.erro || 'Falha ao criar a pasta.');
                    pastas.push({
                        id: Number(res.j.id),
                        nome: res.j.nome,
                        paiId: res.j.paiId != null ? Number(res.j.paiId) : null,
                        ordem: pastas.length + 1,
                        subpastas: 0,
                        arquivos: 0,
                        urlRenomear: cfg.urlRenomearTpl.replace('__ID__', res.j.id),
                        csrfRenomear: res.j.csrfRenomear || '',
                        urlExcluir: cfg.urlExcluirTpl.replace('__ID__', res.j.id),
                        csrfExcluir: res.j.csrfExcluir || '',
                        urlMover: cfg.urlMoverTpl.replace('__ID__', res.j.id),
                        csrfMover: res.j.csrfMover || '',
                    });
                    renderizar();
                }).catch(function (err) { alert(err.message); });
            });
        });
    }

    function renomearPasta(p) {
        pedirTexto('Renomear pasta', p.nome, 'Nome da pasta…').then(function (nome) {
            if (nome == null) return;
            postForm(p.urlRenomear, { _token: p.csrfRenomear, nome: nome }).then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao renomear.');
                p.nome = res.j.nome;
                renderizar();
            }).catch(function (err) { alert(err.message); });
        });
    }

    // Aviso montado com a contagem da árvore (D3): o número tem de existir ANTES do clique.
    function avisoExclusao(p) {
        const c = contarArvore(p.id);
        if (c.subpastas === 0 && c.arquivos === 0) {
            return 'A pasta "' + p.nome + '" está vazia. Excluir mesmo assim? Esta ação não pode ser desfeita.';
        }
        const partes = [];
        if (c.subpastas > 0) partes.push(pluralizar(c.subpastas, 'subpasta', 'subpastas'));
        if (c.arquivos > 0) partes.push(pluralizar(c.arquivos, 'arquivo', 'arquivos'));
        return 'Excluir a pasta "' + p.nome + '" e todo o conteúdo dela? Ao todo: ' + partes.join(' e ') + '. Esta ação não pode ser desfeita.';
    }

    function excluirPasta(p) {
        if (!confirm(avisoExclusao(p))) return;
        postForm(p.urlExcluir, { _token: p.csrfExcluir }).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao excluir.');
            // O back apaga a ÁRVORE inteira (cascade): tira as filhas e os arquivos delas da memória.
            const subarvore = [p.id].concat(descendentes(p.id));
            for (let i = arquivos.length - 1; i >= 0; i--) {
                if (arquivos[i].secaoId != null && subarvore.indexOf(Number(arquivos[i].secaoId)) !== -1) { arquivos.splice(i, 1); totalArquivos--; }
            }
            for (let i = pastas.length - 1; i >= 0; i--) {
                if (subarvore.indexOf(pastas[i].id) !== -1) pastas.splice(i, 1);
            }
            if (caminho.some(function (id) { return subarvore.indexOf(id) !== -1; })) voltarRaiz(); else renderizar();
        }).catch(function (err) { alert(err.message); });
    }

    function moverPasta(p, destinoId) {
        return postForm(p.urlMover, { _token: p.csrfMover, destinoId: destinoId == null ? '' : String(destinoId) }).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
            p.paiId = res.j.paiId != null ? Number(res.j.paiId) : null;
            renderizar();
        }).catch(function (err) { alert(err.message); });
    }

    function escolherDestinoPasta(p) {
        const proibidos = [p.id].concat(descendentes(p.id));
        pedirDestino('Mover "' + p.nome + '" para', {
            proibidos: proibidos,
            atual: p.paiId == null ? null : Number(p.paiId),
        }).then(function (destino) {
            if (destino === undefined) return;      // cancelou
            moverPasta(p, destino);                 // null = raiz
        });
    }

    // ---------------------------------------------------- mover arquivo -----
    function moverArquivo(a, destinoId) {
        const campos = { _token: a.csrfMover };
        if (destinoId != null) campos.secao_id = String(destinoId);
        return postForm(a.urlMover, campos).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
            a.secaoId = destinoId == null ? null : Number(destinoId);
            renderizar();
        }).catch(function (err) { alert(err.message); });
    }
    function escolherDestinoArquivo(a) {
        pedirDestino('Mover "' + a.nome + '" para', {
            proibidos: [],
            atual: a.secaoId == null ? null : Number(a.secaoId),
        }).then(function (destino) {
            if (destino === undefined) return;
            moverArquivo(a, destino);
        });
    }

    // --------------------------------------------------- editar arquivo -----
    function abrirEditar(a) {
        const modalEl = document.getElementById('pexEditarModal');
        if (!modalEl || !window.bootstrap) return;
        const ext = extensaoDe(a.nome);
        document.getElementById('pexEditarForm').action = cfg.urlEditarDocTpl.replace('__ID__', a.id);
        document.getElementById('pexEditarToken').value = a.csrfEditar || '';
        document.getElementById('pexEditarNomeBase').value = nomeSemExtensao(a.nome);
        document.getElementById('pexEditarExt').textContent = ext ? '.' + ext : '';
        document.getElementById('pexEditarExtTexto').textContent = ext ? '.' + ext : '(sem extensão)';
        const sel = document.getElementById('pexEditarCategoria');
        if (sel) sel.value = a.categoria || '';
        document.getElementById('pexEditarDescricao').value = a.descricao || '';
        document.getElementById('pexEditarNumero').value = a.numero || '';
        gravarCaminho();      // o POST recarrega a página; volta na mesma pasta
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // -------------------------------------------------- excluir arquivo -----
    function excluirArquivo(a) {
        if (!confirm('Excluir este arquivo? Esta ação não pode ser desfeita.')) return;
        gravarCaminho();
        // O mesmo POST de formulário de sempre (pasta_documento_delete + delete_documento_<id>);
        // o servidor redireciona para #documentos.
        const form = h('form', { method: 'post', action: cfg.urlExcluirDocTpl.replace('__ID__', a.id), hidden: true }, [
            h('input', { type: 'hidden', name: '_token', value: a.csrfExcluir || '' }),
        ]);
        document.body.appendChild(form);
        form.submit();
    }

    // --------------------------------------------------- modal de texto -----
    let promptResolve = null;
    const inputModalEl = document.getElementById('pexInputModal');
    const inputModal   = inputModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(inputModalEl) : null;
    const inputCampo   = document.getElementById('pexInputCampo');
    const inputTitulo  = document.getElementById('pexInputTitulo');
    const inputErro    = document.getElementById('pexInputErro');

    function pedirTexto(titulo, valor, placeholder) {
        return new Promise(function (resolve) {
            if (!inputModal) { const v = prompt(titulo, valor || ''); resolve(v == null ? null : v.trim()); return; }
            promptResolve = resolve;
            inputTitulo.textContent = titulo;
            inputCampo.value = valor || '';
            inputCampo.placeholder = placeholder || '';
            inputErro.classList.add('d-none');
            inputModal.show();
            setTimeout(function () { inputCampo.focus(); inputCampo.select(); }, 250);
        });
    }
    if (inputModal) {
        const confirmar = function () {
            const v = (inputCampo.value || '').trim();
            if (v === '') { inputErro.textContent = 'Informe um nome.'; inputErro.classList.remove('d-none'); return; }
            const r = promptResolve; promptResolve = null;
            inputModal.hide();
            if (r) r(v);
        };
        document.getElementById('pexInputConfirmar').addEventListener('click', confirmar);
        inputCampo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); confirmar(); } });
        inputModalEl.addEventListener('hidden.bs.modal', function () {
            if (promptResolve) { const r = promptResolve; promptResolve = null; r(null); }
        });
    }

    // ------------------------------------------------- modal de destino -----
    // Árvore de pastas (raiz + cada pasta recuada pela profundidade). Resolve com null (raiz),
    // um id, ou undefined (cancelou).
    let destinoResolve = null;
    let destinoEscolhido;
    const destinoModalEl = document.getElementById('pexDestinoModal');
    const destinoModal   = destinoModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(destinoModalEl) : null;
    const destinoArvore  = document.getElementById('pexDestinoArvore');
    const destinoTitulo  = document.getElementById('pexDestinoTitulo');
    const destinoConfirmar = document.getElementById('pexDestinoConfirmar');

    function pedirDestino(titulo, opts) {
        return new Promise(function (resolve) {
            if (!destinoModal) { resolve(undefined); return; }
            destinoResolve = resolve;
            destinoEscolhido = undefined;
            destinoTitulo.textContent = titulo;
            destinoConfirmar.disabled = true;
            destinoArvore.textContent = '';

            const proibidos = opts.proibidos || [];
            const itemDestino = function (id, nome, profundidade, ico) {
                const ehAtual = (id == null && opts.atual == null) || (id != null && opts.atual != null && Number(id) === Number(opts.atual));
                const proibido = id != null && proibidos.indexOf(Number(id)) !== -1;
                return h('button', {
                    type: 'button',
                    class: 'pex-dest-item',
                    role: 'option',
                    'aria-selected': 'false',
                    'data-pex-destino': id == null ? '' : String(id),
                    disabled: ehAtual || proibido,
                    title: proibido ? 'Uma pasta não pode ir para dentro dela mesma' : (ehAtual ? 'Já está aqui' : nome),
                    style: 'padding-left:' + (10 + profundidade * 18) + 'px',
                }, [icone(ico), h('span', { text: nome }), ehAtual ? h('small', { text: 'atual' }) : null]);
            };
            destinoArvore.appendChild(itemDestino(null, 'Documentos (raiz da pasta)', 0, 'bi-house-door'));
            const montar = function (paiId, profundidade) {
                if (profundidade > 100) return;
                pastas.filter(function (p) { return (p.paiId == null ? null : Number(p.paiId)) === paiId; })
                    .sort(function (a, b) { return (a.ordem - b.ordem) || cmpNome(a, b); })
                    .forEach(function (p) {
                        destinoArvore.appendChild(itemDestino(p.id, p.nome, profundidade, 'bi-folder-fill'));
                        montar(p.id, profundidade + 1);
                    });
            };
            montar(null, 1);
            destinoModal.show();
        });
    }
    if (destinoModal) {
        destinoArvore.addEventListener('click', function (e) {
            const b = e.target.closest('[data-pex-destino]');
            if (!b || b.disabled) return;
            destinoArvore.querySelectorAll('[data-pex-destino]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
            destinoEscolhido = b.dataset.pexDestino === '' ? null : Number(b.dataset.pexDestino);
            destinoConfirmar.disabled = false;
        });
        destinoArvore.addEventListener('dblclick', function (e) {
            const b = e.target.closest('[data-pex-destino]');
            if (!b || b.disabled) return;
            destinoEscolhido = b.dataset.pexDestino === '' ? null : Number(b.dataset.pexDestino);
            destinoConfirmar.click();
        });
        destinoConfirmar.addEventListener('click', function () {
            if (destinoEscolhido === undefined) return;
            const r = destinoResolve; destinoResolve = null;
            const v = destinoEscolhido;
            destinoModal.hide();
            if (r) r(v);
        });
        destinoModalEl.addEventListener('hidden.bs.modal', function () {
            if (destinoResolve) { const r = destinoResolve; destinoResolve = null; r(undefined); }
        });
    }

    // ------------------------------------------------------------ upload ----
    if (el.btnAnexar && el.fileInput) {
        el.btnAnexar.addEventListener('click', function () { el.fileInput.click(); });
        el.fileInput.addEventListener('change', function () {
            if (el.fileInput.files && el.fileInput.files.length) enviarArquivos(Array.prototype.slice.call(el.fileInput.files));
            el.fileInput.value = '';
        });
    }
    // Arrastar do computador para a pasta aberta (função só do sistema, DOC-42).
    if (el.corpo) {
        const temArquivosDoSO = function (e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; };
        ['dragenter', 'dragover'].forEach(function (ev) {
            el.corpo.addEventListener(ev, function (e) {
                if (temArquivosDoSO(e)) { e.preventDefault(); el.corpo.classList.add('pex-corpo--soltando'); }
            });
        });
        el.corpo.addEventListener('dragleave', function (e) {
            if (!el.corpo.contains(e.relatedTarget)) el.corpo.classList.remove('pex-corpo--soltando');
        });
        el.corpo.addEventListener('drop', function (e) {
            if (!temArquivosDoSO(e)) return;
            el.corpo.classList.remove('pex-corpo--soltando');
            e.preventDefault();
            if (e.dataTransfer.files && e.dataTransfer.files.length) enviarArquivos(Array.prototype.slice.call(e.dataTransfer.files));
        });
    }

    function enviarArquivos(lista) {
        if (typeof window.enviarArquivoComProgresso !== 'function') { alert('Upload indisponível.'); return; }
        const destino = pastaAtualId();
        let i = 0, houveErro = false;
        el.uploadBar.hidden = false;

        function proximo() {
            if (i >= lista.length) {
                el.uploadCont.textContent = houveErro ? 'Concluído com erros' : 'Concluído';
                // Recarrega para renderizar as linhas novas com os tokens do servidor; volta à
                // mesma pasta (sessionStorage) e à mesma aba (fragmento #documentos).
                gravarCaminho();
                setTimeout(function () {
                    window.location.hash = 'documentos';
                    window.location.reload();
                }, 700);
                return;
            }
            const file = lista[i];
            el.uploadNome.textContent = file.name;
            el.uploadCont.textContent = (i + 1) + '/' + lista.length;
            el.uploadProg.style.width = '0%';
            el.uploadProg.className = 'progress-bar';

            window.enviarArquivoComProgresso(file, {
                url: cfg.urlUpload,
                csrf: cfg.csrfUpload,
                categoria: 'DEMAIS',
                descricao: '',
                numero: '',
                secaoId: destino == null ? null : String(destino),
                reduzir: false,
                onProgress: function (pct) { el.uploadProg.style.width = pct + '%'; },
                onComprimindo: function () { el.uploadProg.style.width = '100%'; },
            }).catch(function (err) {
                houveErro = true;
                el.uploadProg.classList.add('bg-danger');
                el.uploadNome.textContent = '✗ ' + file.name + ' — ' + err.message;
            }).then(function () { i++; proximo(); });
        }
        proximo();
    }

    // ------------------------------------------- arrastar item → pasta ------
    /* Dois mecanismos, um por vez:
       - modo Manual: SortableJS reordena (persistindo em /reordenar); soltar sobre uma linha de
         pasta MOVE em vez de reordenar — decidido pela COORDENADA do soltar contra o retângulo
         das linhas de pasta, porque o Sortable reposiciona o item arrastado sob o cursor e
         `e.target` seria sempre o próprio item (lição de 21/08 no fm);
       - demais ordens: arraste nativo HTML5 da linha (arquivo OU pasta) para uma linha de pasta. */
    let sortable = null;
    let itemArrastado = null;    // elemento .pex-item em arraste (Sortable)
    let ultimoPonto = null;
    let alvoRealcado = null;

    function linhasDePasta() { return Array.prototype.slice.call(el.lista.querySelectorAll('.pex-item--pasta')); }
    function pastaSobPonto(x, y, exceto) {
        if (x == null) return null;
        const linhas = linhasDePasta();
        for (let i = 0; i < linhas.length; i++) {
            if (linhas[i] === exceto) continue;
            const r = linhas[i].getBoundingClientRect();
            if (r.width && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return linhas[i];
        }
        return null;
    }
    function realcarAlvo(linha) {
        if (alvoRealcado === linha) return;
        if (alvoRealcado) alvoRealcado.classList.remove('pex-item--alvo');
        alvoRealcado = linha;
        if (alvoRealcado) alvoRealcado.classList.add('pex-item--alvo');
    }
    document.addEventListener('dragover', function (e) {
        if (!itemArrastado) return;
        ultimoPonto = { x: e.clientX, y: e.clientY };
        realcarAlvo(pastaSobPonto(e.clientX, e.clientY, itemArrastado));
    }, true);

    function soltarEm(item, destinoLinha) {
        const destinoId = Number(destinoLinha.dataset.pexId);
        if (item.dataset.pexTipo === 'arquivo') {
            const a = arquivoPorId(item.dataset.pexId);
            if (a && (a.secaoId == null || Number(a.secaoId) !== destinoId)) moverArquivo(a, destinoId);
            return;
        }
        const p = pastaPorId(item.dataset.pexId);
        if (!p || p.id === destinoId) return;
        if (descendentes(p.id).indexOf(destinoId) !== -1) { alert('Uma pasta não pode ir para dentro dela mesma.'); return; }
        if (p.paiId != null && Number(p.paiId) === destinoId) return;
        moverPasta(p, destinoId);
    }

    function ligarSortable() {
        if (sortable) { sortable.destroy(); sortable = null; }
        if (!window.Sortable || classificar.chave !== 'manual' || normalizar(busca) !== '') return;
        sortable = new Sortable(el.lista, {
            draggable: '.pex-item',
            animation: 150,
            ghostClass: 'pex-arrastando',
            // Links, botões e menus continuam clicáveis: não iniciam arraste.
            filter: 'a, button, .dropdown-menu',
            preventOnFilter: false,
            onStart: function (evt) { itemArrastado = evt.item; ultimoPonto = null; },
            onEnd: function (evt) {
                const arrastado = itemArrastado;
                itemArrastado = null;
                realcarAlvo(null);
                const ponto = ultimoPonto || (evt.originalEvent ? { x: evt.originalEvent.clientX, y: evt.originalEvent.clientY } : null);
                const destino = ponto && arrastado ? pastaSobPonto(ponto.x, ponto.y, arrastado) : null;
                if (arrastado && destino) { soltarEm(arrastado, destino); return; }
                if (!arrastado) return;

                // Reordenar: a ordem nova é a ordem do DOM, por tipo. Persiste só o tipo arrastado.
                const tipo = arrastado.dataset.pexTipo;
                const ids = Array.prototype.slice.call(el.lista.querySelectorAll('.pex-item[data-pex-tipo="' + tipo + '"]'))
                    .map(function (n) { return Number(n.dataset.pexId); });
                ids.forEach(function (id, i) {
                    const it = tipo === 'pasta' ? pastaPorId(id) : arquivoPorId(id);
                    if (it) it.ordem = i + 1;
                });
                if (tipo === 'pasta') persistirOrdem(cfg.urlReordenarSecoes, cfg.csrfReordenarSecoes, ids);
                else persistirOrdem(cfg.urlReordenarDocs, cfg.csrfReordenarDocs, ids);
                renderizar();
            },
        });
    }

    // Arraste nativo (fora do modo Manual)
    let nativoArrastado = null;
    el.lista.addEventListener('dragstart', function (e) {
        if (sortable) return;
        const item = e.target.closest && e.target.closest('.pex-item');
        if (!item) return;
        nativoArrastado = item;
        item.classList.add('pex-arrastando');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', item.dataset.pexTipo + ':' + item.dataset.pexId); } catch (err) { /* IE */ }
    });
    el.lista.addEventListener('dragover', function (e) {
        if (!nativoArrastado) return;
        const alvo = e.target.closest && e.target.closest('.pex-item--pasta');
        if (alvo && alvo !== nativoArrastado) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; realcarAlvo(alvo); }
        else realcarAlvo(null);
    });
    el.lista.addEventListener('dragleave', function (e) {
        if (!nativoArrastado) return;
        if (!el.lista.contains(e.relatedTarget)) realcarAlvo(null);
    });
    el.lista.addEventListener('drop', function (e) {
        if (!nativoArrastado) return;
        const alvo = e.target.closest && e.target.closest('.pex-item--pasta');
        e.preventDefault();
        e.stopPropagation();
        const item = nativoArrastado;
        nativoArrastado = null;
        realcarAlvo(null);
        item.classList.remove('pex-arrastando');
        if (alvo && alvo !== item) soltarEm(item, alvo);
    });
    el.lista.addEventListener('dragend', function () {
        if (nativoArrastado) nativoArrastado.classList.remove('pex-arrastando');
        nativoArrastado = null;
        realcarAlvo(null);
    });

    // ---------------------------------------------------- estado inicial ----
    (function inicializar() {
        let salvo = null;
        try { salvo = JSON.parse(sessionStorage.getItem(CHAVE_CAMINHO) || 'null'); } catch (e) { /* formato inválido */ }
        // Só restaura se TODOS os níveis salvos ainda existirem — pasta excluída cai na raiz.
        if (Array.isArray(salvo) && salvo.length && salvo.every(function (id) { return pastaPorId(id); })) {
            caminho = cadeiaAte(salvo[salvo.length - 1]);
        }
        atualizarBotaoLimpar();
        renderizar();
    })();
})();
