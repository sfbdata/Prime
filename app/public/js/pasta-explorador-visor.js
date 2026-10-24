/* ==========================================================================
   Visor em tela cheia da aba Documentos (lote L10, DOC-47..50)
   Desenho: 02 - EXPEDIENTES 1.2.3 — template dc L299-329, lógica `visorVals`
   dc L4634-4673. Decisão: docs/specs/trilha-b-documentos-arquitetura.md §5 L10.

   O CONTEÚDO não é desenhado aqui: vai por `VisualizadorDocumento.abrir` (o
   mesmo módulo do #previewDocModal — PDF/imagem/áudio/vídeo direto, o resto em
   iframe sandbox) com `conteudo` = `#pexVisorAlvo`. Este arquivo só cuida da
   moldura: cabeçalho, anterior/próximo pela lista VISÍVEL do explorador (que o
   pasta-explorador.js entrega já na ordem, com filtro e busca aplicados),
   contador "n de N", zoom por escala do contêiner, imprimir, baixar, abrir em
   nova aba, fechar devolvendo o foco à linha, teclado e trap de foco.

   API (consumida pelo pasta-explorador.js):
     PexVisor.abrir({ arquivos: [...], indice, aoFechar(arquivo) })
       arquivo = { id, nome, mime, viewUrl, downloadUrl, icone, tipo, tamanho, data }
     PexVisor.fechar()
     PexVisor.aberto()

   Fora do desenho, de propósito:
     - "Soltar arquivo no visor" (DOC-51) NÃO é renderizado (S-7): no dc é um
       blob local que nunca chega ao servidor. Arquivo solto aqui é só
       ignorado (sem navegar para ele e sem subir nada).
     - Imprimir (DOC-108): PDF e imagem imprimem o próprio arquivo; DOCX,
       planilha, texto, ODT, PPTX, ODP, RTF, EML e a lista do ZIP imprimem o
       conteúdo JÁ desenhado, que o VisualizadorDocumento.paraImpressao entrega
       como cópia inerte e limpa. Esse conteúdo vai para um iframe oculto
       sandbox sem permissão de script e com a CSP do visualizador. Áudio, vídeo,
       assinatura e "outro" seguem com o botão desabilitado e o motivo no title.

   Sem innerHTML: todo nó nasce por createElement/textContent (nome de arquivo
   é dado do usuário). Sem storage: o zoom vale só para esta abertura.
   ========================================================================== */
