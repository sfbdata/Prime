/* ==========================================================================
   Explorador de documentos da Pasta (aba "Documentos") — comportamento
   Desenho: 02 - EXPEDIENTES 1.2.3 (dc L2020-2297 e L4670-4960).
   Decisão: docs/specs/trilha-b-documentos-arquitetura.md (lotes L1, L2 e L5).

   Lê os dados de `#pexDados` (JSON montado por ExploradorDeDocumentosOutput)
   e renderiza SÓ o nível aberto (ou os resultados da busca) em DocumentFragment:
   a pasta de produção com 1.128 documentos não pode ter 1.128 linhas no HTML.

   L2: oito modos de exibição (dc `EXP_MODOS`, L3051; tamanhos em `expVals`,
   L4701-4723), colunas móveis e redimensionáveis (dc L3079-3139), filtro por
   tipo de documento (dc `TIPOS_DOC`/`expGrupo`, L3082-3087) e painel de
   detalhes do item selecionado (dc L2271-2289, L4952-4953).

   L5: a interação completa (dc `mk`/`sel`/`ctx`/`lassoIni`/`tecla`, L4730-4950):
   - clique seleciona; Ctrl/Cmd alterna; Shift estende da âncora; duplo clique ou
     Enter abre (pasta entra, arquivo pré-visualiza). No toque (celular ou ponteiro
     sem hover) tocar entra/abre, como antes, e o toque longo abre o menu;
   - laço (retângulo) a partir do espaço vazio da lista; Ctrl soma à seleção;
   - teclado: setas/Home/End, Shift+setas, Ctrl+A, Esc, F2, Del, Ctrl+X/Ctrl+V;
   - menu de contexto (botão direito, ⋮ no toque) por item, por vários e no fundo,
     na ordem do desenho — SEM os itens que dependem de lotes futuros (favoritos L6,
     Desfazer L7, zip/Copiar L8, visor L10) nem dos itens E (Chat I.A);
   - barra de seleção, toast, criar/renomear inline, arraste nativo da SELEÇÃO
     para pasta (pasta→pasta inclusive), ações em lote por mover-lote/excluir-lote
     (um token por pasta: `csrfLote`), upload inserindo a linha sem recarregar.
   A seleção troca classes nas linhas já renderizadas: nunca refaz a lista.

   Depende de: Bootstrap 5 (Modal), SortableJS (opcional, só no modo Manual),
   `window.enviarArquivoComProgresso` (helper do template, também do Peticionar)
   e do `#previewDocModal` já ligado pelo visualizador-documento.js.

   Storage — SÓ preferência de visualização em localStorage (`pex:classificar`,
   `pex:colunas`, `pex:modo`, `pex:painel`), sempre dentro de try/catch. O filtro
   por tipo NÃO é persistido (o dc não persiste `expTipoF`): vale só para esta
   visita. A pasta aberta fica em sessionStorage (`pex:pasta:<id>:caminho`). Nada
   de flag de aba: o retorno é pelo fragmento `#documentos`, que o pasta-show.js abre.
   A área de transferência do Recortar vive só em memória.
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
        pastaRotulo:         raiz.dataset.pastaRotulo || pastaId,
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
        // Ações em lote (L4, D4): um token por pasta, ids no corpo, posse provada no servidor.
        urlMoverLote:        dados.urlMoverLote || '',
        urlExcluirLote:      dados.urlExcluirLote || '',
        csrfLote:            dados.csrfLote || '',
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
        ordemColunas:   document.getElementById('pexOrdemColunas'),
        filtroTipo:     document.getElementById('pexFiltroTipo'),
        filtroSelo:     document.getElementById('pexFiltroSelo'),
        filtroInfo:     document.getElementById('pexFiltroInfo'),
        filtroNome:     document.getElementById('pexFiltroNome'),
        filtroLimpar:   document.getElementById('pexFiltroLimpar'),
        painel:         document.getElementById('pexPainel'),
        painelVazio:    document.getElementById('pexPainelVazio'),
        painelSel:      document.getElementById('pexPainelSel'),
        painelAlternar: document.getElementById('pexPainelAlternar'),
        // L5
        selecao:        document.getElementById('pexSelecao'),
        selecaoTexto:   document.getElementById('pexSelecaoTexto'),
        selecaoLimpar:  document.getElementById('pexSelecaoLimpar'),
        laco:           document.getElementById('pexLaco'),
        menu:           document.getElementById('pexMenu'),
        menuFundo:      document.getElementById('pexMenuFundo'),
        menuItemTpl:    document.getElementById('pexMenuItem'),
        toast:          document.getElementById('pexToast'),
        toastTexto:     document.getElementById('pexToastTexto'),
        toastIcone:     document.getElementById('pexToastIcone'),
    };
    if (!el.lista) return;

    // ------------------------------------------------------------ estado ----
    const pastas   = dados.pastas;            // mutável em memória
    const arquivos = dados.arquivos;
    let totalArquivos = Number(dados.totalArquivos) || arquivos.length;

    const CHAVE_CAMINHO     = 'pex:pasta:' + pastaId + ':caminho';
    const CHAVE_CLASSIFICAR = 'pex:classificar';
    const CHAVE_COLUNAS     = 'pex:colunas';
    const CHAVE_MODO        = 'pex:modo';
    const CHAVE_PAINEL      = 'pex:painel';
    const CLASSIFICACOES    = ['manual', 'nome', 'tipo', 'tamanho', 'data', 'categoria'];
    // Primeiro clique de cada coluna: data e tamanho começam DECRESCENTES (mais recente / maior
    // primeiro), nome, tipo e categoria em A–Z — regra do desenho (dc L4862) e do fm antigo.
    const SENTIDO_INICIAL   = { nome: false, tipo: false, categoria: false, tamanho: true, data: true };

    /* ---- Modos (dc `EXP_MODOS` L3051): tamanho do ícone em px por modo (dc `expVals`
       L4708: grade xg 72 · g 52 · m 38; p 18 · lista 16 · det 17 · blocos 40 · cont 32).
       Rótulos e ícones do menu moram no template; aqui só o que o render precisa. ---- */
    const ICONE_PX     = { xg: 72, g: 52, m: 38, p: 18, lista: 16, det: 17, blocos: 40, cont: 32 };
    const MODOS_GRADE  = ['xg', 'g', 'm'];
    const MODO_PADRAO  = 'det';
    // Ícone ≥ 52px é o "estilo Office" (folha branca + selo com a sigla, dc `fi()` L3056-3070);
    // abaixo disso, o ícone cheio do Bootstrap pelo tipo (o mesmo do Detalhes).
    const OFFICE_MIN_PX = 52;
    const ICONE_PAINEL_PX = 64;

    /* ---- Colunas (dc `COLS_PAD`/`COL_LIM`, L3080-3101). `cat` (Categoria jurídica, S-2) não
       existe no desenho: entra entre Tipo e Tamanho, desligada, com limites próprios. ---- */
    const COLUNAS_PAD = { ord: ['nome', 'tipo', 'cat', 'tam', 'data'], w: { tipo: 150, cat: 140, tam: 90, data: 110 } };
    const COLUNAS_LIM = { tipo: [80, 360], cat: [80, 300], tam: [60, 200], data: [80, 240] };
    const COLUNAS_ROTULO = { nome: 'Nome', tipo: 'Tipo', cat: 'Categoria', tam: 'Tamanho', data: 'Modificado' };
    const COLUNA_CLASSIFICAR = { nome: 'nome', tipo: 'tipo', cat: 'categoria', tam: 'tamanho', data: 'data' };

    /* ---- Tipo de documento (dc `TIPOS_DOC` + `expGrupo`, L3082-3087). ---- */
    const FILTROS = ['todos', 'pastas', 'pdf', 'word', 'excel', 'img', 'zip', 'outros'];

    /* ---- L5: limites e tempos. O teto do lote é o do servidor (PastaDocumentoController,
       2.000 ids por ação): acima disso a tela avisa sem pedir. ---- */
    const TETO_LOTE      = 2000;
    const TOQUE_LONGO_MS = 500;     // toque longo abre o menu (convenção; o desenho é omisso)
    const TOAST_MS       = 4200;    // dc L4772
    const LACO_MARGEM_PX = 40;      // rola sozinho a 40px da borda (dc L4891)

    let caminho = [];          // [] = raiz; senão a cadeia de ids (números) até a pasta aberta
    let busca   = '';
    let buscaTimer = null;     // debounce da digitação na busca
    let colunas     = lerColunas();       // { categoria: bool, ord: [...], w: {...} }
    let classificar = lerClassificar();   // { chave, desc } — depois das colunas: depende da Categoria
    let modo        = lerModo();          // um dos ICONE_PX
    let painel      = lerPainel();        // bool — o desenho começa desligado (dc L2793)
    let filtroTipo  = 'todos';            // um dos FILTROS — só em memória (o dc não persiste)
    let contagemTipos = {};               // { grupo: n } do conjunto na tela, antes do filtro

    // Seleção (dc `expSelK`/`expAnc`/`expFoco`): chaves 'pasta:<id>' | 'arquivo:<id>' dos itens
    // RENDERIZADOS. Âncora = de onde o Shift estende; foco = último alcançado pelas setas.
    const selecao = new Set();
    let ancora = null;
    let foco   = null;
    let areaDeTransferencia = null;       // { op: 'recortar', chaves: [...] } — Copiar é do L8
    let suprimirCliqueAte = 0;            // o clique que segue um laço ou um toque longo não conta
    let lacoVazio = false;                // mousedown no espaço vazio: é laço, não arraste
    let renomeando = null;                // campo inline aberto (renomear ou nova pasta)

    // Toda leitura de storage passa por aqui: navegador em modo privado / storage bloqueado
    // lança na leitura, e preferência perdida não pode derrubar a aba.
    function lerStorage(chave) {
        let v = null;
        try { v = localStorage.getItem(chave); } catch (e) { v = null; }
        return v;
    }
    function lerClassificar() {
        const v = lerStorage(CHAVE_CLASSIFICAR);
        if (!v) return { chave: 'manual', desc: false };
        const desc  = /_desc$/.test(v);
        const chave = v.replace(/_desc$/, '');
        // Categoria só manda na ordem com a coluna ligada (mesma regra de desligar no Organizar).
        if (chave === 'categoria' && !colunas.categoria) return { chave: 'manual', desc: false };
        return CLASSIFICACOES.indexOf(chave) !== -1 ? { chave: chave, desc: desc } : { chave: 'manual', desc: false };
    }
    function gravarClassificar() {
        try { localStorage.setItem(CHAVE_CLASSIFICAR, classificar.chave + (classificar.desc ? '_desc' : '')); } catch (e) { /* silencioso */ }
    }
    function colunasPadrao() {
        return { categoria: false, ord: COLUNAS_PAD.ord.slice(), w: Object.assign({}, COLUNAS_PAD.w) };
    }
    function limitarLargura(k, v) { return Math.max(COLUNAS_LIM[k][0], Math.min(COLUNAS_LIM[k][1], Math.round(v))); }
    // Aceita o formato do L1 (`{categoria}`) e o do L2 (`{categoria, ord, w}`); o que não
    // fechar com as colunas conhecidas volta ao padrão — preferência corrompida não quebra a grade.
    function lerColunas() {
        const c = colunasPadrao();
        let v = null;
        try { v = JSON.parse(lerStorage(CHAVE_COLUNAS) || 'null'); } catch (e) { v = null; }
        if (!v || typeof v !== 'object') return c;
        c.categoria = v.categoria === true;
        if (Array.isArray(v.ord) && v.ord.length === c.ord.length && c.ord.every(function (k) { return v.ord.indexOf(k) !== -1; })) c.ord = v.ord.slice();
        if (v.w && typeof v.w === 'object') {
            Object.keys(COLUNAS_LIM).forEach(function (k) {
                const n = Number(v.w[k]);
                if (isFinite(n) && n > 0) c.w[k] = limitarLargura(k, n);
            });
        }
        return c;
    }
    function gravarColunas() {
        try { localStorage.setItem(CHAVE_COLUNAS, JSON.stringify(colunas)); } catch (e) { /* silencioso */ }
    }
    function lerModo() {
        const v = lerStorage(CHAVE_MODO);
        return v && Object.prototype.hasOwnProperty.call(ICONE_PX, v) ? v : MODO_PADRAO;
    }
    function gravarModo() {
        try { localStorage.setItem(CHAVE_MODO, modo); } catch (e) { /* silencioso */ }
    }
    function lerPainel() { return lerStorage(CHAVE_PAINEL) === '1'; }
    function gravarPainel() {
        try { localStorage.setItem(CHAVE_PAINEL, painel ? '1' : '0'); } catch (e) { /* silencioso */ }
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
    function formatarDataHora(s) {
        const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : formatarData(s);
    }
    function extensaoDe(nome) {
        const m = String(nome || '').match(/\.([^.\/\\]+)$/);
        return m ? m[1] : '';
    }
    function nomeSemExtensao(nome) {
        const ext = extensaoDe(nome);
        return ext ? String(nome).slice(0, -(ext.length + 1)) : String(nome || '');
    }
    function ehToque() {
        try { return window.matchMedia('(max-width: 767.98px), (hover: none)').matches; } catch (e) { return false; }
    }
    function ehEstreito() {
        try { return window.matchMedia('(max-width: 767.98px)').matches; } catch (e) { return false; }
    }
    // Clipboard API com fallback (execCommand) para contexto sem permissão ou sem HTTPS.
    function copiarPorExec(t) {
        const ta = h('textarea', { 'aria-hidden': 'true', readonly: true, style: 'position:fixed;top:0;left:0;opacity:0;pointer-events:none' });
        ta.value = t;
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }
    function copiarTexto(t) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(t).then(function () { return true; }, function () { return copiarPorExec(t); });
        }
        return Promise.resolve(copiarPorExec(t));
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

    /* ---- Ícone "estilo Office" (dc `FI` + `fi()`, L3055-3070): [classe de cor, sigla do selo].
       Extensão fora do mapa: cinza com as 3 primeiras letras, como o desenho. ---- */
    const FI = {
        PDF: ['pdf', 'PDF'], DOCX: ['word', 'W'], DOC: ['word', 'W'], XLSX: ['excel', 'X'], XLS: ['excel', 'X'], CSV: ['excel', 'X'],
        PPTX: ['ppt', 'P'], ZIP: ['zip', 'ZIP'], RAR: ['rar', 'RAR'], PNG: ['img', 'PNG'], JPG: ['img', 'JPG'], JPEG: ['img', 'JPG'],
        TXT: ['txt', 'TXT'], MP4: ['mp4', 'MP4'],
    };
    function iconeArquivo(nome, px) {
        const t = tipoDe(nome);
        if (px < OFFICE_MIN_PX) return icone(t[0] + ' pex-ico-' + t[1]);
        const ext = extensaoDe(nome).toUpperCase();
        const f = FI[ext] || ['outro', ext.slice(0, 3) || '?'];
        return h('span', { class: 'pex-fi pex-fi--' + f[0] + (f[1].length === 1 ? ' pex-fi--1' : ''), 'aria-hidden': 'true' }, [
            h('i', { class: 'bi bi-file-earmark-fill pex-fi-folha' }),
            h('i', { class: 'bi bi-file-earmark pex-fi-contorno' }),
            h('span', { class: 'pex-fi-selo', text: f[1] }),
        ]);
    }

    // Grupo do filtro "Tipo de documento" pela extensão (dc `expGrupo`, L3083-3087).
    function grupoDe(it) {
        if (it.tipo === 'pasta') return 'pastas';
        const e = extensaoDe(it.nome).toUpperCase();
        if (e === 'PDF') return 'pdf';
        if (/^DOCX?$|^RTF$|^ODT$/.test(e)) return 'word';
        if (/^XLSX?$|^CSV$|^ODS$/.test(e)) return 'excel';
        if (/^(JPE?G|PNG|GIF|WEBP|HEIC|BMP)$/.test(e)) return 'img';
        if (/^(ZIP|RAR|7Z)$/.test(e)) return 'zip';
        return 'outros';
    }
    function rotuloFiltro(id) {
        const r = el.filtroTipo ? el.filtroTipo.querySelector('[data-pex-filtro="' + id + '"] .pex-filtro-rotulo') : null;
        return r ? r.textContent.trim() : id;
    }

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
    function arquivoPorId(id) {
        id = Number(id);
        for (let i = 0; i < arquivos.length; i++) if (arquivos[i].id === id) return arquivos[i];
        return null;
    }
    function paiDe(id) { const p = pastaPorId(id); return p && p.paiId != null ? Number(p.paiId) : null; }
    function pastaAtualId() { return caminho.length ? caminho[caminho.length - 1] : null; }
    function nomePasta(id) { const p = pastaPorId(id); return p ? p.nome : ''; }
    function nomeDoLocal(id) { return id == null ? 'Documentos' : nomePasta(id); }

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

        // Filtro por tipo (dc L3141-3142): a contagem de cada grupo é do conjunto que está na
        // tela ANTES do filtro — o nível aberto, ou os resultados da busca.
        contagemTipos = {};
        ps.concat(as).forEach(function (it) { const g = grupoDe(it); contagemTipos[g] = (contagemTipos[g] || 0) + 1; });
        if (filtroTipo !== 'todos') {
            const doTipo = function (it) { return grupoDe(it) === filtroTipo; };
            ps = ps.filter(doTipo);
            as = as.filter(doTipo);
        }
        return ps.concat(as);
    }

    // ------------------------------------------------------------ render ----
    let itensRenderizados = [];
    let linhasPorChave = new Map();     // chave → elemento .pex-item do render atual

    function chaveDe(it) { return it.tipo + ':' + it.id; }
    function chaveDoElemento(n) { return n.dataset.pexTipo + ':' + Number(n.dataset.pexId); }
    function idDaLinha(chave) { return 'pex-item-' + chave.replace(':', '-'); }
    function colunasVisiveis() {
        return colunas.ord.filter(function (k) { return k !== 'cat' || colunas.categoria; });
    }
    // Grade do Detalhes (dc `expGtc`, L3093): Nome flexível, as demais com a largura do usuário.
    // Vai numa variável CSS da raiz: arrastar a alça reescreve UMA propriedade, sem refazer
    // nenhuma linha. A coluna do ⋮ (32px) é do CSS, só em `(hover: none)` (S-1).
    function gradeDasColunas() {
        return colunasVisiveis().map(function (k) { return k === 'nome' ? 'minmax(140px, 1fr)' : colunas.w[k] + 'px'; }).join(' ');
    }
    function aplicarGrade() { raiz.style.setProperty('--pex-gtc', gradeDasColunas()); }

    function renderizar() {
        const buscando = normalizar(busca) !== '';
        const itens = itensVisiveis();
        itensRenderizados = itens;
        // A lista vai ser refeita: um campo inline aberto não sobrevive a isso.
        if (renomeando) cancelarRenomear(true);
        // Seleção só de quem continua na tela.
        const vivas = {};
        itens.forEach(function (it) { vivas[chaveDe(it)] = true; });
        Array.from(selecao).forEach(function (k) { if (!vivas[k]) selecao.delete(k); });
        if (ancora && !vivas[ancora]) ancora = null;
        if (foco && !vivas[foco]) foco = null;

        Object.keys(ICONE_PX).forEach(function (m) { raiz.classList.toggle('pex--m-' + m, m === modo); });
        raiz.classList.toggle('pex--grade', MODOS_GRADE.indexOf(modo) !== -1);
        raiz.classList.toggle('pex--painel', painel);
        aplicarGrade();
        el.cabecalho.hidden = modo !== 'det';
        renderizarCabecalho();
        renderizarTrilha(buscando);
        renderizarMenus();

        const frag = document.createDocumentFragment();
        linhasPorChave = new Map();
        itens.forEach(function (it) {
            const linha = it.tipo === 'pasta' ? linhaPasta(it.dado, buscando) : linhaArquivo(it.dado, buscando);
            linhasPorChave.set(chaveDe(it), linha);
            frag.appendChild(linha);
        });
        el.lista.textContent = '';
        el.lista.appendChild(frag);
        el.lista.hidden = itens.length === 0;
        // Modo Lista (dc L4724): fluxo em colunas, ⌈n/3⌉ linhas.
        el.lista.style.setProperty('--pex-linhas', String(Math.max(1, Math.ceil(itens.length / 3))));

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
                : (filtroTipo !== 'todos'
                    // O desenho é omisso: subindo pela trilha com o filtro ativo, uma pasta sem
                    // nada do tipo não pode parecer vazia — o chip "Mostrando somente" fica logo acima.
                    ? 'Nenhum item do tipo ' + rotuloFiltro(filtroTipo) + (caminho.length ? ' nesta pasta.' : ' aqui.')
                    : (caminho.length ? 'Pasta vazia. Arraste arquivos para cá ou clique em Anexar.' : 'Nenhum arquivo ainda. Arraste arquivos para cá ou clique em Anexar.'));
            el.vazio.hidden = false;
        } else {
            el.vazio.hidden = true;
        }

        atualizarAtivo();
        renderizarBarra();
        renderizarPainel();
        renderizarRodape();
        if (el.contagem) el.contagem.textContent = String(totalArquivos);
        ligarSortable();
    }

    function renderizarCabecalho() {
        // A coluna Categoria entra/sai do cabeçalho conforme o Organizar (S-2).
        let colCat = el.cabecalho.querySelector('.pex-col-cat');
        if (colunas.categoria && !colCat) {
            colCat = h('div', { class: 'pex-col pex-col-cat', 'aria-sort': 'none' }, [
                h('button', { type: 'button', 'data-pex-classificar': 'categoria' }, [h('span', { text: 'Categoria' }), h('i', { class: 'bi pex-seta', 'aria-hidden': 'true' })]),
            ]);
            el.cabecalho.insertBefore(colCat, el.cabecalho.querySelector('.pex-col-acoes'));
        } else if (!colunas.categoria && colCat) {
            colCat.remove();
        }
        // Ordem do usuário: as células do cabeçalho são MOVIDAS (appendChild move, não copia) e
        // cada uma ganha a alça de largura (dc L2214) — menos a última, como o desenho.
        const vis = colunasVisiveis();
        const acoes = el.cabecalho.querySelector('.pex-col-acoes');
        vis.forEach(function (k, i) {
            const col = el.cabecalho.querySelector('.pex-col-' + k);
            if (!col) return;
            col.dataset.pexCol = k;
            const btn = col.querySelector('[data-pex-classificar]');
            if (btn) btn.title = 'Clique para classificar · arraste para mover a coluna';
            let alca = col.querySelector('.pex-alca');
            if (!alca) {
                alca = h('span', { class: 'pex-alca', title: 'Arraste para ajustar a largura · clique duplo para voltar ao padrão', 'aria-hidden': 'true' }, [h('span', { class: 'pex-alca-linha' })]);
                col.appendChild(alca);
            }
            alca.hidden = i === vis.length - 1;
            el.cabecalho.insertBefore(col, acoes);
        });
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
        // Classificar por: Manual primeiro (S-5), depois as colunas NA ORDEM das colunas
        // (dc `expOrgVals.classes`, L3151 — `c.ord.map`).
        // A pílula Categoria, oculta com a coluna desligada, vai para o fim.
        const ordemPilulas = ['manual'].concat(colunasVisiveis().map(function (k) { return COLUNA_CLASSIFICAR[k]; }));
        if (ordemPilulas.indexOf('categoria') === -1) ordemPilulas.push('categoria');
        ordemPilulas.forEach(function (chave) {
            const b = el.classificar.querySelector('[data-pex-classificar="' + chave + '"]');
            if (b) el.classificar.appendChild(b);
        });
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

        renderizarOrdemColunas();
        renderizarFiltroTipos();

        // Visualizar: o rádio do modo atual, o ícone do botão (dc `modoIc`) e o painel.
        let icModo = null;
        el.menuVisualizar.querySelectorAll('[data-pex-modo]').forEach(function (b) {
            const on = b.dataset.pexModo === modo;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            if (on) icModo = b.querySelector('.bi');
        });
        const icBotao = el.btnVisualizar.querySelector('.bi:not(.pex-chev)');
        if (icBotao && icModo) icBotao.className = icModo.className;
        if (el.painelAlternar) el.painelAlternar.setAttribute('aria-checked', painel ? 'true' : 'false');
    }

    // Ordem das colunas (dc L2043-2046): uma linha por coluna visível, número, rótulo e setas.
    function renderizarOrdemColunas() {
        if (!el.ordemColunas) return;
        const vis = colunasVisiveis();
        const frag = document.createDocumentFragment();
        vis.forEach(function (k, i) {
            frag.appendChild(h('div', { class: 'pex-ordem-linha' }, [
                h('span', { class: 'pex-ordem-n', text: String(i + 1) }),
                h('span', { class: 'pex-ordem-rotulo', text: COLUNAS_ROTULO[k] }),
                h('button', { type: 'button', class: 'pex-ordem-seta', 'data-pex-mover-de': String(i), 'data-pex-mover-para': String(i - 1), disabled: i === 0, title: 'Mover para a esquerda', 'aria-label': 'Mover ' + COLUNAS_ROTULO[k] + ' para a esquerda' }, [icone('bi-arrow-left')]),
                h('button', { type: 'button', class: 'pex-ordem-seta', 'data-pex-mover-de': String(i), 'data-pex-mover-para': String(i + 1), disabled: i === vis.length - 1, title: 'Mover para a direita', 'aria-label': 'Mover ' + COLUNAS_ROTULO[k] + ' para a direita' }, [icone('bi-arrow-right')]),
            ]));
        });
        el.ordemColunas.textContent = '';
        el.ordemColunas.appendChild(frag);
    }

    // Tipo de documento (dc L2048-2054): contagem por grupo, desabilitado quando zero (menos
    // "Todos"), ponto no ativo; botão Organizar destacado com o selo "1" e o chip acima da lista.
    function renderizarFiltroTipos() {
        if (!el.filtroTipo) return;
        let total = 0;
        Object.keys(contagemTipos).forEach(function (g) { total += contagemTipos[g]; });
        el.filtroTipo.querySelectorAll('[data-pex-filtro]').forEach(function (b) {
            const id = b.dataset.pexFiltro;
            const n = id === 'todos' ? total : (contagemTipos[id] || 0);
            const on = id === filtroTipo;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            b.disabled = n === 0 && id !== 'todos' && !on;
            const cont = b.querySelector('.pex-filtro-n');
            if (cont) cont.textContent = String(n);
        });
        const ativo = filtroTipo !== 'todos';
        el.btnOrganizar.classList.toggle('pex-btn-menu--on', ativo);
        if (el.filtroSelo) el.filtroSelo.hidden = !ativo;
        if (el.filtroInfo) el.filtroInfo.hidden = !ativo;
        if (el.filtroNome) el.filtroNome.textContent = ativo ? rotuloFiltro(filtroTipo) : '';
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
    // Conteúdo de pasta como o desenho mostra no sub (dc L4706): "22 arquivos" / "Vazia".
    function conteudoPasta(c) {
        if (c.arquivos > 0) return pluralizar(c.arquivos, 'arquivo', 'arquivos');
        if (c.subpastas > 0) return pluralizar(c.subpastas, 'subpasta', 'subpastas');
        return 'Vazia';
    }
    function numeroDescricao(a) {
        return (a.numero ? 'Nº ' + a.numero : '') + (a.numero && a.descricao ? ' · ' : '') + (a.descricao || '');
    }

    /* Monta a linha/cartão de um item conforme o modo (dc `mk`, L4730-4760):
       - Detalhes: células na ORDEM das colunas do usuário;
       - Conteúdo: sub "Tipo · Categoria" + "Nº · descrição" (DOC-22) e, à direita, data e tamanho;
       - Blocos: sub "Tipo · tamanho"; pastas mostram o conteúdo em Blocos e Conteúdo;
       - grade (xg/g/m), Pequenos e Lista: só ícone e nome.
       A célula do ⋮ nasce em toda linha, mas o CSS só a mostra em `(hover: none)` (S-1): no mouse
       as ações são o botão direito, o teclado e a barra de seleção. */
    function montarItem(attrs, ehPasta, nomeEl, sub, extras, celulasDetalhe, lado, buscando, localId) {
        const txt = [nomeEl];
        if (sub) txt.push(h('span', { class: 'pex-sub', text: sub, title: sub }));
        (extras || []).forEach(function (x) { if (x) txt.push(h('span', { class: 'pex-sub pex-sub--nd', text: x, title: x })); });
        if (buscando) txt.push(celulaLocal(localId));
        const nome = h('span', { class: 'pex-cel pex-cel-nome' }, [
            h('span', { class: 'pex-ico' }, [ehPasta ? icone(TIPO_PASTA[0] + ' pex-ico-pasta') : null]),
            h('span', { class: 'pex-txt' }, txt),
        ]);
        const celulas = [];
        if (modo === 'det') {
            colunasVisiveis().forEach(function (k) { celulas.push(k === 'nome' ? nome : celulasDetalhe[k]); });
        } else {
            celulas.push(nome);
            if (lado) celulas.push(lado);
        }
        celulas.push(h('span', { class: 'pex-cel pex-cel-acoes' }, [botaoMenu(ehPasta ? 'Ações da pasta' : 'Ações do arquivo')]));
        const chave = attrs['data-pex-tipo'] + ':' + attrs['data-pex-id'];
        const on = selecao.has(chave);
        if (on) attrs.class += ' pex-item--sel';
        attrs.id = idDaLinha(chave);
        attrs.role = 'option';
        attrs['aria-selected'] = on ? 'true' : 'false';
        return { linha: h('div', attrs, celulas), ico: nome.firstChild };
    }

    // ⋮ (S-1): só aparece no toque, pelo CSS; abre o MESMO menu de contexto do botão direito.
    function botaoMenu(rotulo) {
        return h('button', { type: 'button', class: 'pex-menu', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-label': rotulo, title: 'Mais ações' }, [icone('bi-three-dots-vertical')]);
    }

    function linhaPasta(p, buscando) {
        const contagem = contarArvore(p.id);
        const sub = modo === 'blocos' || modo === 'cont' ? conteudoPasta(contagem) : '';
        const r = montarItem({
            class: 'pex-item pex-item--pasta',
            'data-pex-tipo': 'pasta',
            'data-pex-id': String(p.id),
            'data-pex-subpastas': String(contagem.subpastas),
            'data-pex-arquivos': String(contagem.arquivos),
            tabindex: '0',
            title: p.nome + ' · ' + pluralizar(contagem.arquivos, 'arquivo', 'arquivos'),
            'aria-label': 'Pasta ' + p.nome,
            draggable: usaSortable() ? null : 'true',
        }, true, h('span', { class: 'pex-nome' }, nomeComRealce(p.nome, buscando)), sub, null, {
            tipo: h('span', { class: 'pex-cel pex-cel-tipo', text: TIPO_PASTA[2] }),
            cat:  h('span', { class: 'pex-cel pex-cel-cat' }),
            tam:  h('span', { class: 'pex-cel pex-cel-tam' }),
            data: h('span', { class: 'pex-cel pex-cel-data' }),
        }, null, buscando, p.paiId);
        return r.linha;
    }

    function nomeArquivo(a, buscando) {
        /* O nome continua um <a> de verdade para a URL de visualização: Ctrl/Cmd/meio-clique
           abrem em outra aba, e sem JS o link ainda leva ao arquivo. O clique simples SELECIONA
           (DOC-14/21); o duplo clique, Enter ou o menu abrem o pré-visualizador. */
        return h('a', {
            href: a.viewUrl, target: '_blank', rel: 'noopener noreferrer',
            class: 'pex-nome pex-arq-preview',
            'data-url': a.viewUrl, 'data-nome': a.nome, 'data-mime': a.mime || '',
            title: a.nome,
            draggable: 'false',
        }, nomeComRealce(a.nome, buscando));
    }

    function linhaArquivo(a, buscando) {
        const t = tipoDe(a.nome);
        let sub = '';
        if (modo === 'blocos') sub = t[2] + ' · ' + formatarBytes(a.tamanho);
        else if (modo === 'cont') sub = t[2] + (a.categoriaRotulo ? ' · ' + a.categoriaRotulo : '');
        // "Nº · descrição" (§16.2, DOC-22): função só do sistema, mora no Conteúdo e no painel.
        const extras = modo === 'cont' ? [numeroDescricao(a)] : null;
        const lado = modo === 'cont'
            ? h('span', { class: 'pex-lado' }, [h('span', { text: formatarData(a.carregadoEm) }), h('span', { text: formatarBytes(a.tamanho) })])
            : null;
        const r = montarItem({
            class: 'pex-item pex-item--arquivo',
            'data-pex-tipo': 'arquivo',
            'data-pex-id': String(a.id),
            title: a.nome,
            draggable: usaSortable() ? null : 'true',
        }, false, nomeArquivo(a, buscando), sub, extras, {
            tipo: h('span', { class: 'pex-cel pex-cel-tipo', text: t[2], title: t[2] }),
            cat:  h('span', { class: 'pex-cel pex-cel-cat', text: a.categoriaRotulo || '', title: a.categoriaRotulo || '' }),
            tam:  h('span', { class: 'pex-cel pex-cel-tam', text: formatarBytes(a.tamanho) }),
            data: h('span', { class: 'pex-cel pex-cel-data', text: formatarData(a.carregadoEm) }),
        }, lado, buscando, a.secaoId);
        r.ico.appendChild(iconeArquivo(a.nome, ICONE_PX[modo]));
        return r.linha;
    }

    // ------------------------------------------------- painel de detalhes ---
    /* dc L2271-2289 / `pProps` L4953. Só o que o #pexDados TEM: o desenho pede "Modificado",
       mas o dado é a data em que o arquivo foi ADICIONADO (`carregadoEm`) — rotular de
       "Modificado" seria afirmar o que o sistema não sabe. Com vários itens selecionados, o
       resumo do desenho (dc L2278): pilha, "N itens selecionados", pastas/arquivos/tamanho. */
    function itensSelecionados() {
        return itensRenderizados.filter(function (it) { return selecao.has(chaveDe(it)); });
    }
    function itemPorChave(chave) {
        for (let i = 0; i < itensRenderizados.length; i++) if (chaveDe(itensRenderizados[i]) === chave) return itensRenderizados[i];
        return null;
    }
    function ehArquivo(it) { return it.tipo === 'arquivo'; }
    function ehPastaItem(it) { return it.tipo === 'pasta'; }
    function somaDosArquivos(itens) {
        return itens.filter(ehArquivo).reduce(function (t, it) { return t + (Number(it.dado.tamanho) || 0); }, 0);
    }
    function propriedadesDe(it) {
        const d = it.dado;
        if (it.tipo === 'pasta') {
            const c = contarArvore(d.id);
            const partes = [];
            if (c.arquivos > 0) partes.push(pluralizar(c.arquivos, 'arquivo', 'arquivos'));
            if (c.subpastas > 0) partes.push(pluralizar(c.subpastas, 'subpasta', 'subpastas'));
            return [
                ['Tipo', TIPO_PASTA[2]],
                ['Conteúdo', partes.length ? partes.join(' · ') : 'Vazia'],
                ['Local', caminhoLegivel(d.paiId == null ? null : Number(d.paiId))],
            ];
        }
        return [
            ['Tipo', tipoDe(d.nome)[2]],
            ['Categoria', d.categoriaRotulo || ''],
            ['Tamanho', formatarBytes(d.tamanho)],
            ['Adicionado em', formatarDataHora(d.carregadoEm)],
            ['Número', d.numero || ''],
            ['Descrição', d.descricao || ''],
            ['Local', caminhoLegivel(d.secaoId == null ? null : Number(d.secaoId))],
        ].filter(function (p) { return p[1] !== ''; });
    }
    function propriedadesDaSelecao(sel) {
        return [
            ['Pastas', String(sel.filter(ehPastaItem).length)],
            ['Arquivos', String(sel.filter(ehArquivo).length)],
            ['Tamanho dos arquivos', formatarBytes(somaDosArquivos(sel))],
        ];
    }
    function renderizarPainel() {
        if (!el.painel) return;
        el.painel.hidden = !painel;
        if (!painel) return;
        const sel = itensSelecionados();
        const it = sel.length === 1 ? sel[0] : null;
        const multi = sel.length > 1;
        el.painelVazio.hidden = !!it || multi;
        el.painelSel.hidden = !it && !multi;
        el.painelSel.textContent = '';
        if (!it && !multi) return;
        const frag = document.createDocumentFragment();
        if (multi) {
            frag.appendChild(h('span', { class: 'pex-painel-ico' }, [icone('bi-stack')]));
            frag.appendChild(h('span', { class: 'pex-painel-nome', text: sel.length + ' itens selecionados' }));
            frag.appendChild(h('dl', { class: 'pex-painel-props' }, propriedadesDaSelecao(sel).map(function (p) {
                return h('div', { class: 'pex-painel-prop' }, [h('dt', { text: p[0] }), h('dd', { text: p[1] })]);
            })));
        } else {
            const ehPasta = it.tipo === 'pasta';
            frag.appendChild(h('span', { class: 'pex-painel-ico' }, [ehPasta ? icone(TIPO_PASTA[0] + ' pex-ico-pasta') : iconeArquivo(it.dado.nome, ICONE_PAINEL_PX)]));
            frag.appendChild(h('span', { class: 'pex-painel-nome', text: it.dado.nome }));
            frag.appendChild(h('dl', { class: 'pex-painel-props' }, propriedadesDe(it).map(function (p) {
                return h('div', { class: 'pex-painel-prop' }, [h('dt', { text: p[0] }), h('dd', { text: p[1] })]);
            })));
        }
        el.painelSel.appendChild(frag);
    }

    // ----------------------------------------------------------- seleção ----
    /* A seleção NUNCA refaz a lista: troca a classe nas linhas que já estão no DOM e refaz só a
       barra e o painel. Na pasta de 1.128 documentos, Ctrl+A tem de ser instantâneo. */
    function aplicarSelecao() {
        linhasPorChave.forEach(function (n, chave) {
            const on = selecao.has(chave);
            if (n.classList.contains('pex-item--sel') === on) return;
            n.classList.toggle('pex-item--sel', on);
            n.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        atualizarAtivo();
    }
    function atualizarAtivo() {
        const k = foco || (selecao.size ? Array.from(selecao)[selecao.size - 1] : null);
        if (k) el.lista.setAttribute('aria-activedescendant', idDaLinha(k));
        else el.lista.removeAttribute('aria-activedescendant');
    }
    function depoisDaSelecao() {
        aplicarSelecao();
        renderizarBarra();
        renderizarPainel();
    }
    // Seleção simples por chave (ou nenhuma, com null): o que o painel e o clique usam.
    function selecionar(chave) {
        selecao.clear();
        if (chave) selecao.add(chave);
        ancora = chave;
        foco = chave;
        aplicarSelecao();
        renderizarBarra();
        renderizarPainel();
    }
    function definirSelecao(chaves, opts) {
        selecao.clear();
        chaves.forEach(function (k) { selecao.add(k); });
        if (opts && 'ancora' in opts) ancora = opts.ancora;
        if (opts && 'foco' in opts) foco = opts.foco;
        depoisDaSelecao();
    }
    function alternarSelecao(chave) {
        if (selecao.has(chave)) selecao.delete(chave); else selecao.add(chave);
        ancora = chave;
        foco = chave;
        depoisDaSelecao();
    }
    // Shift: intervalo da âncora até a chave, na ordem da tela (dc `sel`, L4750).
    function selecionarIntervalo(chave) {
        const chaves = itensRenderizados.map(chaveDe);
        const a = chaves.indexOf(ancora);
        const b = chaves.indexOf(chave);
        if (a === -1 || b === -1) { selecionar(chave); return; }
        definirSelecao(chaves.slice(Math.min(a, b), Math.max(a, b) + 1), { foco: chave });
    }
    function selecionarTudo() {
        definirSelecao(itensRenderizados.map(chaveDe), { foco: foco });
    }
    function limparSelecao(silencioso) {
        const havia = selecao.size > 0;
        selecao.clear();
        ancora = null;
        foco = null;
        if (!silencioso && havia) depoisDaSelecao();
    }
    function linhaDe(chave) { return linhasPorChave.get(chave) || null; }

    // Barra de seleção (dc L2194-2199, `barra` L4844): contagem + tamanho e as ações com rota.
    function renderizarBarra() {
        if (!el.selecao) return;
        const sel = itensSelecionados();
        el.selecao.hidden = sel.length === 0;
        if (!sel.length) return;
        const soma = somaDosArquivos(sel);
        el.selecaoTexto.textContent = (sel.length === 1 ? '1 selecionado' : sel.length + ' selecionados') + (soma ? ' · ' + formatarBytes(soma) : '');
        const baixar = el.selecao.querySelector('[data-pex-sel="baixar"]');
        const renomear = el.selecao.querySelector('[data-pex-sel="renomear"]');
        if (baixar) baixar.hidden = !(sel.length === 1 && sel[0].tipo === 'arquivo');
        if (renomear) renomear.hidden = sel.length !== 1;
    }
    if (el.selecao) {
        el.selecaoLimpar.addEventListener('click', function () { limparSelecao(); el.lista.focus({ preventScroll: true }); });
        el.selecao.addEventListener('click', function (e) {
            const b = e.target.closest('[data-pex-sel]');
            if (!b) return;
            const sel = itensSelecionados();
            switch (b.dataset.pexSel) {
                case 'baixar':   if (sel.length === 1 && sel[0].tipo === 'arquivo') baixar(sel[0].dado); break;
                case 'recortar': recortar(sel); break;
                case 'renomear': if (sel.length === 1) iniciarRenomear(sel[0]); break;
                case 'tudo':     selecionarTudo(); break;
                case 'excluir':  excluirItens(sel); break;
            }
        });
    }

    // --------------------------------------------------------- navegação ----
    // Trocar de nível limpa a seleção, como o desenho (`expSelK: []` em toda navegação).
    function entrar(id) {
        caminho = cadeiaAte(id);
        limparSelecao(true);
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
        limparSelecao(true);
        gravarCaminho();
        renderizar();
    }
    function subir() { if (caminho.length) irParaNivel(caminho.length - 2); }
    function voltarRaiz() { irParaNivel(-1); }

    // ============================================================ EVENTOS ====

    /* Clique na lista (dc `sel`, L4748-4753): seleciona; Ctrl/Cmd alterna; Shift estende.
       O ⋮ (toque) abre o menu. No toque (abaixo de 768px ou ponteiro sem hover) a pasta ENTRA
       e o nome do arquivo ABRE, como antes — duplo toque não é gesto confiável; o resto da linha
       seleciona. Convenção do sistema; o desenho é omisso sobre toque. */
    el.lista.addEventListener('click', function (e) {
        if (Date.now() < suprimirCliqueAte) { e.preventDefault(); return; }
        const item = e.target.closest('.pex-item');
        if (item && item.dataset.pexTemp !== undefined) return;
        if (e.target.closest('.pex-ren')) return;
        const btnMenu = e.target.closest('.pex-menu');
        if (btnMenu && item) {
            e.preventDefault();
            e.stopPropagation();
            abrirMenuNoBotao(btnMenu, item);
            return;
        }
        const prev = e.target.closest('.pex-arq-preview');
        if (prev) {
            // O nome é um <a> de verdade: com Ctrl/Cmd/Alt, deixa o navegador abrir em outra aba.
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            e.preventDefault();
            if (ehToque() && !e.shiftKey && item) { selecionar(chaveDoElemento(item)); abrirPreview(prev); return; }
        }
        if (!item) { limparSelecao(); return; }
        const chave = chaveDoElemento(item);
        if (ehToque() && item.dataset.pexTipo === 'pasta') { entrar(Number(item.dataset.pexId)); return; }
        if (e.ctrlKey || e.metaKey) alternarSelecao(chave);
        else if (e.shiftKey) selecionarIntervalo(chave);
        else selecionar(chave);
        el.lista.focus({ preventScroll: true });
    });
    // Duplo clique abre (dc `abrir`, L4758): pasta entra, arquivo pré-visualiza.
    el.lista.addEventListener('dblclick', function (e) {
        if (e.target.closest('.pex-ren, .pex-menu')) return;
        const item = e.target.closest('.pex-item');
        if (!item || item.dataset.pexTemp !== undefined) return;
        e.preventDefault();
        abrirItem(itemPorChave(chaveDoElemento(item)));
    });

    function abrirPreview(gatilho) {
        const modal = document.getElementById('previewDocModal');
        if (!modal || !window.bootstrap) { window.open(gatilho.dataset.url, '_blank', 'noopener'); return; }
        bootstrap.Modal.getOrCreateInstance(modal).show(gatilho);
    }
    // O visualizador lê `relatedTarget.dataset.url|nome|mime`: o gatilho é o link da linha, ou um
    // nó avulso com os mesmos três dados quando a linha não está na tela.
    function gatilhoDe(a) {
        const linha = linhaDe('arquivo:' + a.id);
        const g = linha ? linha.querySelector('.pex-arq-preview') : null;
        return g || h('span', { 'data-url': a.viewUrl, 'data-nome': a.nome, 'data-mime': a.mime || '' });
    }
    function abrirPreviewDe(a) { abrirPreview(gatilhoDe(a)); }
    function abrirItem(it) {
        if (!it) return;
        if (it.tipo === 'pasta') entrar(it.id); else abrirPreviewDe(it.dado);
    }
    // Baixar: o mesmo link de download de sempre, em outra aba.
    function baixar(a) {
        const l = h('a', { href: a.downloadUrl, target: '_blank', rel: 'noopener', hidden: true });
        document.body.appendChild(l);
        l.click();
        l.remove();
    }

    // Trilha
    el.trilhaNiveis.addEventListener('click', function (e) {
        const b = e.target.closest('[data-pex-nivel]');
        if (b) irParaNivel(parseInt(b.dataset.pexNivel, 10));
    });
    if (el.subir) el.subir.addEventListener('click', subir);

    // ------------------------------------------------------------ teclado ---
    /* dc `tecla` (L4902-4931). Fora de campos de texto e do checklist (que tem campos e botões
       próprios). Backspace / Alt+← / Alt+↑ sobem um nível de qualquer ponto do explorador (como
       no L2); os demais atalhos só com o foco NA LISTA ou numa linha — com o foco num botão da
       faixa, do Organizar, da barra ou de um popover, Enter/Espaço/setas são do navegador.
       Ctrl+A tudo; Del exclui; F2 renomeia; Enter abre; Espaço visualiza; Esc limpa (ou, com um
       popover aberto, só o fecha); Ctrl+X/V recorta/cola; setas, Home e End movem a seleção
       (Shift estende). Ctrl+C é do L8 (Copiar) e não faz nada aqui. */
    raiz.addEventListener('keydown', function (e) {
        const tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
        // O checklist tem campos e botões próprios: Backspace lá é do checklist, não da navegação.
        if (e.target && e.target.closest && e.target.closest('#pexChecklist')) return;
        if (el.menu && !el.menu.hidden && e.target.closest('#pexMenu')) { teclaNoMenu(e); return; }
        const ctrl = e.ctrlKey || e.metaKey;
        const k = e.key;
        const baixa = String(k || '').toLowerCase();
        if ((k === 'Backspace' || (e.altKey && (k === 'ArrowLeft' || k === 'ArrowUp'))) && caminho.length) {
            e.preventDefault();
            subir();
            return;
        }
        const noAlvo = e.target === el.lista || !!(e.target.closest && e.target.closest('.pex-item'));
        if (k === 'Escape') {
            // Com um popover aberto, Esc fecha só o popover (document) e a seleção fica.
            if (popoverAberto()) return;
            if (selecao.size && (noAlvo || e.target.closest('#pexSelecao'))) { e.preventDefault(); limparSelecao(); }
            return;
        }
        if (!noAlvo) return;
        const sel = itensSelecionados();
        const linhaFocada = e.target.closest ? e.target.closest('.pex-item') : null;
        if (ctrl && baixa === 'a') { e.preventDefault(); selecionarTudo(); return; }
        if (k === 'Delete') { if (sel.length) { e.preventDefault(); excluirItens(sel); } return; }
        if (k === 'F2') { if (sel.length === 1) { e.preventDefault(); iniciarRenomear(sel[0]); } return; }
        if (k === 'Enter') {
            const alvo = sel.length === 1 ? sel[0] : (linhaFocada ? itemPorChave(chaveDoElemento(linhaFocada)) : null);
            if (alvo) { e.preventDefault(); abrirItem(alvo); }
            return;
        }
        if (k === ' ') {
            const alvo = sel.length === 1 ? sel[0] : (linhaFocada ? itemPorChave(chaveDoElemento(linhaFocada)) : null);
            if (alvo) { e.preventDefault(); if (alvo.tipo === 'arquivo') abrirPreviewDe(alvo.dado); else entrar(alvo.id); }
            return;
        }
        if (ctrl && baixa === 'x') { if (sel.length) { e.preventDefault(); recortar(sel); } return; }
        if (ctrl && baixa === 'v') { if (areaDeTransferencia) { e.preventDefault(); colarAqui(); } return; }
        if (ctrl) return;
        if (/^Arrow(Up|Down|Left|Right)$/.test(k) || k === 'Home' || k === 'End') {
            e.preventDefault();
            navegarTeclado(k, e.shiftKey);
        }
    });

    // Setas (dc L4917-4930): passo 1 em lista; nos modos de grade o passo vertical é o número de
    // colunas MEDIDO na primeira linha; no modo Lista (fluxo em colunas) ←/→ pulam ⌈n/3⌉.
    function navegarTeclado(k, estender) {
        const chaves = itensRenderizados.map(chaveDe);
        if (!chaves.length) return;
        let fluxo = 'lista';
        if (modo === 'lista' && !ehEstreito()) fluxo = 'coluna';
        else if (MODOS_GRADE.indexOf(modo) !== -1 || modo === 'p' || modo === 'blocos') fluxo = 'grade';
        let cols = 1;
        if (fluxo === 'grade') {
            const els = Array.prototype.slice.call(el.lista.children);
            const t0 = els[0] ? els[0].getBoundingClientRect().top : 0;
            cols = els.filter(function (x) { return Math.abs(x.getBoundingClientRect().top - t0) < 2; }).length || 1;
        }
        const linhas = Math.ceil(chaves.length / 3);
        const passos = {
            ArrowDown:  fluxo === 'grade' ? cols : 1,
            ArrowUp:    fluxo === 'grade' ? -cols : -1,
            ArrowRight: fluxo === 'grade' ? 1 : (fluxo === 'coluna' ? linhas : 0),
            ArrowLeft:  fluxo === 'grade' ? -1 : (fluxo === 'coluna' ? -linhas : 0),
        };
        const ultima = selecao.size ? Array.from(selecao)[selecao.size - 1] : null;
        const atual = chaves.indexOf(foco && chaves.indexOf(foco) !== -1 ? foco : ultima);
        let n;
        if (k === 'Home') n = 0;
        else if (k === 'End') n = chaves.length - 1;
        else {
            const passo = passos[k];
            if (!passo) return;
            n = atual < 0 ? 0 : Math.max(0, Math.min(chaves.length - 1, atual + passo));
        }
        const nk = chaves[n];
        if (estender) {
            const a = chaves.indexOf(ancora && chaves.indexOf(ancora) !== -1 ? ancora : nk);
            definirSelecao(chaves.slice(Math.min(a, n), Math.max(a, n) + 1), { ancora: chaves[a], foco: nk });
        } else {
            selecionar(nk);
        }
        const linha = linhaDe(nk);
        if (linha && linha.scrollIntoView) linha.scrollIntoView({ block: 'nearest' });
    }

    // --------------------------------------------------------------- laço ---
    /* dc `lassoIni` (L4874-4900). Como no Windows: arrastar a partir do ícone/nome move o item;
       a partir do espaço vazio (da lista ou da própria linha) abre a seleção em retângulo. Só com
       mouse (mousedown/mousemove): no toque não existe laço. Ctrl soma à seleção que já havia.
       Os retângulos das linhas são medidos UMA vez, em coordenadas da página, no início. */
    function alcanceDoConteudo(linha) {
        const cel = linha.querySelector('.pex-cel-nome');
        if (!cel) return Infinity;
        let dir = 0;
        const tw = document.createTreeWalker(cel, NodeFilter.SHOW_TEXT);
        let tn;
        while ((tn = tw.nextNode())) {
            if (!tn.nodeValue.trim()) continue;
            const rg = document.createRange();
            rg.selectNodeContents(tn);
            Array.prototype.forEach.call(rg.getClientRects(), function (r) { dir = Math.max(dir, r.right); });
        }
        cel.querySelectorAll('i, svg, img, .pex-fi').forEach(function (n) { dir = Math.max(dir, n.getBoundingClientRect().right); });
        return dir + 6;
    }
    function pontoNoConteudo(linha, x) { return x == null || x <= alcanceDoConteudo(linha); }
    function medirLinhas() {
        const sy = window.scrollY, sx = window.scrollX;
        return Array.prototype.map.call(el.lista.children, function (n) {
            const r = n.getBoundingClientRect();
            return { chave: chaveDoElemento(n), top: r.top + sy, bottom: r.bottom + sy, left: r.left + sx, right: r.right + sx };
        });
    }
    function posicionarLaco(r) {
        el.laco.style.left = r.l + 'px';
        el.laco.style.top = r.t + 'px';
        el.laco.style.width = (r.r - r.l) + 'px';
        el.laco.style.height = (r.b - r.t) + 'px';
    }
    el.lista.addEventListener('mousedown', function (e) {
        lacoVazio = false;
        if (e.button !== 0 || !el.laco) return;
        if (e.target.closest('input, button, a, .pex-ren, .pex-menu')) return;
        const linha = e.target.closest('.pex-item');
        if (linha && (linha.dataset.pexTemp !== undefined || pontoNoConteudo(linha, e.clientX))) return;
        lacoVazio = true;
        iniciarLaco(e);
    });
    function iniciarLaco(e) {
        const base = (e.ctrlKey || e.metaKey) ? Array.from(selecao) : [];
        const x0 = e.clientX;
        const yPagina = e.clientY + window.scrollY;
        let ativo = false;
        let rects = null;
        let ultimo = null;
        let raf = null;
        let assinatura = '';
        const aplicar = function (ev) {
            const y0 = yPagina - window.scrollY;
            if (!ativo && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 5) return;
            if (!ativo) { ativo = true; rects = medirLinhas(); el.laco.hidden = false; }
            if (ev.cancelable) ev.preventDefault();
            const r = { l: Math.min(x0, ev.clientX), t: Math.min(y0, ev.clientY), r: Math.max(x0, ev.clientX), b: Math.max(y0, ev.clientY) };
            posicionarLaco(r);
            const sy = window.scrollY, sx = window.scrollX;
            const dentro = rects.filter(function (q) { return q.right > r.l + sx && q.left < r.r + sx && q.bottom > r.t + sy && q.top < r.b + sy; }).map(function (q) { return q.chave; });
            const chaves = base.concat(dentro.filter(function (k) { return base.indexOf(k) === -1; }));
            const nova = chaves.join('|');
            if (nova !== assinatura) { assinatura = nova; definirSelecao(chaves, { foco: chaves.length ? chaves[chaves.length - 1] : null }); }
        };
        const mover = function (ev) {
            aplicar(ev);
            ultimo = ev;
            if (!raf) {
                const passo = function () {
                    const u = ultimo;
                    if (!u || !ativo) { raf = null; return; }
                    const m = LACO_MARGEM_PX;
                    const v = u.clientY < m ? -(m - u.clientY) : (u.clientY > window.innerHeight - m ? u.clientY - (window.innerHeight - m) : 0);
                    if (v) { window.scrollBy(0, v / 2); aplicar(u); }
                    raf = requestAnimationFrame(passo);
                };
                raf = requestAnimationFrame(passo);
            }
        };
        const soltar = function () {
            window.removeEventListener('mousemove', mover);
            window.removeEventListener('mouseup', soltar);
            if (raf) cancelAnimationFrame(raf);
            raf = null;
            ultimo = null;
            lacoVazio = false;
            el.laco.hidden = true;
            // O clique que o navegador dispara depois de um laço não conta como clique.
            if (ativo) suprimirCliqueAte = Date.now() + 80;
        };
        window.addEventListener('mousemove', mover);
        window.addEventListener('mouseup', soltar);
        el.lista.focus({ preventScroll: true });
    }

    // -------------------------------------------------------- toque longo ---
    /* No toque não há botão direito: segurar ~500 ms sobre uma linha abre o menu de contexto
       dela (DOC-85; convenção). Mover o dedo (rolagem) cancela. O clique que vem depois é
       engolido. O `contextmenu` nativo do Android faz o mesmo caminho, sem duplicar. */
    let toqueTimer = null;
    let toqueInicio = null;
    let suprimirProximoClique = false;   // zerado pelo próprio clique engolido ou pelo toque seguinte
    function cancelarToqueLongo() {
        clearTimeout(toqueTimer);
        toqueTimer = null;
        toqueInicio = null;
    }
    // No fundo da lista (ou no vazio da pasta) o toque longo abre o menu de fundo — o iOS não
    // dispara `contextmenu`, então sem isto "Nova pasta"/"Colar" não existiriam no celular.
    function ligarToqueLongo(alvo) {
        alvo.addEventListener('pointerdown', function (e) {
            if (e.pointerType !== 'touch' && e.pointerType !== 'pen') return;
            suprimirProximoClique = false;      // gesto novo: o clique engolido era o do anterior
            const linha = e.target.closest('.pex-item');
            if ((linha && linha.dataset.pexTemp !== undefined) || e.target.closest('.pex-ren, .pex-menu')) return;
            cancelarToqueLongo();
            toqueInicio = { x: e.clientX, y: e.clientY, linha: linha };
            toqueTimer = setTimeout(function () {
                const t = toqueInicio;
                cancelarToqueLongo();
                if (!t) return;
                suprimirProximoClique = true;
                abrirMenu(t.x, t.y, t.linha ? itemPorChave(chaveDoElemento(t.linha)) : null);
            }, TOQUE_LONGO_MS);
        });
        alvo.addEventListener('pointermove', function (e) {
            if (toqueInicio && Math.hypot(e.clientX - toqueInicio.x, e.clientY - toqueInicio.y) > 10) cancelarToqueLongo();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { alvo.addEventListener(ev, cancelarToqueLongo); });
    }
    ligarToqueLongo(el.lista);
    if (el.vazio) ligarToqueLongo(el.vazio);
    // O clique que o navegador sintetiza ao soltar o dedo depois do toque longo cairia no menu
    // recém-aberto (o 1º item fica sob o dedo) ou no fundo (fecharia): engolido onde quer que caia.
    document.addEventListener('click', function (e) {
        if (!suprimirProximoClique) return;
        suprimirProximoClique = false;
        e.preventDefault();
        e.stopPropagation();
    }, true);

    // --------------------------------------------------- menu de contexto ---
    /* dc `ctxItens` (L4797-4834), na ordem do desenho, só com o que tem ação hoje:
       - de lotes futuros, NÃO renderizados: "Baixar como .zip" e Copiar (L8), favoritos (L6),
         Desfazer (L7), visor (L10); "Encaminhar via Chat I.A" é item E;
       - "Compartilhar link" vira "Copiar link" INTERNO (S-12): a URL de visualização, absoluta;
       - "Editar…" (categoria, descrição, número) é função do sistema: o desenho é omisso. */
    const SEP = {};
    let menuAcoes = [];
    function op(rotulo, ico, acao, extra) {
        extra = extra || {};
        return { rotulo: rotulo, icone: ico, acao: acao, atalho: extra.atalho || '', perigo: !!extra.perigo, desabilitado: !!extra.desabilitado };
    }
    function opcoesDoMenu(alvo) {
        const sel = itensSelecionados();
        const multi = !!alvo && sel.length > 1 && selecao.has(chaveDe(alvo));
        if (!alvo) {
            return [
                op('Nova pasta', 'bi-folder-plus', novaPastaInline),
                op('Colar', 'bi-clipboard', colarAqui, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia }),
                op('Selecionar tudo', 'bi-check2-all', selecionarTudo, { atalho: 'Ctrl+A' }),
                SEP,
            ].concat([['nome', 'Classificar por nome'], ['data', 'Classificar por data'], ['tamanho', 'Classificar por tamanho'], ['tipo', 'Classificar por tipo']].map(function (par) {
                return op(par[1], classificar.chave === par[0] ? 'bi-check2' : 'bi-sort-down', function () { if (classificar.chave !== par[0]) classificarPor(par[0]); });
            }), [
                SEP,
                op(painel ? 'Ocultar painel de detalhes' : 'Mostrar painel de detalhes', 'bi-layout-sidebar-reverse', alternarPainel),
            ]);
        }
        if (multi) {
            const arqs = sel.filter(ehArquivo);
            return (arqs.length ? [op('Copiar links' + (arqs.length < sel.length ? ' (' + arqs.length + ')' : ''), 'bi-link-45deg', function () { copiarLinks(arqs); })] : []).concat([
                SEP,
                op('Recortar', 'bi-scissors', function () { recortar(sel); }, { atalho: 'Ctrl+X' }),
                op('Mover para…', 'bi-folder-symlink', function () { escolherDestino(sel); }),
                op('Copiar caminhos', 'bi-signpost', function () { copiarCaminhos(sel); }),
                SEP,
                op('Excluir ' + sel.length + ' itens', 'bi-trash3', function () { excluirItens(sel); }, { atalho: 'Del', perigo: true }),
                SEP,
                op('Propriedades', 'bi-info-square', mostrarPainel),
            ]);
        }
        const ehPasta = alvo.tipo === 'pasta';
        const a = alvo.dado;
        return [op('Abrir', ehPasta ? 'bi-folder2-open' : 'bi-box-arrow-up-right', function () { abrirItem(alvo); }, { atalho: 'Enter' })]
            .concat(ehPasta ? [] : [
                op('Visualizar', 'bi-eye', function () { abrirPreviewDe(a); }, { atalho: 'Espaço' }),
                op('Baixar', 'bi-download', function () { baixar(a); }),
                op('Copiar link', 'bi-link-45deg', function () { copiarLinks([alvo]); }),
            ])
            .concat([
                SEP,
                op('Recortar', 'bi-scissors', function () { recortar([alvo]); }, { atalho: 'Ctrl+X' }),
            ])
            .concat(ehPasta ? [op('Colar', 'bi-clipboard', function () { colarEm(alvo.id); }, { atalho: 'Ctrl+V', desabilitado: !areaDeTransferencia })] : [])
            .concat([
                // "Mover para…" (modal de destino) é função do sistema (§16.7); o desenho é omisso.
                op('Mover para…', 'bi-folder-symlink', function () { escolherDestino([alvo]); }),
                op('Copiar caminho', 'bi-signpost', function () { copiarCaminhos([alvo]); }),
                SEP,
                op('Renomear', 'bi-input-cursor-text', function () { iniciarRenomear(alvo); }, { atalho: 'F2' }),
            ])
            .concat(ehPasta ? [] : [op('Editar…', 'bi-pencil', function () { abrirEditar(a); })])
            .concat([
                op('Excluir', 'bi-trash3', function () { excluirItens([alvo]); }, { atalho: 'Del', perigo: true }),
                SEP,
                op('Propriedades', 'bi-info-square', mostrarPainel),
            ]);
    }
    function abrirMenu(x, y, alvo) {
        if (!el.menu || !el.menuItemTpl) return;
        cancelarRenomear();
        // Botão direito num item fora da seleção: ele passa a ser a seleção (dc `ctx`, L4759).
        if (alvo && !selecao.has(chaveDe(alvo))) selecionar(chaveDe(alvo));
        const frag = document.createDocumentFragment();
        menuAcoes = [];
        let ultimoSep = true;
        opcoesDoMenu(alvo).forEach(function (o) {
            if (o === SEP) {
                if (!ultimoSep) { frag.appendChild(h('div', { class: 'pex-ctx-sep', role: 'separator' })); ultimoSep = true; }
                return;
            }
            const n = el.menuItemTpl.content.firstElementChild.cloneNode(true);
            n.querySelector('.pex-ctx-ico').className = 'bi pex-ctx-ico ' + o.icone;
            n.querySelector('.pex-ctx-rotulo').textContent = o.rotulo;
            n.querySelector('.pex-ctx-atalho').textContent = o.atalho;
            if (o.perigo) n.classList.add('pex-ctx-item--perigo');
            if (o.desabilitado) n.disabled = true;
            n.dataset.pexMenuI = String(menuAcoes.length);
            menuAcoes.push(o.acao);
            frag.appendChild(n);
            ultimoSep = false;
        });
        if (frag.lastChild && frag.lastChild.classList && frag.lastChild.classList.contains('pex-ctx-sep')) frag.removeChild(frag.lastChild);
        el.menu.textContent = '';
        el.menu.appendChild(frag);
        el.menuFundo.hidden = false;
        el.menu.hidden = false;
        // Limitado à janela (DOC-34): 240px e nunca para fora da borda direita/inferior.
        const W = el.menu.offsetWidth, H = el.menu.offsetHeight;
        el.menu.style.left = Math.max(8, Math.min(x, window.innerWidth - W - 8)) + 'px';
        el.menu.style.top = Math.max(8, Math.min(y, window.innerHeight - H - 8)) + 'px';
        const primeiro = el.menu.querySelector('.pex-ctx-item:not(:disabled)');
        if (primeiro) primeiro.focus({ preventScroll: true });
    }
    let menuBotao = null;                // o ⋮ que abriu o menu (toque): aria-expanded e foco de volta
    function abrirMenuNoBotao(btn, linha) {
        const r = btn.getBoundingClientRect();
        abrirMenu(r.left, r.bottom + 2, itemPorChave(chaveDoElemento(linha)));
        if (menuAberto()) { menuBotao = btn; btn.setAttribute('aria-expanded', 'true'); }
    }
    function menuAberto() { return !!el.menu && !el.menu.hidden; }
    function fecharMenu() {
        if (!menuAberto()) return;
        el.menu.hidden = true;
        el.menuFundo.hidden = true;
        el.menu.textContent = '';
        menuAcoes = [];
        const b = menuBotao;
        menuBotao = null;
        if (b && b.isConnected) { b.setAttribute('aria-expanded', 'false'); b.focus({ preventScroll: true }); }
        else el.lista.focus({ preventScroll: true });
    }
    function teclaNoMenu(e) {
        const itens = Array.prototype.slice.call(el.menu.querySelectorAll('.pex-ctx-item:not(:disabled)'));
        const i = itens.indexOf(document.activeElement);
        if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); fecharMenu(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); (itens[i + 1] || itens[0]).focus(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); (itens[i - 1] || itens[itens.length - 1]).focus(); return; }
        if (e.key === 'Home') { e.preventDefault(); if (itens[0]) itens[0].focus(); return; }
        if (e.key === 'End') { e.preventDefault(); if (itens.length) itens[itens.length - 1].focus(); }
    }
    if (el.menu) {
        el.menu.addEventListener('click', function (e) {
            const b = e.target.closest('[data-pex-menu-i]');
            if (!b || b.disabled) return;
            const f = menuAcoes[Number(b.dataset.pexMenuI)];
            fecharMenu();
            if (typeof f === 'function') f();
        });
        el.menu.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        el.menuFundo.addEventListener('click', fecharMenu);
        el.menuFundo.addEventListener('contextmenu', function (e) { e.preventDefault(); fecharMenu(); });
        window.addEventListener('scroll', fecharMenu, { passive: true });
        window.addEventListener('resize', fecharMenu);
        // Botão direito na lista (item ou fundo) e no vazio da pasta ("Nova pasta", "Colar").
        const porBotaoDireito = function (e) {
            if (e.target.closest('.pex-ren')) return;    // no campo inline, o menu nativo (colar texto)
            e.preventDefault();
            cancelarToqueLongo();
            const linha = e.target.closest('.pex-item');
            if (linha && linha.dataset.pexTemp !== undefined) return;
            abrirMenu(e.clientX, e.clientY, linha ? itemPorChave(chaveDoElemento(linha)) : null);
        };
        el.lista.addEventListener('contextmenu', porBotaoDireito);
        if (el.vazio) el.vazio.addEventListener('contextmenu', porBotaoDireito);
    }

    // -------------------------------------------------------------- toast ---
    // dc `aviso` (L4772): 4,2 s. Sem "Desfazer" até a lixeira (L7). Erro fica mais (6 s) e com ícone.
    let toastTimer = null;
    function toast(texto, erro) {
        if (!el.toast) { if (erro) alert(texto); return; }
        clearTimeout(toastTimer);
        el.toastTexto.textContent = texto;
        el.toast.classList.toggle('pex-toast--erro', !!erro);
        if (el.toastIcone) {
            el.toastIcone.className = 'bi pex-toast-ico' + (erro ? ' bi-exclamation-triangle-fill' : '');
            el.toastIcone.hidden = !erro;
        }
        el.toast.hidden = false;
        toastTimer = setTimeout(function () { el.toast.hidden = true; }, erro ? 6000 : TOAST_MS);
    }
    function toastErro(texto) { toast(texto || 'Algo deu errado.', true); }

    // ------------------------------------------------------- ações do L5 ----
    function rotuloDe(itens) { return itens.length === 1 ? itens[0].nome : itens.length + ' itens'; }
    function localDe(it) {
        const v = it.tipo === 'pasta' ? it.dado.paiId : it.dado.secaoId;
        return v == null ? null : Number(v);
    }
    function linkAbsoluto(a) { return new URL(a.viewUrl, window.location.href).href; }
    // "Copiar link" (S-12): o link interno de visualização, que exige login e permissão na pasta.
    function copiarLinks(itens) {
        const arqs = itens.filter(ehArquivo);
        if (!arqs.length) return;
        copiarTexto(arqs.map(function (it) { return linkAbsoluto(it.dado); }).join('\n')).then(function (ok) {
            if (ok) toast(arqs.length === 1 ? 'Link copiado' : 'Links copiados'); else toastErro('Não foi possível copiar.');
        });
    }
    // "Copiar caminho" (DOC-38): "BlueJus › Pasta <n> › Documentos › … › nome".
    function caminhoDe(it) {
        return 'BlueJus › Pasta ' + cfg.pastaRotulo + ' › ' + caminhoLegivel(localDe(it)) + ' › ' + it.nome;
    }
    function copiarCaminhos(itens) {
        if (!itens.length) return;
        copiarTexto(itens.map(caminhoDe).join('\n')).then(function (ok) {
            if (ok) toast(itens.length === 1 ? 'Caminho copiado' : 'Caminhos copiados'); else toastErro('Não foi possível copiar.');
        });
    }
    // Recortar (Ctrl+X): só memória; Colar move pelo mover-lote. Copiar/duplicar é do L8.
    function recortar(itens) {
        if (!itens.length) return;
        areaDeTransferencia = { op: 'recortar', chaves: itens.map(chaveDe) };
        toast('Recortado: ' + rotuloDe(itens));
    }
    function chaveExiste(k) {
        const partes = k.split(':');
        return !!(partes[0] === 'pasta' ? pastaPorId(partes[1]) : arquivoPorId(partes[1]));
    }
    // Itens excluídos (ou que sumiram) saem do recorte; sem sobrar nada, o recorte acaba.
    function limparRecorteDoQueNaoExiste() {
        if (!areaDeTransferencia) return;
        areaDeTransferencia.chaves = areaDeTransferencia.chaves.filter(chaveExiste);
        if (!areaDeTransferencia.chaves.length) areaDeTransferencia = null;
    }
    // O recorte só é consumido quando o mover-lote dá certo: erro do servidor deixa o Ctrl+V
    // para repetir; "já estão aqui" e ciclo também o preservam.
    function colarEm(destinoId) {
        if (!areaDeTransferencia) return;
        const chaves = areaDeTransferencia.chaves.filter(function (k) { return k !== 'pasta:' + destinoId && chaveExiste(k); });
        if (!chaves.length) { areaDeTransferencia = null; return; }
        moverLote(chaves, destinoId).then(function (ok) { if (ok) areaDeTransferencia = null; });
    }
    function colarAqui() { colarEm(pastaAtualId()); }
    function mostrarPainel() {
        if (painel) { renderizarPainel(); return; }
        alternarPainel();
    }
    function alternarPainel() {
        painel = !painel;
        gravarPainel();
        fecharPopovers();
        renderizar();
    }

    // Divide chaves em ids de documentos e de pastas, como o corpo do lote espera.
    function separarChaves(chaves) {
        const docs = [], secs = [];
        chaves.forEach(function (k) {
            const partes = k.split(':');
            const id = Number(partes[1]);
            if (!id) return;
            if (partes[0] === 'pasta') secs.push(id); else docs.push(id);
        });
        return { documentos: docs, secoes: secs };
    }
    function acimaDoTeto(n) {
        if (n <= TETO_LOTE) return false;
        toastErro('Seleção acima do limite de ' + TETO_LOTE.toLocaleString('pt-BR') + ' itens por ação. Selecione menos itens.');
        return true;
    }

    /* Mover em lote (D4): UM pedido para a seleção toda — arquivos e pastas, inclusive pasta
       dentro de pasta (DOC-55). Ciclo e "já está aqui" são barrados na tela antes do pedido; o
       servidor confere de novo. Depois do sucesso a memória é atualizada e a lista refeita. */
    function moverLote(chaves, destinoId) {
        destinoId = destinoId == null ? null : Number(destinoId);
        const lote = separarChaves(chaves);
        const n = lote.documentos.length + lote.secoes.length;
        // Resolve com true SÓ quando o servidor confirmou (é o que consome o recorte).
        if (!n) return Promise.resolve(false);
        if (acimaDoTeto(n)) return Promise.resolve(false);
        const jaLa = chaves.every(function (k) {
            const partes = k.split(':');
            const d = partes[0] === 'pasta' ? pastaPorId(partes[1]) : arquivoPorId(partes[1]);
            const local = d ? (partes[0] === 'pasta' ? d.paiId : d.secaoId) : undefined;
            return (local == null ? null : Number(local)) === destinoId;
        });
        if (jaLa) { toast('Os itens já estão nesta pasta'); return Promise.resolve(false); }
        if (destinoId != null && lote.secoes.some(function (id) { return id === destinoId || descendentes(id).indexOf(destinoId) !== -1; })) {
            toastErro('Uma pasta não pode ir para dentro dela mesma.');
            return Promise.resolve(false);
        }
        // Pelos dados em memória, não pela tela: depois de Recortar, o usuário pode ter navegado.
        const nomes = chaves.map(function (k) {
            const partes = k.split(':');
            const d = partes[0] === 'pasta' ? pastaPorId(partes[1]) : arquivoPorId(partes[1]);
            return d ? d.nome : '';
        }).filter(Boolean);
        return postJson(cfg.urlMoverLote, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes, destinoId: destinoId }).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao mover.');
            lote.documentos.forEach(function (id) { const a = arquivoPorId(id); if (a) a.secaoId = destinoId; });
            lote.secoes.forEach(function (id) { const p = pastaPorId(id); if (p) p.paiId = destinoId; });
            limparSelecao(true);
            renderizar();
            toast((n === 1 ? '"' + (nomes[0] || '1 item') + '" movido' : n + ' itens movidos') + ' para ' + nomeDoLocal(destinoId));
            return true;
        }).catch(function (err) { toastErro(err.message || 'Erro de comunicação.'); return false; });
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
    function avisoExclusaoDoLote(itens) {
        if (itens.length === 1) {
            return itens[0].tipo === 'pasta' ? avisoExclusao(itens[0].dado) : 'Excluir "' + itens[0].nome + '"? Esta ação não pode ser desfeita.';
        }
        let subpastas = 0, arquivosN = 0;
        itens.forEach(function (it) {
            if (it.tipo === 'arquivo') { arquivosN++; return; }
            const c = contarArvore(it.id);
            subpastas += 1 + c.subpastas;
            arquivosN += c.arquivos;
        });
        const partes = [];
        if (subpastas > 0) partes.push(pluralizar(subpastas, 'pasta', 'pastas'));
        if (arquivosN > 0) partes.push(pluralizar(arquivosN, 'arquivo', 'arquivos'));
        return 'Excluir ' + itens.length + ' itens? Ao todo: ' + partes.join(' e ') + '. Esta ação não pode ser desfeita.';
    }
    /* Excluir (DOC-57): um, vários ou pasta — sempre pelo excluir-lote. O `confirm()` fica até o
       Samuel decidir (S-3); o Desfazer chega com a lixeira (L7). */
    function excluirItens(itens) {
        if (!itens.length) return;
        const lote = separarChaves(itens.map(chaveDe));
        if (acimaDoTeto(lote.documentos.length + lote.secoes.length)) return;
        if (!confirm(avisoExclusaoDoLote(itens))) return;
        postJson(cfg.urlExcluirLote, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes }).then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao excluir.');
            // O back apaga a ÁRVORE de cada pasta (cascade): tira as filhas e os arquivos delas.
            let subarvore = [];
            lote.secoes.forEach(function (id) { subarvore = subarvore.concat([id], descendentes(id)); });
            for (let i = arquivos.length - 1; i >= 0; i--) {
                const a = arquivos[i];
                if (lote.documentos.indexOf(a.id) !== -1 || (a.secaoId != null && subarvore.indexOf(Number(a.secaoId)) !== -1)) { arquivos.splice(i, 1); totalArquivos--; }
            }
            for (let i = pastas.length - 1; i >= 0; i--) {
                if (subarvore.indexOf(pastas[i].id) !== -1) pastas.splice(i, 1);
            }
            limparRecorteDoQueNaoExiste();
            limparSelecao(true);
            if (caminho.some(function (id) { return subarvore.indexOf(id) !== -1; })) voltarRaiz(); else renderizar();
            toast(itens.length === 1 ? 'Excluído: ' + itens[0].nome : itens.length + ' itens excluídos');
        }).catch(function (err) { toastErro(err.message || 'Erro de comunicação.'); });
    }

    // "Mover para…" (modal de destino) para a seleção — pastas selecionadas e descendentes
    // ficam desabilitadas; o nível atual só é marcado quando todos estão no mesmo lugar.
    function escolherDestino(itens) {
        if (!itens.length) return;
        let proibidos = [];
        itens.filter(ehPastaItem).forEach(function (it) { proibidos = proibidos.concat([it.id], descendentes(it.id)); });
        const locais = [];
        itens.forEach(function (it) { const l = localDe(it); if (locais.indexOf(l) === -1) locais.push(l); });
        pedirDestino(itens.length === 1 ? 'Mover "' + itens[0].nome + '" para' : 'Mover ' + itens.length + ' itens para', {
            proibidos: proibidos,
            atual: locais.length === 1 ? locais[0] : null,
            semAtual: locais.length !== 1,
        }).then(function (destino) {
            if (destino === undefined) return;      // cancelou
            moverLote(itens.map(chaveDe), destino); // null = raiz
        });
    }

    // ------------------------------------------- renomear / criar inline ----
    /* dc L2234 e `renTecla`/`renSalvar` (L4941-4944): campo no lugar do nome; Enter salva, Esc
       cancela, perder o foco salva. Para arquivo só o nome-base — a extensão é preservada pelo
       servidor (`nomeBase` do editar em XHR, D3). Nova pasta: linha provisória no topo já em
       edição (DOC-53); o nome só vai ao servidor quando confirmado. */
    function iniciarRenomear(it, opts) {
        opts = opts || {};
        cancelarRenomear();
        const linha = opts.linha || linhaDe(chaveDe(it));
        if (!linha) return;
        const nomeEl = linha.querySelector('.pex-nome');
        if (!nomeEl) return;
        const arquivo = it.tipo === 'arquivo';
        const ext = arquivo ? extensaoDe(it.nome) : '';
        const valor = arquivo ? nomeSemExtensao(it.nome) : it.nome;
        const input = h('input', { type: 'text', class: 'pex-ren', 'aria-label': arquivo ? 'Novo nome do arquivo' : 'Nome da pasta', maxlength: '250', autocomplete: 'off', spellcheck: 'false' });
        input.value = valor;
        const wrap = h('span', { class: 'pex-ren-wrap' }, [input, ext ? h('span', { class: 'pex-ren-ext', text: '.' + ext }) : null]);
        nomeEl.hidden = true;
        nomeEl.parentNode.insertBefore(wrap, nomeEl.nextSibling);
        // Enquanto edita, a linha não arrasta: o mouse dentro do campo é para selecionar texto.
        linha.setAttribute('draggable', 'false');
        renomeando = { it: it, linha: linha, nomeEl: nomeEl, wrap: wrap, input: input, criar: !!opts.criar, original: valor, salvando: false };
        input.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); salvarRenomear(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancelarRenomear(); }
        });
        ['click', 'dblclick', 'mousedown', 'contextmenu', 'pointerdown'].forEach(function (ev) { input.addEventListener(ev, function (e) { e.stopPropagation(); }); });
        input.addEventListener('blur', function () { salvarRenomear(); });
        input.focus();
        input.select();
    }
    function cancelarRenomear(semRender) {
        if (!renomeando) return;
        const r = renomeando;
        renomeando = null;
        if (semRender) return;         // quem chamou vai refazer a lista
        if (r.criar) { r.linha.remove(); renderizar(); return; }
        r.wrap.remove();
        r.nomeEl.hidden = false;
        if (usaSortable()) r.linha.removeAttribute('draggable'); else r.linha.setAttribute('draggable', 'true');
    }
    function salvarRenomear() {
        if (!renomeando || renomeando.salvando) return;
        const r = renomeando;
        const v = (r.input.value || '').trim();
        if (v === '' || (!r.criar && v === r.original)) { cancelarRenomear(); return; }
        r.salvando = true;
        r.input.disabled = true;
        let pedido;
        if (r.criar) {
            pedido = criarPasta(v);
        } else if (r.it.tipo === 'pasta') {
            const p = r.it.dado;
            pedido = postForm(p.urlRenomear, { _token: p.csrfRenomear, nome: v }).then(function (res) {
                if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.erro) || 'Falha ao renomear.');
                p.nome = res.j.nome;
            });
        } else {
            const a = r.it.dado;
            pedido = postForm(cfg.urlEditarDocTpl.replace('__ID__', a.id), {
                _token: a.csrfEditar, nomeBase: v, categoria: a.categoria || '', descricao: a.descricao || '', numero: a.numero || '',
            }).then(function (res) {
                if (!res.ok || !res.j.ok || !res.j.documento) throw new Error((res.j && res.j.erro) || 'Falha ao renomear.');
                Object.assign(a, res.j.documento);
            });
        }
        pedido.then(function () {
            if (renomeando === r) renomeando = null;
            renderizar();
        }).catch(function (err) {
            toastErro(err.message || 'Erro de comunicação.');
            if (renomeando === r) { r.salvando = false; r.input.disabled = false; cancelarRenomear(); }
        });
    }
    // "Nova pasta" / "Nova pasta (2)"… — o primeiro nome livre neste nível (dc L4827).
    function nomeLivre(base) {
        const atual = pastaAtualId();
        const nomes = pastas.filter(function (p) { return (p.paiId == null ? null : Number(p.paiId)) === atual; }).map(function (p) { return normalizar(p.nome); });
        let nome = base, n = 1;
        while (nomes.indexOf(normalizar(nome)) !== -1) { n++; nome = base + ' (' + n + ')'; }
        return nome;
    }
    function novaPastaInline() {
        if (normalizar(busca) !== '') aplicarBusca('');      // a linha nova vai no nível aberto
        if (filtroTipo !== 'todos' && filtroTipo !== 'pastas') definirFiltro('todos');
        cancelarRenomear();
        const nome = nomeLivre('Nova pasta');
        const temp = { id: 0, nome: nome, paiId: pastaAtualId(), ordem: 0, subpastas: 0, arquivos: 0 };
        const linha = linhaPasta(temp, false);
        linha.dataset.pexTemp = '';
        linha.removeAttribute('draggable');
        linha.removeAttribute('tabindex');
        el.lista.hidden = false;
        el.vazio.hidden = true;
        el.lista.insertBefore(linha, el.lista.firstChild);
        iniciarRenomear({ tipo: 'pasta', id: 0, nome: nome, dado: temp }, { linha: linha, criar: true });
    }
    function criarPasta(nome) {
        const campos = { _token: cfg.csrfCriarSecao, nome: nome };
        if (caminho.length) campos.paiId = String(pastaAtualId());
        return postForm(cfg.urlCriarSecao, campos).then(function (res) {
            if (!res.ok || !res.j.id) throw new Error((res.j && res.j.erro) || 'Falha ao criar a pasta.');
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
            selecao.clear();
            selecao.add('pasta:' + Number(res.j.id));
            ancora = foco = 'pasta:' + Number(res.j.id);
        });
    }
    if (el.btnNovaPasta) el.btnNovaPasta.addEventListener('click', novaPastaInline);

    // Busca
    function atualizarBotaoLimpar() { if (el.buscaLimpar) el.buscaLimpar.hidden = busca.trim() === ''; }
    // Digitação com debounce de 120 ms: na pasta de 1.128 docs cada tecla refaz a lista inteira.
    function aplicarBusca(valor) {
        clearTimeout(buscaTimer);
        buscaTimer = null;
        busca = valor;
        if (el.busca) el.busca.value = valor;
        atualizarBotaoLimpar();
        limparSelecao(true);
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
    let semCliqueAte = 0;      // depois de arrastar um título, o "click" que segue não classifica
    el.cabecalho.addEventListener('click', function (e) {
        if (Date.now() < semCliqueAte) return;
        const b = e.target.closest('[data-pex-classificar]');
        if (b) classificarPor(b.dataset.pexClassificar);
    });
    el.classificar.addEventListener('click', function (e) {
        const b = e.target.closest('[data-pex-classificar]');
        if (b) classificarPor(b.dataset.pexClassificar);
    });

    // ------------------------------------------- colunas: ordem e largura ---
    // Move a coluna `de` para `para` (índices entre as VISÍVEIS). Com a Categoria desligada ela
    // continua guardada na ordem completa, na mesma posição relativa.
    function moverColuna(de, para) {
        const vis = colunasVisiveis();
        if (para < 0 || para >= vis.length || para === de) return;
        const k = vis.splice(de, 1)[0];
        vis.splice(para, 0, k);
        if (vis.length < colunas.ord.length) vis.splice(Math.min(colunas.ord.indexOf('cat'), vis.length), 0, 'cat');
        colunas.ord = vis;
        gravarColunas();
        renderizar();
    }
    // Alça entre a coluna i e a i+1 (dc `expColDrag`, L3105-3117): ao lado do Nome só a outra
    // coluna muda (o Nome é flexível); entre duas fixas, o que uma ganha a outra perde, dentro
    // dos limites das duas. Durante o arraste só a variável da grade muda; grava ao soltar.
    function iniciarRedimensionar(e, i) {
        const vis = colunasVisiveis();
        const L = vis[i], R = vis[i + 1];
        if (!R) return;
        e.preventDefault();
        e.stopPropagation();
        const x0 = e.clientX;
        const w0 = Object.assign({}, colunas.w);
        document.body.style.cursor = 'col-resize';
        const mover = function (ev) {
            let d = Math.round(ev.clientX - x0);
            const w = Object.assign({}, w0);
            if (L === 'nome') w[R] = limitarLargura(R, w0[R] - d);
            else if (R === 'nome') w[L] = limitarLargura(L, w0[L] + d);
            else {
                d = Math.max(COLUNAS_LIM[L][0] - w0[L], Math.min(COLUNAS_LIM[L][1] - w0[L], d));
                d = Math.max(w0[R] - COLUNAS_LIM[R][1], Math.min(w0[R] - COLUNAS_LIM[R][0], d));
                w[L] = w0[L] + d;
                w[R] = w0[R] - d;
            }
            colunas.w = w;
            aplicarGrade();
        };
        const soltar = function () {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            window.removeEventListener('pointercancel', soltar);
            document.body.style.cursor = '';
            semCliqueAte = Date.now() + 50;
            gravarColunas();
        };
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
        window.addEventListener('pointercancel', soltar);
    }
    // Duplo clique na alça devolve as duas colunas vizinhas à largura padrão (dc `expColReset`).
    function restaurarLargura(i) {
        const vis = colunasVisiveis();
        [vis[i], vis[i + 1]].forEach(function (k) { if (k && k !== 'nome') colunas.w[k] = COLUNAS_PAD.w[k]; });
        gravarColunas();
        aplicarGrade();
    }
    // Arrastar o título para os lados (dc `expColArrastar`, L3123-3139): só vira arraste depois
    // de 6px; o indicador de inserção é a sombra de ±7px na borda da coluna-alvo.
    function iniciarMoverColuna(e, i) {
        if (e.button !== undefined && e.button !== 0) return;
        const x0 = e.clientX;
        const vis = colunasVisiveis();
        const cels = vis.map(function (k) { return el.cabecalho.querySelector('.pex-col-' + k); }).filter(Boolean);
        let ativo = false;
        const alvo = function (x) {
            for (let k = 0; k < cels.length; k++) {
                const r = cels[k].getBoundingClientRect();
                if (x < r.left + r.width / 2) return k;
            }
            return cels.length;
        };
        const limpar = function () {
            cels.forEach(function (c) { c.classList.remove('pex-col--arrastando', 'pex-col--ins-esq', 'pex-col--ins-dir'); });
        };
        const mover = function (ev) {
            if (!ativo && Math.abs(ev.clientX - x0) < 6) return;
            if (!ativo) {
                ativo = true;
                document.body.style.cursor = 'grabbing';
                document.body.style.userSelect = 'none';
            }
            const t = alvo(ev.clientX);
            limpar();
            if (cels[i]) cels[i].classList.add('pex-col--arrastando');
            if (t !== i && t !== i + 1) {
                if (t < cels.length) cels[t].classList.add('pex-col--ins-esq');
                else cels[cels.length - 1].classList.add('pex-col--ins-dir');
            }
        };
        const soltar = function (ev) {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            window.removeEventListener('pointercancel', soltar);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            limpar();
            if (!ativo || ev.type === 'pointercancel') return;
            semCliqueAte = Date.now() + 50;
            let t = alvo(ev.clientX);
            if (t > i) t -= 1;
            moverColuna(i, t);
        };
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
        window.addEventListener('pointercancel', soltar);
    }
    function indiceDaColuna(col) { return col && col.dataset.pexCol ? colunasVisiveis().indexOf(col.dataset.pexCol) : -1; }
    el.cabecalho.addEventListener('pointerdown', function (e) {
        const col = e.target.closest('.pex-col');
        const i = indiceDaColuna(col);
        if (i < 0) return;
        if (e.target.closest('.pex-alca')) { iniciarRedimensionar(e, i); return; }
        if (e.target.closest('[data-pex-classificar]')) iniciarMoverColuna(e, i);
    });
    el.cabecalho.addEventListener('dblclick', function (e) {
        if (!e.target.closest('.pex-alca')) return;
        e.stopPropagation();
        const i = indiceDaColuna(e.target.closest('.pex-col'));
        if (i >= 0) restaurarLargura(i);
    });

    // ------------------------------------------------------- Organizar ------
    function definirFiltro(id) {
        if (FILTROS.indexOf(id) === -1) return;
        filtroTipo = id;
        limparSelecao(true);
        renderizar();
    }
    el.menuOrganizar.addEventListener('click', function (e) {
        const seta = e.target.closest('[data-pex-mover-de]');
        if (seta) {
            if (!seta.disabled) moverColuna(Number(seta.dataset.pexMoverDe), Number(seta.dataset.pexMoverPara));
            return;
        }
        const filtro = e.target.closest('[data-pex-filtro]');
        if (filtro) {
            if (filtro.disabled) return;
            // Escolher um tipo fecha o menu, como o desenho (`expOrgOn: false`).
            fecharPopovers();
            definirFiltro(filtro.dataset.pexFiltro);
            return;
        }
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
    if (el.filtroLimpar) el.filtroLimpar.addEventListener('click', function () { definirFiltro('todos'); });
    // Restaurar padrão (dc `restaurar`, L3155): colunas (ordem e largura), filtro e classificação.
    // Modo e painel são do Visualizar e ficam como estão.
    if (el.btnRestaurar) {
        el.btnRestaurar.addEventListener('click', function () {
            classificar = { chave: 'manual', desc: false };
            colunas = colunasPadrao();
            filtroTipo = 'todos';
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

    function popoverAberto() { return popovers.some(function (p) { return !p.menu.hidden; }); }
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
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fecharPopovers(); fecharMenu(); } });

    // ------------------------------------------------------- Visualizar -----
    // Escolher um modo ou ligar o painel fecha o menu (dc `ir`/`alternarPainel`).
    if (el.menuVisualizar) {
        el.menuVisualizar.addEventListener('click', function (e) {
            const b = e.target.closest('[data-pex-modo]');
            if (b) {
                if (!Object.prototype.hasOwnProperty.call(ICONE_PX, b.dataset.pexModo)) return;
                modo = b.dataset.pexModo;
                gravarModo();
                fecharPopovers();
                renderizar();
                return;
            }
            if (e.target.closest('#pexPainelAlternar')) alternarPainel();
        });
    }

    // ---------------------------------------------------- HTTP (XHR/JSON) ---
    function lerResposta(r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; });
    }
    function postFormData(url, fd) {
        return fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(lerResposta);
    }
    function postForm(url, campos) {
        const fd = new FormData();
        Object.keys(campos).forEach(function (k) { if (campos[k] !== undefined && campos[k] !== null) fd.append(k, campos[k]); });
        return postFormData(url, fd);
    }
    // Lote (mover-lote / excluir-lote): corpo JSON com `_token` = csrfLote (um por pasta).
    function postJson(url, corpo) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(corpo),
        }).then(lerResposta);
    }
    function persistirOrdem(url, csrf, ids) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ _token: csrf, ids: ids }),
        }).catch(function () { /* silencioso; um reload corrige a ordem */ });
    }

    // --------------------------------------------------- editar arquivo -----
    // O modal único (DOC-90) continua para categoria/descrição/número; o POST vai em XHR (D3) e a
    // linha é atualizada a partir do `documento` da resposta, sem recarregar.
    const editarModalEl = document.getElementById('pexEditarModal');
    const editarForm    = document.getElementById('pexEditarForm');
    function abrirEditar(a) {
        if (!editarModalEl || !editarForm || !window.bootstrap) return;
        const ext = extensaoDe(a.nome);
        editarForm.action = cfg.urlEditarDocTpl.replace('__ID__', a.id);
        editarForm.dataset.pexId = String(a.id);
        document.getElementById('pexEditarToken').value = a.csrfEditar || '';
        document.getElementById('pexEditarNomeBase').value = nomeSemExtensao(a.nome);
        document.getElementById('pexEditarExt').textContent = ext ? '.' + ext : '';
        document.getElementById('pexEditarExtTexto').textContent = ext ? '.' + ext : '(sem extensão)';
        const sel = document.getElementById('pexEditarCategoria');
        if (sel) sel.value = a.categoria || '';
        document.getElementById('pexEditarDescricao').value = a.descricao || '';
        document.getElementById('pexEditarNumero').value = a.numero || '';
        bootstrap.Modal.getOrCreateInstance(editarModalEl).show();
    }
    if (editarForm) {
        editarForm.addEventListener('submit', function (e) {
            const a = arquivoPorId(editarForm.dataset.pexId);
            if (!a || !window.fetch) return;        // sem XHR, o POST normal de sempre
            e.preventDefault();
            const botao = editarForm.querySelector('[type="submit"]');
            if (botao) botao.disabled = true;
            postFormData(editarForm.action, new FormData(editarForm)).then(function (res) {
                if (!res.ok || !res.j.ok || !res.j.documento) throw new Error((res.j && res.j.erro) || 'Falha ao salvar.');
                Object.assign(a, res.j.documento);
                bootstrap.Modal.getOrCreateInstance(editarModalEl).hide();
                renderizar();
                toast('Documento atualizado: ' + a.nome);
            }).catch(function (err) { toastErro(err.message || 'Erro de comunicação.'); }).then(function () { if (botao) botao.disabled = false; });
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
                const ehAtual = !opts.semAtual && ((id == null && opts.atual == null) || (id != null && opts.atual != null && Number(id) === Number(opts.atual)));
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

    /* Upload (DOC-43): a resposta traz `documento` na MESMA forma do #pexDados (urls e tokens),
       então a linha entra sem recarregar a página; o aviso de duplicado é do helper e aparece na
       hora. Só se a resposta vier sem essa forma (servidor antigo) é que se recarrega. */
    let uploadTimer = null;
    function inserirArquivoEnviado(data) {
        const d = data && data.documento;
        if (!d || !d.id || !d.viewUrl || !d.nome) return false;
        if (arquivoPorId(d.id)) return true;
        d.secaoId = d.secaoId == null ? null : Number(d.secaoId);
        arquivos.push(d);
        totalArquivos++;
        return true;
    }
    function enviarArquivos(lista) {
        if (typeof window.enviarArquivoComProgresso !== 'function') { toastErro('Upload indisponível.'); return; }
        const destino = pastaAtualId();
        let i = 0, houveErro = false, precisaReload = false;
        const novos = [];
        clearTimeout(uploadTimer);
        el.uploadBar.hidden = false;

        function concluir() {
            el.uploadCont.textContent = houveErro ? 'Concluído com erros' : 'Concluído';
            if (precisaReload) {
                gravarCaminho();
                setTimeout(function () {
                    window.location.hash = 'documentos';
                    window.location.reload();
                }, 700);
                return;
            }
            if (novos.length) {
                limparSelecao(true);
                renderizar();
                definirSelecao(novos, { ancora: novos[0], foco: novos[novos.length - 1] });
                toast(novos.length === 1 ? 'Anexado: ' + (arquivoPorId(novos[0].split(':')[1]) || {}).nome : novos.length + ' arquivos anexados');
            }
            uploadTimer = setTimeout(function () { el.uploadBar.hidden = true; }, houveErro ? 6000 : 1200);
        }
        function proximo() {
            if (i >= lista.length) { concluir(); return; }
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
            }).then(function (data) {
                // Só memória por arquivo: a lista é refeita UMA vez ao concluir o lote (um campo
                // inline aberto não é derrubado a cada arquivo que chega).
                if (inserirArquivoEnviado(data)) novos.push('arquivo:' + Number(data.documento.id));
                else precisaReload = true;
            }, function (err) {
                houveErro = true;
                el.uploadProg.classList.add('bg-danger');
                el.uploadNome.textContent = '✗ ' + file.name + ' — ' + err.message;
                toastErro('Falha ao anexar "' + file.name + '": ' + err.message);
            }).then(function () { i++; proximo(); });
        }
        proximo();
    }

    // ------------------------------------------- arrastar item → pasta ------
    /* Dois mecanismos, um por vez:
       - modo Manual: SortableJS reordena (persistindo em /reordenar); soltar sobre uma linha de
         pasta MOVE em vez de reordenar — decidido pela COORDENADA do soltar contra o retângulo
         das linhas de pasta, porque o Sortable reposiciona o item arrastado sob o cursor e
         `e.target` seria sempre o próprio item (lição de 21/08 no fm). O Sortable arrasta UMA
         linha (sem o plugin de multiarraste); o espaço vazio da linha fica para o laço;
       - demais ordens: arraste nativo HTML5 da SELEÇÃO (arquivos e pastas) para uma linha de pasta. */
    let sortable = null;
    let itemArrastado = null;    // elemento .pex-item em arraste (Sortable)
    let arrasteSortable = null;  // chaves que o arraste do Sortable leva (a seleção, se a linha está nela)
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

    function soltarEm(chaves, destinoLinha) {
        moverLote(chaves, Number(destinoLinha.dataset.pexId));
    }

    /* O Sortable só reordena quando a lista na tela é o nível INTEIRO: com busca ou com filtro por
       tipo ativo o onEnd mandaria só os ids visíveis, e o /reordenar gravaria uma ordem parcial —
       corrompendo a ordem Manual dos que estão escondidos. Nesses casos fica o arraste nativo
       (soltar em pasta), que por isso usa a MESMA condição para o `draggable` das linhas. */
    function usaSortable() {
        return !!window.Sortable && classificar.chave === 'manual' && normalizar(busca) === '' && filtroTipo === 'todos';
    }
    function ligarSortable() {
        if (sortable) { sortable.destroy(); sortable = null; }
        if (!usaSortable()) return;
        sortable = new Sortable(el.lista, {
            draggable: '.pex-item',
            animation: 150,
            ghostClass: 'pex-arrastando',
            // Links, botões, o campo inline e a linha provisória não iniciam arraste; e, com o
            // mouse, só o ícone/nome arrasta — o espaço vazio da linha é do laço (dc L4876).
            filter: function (evt, alvo) {
                const t = evt.target;
                if (!t || !t.closest) return false;
                // O nome (<a>) INICIA o arraste: o Sortable desliga o `draggable` próprio dos
                // links (`ignore: 'a, img'`, padrão) e não cancela o mousedown, então o clique,
                // o Ctrl+clique e o clique do meio continuam chegando ao link.
                if (t.closest('button, input, .pex-ren') || alvo.dataset.pexTemp !== undefined) return true;
                if (evt.pointerType === 'touch' || /^touch/.test(evt.type || '')) return false;
                return !pontoNoConteudo(alvo, evt.clientX);
            },
            preventOnFilter: false,
            onStart: function (evt) {
                cancelarRenomear();
                itemArrastado = evt.item;
                ultimoPonto = null;
                // Como no arraste nativo: linha dentro da seleção leva a seleção inteira ao soltar
                // numa pasta (o reordenar continua sendo só da linha arrastada).
                const chave = chaveDoElemento(evt.item);
                arrasteSortable = selecao.has(chave) ? Array.from(selecao) : [chave];
            },
            onEnd: function (evt) {
                const arrastado = itemArrastado;
                const chaves = arrasteSortable || [];
                itemArrastado = null;
                arrasteSortable = null;
                realcarAlvo(null);
                const ponto = ultimoPonto || (evt.originalEvent ? { x: evt.originalEvent.clientX, y: evt.originalEvent.clientY } : null);
                const destino = ponto && arrastado ? pastaSobPonto(ponto.x, ponto.y, arrastado) : null;
                if (arrastado && destino) {
                    const alvoChave = chaveDoElemento(destino);
                    soltarEm(chaves.filter(function (k) { return k !== alvoChave; }), destino);
                    return;
                }
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

    // Arraste nativo da SELEÇÃO (fora do modo Manual), dc `dIni`/`dOver`/`dDrop` (L4760-4765):
    // arrastar um item fora da seleção seleciona só ele; soltar numa pasta move todos.
    let arrasteChaves = null;
    el.lista.addEventListener('dragstart', function (e) {
        if (lacoVazio) { e.preventDefault(); return; }
        if (sortable) return;
        const item = e.target.closest && e.target.closest('.pex-item');
        if (!item || item.dataset.pexTemp !== undefined || e.target.closest('.pex-ren')) { if (e.cancelable) e.preventDefault(); return; }
        cancelarRenomear();
        const chave = chaveDoElemento(item);
        if (!selecao.has(chave)) selecionar(chave);
        arrasteChaves = Array.from(selecao);
        arrasteChaves.forEach(function (k) { const l = linhaDe(k); if (l) l.classList.add('pex-arrastando'); });
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', arrasteChaves.join(',')); } catch (err) { /* IE */ }
    });
    el.lista.addEventListener('dragover', function (e) {
        if (!arrasteChaves) return;
        const alvo = e.target.closest && e.target.closest('.pex-item--pasta');
        if (alvo && arrasteChaves.indexOf(chaveDoElemento(alvo)) === -1) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; realcarAlvo(alvo); }
        else realcarAlvo(null);
    });
    el.lista.addEventListener('dragleave', function (e) {
        if (!arrasteChaves) return;
        if (!el.lista.contains(e.relatedTarget)) realcarAlvo(null);
    });
    el.lista.addEventListener('drop', function (e) {
        if (!arrasteChaves) return;
        const alvo = e.target.closest && e.target.closest('.pex-item--pasta');
        e.preventDefault();
        e.stopPropagation();
        const chaves = arrasteChaves;
        terminarArrasteNativo();
        if (alvo && chaves.indexOf(chaveDoElemento(alvo)) === -1) soltarEm(chaves, alvo);
    });
    el.lista.addEventListener('dragend', terminarArrasteNativo);
    function terminarArrasteNativo() {
        arrasteChaves = null;
        el.lista.querySelectorAll('.pex-arrastando').forEach(function (n) { n.classList.remove('pex-arrastando'); });
        realcarAlvo(null);
    }

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
