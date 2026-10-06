/* ==========================================================================
   Visualizador de documentos — módulo único do modal #previewDocModal
   --------------------------------------------------------------------------
   Usado pelas telas de pasta, cliente e tarefa (e, indiretamente, pelo
   gerenciador de arquivos da pasta, que abre o mesmo modal com
   bootstrap.Modal.show(botao)). Cada tela mantém o PRÓPRIO markup do modal;
   este arquivo só preenche #previewDocConteudo, #previewDocNome e
   #previewDocDownload — ids que são contrato com os templates e os testes.

   API:
     VisualizadorDocumento.ligarModal(modalEl, opcoes)
         Liga show/hide do modal Bootstrap: lê data-url, data-nome, data-mime
         e data-download (opcional) do botão que abriu o modal.
     VisualizadorDocumento.abrir({ url, nome, mime, urlDownload, conteudo })
         Renderiza a pré-visualização em `conteudo` (padrão: #previewDocConteudo).
     VisualizadorDocumento.limpar(conteudo)
         Esvazia a área e cancela qualquer carregamento em andamento.

   Tipos:
     PDF (iframe), imagem, áudio, vídeo — como sempre foi.
     DOCX (mammoth.js), planilhas XLSX/XLSM/XLS/ODS/CSV (SheetJS) e texto
     (TXT/JSON/XML/LOG/MD/text/*). Bibliotecas auto-hospedadas em
     /js/vendor/, carregadas só quando o tipo pede.
     ODT (texto do content.xml), PPTX/PPSX (só o texto dos slides), RTF (só
     o texto), EML (cabeçalhos + corpo) e ZIP (lista das entradas, sem
     extrair) — sem biblioteca: o ZIP é lido aqui mesmo (diretório central)
     e descompactado com DecompressionStream('deflate-raw') nativo, com teto
     de bytes descompactados (zip bomb). XML vai por DOMParser em documento
     separado (inerte, sem scripts).

   Segurança:
     - HTML gerado por mammoth/SheetJS NUNCA entra no DOM da página: vai para
       um <iframe sandbox=""> (sem scripts, origem opaca) via srcdoc, com CSP
       "default-src 'none'; img-src data:; style-src 'unsafe-inline'".
     - Texto puro vai por textContent num <pre>.
     - Texto extraído de ODT/PPTX/RTF/EML e a lista do ZIP viram nós do DOM
       montados com textContent — nenhuma string do arquivo vira HTML.
     - O HTML de um e-mail (quando não há text/plain) só entra pelo mesmo
       iframe sandbox + CSP; a CSP barra imagem remota (pixel de rastreio).
     - Nome e URL entram por propriedades do DOM, nunca interpolados em HTML.
   ========================================================================== */