(function () {
    'use strict';

    if (window.PexVisor) return;

    const visor = document.getElementById('pexVisor');
    if (!visor) return;

    /* Fora do explorador: nenhum ancestral com transform prende o `fixed`, e as
       teclas do visor não sobem para o keydown da RAIZ do explorador (Backspace,
       setas, Del da lista). Não é isolamento total: os ouvintes do `document`
       do pasta-explorador.js (Esc fecha popover/menu, clique fecha popover)
       continuam vendo os eventos — inofensivos com o visor aberto. */
    document.body.appendChild(visor);

    const el = {
        icone:    document.getElementById('pexVisorIcone'),
        nome:     document.getElementById('pexVisorNome'),
        meta:     document.getElementById('pexVisorMeta'),
        anterior: document.getElementById('pexVisorAnterior'),
        proximo:  document.getElementById('pexVisorProximo'),
        posicao:  document.getElementById('pexVisorPosicao'),
        zoom:     document.getElementById('pexVisorZoom'),
        menos:    document.getElementById('pexVisorMenos'),
        mais:     document.getElementById('pexVisorMais'),
        zoomTxt:  document.getElementById('pexVisorZoomTxt'),
        baixar:   document.getElementById('pexVisorBaixar'),
        imprimir: document.getElementById('pexVisorImprimir'),
        novaAba:  document.getElementById('pexVisorNovaAba'),
        fechar:   document.getElementById('pexVisorFechar'),
        area:     document.getElementById('pexVisorArea'),
        alvo:     document.getElementById('pexVisorAlvo'),
        aviso:    document.getElementById('pexVisorAviso'),
    };

    const pastaTxt = BJ_T.t('pasta_documentos.js.visor_pasta', { pasta: visor.dataset.pastaRotulo || '' })
        + (visor.dataset.pastaCliente ? ' · ' + visor.dataset.pastaCliente : '');

    // dc `zoom`: de 10 em 10, de 50% a 200% (DOC-49).
    const ZOOM_MIN = 50, ZOOM_MAX = 200, ZOOM_PASSO = 10;
    // dc `temZoom`: sem zoom para áudio, vídeo, ZIP (lista) e o que não tem visualização.
    const TIPOS_COM_ZOOM = ['pdf', 'imagem', 'docx', 'planilha', 'texto', 'odt', 'pptx', 'odp', 'rtf', 'eml', 'doc', 'tiff'];
    // DOC-108: PDF e imagem imprimem o ARQUIVO; os demais, o conteúdo que o visualizador desenhou.
    const TIPOS_IMPRIMIVEIS_ARQUIVO = ['pdf', 'imagem'];
    const TIPOS_IMPRIMIVEIS_CONTEUDO = ['docx', 'planilha', 'texto', 'odt', 'pptx', 'odp', 'rtf', 'eml', 'zip', 'doc', 'tiff'];
    /* O iframe do conteúdo: sandbox sem permissão de script (nada de dentro roda). allow-same-origin é o que
       deixa o visor preenchê-lo por DOM e chamar print() — sem scripts lá dentro, não abre porta;
       allow-modals é o que impede o navegador de ignorar o print() de um documento sandbox. */
    const SANDBOX_IMPRESSAO = 'allow-same-origin allow-modals';
    // A mesma CSP do srcdoc do visualizador (CSP_SRCDOC): rede fechada, só imagem data: e estilo inline.
    const CSP_IMPRESSAO = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";
    // A tarja do dc (L4659): fonte 9pt, cinza, com fio embaixo.
    const CSS_TARJA = '.cab{font-size:9pt;color:#6b8494;border-bottom:1px solid #c9d6de;padding-bottom:4px;margin-bottom:10px}';
    // Sem a propriedade CSS `zoom`, o grupo some (o CSS tem o @supports correspondente).
    const ZOOM_SUPORTADO = !window.CSS || typeof window.CSS.supports !== 'function' || window.CSS.supports('zoom', '1');
    const MSG_PREPARANDO = BJ_T.t('pasta_documentos.js.visor_preparando');
    const MOTIVO_SEM_IMPRESSAO = BJ_T.t('pasta_documentos.js.visor_sem_impressao');
    const MSG_ABRA_O_CONTEUDO = BJ_T.t('pasta_documentos.js.visor_abra_conteudo'); // dc L4659
    const MSG_FALHA_IMPRESSAO = BJ_T.t('pasta_documentos.js.visor_falha_impressao');

    let lista = [];
    let indice = 0;
    let zoom = 100;
    let aoFechar = null;
    let imprimindo = false;
    let avisoTimer = null;
    let overflowAnterior = '';

    function aberto() { return !visor.hidden; }
    function ehImprimivel(tipo) {
        return TIPOS_IMPRIMIVEIS_ARQUIVO.indexOf(tipo) !== -1 || TIPOS_IMPRIMIVEIS_CONTEUDO.indexOf(tipo) !== -1;
    }
    function atual() { return lista[indice] || null; }
    function tipoDe(a) {
        return window.VisualizadorDocumento && typeof window.VisualizadorDocumento.tipoDe === 'function'
            ? window.VisualizadorDocumento.tipoDe(a.mime, a.nome)
            : 'outro';
    }

    function mostrarAviso(texto) {
        clearTimeout(avisoTimer);
        el.aviso.textContent = texto;
        el.aviso.hidden = false;
        avisoTimer = setTimeout(function () { el.aviso.hidden = true; el.aviso.textContent = ''; }, 2600);
    }

    // ------------------------------------------------------------ altura ----
    /* Os quadros (iframe do PDF, do DOCX, da planilha) precisam de altura
       definida. O cabeçalho quebra em mais linhas no celular, então a altura
       útil é MEDIDA, não suposta: vai em px numa variável do visor. */
    function medirArea() {
        if (!aberto()) return;
        visor.style.setProperty('--pex-visor-area-h', el.area.clientHeight + 'px');
    }
    if (window.ResizeObserver) {
        new ResizeObserver(medirArea).observe(el.area);
    } else {
        window.addEventListener('resize', medirArea);
    }

    // -------------------------------------------------------------- zoom ----
    /* "Escala do contêiner": o alvo recebe `zoom` (afeta o layout, então a área
       rola de verdade) e uma largura proporcional — o que é 100% de largura lá
       dentro continua sendo a largura da área, só que ampliada ou reduzida. */
    function aplicarZoom() {
        el.alvo.style.setProperty('--pex-visor-zoom', String(zoom / 100));
        el.zoomTxt.textContent = zoom + '%';
        el.menos.disabled = zoom <= ZOOM_MIN;
        el.mais.disabled = zoom >= ZOOM_MAX;
    }
    function mudarZoom(delta) {
        if (el.zoom.hidden) return;
        const novo = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom + delta));
        if (novo === zoom) return;
        zoom = novo;
        aplicarZoom();
    }
    function zoomPadrao() {
        if (el.zoom.hidden || zoom === 100) return;
        zoom = 100;
        aplicarZoom();
    }

    // ------------------------------------------------------------ render ----
    function renderizar() {
        const a = atual();
        if (!a) return;
        const tipo = tipoDe(a);

        el.icone.className = 'bi ' + (a.icone || 'bi-file-earmark-fill') + ' pex-visor-ico';
        el.nome.textContent = a.nome;
        el.nome.title = a.nome;
        el.meta.textContent = [a.tipo, a.tamanho, a.data].filter(Boolean).concat([pastaTxt]).join(' · ');

        el.posicao.textContent = BJ_T.t('pasta_documentos.js.visor_posicao', { n: indice + 1, total: lista.length });
        el.anterior.disabled = indice === 0;
        el.proximo.disabled = indice === lista.length - 1;

        el.zoom.hidden = !ZOOM_SUPORTADO || TIPOS_COM_ZOOM.indexOf(tipo) === -1;
        aplicarZoom();

        const imprimivel = ehImprimivel(tipo);
        el.imprimir.setAttribute('aria-disabled', imprimivel ? 'false' : 'true');
        el.imprimir.title = imprimivel ? BJ_T.t('pasta_documentos.js.visor_imprimir') : MOTIVO_SEM_IMPRESSAO;
        el.imprimir.setAttribute('aria-label', imprimivel ? BJ_T.t('pasta_documentos.js.visor_imprimir') : BJ_T.t('pasta_documentos.js.visor_imprimir_indisponivel', { motivo: MOTIVO_SEM_IMPRESSAO }));

        el.area.scrollTop = 0;
        el.area.scrollLeft = 0;
        if (window.VisualizadorDocumento) {
            window.VisualizadorDocumento.abrir({
                url: a.viewUrl,
                nome: a.nome,
                mime: a.mime || '',
                urlDownload: a.downloadUrl,
                // DOC-141: o tamanho que o explorador já mostra no cabeçalho — vai no aviso do P7S.
                tamanho: a.tamanho || '',
                conteudo: el.alvo,
            });
        } else {
            semVisualizador(a);
        }
    }

    // O módulo é carregado pela página; se faltar, o arquivo ainda sai por Baixar.
    function semVisualizador(a) {
        const div = document.createElement('div');
        div.className = 'pex-visor-indisponivel';
        const p = document.createElement('p');
        p.textContent = BJ_T.t('pasta_documentos.js.visor_sem_previa');
        const l = document.createElement('a');
        l.className = 'btn btn-primary';
        l.href = a.downloadUrl;
        l.textContent = BJ_T.t('pasta_documentos.js.visor_baixar_arquivo');
        div.appendChild(p);
        div.appendChild(l);
        el.alvo.replaceChildren(div);
    }

    function ir(delta) {
        const novo = Math.max(0, Math.min(lista.length - 1, indice + delta));
        if (novo === indice) return;
        indice = novo;
        renderizar();
    }

    // ----------------------------------------------------------- ações ----
    function baixar() {
        const a = atual();
        if (!a || !a.downloadUrl) return;
        const l = document.createElement('a');
        l.href = a.downloadUrl;
        l.target = '_blank';
        l.rel = 'noopener';
        l.hidden = true;
        document.body.appendChild(l);
        l.click();
        l.remove();
    }

    function abrirEmNovaAba() {
        const a = atual();
        if (a && a.viewUrl) window.open(a.viewUrl, '_blank', 'noopener');
    }

    /* A tarja do dc (L4659): "BlueJus · Pasta N · nome · impresso em …". Por
       textContent: o nome do arquivo é dado do usuário. */
    function tarjaDeImpressao(d, a) {
        const cab = d.createElement('div');
        cab.className = 'cab';
        cab.textContent = BJ_T.t('pasta_documentos.js.visor_tarja', { pasta: visor.dataset.pastaRotulo || '', nome: a.nome, quando: new Date().toLocaleString('pt-BR') });
        return cab;
    }

    /* O documento vazio do iframe do conteúdo. Só constantes: CSP, estilo do
       visualizador e da tarja — nenhum dado do arquivo passa por string; o
       conteúdo entra depois, por DOM (importNode da cópia inerte). */
    function documentoDeImpressao(css) {
        return '<!doctype html><html lang="pt-BR"><head>'
            + '<meta http-equiv="Content-Security-Policy" content="' + CSP_IMPRESSAO + '">'
            + '<meta charset="utf-8"><style>' + css + CSS_TARJA + '</style></head><body></body></html>';
    }

    /* Imagem embutida (data:) do DOCX: espera carregar, com teto — sem script
       no documento, não se aposta só no evento. */
    function aguardarImagens(d) {
        const pendentes = Array.from(d.images).filter(function (i) { return !i.complete; });
        if (!pendentes.length) return Promise.resolve();
        return Promise.race([
            Promise.all(pendentes.map(function (i) {
                return new Promise(function (r) {
                    i.addEventListener('load', r, { once: true });
                    i.addEventListener('error', r, { once: true });
                });
            })),
            new Promise(function (r) { setTimeout(r, 1500); }),
        ]);
    }

    /* Impressão por iframe OCULTO (dc `BJImprimir`): funciona onde pop-up é
       bloqueado. PDF: o próprio arquivo carregado no iframe e impresso pelo
       leitor do navegador. Imagem: um documento mínimo montado por DOM, com a
       tarja do dc. Conteúdo desenhado (DOC-108): iframe sandbox sem scripts, com
       a CSP do visualizador, preenchido por DOM com a tarja e a cópia inerte do
       que está na tela. O iframe fica FORA da tela com tamanho real (o leitor de
       PDF do Chrome pode não montar num 0×0) e sai depois do afterprint (ou em
       60 s). A trava `imprimindo` se solta em até 3 s mesmo sem afterprint;
       clique durante a preparação só avisa. */
    function imprimir() {
        const a = atual();
        if (!a) return;
        const tipo = tipoDe(a);
        if (!ehImprimivel(tipo)) { mostrarAviso(MOTIVO_SEM_IMPRESSAO); return; }
        // C6-DOC-02: imagem que o navegador não abriu (HEIC) virou aviso no visor
        // (`data-vd-sem-conteudo`); imprimir daria página quebrada. O botão passa a indisponível.
        if (tipo === 'imagem' && el.alvo.querySelector('[data-vd-sem-conteudo]')) {
            el.imprimir.setAttribute('aria-disabled', 'true');
            el.imprimir.title = MOTIVO_SEM_IMPRESSAO;
            mostrarAviso(MOTIVO_SEM_IMPRESSAO);
            return;
        }
        if (imprimindo) { mostrarAviso(MSG_PREPARANDO); return; } // um de cada vez: não empilha iframes

        // Conteúdo desenhado: só depois que o visualizador terminou (dc: "Abra o conteúdo…").
        let renderizado = null;
        if (TIPOS_IMPRIMIVEIS_CONTEUDO.indexOf(tipo) !== -1) {
            renderizado = window.VisualizadorDocumento && typeof window.VisualizadorDocumento.paraImpressao === 'function'
                ? window.VisualizadorDocumento.paraImpressao(el.alvo)
                : null;
            if (!renderizado) { mostrarAviso(MSG_ABRA_O_CONTEUDO); return; }
        }

        imprimindo = true;
        let trava = null;
        const liberarEm3s = function () {
            clearTimeout(trava);
            trava = setTimeout(function () { imprimindo = false; }, 3000);
        };
        liberarEm3s();
        mostrarAviso(MSG_PREPARANDO);
        const fr = document.createElement('iframe');
        fr.className = 'pex-visor-impressao';
        fr.setAttribute('aria-hidden', 'true');
        fr.tabIndex = -1;
        fr.title = BJ_T.t('pasta_documentos.js.visor_impressao_de', { nome: a.nome });

        let removido = false;
        const remover = function () {
            if (removido) return;
            removido = true;
            clearTimeout(trava);
            imprimindo = false;
            fr.remove();
        };
        const disparar = function () {
            setTimeout(function () {
                try {
                    const w = fr.contentWindow;
                    w.addEventListener('afterprint', function () { setTimeout(remover, 100); });
                    w.focus();
                    liberarEm3s();
                    w.print();
                } catch (e) {
                    mostrarAviso(MSG_FALHA_IMPRESSAO);
                    remover();
                    return;
                }
                setTimeout(remover, 60000);
            }, 150);
        };

        if (tipo === 'pdf') {
            fr.addEventListener('load', disparar, { once: true });
            fr.src = a.viewUrl;
            document.body.appendChild(fr);
            return;
        }

        if (renderizado) {
            fr.setAttribute('sandbox', SANDBOX_IMPRESSAO);
            fr.addEventListener('load', function () {
                const dc = fr.contentDocument;
                if (!dc || !dc.body) { remover(); mostrarAviso(MSG_FALHA_IMPRESSAO); return; }
                dc.title = a.nome;
                dc.body.appendChild(tarjaDeImpressao(dc, a));
                dc.body.appendChild(dc.importNode(renderizado.nos, true));
                aguardarImagens(dc).then(disparar);
            }, { once: true });
            fr.srcdoc = documentoDeImpressao(renderizado.css);
            document.body.appendChild(fr);
            return;
        }

        // Imagem: o about:blank inicial do iframe, preenchido por DOM.
        document.body.appendChild(fr);
        const d = fr.contentDocument;
        if (!d) { remover(); mostrarAviso(MSG_FALHA_IMPRESSAO); return; }
        d.title = a.nome;
        const estilo = d.createElement('style');
        estilo.textContent = '@page{margin:12mm}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#12242f}'
            + CSS_TARJA
            + 'img{display:block;max-width:100%;max-height:250mm;margin:0 auto;page-break-inside:avoid}';
        d.head.appendChild(estilo);
        d.body.appendChild(tarjaDeImpressao(d, a));
        const img = d.createElement('img');
        img.alt = a.nome;
        img.addEventListener('load', disparar, { once: true });
        img.addEventListener('error', function () {
            mostrarAviso(BJ_T.t('pasta_documentos.js.visor_falha_imagem'));
            remover();
        }, { once: true });
        img.src = a.viewUrl;
        d.body.appendChild(img);
    }

    // --------------------------------------------------- abrir / fechar ----
    function abrir(opcoes) {
        const o = opcoes || {};
        const arquivos = Array.isArray(o.arquivos) ? o.arquivos.filter(Boolean) : [];
        if (!arquivos.length) return false;
        lista = arquivos;
        indice = Math.max(0, Math.min(arquivos.length - 1, Number(o.indice) || 0));
        zoom = 100;
        aoFechar = typeof o.aoFechar === 'function' ? o.aoFechar : null;

        if (!aberto()) {
            overflowAnterior = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            visor.hidden = false;
        }
        medirArea();
        renderizar();
        visor.focus({ preventScroll: true });
        return true;
    }

    function fechar() {
        if (!aberto()) return;
        const a = atual();
        const cb = aoFechar;
        if (window.VisualizadorDocumento) window.VisualizadorDocumento.limpar(el.alvo); // aborta o download
        else el.alvo.replaceChildren();
        clearTimeout(avisoTimer);
        el.aviso.hidden = true;
        visor.hidden = true;
        document.body.style.overflow = overflowAnterior;
        lista = [];
        aoFechar = null;
        // O foco volta para a linha do arquivo que estava na tela (quem sabe qual é a linha é o explorador).
        if (cb && a) cb(a);
    }

    // ----------------------------------------------------------- eventos ----
    el.anterior.addEventListener('click', function () { ir(-1); });
    el.proximo.addEventListener('click', function () { ir(1); });
    el.menos.addEventListener('click', function () { mudarZoom(-ZOOM_PASSO); });
    el.mais.addEventListener('click', function () { mudarZoom(ZOOM_PASSO); });
    el.zoomTxt.addEventListener('click', zoomPadrao);
    el.baixar.addEventListener('click', baixar);
    el.imprimir.addEventListener('click', imprimir);
    el.novaAba.addEventListener('click', abrirEmNovaAba);
    el.fechar.addEventListener('click', fechar);

    // S-7: nada de área de soltar. Sem isto, soltar um arquivo aqui faria o navegador sair da página.
    el.area.addEventListener('dragover', function (e) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'none'; });
    el.area.addEventListener('drop', function (e) { e.preventDefault(); });

    function focaveis() {
        return Array.from(visor.querySelectorAll('button, a[href], iframe, [tabindex]:not([tabindex="-1"])'))
            .filter(function (n) { return !n.disabled && !n.closest('[hidden]'); });
    }

    /* dc `tecla`: Esc fecha, ← → percorrem; + e − dão zoom. Em campo de texto
       (não há, mas um tipo futuro pode ter) as teclas são do campo. Ctrl/Cmd
       com + e − é o zoom do NAVEGADOR: não é interceptado. */
    visor.addEventListener('keydown', function (e) {
        const k = e.key;
        if (k === 'Escape') { e.preventDefault(); fechar(); return; }
        if (k === 'Tab') {
            const fs = focaveis();
            if (!fs.length) { e.preventDefault(); visor.focus(); return; }
            const primeiro = fs[0], ultimo = fs[fs.length - 1];
            const ativo = document.activeElement;
            if (e.shiftKey && (ativo === primeiro || ativo === visor)) { e.preventDefault(); ultimo.focus(); }
            else if (!e.shiftKey && ativo === ultimo) { e.preventDefault(); primeiro.focus(); }
            return;
        }
        const tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (k === 'ArrowLeft') { e.preventDefault(); ir(-1); return; }
        if (k === 'ArrowRight') { e.preventDefault(); ir(1); return; }
        if (k === '+' || k === '=') { e.preventDefault(); mudarZoom(ZOOM_PASSO); return; }
        if (k === '-' || k === '_') { e.preventDefault(); mudarZoom(-ZOOM_PASSO); }
    });

    // Trap de foco: o que tentar sair do visor (clique fora não há; Tab de dentro de um iframe
    // há) volta para o próprio visor.
    document.addEventListener('focusin', function (e) {
        if (!aberto() || visor.contains(e.target)) return;
        visor.focus({ preventScroll: true });
    });

    window.PexVisor = { abrir: abrir, fechar: fechar, aberto: aberto };
})();
