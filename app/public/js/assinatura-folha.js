/* =============================================================================
   assinatura-folha.js: folha A4 da prévia da tela Assinatura de documentos (etapa 1).
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html, funções blocoV, html, pintar,
   fundoPrev, seloAneis e anelTxt (contrato em docs/specs/assinatura-documentos-etapa-1.md, seções 2 e 3.3).

   Um único desenho de folha para o assistente e o detalhe:
   - página inteira ou duas colunas (layout '2col', com o texto lateral), folha branca sem fundo e sem rodapé;
   - fundo de segurança em canvas (degradê, linhas onduladas, rosácea, marca d'água, molduras, faixa lateral,
     microtexto e selo com "PRÉVIA" ou visto), guardado em memória por código;
   - rodapé de validação com QR, código com letras crescendo e SHA-256 (ou "gerado no envio do documento");
   - bloco {k:'pg', n} = página n do PDF enviado, desenhada pelo pdf.js 3.11.174 a partir do arquivo local
     (antes de salvar) ou da URL do servidor (depois). O protótipo guardava a página como imagem no próprio bloco.

   O conteúdo sai sempre escapado; {b}...{/b} é o único realce, e o campo vazio do assistente vem marcado
   (\u0001...\u0002) para sair em amarelo.
   ============================================================================= */