(function () {
    'use strict';

    if (window.VisualizadorDocumento) { return; }

    /* Base das bibliotecas: relativa a este próprio arquivo (funciona com
       /js/... direto ou via asset()). */
    const SCRIPT_ATUAL = document.currentScript ? document.currentScript.src : '/js/visualizador-documento.js';
    const BASE_VENDOR  = new URL('vendor/', SCRIPT_ATUAL).href;

    const BIBLIOTECAS = {
        mammoth: { src: BASE_VENDOR + 'mammoth/mammoth.browser.min.js', global: 'mammoth' },
        xlsx:    { src: BASE_VENDOR + 'xlsx/xlsx.full.min.js',          global: 'XLSX' },
    };

    /* Limites. O de bytes é o que de fato segura o navegador: mammoth e
       XLSX.read rodam na thread principal, e o SheetJS só aplica o corte de
       linhas (sheetRows) DEPOIS de descompactar o arquivo inteiro — por isso a
       planilha tem teto de bytes menor. Os de linha/coluna só limitam o HTML
       desenhado, não o custo de ler. */
    const LIMITE_LINHAS_PLANILHA  = 2000;
    const LIMITE_COLUNAS_PLANILHA = 200;
    const LIMITE_BYTES_TEXTO      = 1024 * 1024;        // 1 MB exibidos
    const LIMITE_BYTES_DOCX       = 15 * 1024 * 1024;   // 15 MB
    const LIMITE_BYTES_PLANILHA   = 5 * 1024 * 1024;    // 5 MB
    const LIMITE_BYTES_ODT        = 15 * 1024 * 1024;   // 15 MB (como o DOCX)
    const LIMITE_BYTES_PPTX       = 30 * 1024 * 1024;   // 30 MB (apresentação traz imagem)
    const LIMITE_BYTES_RTF        = 10 * 1024 * 1024;   // 10 MB lidos (RTF embute imagem em hexa)
    const LIMITE_BYTES_EML        = 10 * 1024 * 1024;   // 10 MB
    const LIMITE_BYTES_ZIP        = 30 * 1024 * 1024;   // 30 MB (o diretório central fica no fim)
    /* Teto do que se DESCOMPACTA de dentro de um ZIP (content.xml, slides):
       um XML de poucos KB pode inflar para GB (zip bomb). Por entrada e, nos
       slides, somado. */
    const LIMITE_BYTES_XML_ZIP    = 20 * 1024 * 1024;   // 20 MB
    const LIMITE_SLIDES           = 300;
    const LIMITE_ENTRADAS_ZIP     = 2000;
    const LIMITE_CARACTERES       = 1024 * 1024;        // texto extraído exibido (ODT/RTF/EML/PPTX)

    const CSP_SRCDOC = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";

    const MIMES_DOCX = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    const MIMES_PLANILHA = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel.sheet.macroenabled.12',
        'application/vnd.ms-excel',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'application/csv',
    ];
    const MIMES_TEXTO = [
        'application/json',
        'application/xml',
        'application/ld+json',
    ];
    const EXT_DOCX     = ['docx'];
    const EXT_PLANILHA = ['xlsx', 'xlsm', 'xls', 'ods', 'csv'];
    const EXT_TEXTO    = ['txt', 'json', 'xml', 'log', 'md'];
    const MIMES_ODT  = ['application/vnd.oasis.opendocument.text'];
    const MIMES_PPTX = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
    ];
    const MIMES_RTF  = ['application/rtf', 'application/x-rtf', 'text/rtf'];
    const MIMES_EML  = ['message/rfc822'];
    const MIMES_ZIP  = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];
    const EXT_ODT  = ['odt'];
    const EXT_PPTX = ['pptx', 'ppsx'];
    const EXT_RTF  = ['rtf'];
    const EXT_EML  = ['eml'];
    const EXT_ZIP  = ['zip'];

    /* ------------------------------------------------------------ util --- */

    function el(tag, classe, texto) {
        const e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto != null) { e.textContent = texto; }
        return e;
    }

    function extensao(nome) {
        const m = /\.([a-z0-9]+)$/i.exec(String(nome || '').trim());
        return m ? m[1].toLowerCase() : '';
    }

    function urlDownloadPadrao(url) {
        return String(url || '').replace('/visualizar', '/download');
    }

    /** Decide como abrir. PDF/imagem/áudio/vídeo seguem só o MIME, como antes. */
    function tipoDe(mime, nome) {
        const m   = String(mime || '').toLowerCase();
        const ext = extensao(nome);

        if (m === 'application/pdf')  { return 'pdf'; }
        if (m.startsWith('image/'))   { return 'imagem'; }
        if (m.startsWith('audio/'))   { return 'audio'; }
        if (m.startsWith('video/'))   { return 'video'; }
        if (MIMES_DOCX.includes(m) || EXT_DOCX.includes(ext))         { return 'docx'; }
        if (MIMES_PLANILHA.includes(m) || EXT_PLANILHA.includes(ext)) { return 'planilha'; }
        /* Antes do texto: text/rtf começa com text/. Antes do ZIP: ODT/PPTX
           às vezes chegam com MIME application/zip — a extensão decide. */
        if (MIMES_ODT.includes(m) || EXT_ODT.includes(ext))           { return 'odt'; }
        if (MIMES_PPTX.includes(m) || EXT_PPTX.includes(ext))         { return 'pptx'; }
        if (MIMES_RTF.includes(m) || EXT_RTF.includes(ext))           { return 'rtf'; }
        if (MIMES_EML.includes(m) || EXT_EML.includes(ext))           { return 'eml'; }
        if (MIMES_ZIP.includes(m) || EXT_ZIP.includes(ext))           { return 'zip'; }
        if (m.startsWith('text/') || MIMES_TEXTO.includes(m) || m.endsWith('+xml') || EXT_TEXTO.includes(ext)) {
            return 'texto';
        }
        return 'outro';
    }

    const carregando = {};
    function carregarBiblioteca(chave) {
        const b = BIBLIOTECAS[chave];
        if (window[b.global]) { return Promise.resolve(window[b.global]); }
        if (carregando[chave]) { return carregando[chave]; }
        carregando[chave] = new Promise(function (ok, falha) {
            const s = document.createElement('script');
            s.src = b.src;
            s.async = true;
            s.onload = function () {
                if (window[b.global]) { ok(window[b.global]); return; }
                /* Carregou mas não definiu o global: libera nova tentativa. */
                delete carregando[chave];
                s.remove();
                falha(new Error('biblioteca'));
            };
            s.onerror = function () {
                delete carregando[chave];
                s.remove();
                falha(new Error('biblioteca'));
            };
            document.head.appendChild(s);
        });
        return carregando[chave];
    }

    /** Lê a resposta até `limite` bytes; cancela o resto do download. */
    async function lerAte(resp, limite) {
        if (!resp.body || !resp.body.getReader) {
            const buf = new Uint8Array(await resp.arrayBuffer());
            return { bytes: buf.length > limite ? buf.slice(0, limite) : buf, truncado: buf.length > limite };
        }
        const leitor = resp.body.getReader();
        const partes = [];
        let total = 0;
        let truncado = false;
        for (;;) {
            const r = await leitor.read();
            if (r.done) { break; }
            partes.push(r.value);
            total += r.value.length;
            if (total > limite) {
                truncado = true;
                try { await leitor.cancel(); } catch (e) { /* já encerrado */ }
                break;
            }
        }
        const saida = new Uint8Array(Math.min(total, limite));
        let pos = 0;
        for (const p of partes) {
            if (pos >= saida.length) { break; }
            const pedaco = p.subarray(0, saida.length - pos);
            saida.set(pedaco, pos);
            pos += pedaco.length;
        }
        return { bytes: saida, truncado: truncado };
    }

    /**
     * Baixa o arquivo pela rota de visualização (mesma origem, mesma guarda de
     * acesso). `parcial`: texto aceita ficar só com o começo; DOCX/planilha não
     * — se passar do limite, nem baixa o resto.
     */
    async function baixar(url, limite, parcial, sinal) {
        const resp = await fetch(url, { credentials: 'same-origin', signal: sinal });
        if (!resp.ok) { throw new Error('http'); }
        const tamanho = parseInt(resp.headers.get('Content-Length') || '', 10);
        if (!parcial && !isNaN(tamanho) && tamanho > limite) {
            try { if (resp.body) { await resp.body.cancel(); } } catch (e) { /* ignora */ }
            return { bytes: null, truncado: true };
        }
        return lerAte(resp, limite);
    }

    /** UTF-8 primeiro; se não for UTF-8 válido, Windows-1252 (planilha/CSV antigo). */
    function decodificar(bytes, truncado) {
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes, { stream: truncado });
        } catch (e) {
            return new TextDecoder('windows-1252').decode(bytes);
        }
    }

    /** Decodifica com o charset declarado; rótulo desconhecido cai no decodificar(). */
    function decodificarCharset(bytes, charset) {
        const rotulo = String(charset || '').trim().toLowerCase();
        if (rotulo) {
            try { return new TextDecoder(rotulo).decode(bytes); } catch (e) { /* rótulo desconhecido */ }
        }
        return decodificar(bytes, false);
    }

    /** Erro com mensagem que PODE ir para a tela (o abrir() mostra vdMensagem). */
    function falha(mensagem) {
        const e = new Error(mensagem);
        e.vdMensagem = mensagem;
        return e;
    }

    /** Bytes → string "binária" (1 caractere por byte), sem perder 0x80–0x9F. */
    function bytesParaBinario(bytes) {
        let s = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return s;
    }

    function binarioParaBytes(s) {
        const b = new Uint8Array(s.length);
        for (let i = 0; i < s.length; i++) { b[i] = s.charCodeAt(i) & 0xff; }
        return b;
    }

    function formatarTamanho(n) {
        if (n >= 1024 * 1024 * 1024) { return (n / (1024 * 1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' GB'; }
        if (n >= 1024 * 1024) { return (n / (1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' MB'; }
        if (n >= 1024) { return Math.round(n / 1024).toLocaleString('pt-BR') + ' KB'; }
        return n + ' B';
    }

    /* ----------------------------------------------- ZIP (nativo) ------ */

    const MSG_ZIP_INVALIDO = 'O arquivo não é um ZIP válido, está corrompido ou protegido por senha.';

    /* Metade alta do CP850 (página OEM do Windows em português): nome de
       entrada sem a flag UTF-8 que também não é UTF-8 válido. */
    const CP850_ALTO = 'ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜø£Ø×ƒáíóúñÑªº¿®¬½¼¡«»░▒▓│┤ÁÂÀ©╣║╗╝¢¥┐'
        + '└┴┬├─┼ãÃ╚╔╩╦╠═╬¤ðÐÊËÈıÍÎÏ┘┌█▄¦Ì▀ÓßÔÒõÕµþÞÚÛÙýÝ¯´\u00ad±‗¾¶§÷¸°¨·¹³²■\u00a0';

    function lerU16(b, p) { return b[p] | (b[p + 1] << 8); }
    function lerU32(b, p) { return (b[p] | (b[p + 1] << 8) | (b[p + 2] << 16) | (b[p + 3] << 24)) >>> 0; }

    function nomeDaEntrada(bytes, utf8) {
        if (utf8) { return new TextDecoder('utf-8').decode(bytes); }
        try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch (e) { /* CP850 */ }
        let s = '';
        for (let i = 0; i < bytes.length; i++) {
            s += bytes[i] < 128 ? String.fromCharCode(bytes[i]) : CP850_ALTO[bytes[i] - 128];
        }
        return s;
    }

    function dataDos(hora, data) {
        const mes = (data >> 5) & 15;
        const dia = data & 31;
        if (!mes || !dia) { return null; }
        return new Date(1980 + (data >> 9), mes - 1, dia, hora >> 11, (hora >> 5) & 63, (hora & 31) * 2);
    }

    /**
     * Lê o diretório central (fim do arquivo) — só metadados, nada é
     * descompactado aqui. ZIP64 não é suportado (arquivo > 4 GB ou > 65535
     * entradas, fora dos tetos deste visualizador de qualquer forma).
     */
    function lerZip(bytes, mensagemInvalido) {
        const invalido = mensagemInvalido || MSG_ZIP_INVALIDO;
        const n = bytes.length;
        let eocd = -1;
        for (let p = n - 22; p >= 0 && p >= n - 22 - 0xffff; p--) {
            if (bytes[p] === 0x50 && bytes[p + 1] === 0x4b && bytes[p + 2] === 0x05 && bytes[p + 3] === 0x06) { eocd = p; break; }
        }
        if (eocd < 0) { throw falha(invalido); }
        const total    = lerU16(bytes, eocd + 10);
        const tamCd    = lerU32(bytes, eocd + 12);
        const inicioCd = lerU32(bytes, eocd + 16);
        if (total === 0xffff || tamCd === 0xffffffff || inicioCd === 0xffffffff) {
            throw falha('Este ZIP usa o formato ZIP64, que a pré-visualização não lê. Baixe para abrir.');
        }
        if (inicioCd + tamCd > eocd) { throw falha(invalido); }

        const entradas = [];
        let p = inicioCd;
        for (let i = 0; i < total; i++) {
            if (p + 46 > eocd || lerU32(bytes, p) !== 0x02014b50) { throw falha(invalido); }
            const flags  = lerU16(bytes, p + 8);
            const lNome  = lerU16(bytes, p + 28);
            const lExtra = lerU16(bytes, p + 30);
            const lComen = lerU16(bytes, p + 32);
            const nome   = nomeDaEntrada(bytes.subarray(p + 46, p + 46 + lNome), (flags & 0x800) !== 0);
            entradas.push({
                nome: nome,
                pasta: nome.endsWith('/'),
                criptografado: (flags & 1) !== 0,
                metodo: lerU16(bytes, p + 10),
                data: dataDos(lerU16(bytes, p + 12), lerU16(bytes, p + 14)),
                comprimido: lerU32(bytes, p + 20),
                tamanho: lerU32(bytes, p + 24),
                offsetLocal: lerU32(bytes, p + 42),
            });
            p += 46 + lNome + lExtra + lComen;
        }
        return entradas;
    }

    /** Descompacta deflate com teto: passou do teto, cancela e falha (zip bomb). */
    async function inflar(dados, teto) {
        if (typeof DecompressionStream === 'undefined') {
            throw falha('Este navegador não consegue abrir este formato. Baixe o arquivo para abrir.');
        }
        const ds = new DecompressionStream('deflate-raw');
        const escritor = ds.writable.getWriter();
        escritor.write(dados).catch(function () { /* erro chega pelo leitor */ });
        escritor.close().catch(function () { /* idem */ });
        const leitor = ds.readable.getReader();
        const partes = [];
        let total = 0;
        for (;;) {
            const r = await leitor.read();
            if (r.done) { break; }
            total += r.value.length;
            if (total > teto) {
                leitor.cancel().catch(function () { /* já encerrado */ });
                throw falha('O conteúdo descompactado é grande demais para pré-visualizar. Baixe para abrir.');
            }
            partes.push(r.value);
        }
        const saida = new Uint8Array(total);
        let pos = 0;
        for (const pt of partes) { saida.set(pt, pos); pos += pt.length; }
        return saida;
    }

    async function extrairEntrada(bytes, entrada, teto) {
        if (entrada.criptografado) { throw falha('O arquivo está protegido por senha. Baixe para abrir.'); }
        const p = entrada.offsetLocal;
        if (p + 30 > bytes.length || lerU32(bytes, p) !== 0x04034b50) { throw falha(MSG_ZIP_INVALIDO); }
        const inicio = p + 30 + lerU16(bytes, p + 26) + lerU16(bytes, p + 28);
        const dados = bytes.subarray(inicio, inicio + entrada.comprimido);
        if (dados.length < entrada.comprimido) { throw falha(MSG_ZIP_INVALIDO); }
        if (entrada.metodo === 0) {
            if (dados.length > teto) { throw falha('O conteúdo descompactado é grande demais para pré-visualizar. Baixe para abrir.'); }
            return dados;
        }
        if (entrada.metodo !== 8) { throw falha('O arquivo usa uma compressão que a pré-visualização não lê. Baixe para abrir.'); }
        return inflar(dados, teto);
    }

    function acharEntrada(entradas, nome) {
        return entradas.find(function (e) { return e.nome === nome; }) || null;
    }

    /** XML em documento separado (inerte: sem scripts, sem rede). */
    function lerXml(bytes, mensagemInvalido) {
        const doc = new DOMParser().parseFromString(new TextDecoder('utf-8').decode(bytes), 'application/xml');
        if (doc.getElementsByTagName('parsererror').length) { throw falha(mensagemInvalido); }
        return doc;
    }

    /* ------------------------------------------------------- ODT ------- */

    const NS_ODF_TEXTO  = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    const NS_ODF_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    function ehParagrafoOdf(no) {
        return no.nodeType === 1 && no.namespaceURI === NS_ODF_TEXTO && (no.localName === 'p' || no.localName === 'h');
    }

    /** Texto de um parágrafo ODF; parágrafos aninhados (nota, caixa de texto) ficam de fora — são listados à parte. */
    function textoOdf(no) {
        let s = '';
        for (let f = no.firstChild; f; f = f.nextSibling) {
            if (f.nodeType === 3) { s += f.nodeValue; continue; }
            if (f.nodeType !== 1) { continue; }
            if (ehParagrafoOdf(f)) { continue; }
            if (f.namespaceURI === NS_ODF_TEXTO) {
                if (f.localName === 's') {
                    const c = parseInt(f.getAttributeNS(NS_ODF_TEXTO, 'c') || '1', 10);
                    s += ' '.repeat(Math.min(Math.max(c || 1, 1), 100));
                    continue;
                }
                if (f.localName === 'tab') { s += '\t'; continue; }
                if (f.localName === 'line-break') { s += '\n'; continue; }
                if (f.localName === 'note-citation') { continue; }
            }
            s += textoOdf(f);
        }
        return s;
    }

    function dentroDeNotaOdf(no) {
        for (let p = no.parentNode; p && p.nodeType === 1; p = p.parentNode) {
            if (p.namespaceURI === NS_ODF_TEXTO && p.localName === 'note') { return true; }
        }
        return false;
    }

    /** → { blocos: [{ titulo: nível|0, nota: bool, texto }], cortado } */
    function paragrafosOdt(doc, limite) {
        const corpo = doc.getElementsByTagNameNS(NS_ODF_OFFICE, 'body')[0] || doc.documentElement;
        const todos = corpo.getElementsByTagNameNS(NS_ODF_TEXTO, '*');
        const blocos = [];
        let total = 0;
        for (let i = 0; i < todos.length; i++) {
            const no = todos[i];
            if (!ehParagrafoOdf(no)) { continue; }
            const texto = textoOdf(no);
            total += texto.length;
            if (total > limite) { return { blocos: blocos, cortado: true }; }
            const nivel = no.localName === 'h' ? (parseInt(no.getAttributeNS(NS_ODF_TEXTO, 'outline-level') || '1', 10) || 1) : 0;
            blocos.push({ titulo: nivel, nota: dentroDeNotaOdf(no), texto: texto });
        }
        return { blocos: blocos, cortado: false };
    }

    /* ------------------------------------------------------ PPTX ------- */

    const NS_PPT_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_PPT_MAIN    = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    const NS_PPT_REL     = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_PKG_REL     = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const RE_SLIDE       = /^ppt\/slides\/slide(\d+)\.xml$/;

    /**
     * Ordem dos slides: a de ppt/presentation.xml (sldIdLst → rels), que é a
     * da apresentação; o número no nome do arquivo é só a ordem de criação.
     * Se algo faltar, cai na ordem numérica.
     */
    async function ordemDosSlides(bytes, entradas) {
        const numerica = entradas.filter(function (e) { return RE_SLIDE.test(e.nome); })
            .sort(function (a, b) { return parseInt(RE_SLIDE.exec(a.nome)[1], 10) - parseInt(RE_SLIDE.exec(b.nome)[1], 10); });
        try {
            const pres = acharEntrada(entradas, 'ppt/presentation.xml');
            const rels = acharEntrada(entradas, 'ppt/_rels/presentation.xml.rels');
            if (!pres || !rels) { return numerica; }
            const docPres = lerXml(await extrairEntrada(bytes, pres, LIMITE_BYTES_XML_ZIP), 'x');
            const docRels = lerXml(await extrairEntrada(bytes, rels, LIMITE_BYTES_XML_ZIP), 'x');
            const alvos = {};
            const rs = docRels.getElementsByTagNameNS(NS_PKG_REL, 'Relationship');
            for (let i = 0; i < rs.length; i++) {
                const alvo = rs[i].getAttribute('Target') || '';
                alvos[rs[i].getAttribute('Id')] = alvo.charAt(0) === '/' ? alvo.slice(1) : 'ppt/' + alvo;
            }
            const ids = docPres.getElementsByTagNameNS(NS_PPT_MAIN, 'sldId');
            const ordem = [];
            for (let i = 0; i < ids.length; i++) {
                const e = acharEntrada(entradas, alvos[ids[i].getAttributeNS(NS_PPT_REL, 'id')] || '');
                if (e) { ordem.push(e); }
            }
            return ordem.length ? ordem : numerica;
        } catch (e) {
            if (ehAbort(e)) { throw e; }
            return numerica;
        }
    }

    /** Linhas de texto de um slide (cada a:p vira uma linha; a:br quebra). */
    function linhasDoSlide(doc) {
        const linhas = [];
        const ps = doc.getElementsByTagNameNS(NS_PPT_DRAWING, 'p');
        for (let i = 0; i < ps.length; i++) {
            let s = '';
            const nos = ps[i].getElementsByTagNameNS(NS_PPT_DRAWING, '*');
            for (let j = 0; j < nos.length; j++) {
                if (nos[j].localName === 't') { s += nos[j].textContent; }
                else if (nos[j].localName === 'br') { s += '\n'; }
            }
            if (s.trim()) { linhas.push(s); }
        }
        return linhas;
    }

    /* ------------------------------------------------------- RTF ------- */

    /* Destinos cujo conteúdo não é texto do documento. */
    const RTF_IGNORAR = new Set([
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'objdata', 'themedata',
        'colorschememapping', 'latentstyles', 'datastore', 'xmlnstbl', 'listtable', 'listoverridetable',
        'rsidtbl', 'generator', 'filetbl', 'revtbl', 'pgdsctbl', 'fldinst', 'datafield', 'userprops',
        'docvar', 'shpinst', 'nonshppict', 'header', 'headerl', 'headerr', 'headerf', 'footer',
        'footerl', 'footerr', 'footerf', 'bkmkstart', 'bkmkend', 'mmathPr', 'wgrffmtfilter', 'protusertbl',
    ]);
    const RTF_TEXTO = {
        par: '\n', line: '\n', page: '\n', sect: '\n', row: '\n', tab: '\t', cell: '\t', nestcell: '\t',
        emdash: '\u2014', endash: '\u2013', bullet: '\u2022', lquote: '\u2018', rquote: '\u2019',
        ldblquote: '\u201c', rdblquote: '\u201d', emspace: ' ', enspace: ' ', qmspace: ' ',
    };
    const RTF_CODEPAGES = {
        874: 'windows-874', 932: 'shift_jis', 936: 'gbk', 949: 'euc-kr', 950: 'big5',
        1250: 'windows-1250', 1251: 'windows-1251', 1252: 'windows-1252', 1253: 'windows-1253',
        1254: 'windows-1254', 1255: 'windows-1255', 1256: 'windows-1256', 1257: 'windows-1257',
        1258: 'windows-1258', 10000: 'macintosh',
    };

    /**
     * Parser mínimo: só o TEXTO (sem formatação). `fonte` é string binária.
     * → { texto, cortado }
     */
    function rtfParaTexto(fonte, limite) {
        if (!/^\s*\{\\rtf/.test(fonte)) { throw falha('O arquivo não parece ser um RTF válido.'); }
        let codepage = 'windows-1252';
        const pilha = [];
        let estado = { ignorar: false, uc: 1 };
        let saida = '';
        let bytesPend = [];
        let pular = 0; // caracteres de reserva depois de \uN
        let cortado = false;

        function descarregar() {
            if (!bytesPend.length) { return; }
            let t;
            try { t = new TextDecoder(codepage).decode(Uint8Array.from(bytesPend)); }
            catch (e) { t = new TextDecoder('windows-1252').decode(Uint8Array.from(bytesPend)); }
            saida += t;
            bytesPend = [];
        }
        function emitir(t) {
            if (estado.ignorar) { return; }
            descarregar();
            saida += t;
        }
        function byte(b) {
            if (estado.ignorar) { return; }
            if (pular > 0) { pular--; return; }
            bytesPend.push(b);
        }

        const n = fonte.length;
        let i = 0;
        while (i < n) {
            if (saida.length + bytesPend.length > limite) { cortado = true; break; }
            const c = fonte.charCodeAt(i);
            if (c === 0x7b) { // {
                pilha.push(estado);
                estado = { ignorar: estado.ignorar, uc: estado.uc };
                pular = 0;
                i++;
                continue;
            }
            if (c === 0x7d) { // }
                descarregar();
                estado = pilha.pop() || estado;
                pular = 0;
                i++;
                continue;
            }
            if (c === 0x5c) { // \
                const prox = fonte.charAt(i + 1);
                if (prox === '\\' || prox === '{' || prox === '}') { byte(prox.charCodeAt(0)); i += 2; continue; }
                if (prox === '*') { estado.ignorar = true; i += 2; continue; }
                if (prox === "'") { byte(parseInt(fonte.substr(i + 2, 2), 16) || 0x3f); i += 4; continue; }
                if (prox === '~') { emitir('\u00a0'); i += 2; continue; }
                if (prox === '_') { emitir('\u2011'); i += 2; continue; }
                if (prox === '\n' || prox === '\r') { emitir('\n'); i += 2; continue; }
                const m = /^([a-zA-Z]{1,32})(-?\d{1,10})? ?/.exec(fonte.substr(i + 1, 48));
                if (!m) { i += 2; continue; }
                i += 1 + m[0].length;
                const palavra = m[1];
                const param = m[2] !== undefined ? parseInt(m[2], 10) : null;
                if (palavra === 'bin' && param > 0) { i += param; continue; }
                if (RTF_IGNORAR.has(palavra)) { estado.ignorar = true; continue; }
                if (palavra === 'ansicpg' && param !== null) { codepage = RTF_CODEPAGES[param] || 'windows-1252'; continue; }
                if (palavra === 'mac') { codepage = 'macintosh'; continue; }
                if (palavra === 'uc' && param !== null) { estado.uc = Math.max(0, param); continue; }
                if (palavra === 'u' && param !== null) {
                    emitir(String.fromCharCode(param < 0 ? param + 65536 : param));
                    pular = estado.uc;
                    continue;
                }
                if (Object.prototype.hasOwnProperty.call(RTF_TEXTO, palavra)) { emitir(RTF_TEXTO[palavra]); }
                continue;
            }
            if (c === 0x0d || c === 0x0a) { i++; continue; }
            byte(c);
            i++;
        }
        descarregar();
        return {
            texto: saida.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim(),
            cortado: cortado,
        };
    }

    /* ------------------------------------------------------- EML ------- */

    /** Cabeçalho/corpo de uma mensagem ou parte MIME (string binária). */
    function separarMime(bruto) {
        const m = /\r?\n\r?\n/.exec(bruto);
        const cab = m ? bruto.slice(0, m.index) : bruto;
        const corpo = m ? bruto.slice(m.index + m[0].length) : '';
        const cabecalhos = Object.create(null);
        cab.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/).forEach(function (linha) {
            const p = linha.indexOf(':');
            if (p <= 0) { return; }
            const nome = linha.slice(0, p).trim().toLowerCase();
            if (!/^[a-z0-9-]+$/.test(nome) || nome in cabecalhos) { return; }
            cabecalhos[nome] = linha.slice(p + 1).trim();
        });
        return { cabecalhos: cabecalhos, corpo: corpo };
    }

    /** Valor de cabeçalho → texto: 8 bits como UTF-8 (ou 1252) e palavras RFC 2047. */
    function textoDoCabecalho(valor) {
        const base = decodificar(binarioParaBytes(String(valor || '')), false);
        return base.replace(/(\?=)\s+(=\?)/g, '$1$2').replace(/=\?([^?\s]+)\?([bBqQ])\?([^?\s]*)\?=/g, function (tudo, cs, modo, dado) {
            try {
                const bin = modo.toUpperCase() === 'B'
                    ? atob(dado)
                    : dado.replace(/_/g, ' ').replace(/=([0-9a-fA-F]{2})/g, function (x, h) { return String.fromCharCode(parseInt(h, 16)); });
                return decodificarCharset(binarioParaBytes(bin), cs.split('*')[0]);
            } catch (e) {
                return tudo;
            }
        });
    }

    /** Parâmetro de Content-Type/Content-Disposition (aceita nome*=utf-8''…). */
    function parametroMime(valor, nome) {
        const v = String(valor || '');
        const ext = new RegExp('(?:^|;)\\s*' + nome + '\\*\\s*=\\s*([^\'";]*)\'[^\']*\'([^;\\s]+)', 'i').exec(v);
        if (ext) {
            try { return decodificarCharset(binarioParaBytes(unescape(ext[2])), ext[1]); } catch (e) { /* cai no simples */ }
        }
        const m = new RegExp('(?:^|;)\\s*' + nome + '\\s*=\\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\\s]+))', 'i').exec(v);
        if (!m) { return ''; }
        return m[1] !== undefined ? m[1].replace(/\\(.)/g, '$1') : m[2];
    }

    function corpoDecodificado(corpo, cabecalhos) {
        const cte = String(cabecalhos['content-transfer-encoding'] || '').trim().toLowerCase();
        let bin = corpo;
        if (cte === 'base64') {
            try { bin = atob(corpo.replace(/[^A-Za-z0-9+/=]/g, '')); } catch (e) { bin = corpo; }
        } else if (cte === 'quoted-printable') {
            bin = corpo.replace(/=\r?\n/g, '').replace(/=([0-9a-fA-F]{2})/g, function (x, h) { return String.fromCharCode(parseInt(h, 16)); });
        }
        return decodificarCharset(binarioParaBytes(bin), parametroMime(cabecalhos['content-type'], 'charset'));
    }

    function partesMultipart(corpo, fronteira) {
        const partes = corpo.split('--' + fronteira);
        const saida = [];
        for (let k = 1; k < partes.length; k++) {
            if (partes[k].startsWith('--')) { break; }
            saida.push(partes[k].replace(/^[ \t]*\r?\n/, '').replace(/\r?\n$/, ''));
        }
        return saida;
    }

    /** Percorre a árvore MIME: primeiro text/plain, primeiro text/html, nomes dos anexos. */
    function lerParteMime(bruto, profundidade, acc) {
        const parte = separarMime(bruto);
        const ct = parte.cabecalhos['content-type'] || 'text/plain';
        const tipo = ct.split(';')[0].trim().toLowerCase();
        const disp = parte.cabecalhos['content-disposition'] || '';
        const nomeArq = textoDoCabecalho(parametroMime(disp, 'filename') || parametroMime(ct, 'name'));

        if (tipo.startsWith('multipart/') && profundidade < 8) {
            const fronteira = parametroMime(ct, 'boundary');
            if (fronteira) {
                partesMultipart(parte.corpo, fronteira).forEach(function (p) { lerParteMime(p, profundidade + 1, acc); });
                return;
            }
        }
        const anexo = /^\s*attachment/i.test(disp) || !!nomeArq || tipo === 'message/rfc822';
        if (!anexo && tipo === 'text/plain' && acc.texto === null) { acc.texto = corpoDecodificado(parte.corpo, parte.cabecalhos); return; }
        if (!anexo && tipo === 'text/html' && acc.html === null) { acc.html = corpoDecodificado(parte.corpo, parte.cabecalhos); return; }
        if (anexo) {
            const b64 = /base64/i.test(parte.cabecalhos['content-transfer-encoding'] || '');
            acc.anexos.push({
                nome: nomeArq || (tipo === 'message/rfc822' ? 'mensagem anexada.eml' : '(sem nome)'),
                tamanho: Math.round(parte.corpo.replace(/\s/g, '').length * (b64 ? 0.75 : 1)),
            });
        }
    }

    /** → { cabecalhos (texto), texto, html, anexos } */
    function lerEml(bytes) {
        const bruto = bytesParaBinario(bytes).replace(/^From [^\n]*\n/, ''); // linha de envelope mbox
        const topo = separarMime(bruto);
        const c = topo.cabecalhos;
        if (!('from' in c) && !('subject' in c) && !('date' in c) && !('to' in c)) {
            throw falha('O arquivo não parece ser um e-mail (.eml) válido.');
        }
        const acc = { texto: null, html: null, anexos: [] };
        lerParteMime(bruto, 0, acc);
        return {
            de: textoDoCabecalho(c.from),
            para: textoDoCabecalho(c.to),
            cc: textoDoCabecalho(c.cc),
            assunto: textoDoCabecalho(c.subject),
            data: String(c.date || ''),
            texto: acc.texto,
            html: acc.html,
            anexos: acc.anexos,
        };
    }

    /**
     * Defesa em profundidade antes do srcdoc (que já é sandbox sem scripts):
     * parse inerte via DOMParser, remove elementos ativos, handlers on* e
     * desarma links (clicar não navega o iframe para fora).
     */
    function neutralizar(html) {
        const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
        doc.querySelectorAll('script,style,iframe,frame,object,embed,link,meta,base,form,svg,math').forEach(function (x) { x.remove(); });
        doc.querySelectorAll('*').forEach(function (n) {
            Array.from(n.attributes).forEach(function (a) {
                const nome = a.name.toLowerCase();
                if (nome.startsWith('on') || nome === 'href' || nome === 'srcset' || nome === 'action' || nome === 'formaction') {
                    n.removeAttribute(a.name);
                } else if (nome === 'src' && !/^data:image\//i.test(a.value)) {
                    n.removeAttribute(a.name);
                }
            });
        });
        return doc.body.innerHTML;
    }

    function montarSrcdoc(corpoHtml, css) {
        return '<!doctype html><html lang="pt-BR"><head>'
            + '<meta http-equiv="Content-Security-Policy" content="' + CSP_SRCDOC + '">'
            + '<meta charset="utf-8">'
            + '<style>' + css + '</style>'
            + '</head><body>' + corpoHtml + '</body></html>';
    }

    const CSS_DOCX = 'html{background:#eef1f3}body{margin:0;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#1d1d1d}'
        + '.folha{background:#fff;max-width:820px;margin:0 auto;padding:48px 56px;box-sizing:border-box;'
        + 'box-shadow:0 1px 3px rgba(0,0,0,.25);font-size:14px;line-height:1.6;overflow-wrap:anywhere}'
        + 'img{max-width:100%;height:auto}table{border-collapse:collapse;max-width:100%}td,th{border:1px solid #ccc;padding:4px 6px;vertical-align:top}'
        + 'p{margin:0 0 .6em}';

    const CSS_PLANILHA = 'html{background:#fff}body{margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#1d1d1d}'
        + 'table{border-collapse:collapse}td,th{border:1px solid #dfe3e6;padding:4px 8px;white-space:nowrap;vertical-align:top}'
        + 'tr:first-child td{background:#f1f5f2;font-weight:700;position:sticky;top:0}';

    function criarIframe(srcdoc, titulo) {
        const fr = document.createElement('iframe');
        fr.setAttribute('sandbox', '');
        fr.setAttribute('referrerpolicy', 'no-referrer');
        fr.className = 'vd-quadro w-100 border-0';
        fr.title = titulo || '';
        fr.srcdoc = srcdoc;
        return fr;
    }

    /* ------------------------------------------------------- blocos ----- */

    function blocoNaoDisponivel(urlDownload, mensagem) {
        const div = el('div', 'p-5 text-center text-muted w-100');
        div.appendChild(el('i', 'bi bi-file-earmark display-1 d-block mb-3'));
        div.appendChild(el('p', null, mensagem || 'Pré-visualização não disponível para este tipo de arquivo.'));
        const a = el('a', 'btn btn-primary');
        a.href = urlDownload;
        a.appendChild(el('i', 'bi bi-download me-1'));
        a.appendChild(document.createTextNode(' Baixar arquivo'));
        div.appendChild(a);
        return div;
    }

    function blocoCarregando(nome) {
        const div = el('div', 'vd-carregando p-5 text-center text-muted w-100');
        const sp = el('div', 'spinner-border spinner-border-sm me-2');
        sp.setAttribute('role', 'status');
        sp.setAttribute('aria-hidden', 'true');
        div.appendChild(sp);
        div.appendChild(document.createTextNode('Abrindo ' + (nome || 'arquivo') + '…'));
        return div;
    }

    function aviso(texto) {
        const p = el('div', 'vd-aviso', texto);
        p.setAttribute('role', 'note');
        return p;
    }

    function renderPdf(alvo, o) {
        const fr = document.createElement('iframe');
        fr.src = o.url;
        fr.className = 'w-100 border-0';
        fr.style.height = '75vh';
        fr.title = o.nome;
        alvo.appendChild(fr);
    }

    function renderImagem(alvo, o) {
        const img = document.createElement('img');
        img.src = o.url;
        img.className = 'img-fluid d-block mx-auto p-3';
        img.style.maxHeight = '75vh';
        img.style.objectFit = 'contain';
        img.alt = o.nome;
        alvo.appendChild(img);
    }

    function renderAudio(alvo, o) {
        const div = el('div', 'p-4 text-center w-100');
        div.appendChild(el('i', 'bi bi-file-earmark-music display-1 text-muted d-block mb-3'));
        div.appendChild(el('p', 'text-muted mb-3', o.nome));
        const au = document.createElement('audio');
        au.controls = true;
        au.className = 'w-100';
        au.style.maxWidth = '500px';
        const src = document.createElement('source');
        src.src = o.url;
        src.type = o.mime;
        au.appendChild(src);
        if (o.textoSemSuporteMidia) {
            au.appendChild(document.createTextNode('Seu navegador não suporta reprodução de áudio.'));
        }
        div.appendChild(au);
        alvo.appendChild(div);
    }

    function renderVideo(alvo, o) {
        const v = document.createElement('video');
        v.controls = true;
        v.className = 'w-100 d-block';
        v.style.maxHeight = '75vh';
        const src = document.createElement('source');
        src.src = o.url;
        src.type = o.mime;
        v.appendChild(src);
        if (o.textoSemSuporteMidia) {
            v.appendChild(document.createTextNode('Seu navegador não suporta reprodução de vídeo.'));
        }
        alvo.appendChild(v);
    }

    async function renderDocx(alvo, o, vivo) {
        const [mammoth, arq] = await Promise.all([carregarBiblioteca('mammoth'), baixar(o.url, LIMITE_BYTES_DOCX, false, o.sinal)]);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) {
            trocar(alvo, blocoNaoDisponivel(o.urlDownload, 'Arquivo grande demais para pré-visualizar. Baixe para abrir.'));
            return;
        }
        /* Imagens: mammoth já embute como data: por padrão (e a CSP só aceita data:). */
        const r = await mammoth.convertToHtml({ arrayBuffer: arq.bytes.buffer });
        if (!vivo()) { return; }
        const corpo = neutralizar(r.value) || '<p><i>Documento vazio.</i></p>';
        const wrap = el('div', 'vd-documento w-100');
        wrap.appendChild(criarIframe(montarSrcdoc('<div class="folha">' + corpo + '</div>', CSS_DOCX), o.nome));
        trocar(alvo, wrap);
    }

    async function renderPlanilha(alvo, o, vivo) {
        const ext = extensao(o.nome);
        const ehCsv = ext === 'csv' || /csv/.test(String(o.mime || '').toLowerCase());
        const [XLSX, arq] = await Promise.all([carregarBiblioteca('xlsx'), baixar(o.url, LIMITE_BYTES_PLANILHA, false, o.sinal)]);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) {
            trocar(alvo, blocoNaoDisponivel(o.urlDownload, 'Arquivo grande demais para pré-visualizar. Baixe para abrir.'));
            return;
        }
        /* Lê UMA linha a mais que o limite: é assim que se sabe que houve corte
           mesmo quando o formato (CSV) não informa o tamanho total (!fullref). */
        const opcoesLeitura = { sheetRows: LIMITE_LINHAS_PLANILHA + 1, cellFormula: false, cellStyles: false };
        const wb = ehCsv
            ? XLSX.read(decodificar(arq.bytes, false), Object.assign({ type: 'string' }, opcoesLeitura))
            : XLSX.read(arq.bytes, Object.assign({ type: 'array' }, opcoesLeitura));
        if (!vivo()) { return; }

        const nomes = wb.SheetNames || [];
        if (!nomes.length) {
            trocar(alvo, blocoNaoDisponivel(o.urlDownload, 'A planilha não tem abas para mostrar.'));
            return;
        }

        const wrap   = el('div', 'vd-planilha w-100');
        const abas   = el('div', 'vd-abas');
        abas.setAttribute('role', 'tablist');
        const avisoEl = el('div', 'vd-aviso-area');
        const quadro = el('div', 'vd-quadro-area');

        function mostrar(indice) {
            const nomeAba = nomes[indice];
            const ws = wb.Sheets[nomeAba];
            abas.querySelectorAll('.vd-aba').forEach(function (b, i) {
                const ativa = i === indice;
                b.classList.toggle('ativa', ativa);
                b.setAttribute('aria-selected', ativa ? 'true' : 'false');
                b.tabIndex = ativa ? 0 : -1;
            });
            avisoEl.replaceChildren();
            quadro.replaceChildren();

            if (!ws || !ws['!ref']) {
                quadro.appendChild(el('div', 'p-4 text-center text-muted', 'Aba vazia.'));
                return;
            }
            const faixa      = XLSX.utils.decode_range(ws['!ref']);
            const faixaTotal = ws['!fullref'] ? XLSX.utils.decode_range(ws['!fullref']) : null;
            const totalLinhas  = faixaTotal ? faixaTotal.e.r - faixaTotal.s.r + 1 : null;
            const totalColunas = faixa.e.c - faixa.s.c + 1;
            const linhasLidas   = faixa.e.r - faixa.s.r + 1;
            const cortouLinhas  = linhasLidas > LIMITE_LINHAS_PLANILHA || (totalLinhas !== null && totalLinhas > linhasLidas);
            const cortouColunas = totalColunas > LIMITE_COLUNAS_PLANILHA;
            if (linhasLidas > LIMITE_LINHAS_PLANILHA) { faixa.e.r = faixa.s.r + LIMITE_LINHAS_PLANILHA - 1; }
            if (cortouColunas) { faixa.e.c = faixa.s.c + LIMITE_COLUNAS_PLANILHA - 1; }

            const recorte = Object.assign({}, ws, { '!ref': XLSX.utils.encode_range(faixa) });
            const tabela  = XLSX.utils.sheet_to_html(recorte, { header: '', footer: '', editable: false });

            const partes = [];
            if (cortouLinhas) {
                partes.push('Mostrando as primeiras ' + LIMITE_LINHAS_PLANILHA.toLocaleString('pt-BR') + ' linhas'
                    + (totalLinhas ? ' de ' + totalLinhas.toLocaleString('pt-BR') : '') + '.');
            }
            if (cortouColunas) {
                partes.push('Mostrando as primeiras ' + LIMITE_COLUNAS_PLANILHA + ' colunas de ' + totalColunas + '.');
            }
            if (partes.length) { avisoEl.appendChild(aviso(partes.join(' ') + ' Baixe o arquivo para ver tudo.')); }

            quadro.appendChild(criarIframe(montarSrcdoc(neutralizar(tabela), CSS_PLANILHA), nomeAba));
        }

        nomes.forEach(function (nomeAba, i) {
            const b = el('button', 'vd-aba', nomeAba);
            b.type = 'button';
            b.setAttribute('role', 'tab');
            b.dataset.aba = String(i);
            b.addEventListener('click', function () { mostrar(i); });
            abas.appendChild(b);
        });
        abas.addEventListener('keydown', function (ev) {
            if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') { return; }
            const botoes = Array.from(abas.querySelectorAll('.vd-aba'));
            const atual = botoes.findIndex(function (b) { return b.classList.contains('ativa'); });
            const prox = (atual + (ev.key === 'ArrowRight' ? 1 : -1) + botoes.length) % botoes.length;
            mostrar(prox);
            botoes[prox].focus();
            ev.preventDefault();
        });

        wrap.appendChild(abas);
        wrap.appendChild(avisoEl);
        wrap.appendChild(quadro);
        trocar(alvo, wrap);
        mostrar(0);
    }

    async function renderTexto(alvo, o, vivo) {
        const arq = await baixar(o.url, LIMITE_BYTES_TEXTO, true, o.sinal);
        if (!vivo()) { return; }
        let texto = decodificar(arq.bytes, arq.truncado);
        const ehJson = extensao(o.nome) === 'json' || /json/.test(String(o.mime || '').toLowerCase());
        if (ehJson && !arq.truncado) {
            try { texto = JSON.stringify(JSON.parse(texto), null, 2); } catch (e) { /* mostra como veio */ }
        }
        const wrap = el('div', 'vd-texto-area w-100');
        if (arq.truncado) {
            wrap.appendChild(aviso('Mostrando o primeiro 1 MB do arquivo. Baixe para ver tudo.'));
        }
        const pre = el('pre', 'vd-texto');
        pre.textContent = texto;
        wrap.appendChild(pre);
        trocar(alvo, wrap);
    }

    const MSG_GRANDE = 'Arquivo grande demais para pré-visualizar. Baixe para abrir.';

    /** Folha clara montada só com nós + textContent (nenhuma string do arquivo vira HTML). */
    function folha(classeExtra) {
        const fundo = el('div', 'vd-folha-fundo');
        const f = el('div', 'vd-folha' + (classeExtra ? ' ' + classeExtra : ''));
        fundo.appendChild(f);
        return { fundo: fundo, folha: f };
    }

    async function renderOdt(alvo, o, vivo) {
        const arq = await baixar(o.url, LIMITE_BYTES_ODT, false, o.sinal);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) { trocar(alvo, blocoNaoDisponivel(o.urlDownload, MSG_GRANDE)); return; }

        const invalido = 'O arquivo não parece ser um documento ODT válido (ou está corrompido).';
        const entradas = lerZip(arq.bytes, invalido);
        /* ODF criptografado: o ZIP é normal, a cifra fica declarada no manifesto. */
        const manifesto = acharEntrada(entradas, 'META-INF/manifest.xml');
        if (manifesto) {
            const m = new TextDecoder('utf-8').decode(await extrairEntrada(arq.bytes, manifesto, LIMITE_BYTES_XML_ZIP));
            if (/encryption-data/.test(m)) { throw falha('O documento está protegido por senha. Baixe para abrir.'); }
        }
        const conteudo = acharEntrada(entradas, 'content.xml');
        if (!conteudo) { throw falha(invalido); }
        const doc = lerXml(await extrairEntrada(arq.bytes, conteudo, LIMITE_BYTES_XML_ZIP), invalido);
        if (!vivo()) { return; }

        const r = paragrafosOdt(doc, LIMITE_CARACTERES);
        const wrap = el('div', 'vd-documento w-100');
        wrap.appendChild(aviso('Mostrando só o texto do documento, sem formatação nem imagens.'
            + (r.cortado ? ' O documento é longo: só o começo aparece aqui. Baixe para ver tudo.' : '')));
        const fl = folha();
        if (!r.blocos.some(function (b) { return b.texto.trim(); })) {
            fl.folha.appendChild(el('p', 'vd-vazio', 'Documento sem texto.'));
        }
        r.blocos.forEach(function (b) {
            const tag = b.titulo ? 'h' + Math.min(6, b.titulo + 1) : 'p';
            fl.folha.appendChild(el(tag, b.nota ? 'vd-nota' : null, b.texto));
        });
        wrap.appendChild(fl.fundo);
        trocar(alvo, wrap);
    }

    async function renderPptx(alvo, o, vivo) {
        const arq = await baixar(o.url, LIMITE_BYTES_PPTX, false, o.sinal);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) { trocar(alvo, blocoNaoDisponivel(o.urlDownload, MSG_GRANDE)); return; }

        const invalido = 'O arquivo não parece ser uma apresentação PPTX válida, está corrompido ou protegido por senha.';
        const entradas = lerZip(arq.bytes, invalido);
        const ordem = await ordemDosSlides(arq.bytes, entradas);
        if (!vivo()) { return; }
        if (!ordem.length) { throw falha(invalido); }

        const slides = [];
        let orcamento = LIMITE_BYTES_XML_ZIP * 2; // soma do XML descompactado de todos os slides
        let caracteres = 0;
        for (let i = 0; i < ordem.length && i < LIMITE_SLIDES; i++) {
            if (orcamento <= 0 || caracteres > LIMITE_CARACTERES) { break; }
            const xml = await extrairEntrada(arq.bytes, ordem[i], Math.min(LIMITE_BYTES_XML_ZIP, orcamento));
            if (!vivo()) { return; }
            orcamento -= xml.length;
            const doc = lerXml(xml, invalido);
            const raiz = doc.documentElement;
            const linhas = linhasDoSlide(doc);
            linhas.forEach(function (l) { caracteres += l.length; });
            slides.push({ linhas: linhas, oculto: !!raiz && raiz.getAttribute('show') === '0' });
        }

        const wrap = el('div', 'vd-slides-area w-100');
        const cortou = slides.length < ordem.length;
        wrap.appendChild(aviso('Mostrando só o texto dos slides, sem imagens nem formatação.'
            + (cortou ? ' Aparecem os ' + slides.length + ' primeiros de ' + ordem.length + ' slides. Baixe para ver tudo.' : '')));
        const lista = el('div', 'vd-slides');
        slides.forEach(function (s, i) {
            const card = el('section', 'vd-slide');
            card.setAttribute('aria-label', 'Slide ' + (i + 1));
            card.appendChild(el('div', 'vd-slide-numero', 'Slide ' + (i + 1) + ' de ' + ordem.length + (s.oculto ? ' · oculto' : '')));
            if (!s.linhas.length) {
                card.appendChild(el('p', 'vd-vazio', 'Slide sem texto.'));
            }
            s.linhas.forEach(function (l, j) {
                card.appendChild(el(j === 0 ? 'h3' : 'p', j === 0 ? 'vd-slide-titulo' : null, l));
            });
            lista.appendChild(card);
        });
        wrap.appendChild(lista);
        trocar(alvo, wrap);
    }

    async function renderRtf(alvo, o, vivo) {
        /* Parcial: o começo de um RTF já dá texto legível. */
        const arq = await baixar(o.url, LIMITE_BYTES_RTF, true, o.sinal);
        if (!vivo()) { return; }
        const r = rtfParaTexto(bytesParaBinario(arq.bytes), LIMITE_CARACTERES);
        const wrap = el('div', 'vd-documento w-100');
        wrap.appendChild(aviso('Mostrando só o texto do RTF, sem formatação nem imagens.'
            + (arq.truncado || r.cortado ? ' O arquivo é longo: só o começo aparece aqui. Baixe para ver tudo.' : '')));
        const fl = folha('vd-folha-pre');
        fl.folha.textContent = r.texto || 'Documento sem texto.';
        wrap.appendChild(fl.fundo);
        trocar(alvo, wrap);
    }

    const CSS_EMAIL = 'html{background:#fff}body{margin:0;padding:16px 20px;font-family:Arial,Helvetica,sans-serif;'
        + 'font-size:14px;line-height:1.5;color:#1d1d1d;overflow-wrap:anywhere}img{max-width:100%;height:auto}'
        + 'table{max-width:100%}';

    function linhaCabecalhoEmail(dl, rotulo, valor) {
        if (!valor) { return; }
        dl.appendChild(el('dt', null, rotulo));
        dl.appendChild(el('dd', null, valor));
    }

    async function renderEml(alvo, o, vivo) {
        const arq = await baixar(o.url, LIMITE_BYTES_EML, false, o.sinal);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) { trocar(alvo, blocoNaoDisponivel(o.urlDownload, MSG_GRANDE)); return; }

        const m = lerEml(arq.bytes);
        const wrap = el('div', 'vd-email w-100');
        const cab = el('div', 'vd-email-cabecalho');
        cab.appendChild(el('div', 'vd-email-assunto', m.assunto || '(sem assunto)'));
        const dl = el('dl', 'vd-email-campos');
        const data = m.data ? new Date(m.data) : null;
        linhaCabecalhoEmail(dl, 'De', m.de);
        linhaCabecalhoEmail(dl, 'Para', m.para);
        linhaCabecalhoEmail(dl, 'Cc', m.cc);
        linhaCabecalhoEmail(dl, 'Data', data && !isNaN(data.getTime()) ? data.toLocaleString('pt-BR') : m.data);
        if (m.anexos.length) {
            linhaCabecalhoEmail(dl, 'Anexos', m.anexos.map(function (a) {
                return a.nome + ' (' + formatarTamanho(a.tamanho) + ')';
            }).join(', '));
        }
        cab.appendChild(dl);
        wrap.appendChild(cab);

        if (m.texto !== null) {
            const cortado = m.texto.length > LIMITE_CARACTERES;
            if (cortado) { wrap.appendChild(aviso('O e-mail é longo: só o começo aparece aqui. Baixe para ver tudo.')); }
            const pre = el('pre', 'vd-email-corpo');
            pre.textContent = cortado ? m.texto.slice(0, LIMITE_CARACTERES) : m.texto;
            wrap.appendChild(pre);
        } else if (m.html !== null) {
            /* Só HTML: vai para o sandbox, como o DOCX. Imagens externas ficam
               bloqueadas pela CSP (e o neutralizar tira src que não é data:). */
            wrap.appendChild(aviso('E-mail em formato HTML, mostrado sem imagens externas nem scripts.'));
            const corpo = neutralizar(m.html.slice(0, LIMITE_CARACTERES * 2));
            wrap.appendChild(criarIframe(montarSrcdoc(corpo, CSS_EMAIL), m.assunto || o.nome));
        } else {
            wrap.appendChild(el('p', 'vd-vazio p-4', 'E-mail sem texto.'));
        }
        trocar(alvo, wrap);
    }

    async function renderZip(alvo, o, vivo) {
        const arq = await baixar(o.url, LIMITE_BYTES_ZIP, false, o.sinal);
        if (!vivo()) { return; }
        if (!arq.bytes || arq.truncado) { trocar(alvo, blocoNaoDisponivel(o.urlDownload, MSG_GRANDE)); return; }

        /* Só o diretório central: nada é descompactado. */
        const entradas = lerZip(arq.bytes);
        const arquivos = entradas.filter(function (e) { return !e.pasta; });
        const total = arquivos.reduce(function (s, e) { return s + e.tamanho; }, 0);
        const protegidos = arquivos.some(function (e) { return e.criptografado; });

        const wrap = el('div', 'vd-zip w-100');
        wrap.appendChild(aviso(arquivos.length.toLocaleString('pt-BR') + ' arquivo(s) · '
            + formatarTamanho(total) + ' descompactado(s). Conteúdo listado, não extraído'
            + (protegidos ? '; há itens protegidos por senha' : '') + '.'
            + (entradas.length > LIMITE_ENTRADAS_ZIP ? ' Mostrando as primeiras ' + LIMITE_ENTRADAS_ZIP.toLocaleString('pt-BR')
                + ' de ' + entradas.length.toLocaleString('pt-BR') + ' entradas.' : '')));

        const area = el('div', 'vd-zip-area');
        const tabela = el('table', 'vd-zip-tabela');
        const thead = el('thead');
        const trh = el('tr');
        ['Nome', 'Tamanho', 'Modificado em'].forEach(function (t) { trh.appendChild(el('th', null, t)); });
        thead.appendChild(trh);
        tabela.appendChild(thead);
        const tbody = el('tbody');
        if (!entradas.length) {
            const tr = el('tr');
            const td = el('td', 'vd-vazio', 'O ZIP está vazio.');
            td.colSpan = 3;
            tr.appendChild(td);
            tbody.appendChild(tr);
        }
        entradas.slice(0, LIMITE_ENTRADAS_ZIP).forEach(function (e) {
            const tr = el('tr');
            const tdNome = el('td', 'vd-zip-nome');
            tdNome.appendChild(el('i', 'bi ' + (e.pasta ? 'bi-folder' : (e.criptografado ? 'bi-lock' : 'bi-file-earmark')) + ' me-1'));
            tdNome.appendChild(document.createTextNode(e.nome));
            if (e.criptografado) { tdNome.appendChild(el('span', 'vd-zip-senha', ' (protegido por senha)')); }
            tr.appendChild(tdNome);
            tr.appendChild(el('td', 'vd-zip-tamanho', e.pasta ? '' : formatarTamanho(e.tamanho)));
            tr.appendChild(el('td', 'vd-zip-data', e.data ? e.data.toLocaleString('pt-BR') : ''));
            tbody.appendChild(tr);
        });
        tabela.appendChild(tbody);
        area.appendChild(tabela);
        wrap.appendChild(area);
        trocar(alvo, wrap);
    }

    function trocar(alvo, no) {
        alvo.replaceChildren(no);
    }

    /* --------------------------------------------------------- API ------ */

    /** Esvazia a área e ABORTA o download em andamento (AbortController). */
    function limpar(conteudo) {
        if (!conteudo) { return; }
        conteudo.__vdToken = (conteudo.__vdToken || 0) + 1;
        if (conteudo.__vdAbort) {
            conteudo.__vdAbort.abort();
            conteudo.__vdAbort = null;
        }
        conteudo.replaceChildren();
    }

    function ehAbort(erro) {
        return !!erro && erro.name === 'AbortError';
    }

    function abrir(opcoes) {
        const o = Object.assign({ textoSemSuporteMidia: true }, opcoes || {});
        const alvo = o.conteudo || document.getElementById('previewDocConteudo');
        if (!alvo) { return; }
        o.url  = String(o.url || '');
        o.nome = o.nome == null ? '' : String(o.nome);
        o.mime = String(o.mime || '');
        o.urlDownload = o.urlDownload || urlDownloadPadrao(o.url);

        limpar(alvo); // abrir outro arquivo aborta o download do anterior
        const token = alvo.__vdToken;
        const vivo = function () { return alvo.__vdToken === token; };

        const tipo = tipoDe(o.mime, o.nome);
        if (tipo === 'pdf')    { renderPdf(alvo, o); return; }
        if (tipo === 'imagem') { renderImagem(alvo, o); return; }
        if (tipo === 'audio')  { renderAudio(alvo, o); return; }
        if (tipo === 'video')  { renderVideo(alvo, o); return; }

        const render = {
            docx: renderDocx,
            planilha: renderPlanilha,
            texto: renderTexto,
            odt: renderOdt,
            pptx: renderPptx,
            rtf: renderRtf,
            eml: renderEml,
            zip: renderZip,
        }[tipo];
        if (!render) {
            alvo.appendChild(blocoNaoDisponivel(o.urlDownload));
            return;
        }

        const controle = new AbortController();
        alvo.__vdAbort = controle;
        o.sinal = controle.signal;

        alvo.appendChild(blocoCarregando(o.nome));
        render(alvo, o, vivo).catch(function (erro) {
            if (ehAbort(erro) || !vivo()) { return; }
            /* Falha com mensagem própria (senha, corrompido, zip bomb…) vai
               para a tela; o resto fica com a genérica. */
            trocar(alvo, blocoNaoDisponivel(o.urlDownload, (erro && erro.vdMensagem)
                || 'Não foi possível abrir a pré-visualização deste arquivo.'));
        });
    }

    /**
     * Liga o modal Bootstrap. Opções:
     *   textoSemSuporteMidia (bool, padrão true) — inclui o texto de fallback
     *     "Seu navegador não suporta…" dentro de <audio>/<video>
     *     (a tela de cliente nunca teve esse texto).
     */
    function ligarModal(modal, opcoes) {
        if (!modal) { return; }
        const op = Object.assign({ textoSemSuporteMidia: true }, opcoes || {});
        const conteudo = modal.querySelector('#previewDocConteudo');
        const nomeEl   = modal.querySelector('#previewDocNome');
        const download = modal.querySelector('#previewDocDownload');

        modal.addEventListener('show.bs.modal', function (e) {
            const btn = e.relatedTarget;
            if (!btn || !btn.dataset) { return; }
            const url   = btn.dataset.url || '';
            const nome  = btn.dataset.nome;
            const mime  = btn.dataset.mime;
            const urlDl = btn.dataset.download || urlDownloadPadrao(url);

            if (nomeEl) { nomeEl.textContent = nome; }
            if (download) { download.href = urlDl; }

            abrir({
                url: url,
                nome: nome,
                mime: mime,
                urlDownload: urlDl,
                conteudo: conteudo,
                textoSemSuporteMidia: op.textoSemSuporteMidia,
            });
        });

        modal.addEventListener('hide.bs.modal', function () {
            limpar(conteudo); // aborta o fetch em andamento (AbortController)
        });
    }

    window.VisualizadorDocumento = {
        abrir: abrir,
        limpar: limpar,
        ligarModal: ligarModal,
        tipoDe: tipoDe,
    };
})();
