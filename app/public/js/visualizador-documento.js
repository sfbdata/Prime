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

   Segurança:
     - HTML gerado por mammoth/SheetJS NUNCA entra no DOM da página: vai para
       um <iframe sandbox=""> (sem scripts, origem opaca) via srcdoc, com CSP
       "default-src 'none'; img-src data:; style-src 'unsafe-inline'".
     - Texto puro vai por textContent num <pre>.
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

        const render = { docx: renderDocx, planilha: renderPlanilha, texto: renderTexto }[tipo];
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
            trocar(alvo, blocoNaoDisponivel(o.urlDownload, 'Não foi possível abrir a pré-visualização deste arquivo.'));
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