(function () {
    'use strict';

    if (window.BJAFolha) { return; }

    const T = (chave, p) => BJ_T.t('assinatura_eletronica_tela.js.folha.' + chave, p);
    const COD_VAZIO = 'BJA-XXXX-XXXX-XXXX';
    const QR_VALIDAR = 'https://www.bluejus.com.br/assinar/validar';
    const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const PDFJS_WORKER_SRI = 'sha512-BbrZ76UNZq5BhH7LL7pn9A4TKQpQeNCHOo65/akfelcIBbcVvYWOFQKPXIrykE3qZxYjmDX573oa4Ywsc7rpTw==';

    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' }[c]));
    const marcar = t => esc(t).replace(/\u0001/g, '<span class="m">').replace(/\u0002/g, '</span>').replace(/\{b\}/g, '<b>').replace(/\{\/b\}/g, '</b>');
    const dataHora = v => { const d = new Date(v); return isNaN(d.getTime()) ? '' : d.toLocaleString(window.BJFormato ? window.BJFormato.idioma() : 'pt-BR'); };

    /* ── Selo: nome em anel e microtexto circular com o código do próprio documento ── */
    function anelTxt(g, base, r, fnt, cor, a0) {
        g.save(); g.font = fnt; g.fillStyle = cor; g.textAlign = 'center'; g.textBaseline = 'alphabetic';
        const w0 = g.measureText(base).width, n = Math.max(1, Math.round(2 * Math.PI * r / w0)), chars = Array.from(base.repeat(n)),
            ws = chars.map(ch => g.measureText(ch).width), tot = ws.reduce((a, b) => a + b, 0), esc0 = 2 * Math.PI / tot;
        let a = a0 === undefined ? -Math.PI / 2 - g.measureText('BLUEJUS').width * esc0 / 2 : a0;
        chars.forEach((ch, i) => { const m = a + ws[i] * esc0 / 2; g.save(); g.rotate(m + Math.PI / 2); g.fillText(ch, 0, -r); g.restore(); a += ws[i] * esc0; });
        g.restore();
    }

    function seloAneis(g, sr, cod) {
        const c0 = String(cod || ''), sem = Array.from(c0).reduce((a, ch, i) => a + ch.charCodeAt(0) * (i + 3), 7);
        g.save(); g.fillStyle = 'rgba(255,255,255,.76)'; g.beginPath(); g.arc(0, 0, sr * .83, 0, Math.PI * 2); g.arc(0, 0, sr * .72, 0, Math.PI * 2, true); g.fill(); g.restore();
        anelTxt(g, T('anel', { codigo: c0 }), sr * .745, (sr * .088) + 'px Arial', '#0b3550', sem * .137);
        anelTxt(g, T('anel_interno'), sr * .405, 'bold ' + (sr * .15) + 'px Arial', '#0b3550');
    }

    /* ── Fundo de segurança (fundoPrev do desenho), em JPEG, guardado por situação e código ── */
    let fundos = {};
    function fundo(ok, cod0) {
        const cod = cod0 || COD_VAZIO, k = (ok ? 'o' : 'p') + cod;
        if (fundos[k]) { return fundos[k]; }
        if (Object.keys(fundos).length > 12) { fundos = {}; }
        const W = 800, H = 1131, c = document.createElement('canvas');
        c.width = W * 2; c.height = H * 2;
        const g = c.getContext('2d');
        g.scale(2, 2);
        const iris = g.createLinearGradient(0, 0, W, H);
        ['#f6fafd', '#f6fbf7', '#fdfaf2', '#f9f6fc', '#f6fafd'].forEach((x, i) => iris.addColorStop(i / 4, x));
        g.fillStyle = iris; g.fillRect(0, 0, W, H);
        g.lineWidth = .5;
        for (let j = -2; j < 60; j++) {
            const y0 = j * H / 56;
            g.strokeStyle = j % 2 ? 'rgba(10,111,160,.1)' : 'rgba(30,123,69,.08)';
            g.beginPath();
            for (let x = 0; x <= W; x += 6) { const y = y0 + 11 * Math.sin(x * .016 + j * .32) + 4 * Math.sin(x * .04 + j * .7); if (x) { g.lineTo(x, y); } else { g.moveTo(x, y); } }
            g.stroke();
        }
        g.strokeStyle = 'rgba(11,53,80,.05)'; g.lineWidth = .4;
        for (let j = 0; j < 30; j++) {
            const a0 = j / 30 * Math.PI * 2;
            g.beginPath();
            for (let i = 0; i <= 160; i++) { const t = i / 160 * Math.PI * 2, r = 250 * (.66 + .34 * Math.sin(11 * t)), a = t + a0, x = 400 + r * Math.cos(a), y = 570 + r * Math.sin(a); if (i) { g.lineTo(x, y); } else { g.moveTo(x, y); } }
            g.closePath(); g.stroke();
        }
        g.save(); g.translate(400, 565); g.rotate(-Math.PI / 6.4); g.font = 'bold 15px Arial'; g.fillStyle = 'rgba(11,53,80,.045)';
        const lin = T('marca_dagua').repeat(9);
        for (let y = -840, i = 0; y < 840; y += 50, i++) { g.fillText(lin, -1100 + (i % 2) * 70, y); }
        g.restore();
        g.strokeStyle = '#0b3550'; g.lineWidth = 1.6; g.strokeRect(9, 9, W - 18, H - 18); g.lineWidth = .7; g.strokeRect(27, 27, W - 54, H - 54);
        const bd = (x0, y0, x1, y1) => {
            const L = Math.hypot(x1 - x0, y1 - y0), ux = (x1 - x0) / L, uy = (y1 - y0) / L;
            [[0, '#0a6fa0'], [2.1, '#1e7b45'], [4.2, '#8a6d1f']].forEach(([ph, cor]) => {
                g.strokeStyle = cor; g.lineWidth = .6; g.beginPath();
                for (let t = 0; t <= L; t += 2) { const o = 6.5 * Math.sin(t * .12 + ph), x = x0 + ux * t - uy * o, y = y0 + uy * t + ux * o; if (t) { g.lineTo(x, y); } else { g.moveTo(x, y); } }
                g.stroke();
            });
        };
        bd(18, 18, W - 18, 18); bd(W - 18, 18, W - 18, H - 18); bd(W - 18, H - 18, 18, H - 18); bd(18, H - 18, 18, 18);
        g.save(); g.translate(756, 38);
        for (let y = 0; y + 60 < H - 76; y += 80) {
            const gr = g.createLinearGradient(0, y, 10, y + 50);
            ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'].forEach((x, i) => gr.addColorStop(i / 4, x));
            g.fillStyle = gr; g.fillRect(0, y + 3, 12, 70);
            g.save(); g.translate(8.6, y + 71); g.rotate(-Math.PI / 2); g.fillStyle = '#0b3550'; g.font = 'bold 7.2px Arial'; g.fillText(T('faixa'), 0, 0); g.restore();
        }
        g.restore();
        g.fillStyle = '#0b3550'; g.font = '4.8px Arial';
        let mt = T('microtexto', { codigo: cod }).repeat(9);
        while (g.measureText(mt).width > 710) { mt = mt.slice(0, -1); }
        g.fillText(mt, 36, H - 31);
        const sr = 46;
        g.save(); g.translate(690, 1040); g.beginPath();
        for (let i = 0; i < 64; i++) { const a0 = i / 64 * Math.PI * 2, r = i % 2 ? sr : sr * .914; g[i ? 'lineTo' : 'moveTo'](r * Math.cos(a0), r * Math.sin(a0)); }
        g.closePath();
        let fg;
        if (g.createConicGradient) { fg = g.createConicGradient(1.2, 0, 0); ['#7fd0e8', '#b4e7a8', '#f4e08a', '#f2b0c8', '#b7a6f0', '#7fd0e8'].forEach((x, i) => fg.addColorStop(i / 5, x)); } else { fg = '#bfe3ef'; }
        g.fillStyle = fg; g.fill();
        g.strokeStyle = 'rgba(11,53,80,.4)'; g.lineWidth = .45;
        for (let j = 0; j < 36; j++) { g.save(); g.rotate(j / 36 * Math.PI * 2); g.beginPath(); g.ellipse(0, 0, sr - 6.4, sr * .3, 0, 0, Math.PI * 2); g.stroke(); g.restore(); }
        g.fillStyle = 'rgba(255,255,255,.85)'; g.beginPath(); g.arc(0, 0, sr * .55, 0, Math.PI * 2); g.fill();
        g.textAlign = 'center';
        if (ok) { g.strokeStyle = '#1e7b45'; g.lineWidth = sr * .085; g.beginPath(); g.moveTo(-sr * .26, 0); g.lineTo(-sr * .07, sr * .2); g.lineTo(sr * .27, -sr * .2); g.stroke(); } else { g.font = 'bold 7px Arial'; g.fillStyle = '#8a6200'; g.fillText(T('previa'), 0, 2.5); }
        seloAneis(g, sr, cod);
        g.restore();
        return (fundos[k] = c.toDataURL('image/jpeg', .88));
    }

    /* ── QR do rodapé (qrcode-generator de public/js/vendor) ── */
    const qrs = {};
    function qr(cod) {
        if (qrs[cod]) { return qrs[cod]; }
        if (!window.qrcode) { return ''; }
        const q = window.qrcode(0, 'M');
        q.addData(QR_VALIDAR + (cod ? '/' + cod : ''));
        q.make();
        return (qrs[cod] = q.createDataURL(6, 0));
    }

    function blocoV(cod0, hash) {
        const cod = cod0 || COD_VAZIO, q = qr(cod0 || ''), hs = hash || '';
        const ser = cod.split('').map((ch, i) => '<span style="font-size:' + (14 + i * .55).toFixed(1) + 'px">' + esc(ch) + '</span>').join('');
        return '<div class="bjaV"><div class="lin">' + (q ? '<img alt="' + esc(T('qr')) + '" src="' + q + '">' : '')
            + '<div class="tx"><b>' + esc(T('codigo')) + '</b><div class="cod">' + ser + '</div><div class="sha">SHA-256 '
            + (hs ? esc(hs.slice(0, 32)) + '<br>' + esc(hs.slice(32)) : esc(T('sha_pendente'))) + '</div><div>' + esc(T('confira')) + '</div></div></div></div>';
    }

    /* ── Folha em HTML ──
       o = { blocos, sign: [{nome, situacao, assinadoEm, rubrica}] | null, logo (URL), layout ('' | '2col'), lateral,
             limpa (folha branca), cod, hash } */
    function html(o) {
        const bl = o.blocos || [], sign = o.sign || [], L = !!o.limpa, vB = L ? '' : blocoV(o.cod, o.hash), lg = o.logo || '';
        const ok = !!(sign.length && sign.every(x => x.situacao === 'assinado'));
        const fundoAttr = L ? '' : ' data-bja-fundo="' + (ok ? '1' : '0') + '"';
        const assH = b => {
            const s = sign[b.i], assinado = !!(s && s.situacao === 'assinado');
            return '<div class="bjaAss">' + (assinado && s.rubrica ? '<img alt="' + esc(T('assinatura')) + '" src="' + esc(s.rubrica) + '">' : '')
                + (assinado ? '<div class="bjaSt">' + esc(T('assinado_em', { data: dataHora(s.assinadoEm) })) + '</div>' : '<div class="bjaAssVao"></div>')
                + '<div class="l">' + marcar(b.r || (s ? s.nome : '') || T('assinatura')) + '</div></div>';
        };
        const pg = b => '<img class="bjaPgImg" alt="' + esc(T('pagina')) + '" data-bja-pg="' + (parseInt(b.n, 10) || 1) + '">';

        if (o.layout === '2col') {
            const num = bl.find(b => b.k === 'n'), tit = bl.find(b => b.k === 't'), intro = bl.filter(b => b.full), resto = bl.filter(b => b.k !== 'n' && b.k !== 't' && !b.full);
            return '<div class="bjaPag bjaC' + (L ? ' is-limpa' : '') + '"' + fundoAttr + '><div class="lat">' + marcar(o.lateral || '') + '</div>'
                + (num ? '<div class="num">' + marcar(num.x) + '</div>' : '')
                + '<div class="cab">' + (lg ? '<img alt="' + esc(T('logo_escritorio')) + '" src="' + esc(lg) + '">' : '') + '<div class="tit">' + marcar(tit ? tit.x : '') + '</div></div>'
                + intro.map(b => '<p>' + marcar(b.x) + '</p>').join('')
                + '<div class="cols">' + resto.map(b => b.k === 'h' ? '<div class="hd">' + marcar(b.x) + '</div>' : b.k === 'd' ? '<p class="bjaFd2">' + marcar(b.x) + '</p>' : b.k === 'a' ? assH(b) : b.k === 'pg' ? pg(b) : '<p>' + marcar(b.x) + '</p>').join('') + '</div>'
                + vB + '</div>';
        }

        let h = '<div class="bjaPag' + (L ? '' : ' com-rodape') + '"' + fundoAttr + '>' + (lg ? '<img class="bjaFlogo" alt="' + esc(T('logo')) + '" src="' + esc(lg) + '">' : '');
        bl.forEach(b => {
            if (b.k === 't') { h += '<div class="bjaFt">' + marcar(b.x) + '</div>'; }
            else if (b.k === 's') { h += '<div class="bjaFs">' + marcar(b.x) + '</div>'; }
            else if (b.k === 'h') { h += '<div class="bjaFh">' + marcar(b.x) + '</div>'; }
            else if (b.k === 'l') { h += '<div class="bjaFl">' + marcar(b.x) + '</div>'; }
            else if (b.k === 'd') { h += '<p class="bjaFd">' + marcar(b.x) + '</p>'; }
            else if (b.k === 'p') { h += '<p>' + marcar(b.x) + '</p>'; }
            else if (b.k === 'pg') { h += pg(b); }
            else if (b.k === 'a') { h += assH(b); }
        });
        return h + vB + '</div>';
    }

    /* ── pdf.js: o worker vem do mesmo cdnjs, conferido pelo hash (fetch com integrity) e servido como blob ── */
    let pronto = null;
    function prepararPdf() {
        if (!pronto) {
            pronto = (window.pdfjsLib ? Promise.resolve() : Promise.reject(new Error('pdfjs')))
                .then(() => fetch(PDFJS_WORKER, { integrity: PDFJS_WORKER_SRI, mode: 'cors', credentials: 'omit' }))
                .then(r => { if (!r.ok) { throw new Error('worker ' + r.status); } return r.blob(); })
                .then(b => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(new Blob([b], { type: 'text/javascript' })); })
                .catch(e => { pronto = null; throw e; });
        }
        return pronto;
    }

    /* fonte = { chave, obter: () => Promise<ArrayBuffer> } (arquivo local ou URL do servidor). */
    const pdfs = new Map();
    const imagens = new Map();
    function abrirPdf(fonte) {
        if (!pdfs.has(fonte.chave)) {
            pdfs.set(fonte.chave, prepararPdf()
                .then(() => fonte.obter())
                .then(buf => window.pdfjsLib.getDocument({ data: new Uint8Array(buf), isEvalSupported: false }).promise)
                .catch(e => { pdfs.delete(fonte.chave); throw e; }));
        }
        return pdfs.get(fonte.chave);
    }

    function pagina(fonte, n) {
        if (!fonte) { return Promise.resolve(''); }
        const k = fonte.chave + '#' + n;
        if (!imagens.has(k)) {
            imagens.set(k, abrirPdf(fonte)
                .then(pdf => pdf.getPage(n))
                .then(pg => {
                    const v = pg.getViewport({ scale: 2.4 }), cv = document.createElement('canvas');
                    cv.width = v.width; cv.height = v.height;
                    return pg.render({ canvasContext: cv.getContext('2d'), viewport: v }).promise.then(() => cv.toDataURL('image/jpeg', .85));
                })
                .catch(e => { imagens.delete(k); console.error(e); return ''; }));
        }
        return imagens.get(k);
    }

    /* Fonte a partir de uma URL do servidor (PDF enviado, já salvo). */
    function fonteDaUrl(endereco) {
        return {
            chave: 'u:' + endereco,
            obter: () => fetch(endereco, { credentials: 'same-origin' }).then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.arrayBuffer(); }),
        };
    }

    /* Desenha a folha no elemento .bjaPv (pintar do desenho): só refaz quando o HTML muda. */
    function pintar(el, o) {
        const h = html(o);
        if (el._bjaH === h) { return; }
        el._bjaH = h;
        el.innerHTML = h;
        const pag = el.querySelector('.bjaPag');
        if (pag && pag.hasAttribute('data-bja-fundo')) {
            pag.style.backgroundImage = 'url(' + fundo(pag.getAttribute('data-bja-fundo') === '1', o.cod) + ')';
        }
        const c = el.querySelector('.bjaC');
        if (c && c.offsetWidth > 620) {
            let fs = 12.6;
            while (fs > 8.6 && c.offsetHeight > c.offsetWidth * 1.4145 + 1) { fs -= .2; c.style.fontSize = fs + 'px'; }
        }
        el.querySelectorAll('img[data-bja-pg]').forEach(img => {
            pagina(o.fonte, parseInt(img.getAttribute('data-bja-pg'), 10)).then(src => { if (src && img.isConnected) { img.src = src; } });
        });
    }

    /* Esquece a folha desenhada (o elemento foi reaproveitado em outra tela). */
    function limpar(el) { if (el) { el._bjaH = null; el.innerHTML = ''; } }

    window.BJAFolha = { html, pintar, limpar, abrirPdf, fonteDaUrl, prepararPdf };
})();
