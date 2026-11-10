/* =============================================================================
   assinatura-validar.js: "Validar documento assinado" da Assinatura de documentos (etapa 3, bloco 2; inventário A7).
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (aba dc:159-163, câmera dc:275,
   código no #hash dc:299, codQr, achouQr, pararCam, abrirCam e lerFotoQr dc:338-343, som dc:380, avisar dc:305 e
   validar dc:457).
   Contrato: docs/specs/assinatura-documentos-etapa-3.md (E3-7, E3-8, E3-14 e E3-16).

   Um só componente para a aba Validar da tela interna e para a página pública /assinar/validar: o Twig
   (assinatura_eletronica/_validar.html.twig) monta a raiz [data-bja-validar] com o fixo; este arquivo liga cada raiz
   que achar ao carregar. A procura vai sempre à consulta pública (data-url-consulta), com o código aparado e em
   maiúsculas: assim a aba acha o código de outro escritório, como a página pública. Com data-resultado (a página com
   código), o resultado já veio do servidor e é desenhado ao abrir.

   A janela da câmera fica na raiz da página, fora das abas e do cartão (dc:275): o Twig a monta à parte e o botão
   "Ler QR Code com a câmera" aponta para ela (aria-controls). Aberta, o foco entra nela e não sai (o foco que escapa
   volta, dando a volta pelo lado de onde veio); fechada, volta a quem a abriu. A câmera para sempre que a janela deixa
   de estar visível, e cada abertura é marcada: o pedido de permissão que responde depois de um Cancelar dá stop no
   próprio fluxo e não assume a janela.

   Câmera: getUserMedia com a câmera de trás, um ciclo a cada 180 ms, BarcodeDetector primeiro e, sem ele, o jsQR
   (public/js/vendor/jsqr) numa cópia de 640 px de largura. Foto: o jsQR nas larguras 1600, 2400 e 900. Ao achar um
   código BJA: para a câmera, fecha a janela, preenche o campo, valida, toca dois tons (1568 e 2093 Hz) e vibra 40 ms.
   ============================================================================= */
(function () {
    'use strict';

    if (!window.BJ_T) { return; }

    const T = (chave, p) => BJ_T.t(['assinatura_eletronica_tela', 'js', 'validar', chave].join('.'), p);
    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' }[c]));
    const lerJson = (texto, padrao) => { try { const v = JSON.parse(texto || ''); return v === undefined ? padrao : v; } catch (e) { return padrao; } };
    const idioma = () => (window.BJFormato ? window.BJFormato.idioma() : 'pt-BR');
    const dataHora = v => { if (!v) { return ''; } const d = new Date(v); return isNaN(d.getTime()) ? '' : d.toLocaleString(idioma()); };

    /* codQr do desenho: o primeiro código BJA dentro do texto lido, em maiúsculas. */
    const RE_CODIGO = /BJA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/i;
    const codQr = t => { const m = RE_CODIGO.exec(String(t || '')); return m ? m[0].toUpperCase() : ''; };

    const CICLO_MS = 180;
    const LARGURA_CAMERA = 640;
    const LARGURAS_FOTO = [1600, 2400, 900];
    const AVISO_MS = 3400;

    /* Uma linha por signatário (vSign do desenho): "{nome}: assinado em {data}" ou "{nome}: {situação}". */
    function linhaDoSignatario(s) {
        return s.assinadoEm
            ? T('assinado_em', { nome: s.nome, data: dataHora(s.assinadoEm) })
            : T('signatario_situacao', { nome: s.nome, situacao: s.situacao });
    }

    function htmlDoResultado(d) {
        if (!d) {
            return '<div class="bjaValErro"><b>' + esc(T('nao_localizado')) + '</b> ' + esc(T('confira')) + '</div>';
        }
        return '<div class="bjaValOk"><b>' + esc(T('localizado', { nome: d.nome })) + '</b><br>'
            + esc(T('situacao', { situacao: d.situacao })) + '<br>'
            + esc(T('hash')) + ' <code>' + esc(d.hash || '') + '</code>'
            + (Array.isArray(d.signatarios) ? d.signatarios : []).map(s => '<div>' + esc(linhaDoSignatario(s)) + '</div>').join('')
            + '</div>';
    }

    const FOCAVEIS = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    function montar(raiz) {
        if (!raiz || raiz.bjaValidar) { return; }

        const campo = raiz.querySelector('.bjaValCod');
        const res = raiz.querySelector('.bjaValRes');
        const botaoCam = raiz.querySelector('[data-bja-val="camera"]');
        const janela = botaoCam ? document.getElementById(botaoCam.getAttribute('aria-controls') || '') : null;
        const aviso = raiz.querySelector('.bjaValAviso');
        if (!campo || !res || !janela || !aviso) { return; }
        raiz.bjaValidar = { validarCodigo: preencherEValidar };

        const video = janela.querySelector('video');
        const msg = janela.querySelector('.bjaValCamMsg');
        const consulta = raiz.dataset.urlConsulta || '';
        let seq = 0, camId = 0, pedido = 0, fluxo = null, origem = null, ctxSom = null, tAviso = null;

        function mostrar(d) { res.innerHTML = htmlDoResultado(d); }

        /* avisar do desenho, em vermelho (#a3232b): some em 3,4 s, por cima da janela da câmera. */
        function avisar(texto) {
            aviso.innerHTML = '<div class="bjaToast is-erro" role="status">' + esc(texto) + '</div>';
            clearTimeout(tAviso);
            tAviso = setTimeout(() => { aviso.innerHTML = ''; }, AVISO_MS);
        }

        /* validar do desenho (dc:457): aparado, maiúsculo, igualdade exata no servidor. Vazio não procura. */
        async function validar() {
            const c = campo.value.trim().toUpperCase(), meu = ++seq;
            if (!c) { mostrar(null); return; }
            try {
                const r = await fetch(consulta + '?codigo=' + encodeURIComponent(c), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                let j = null;
                try { j = await r.json(); } catch (e) { j = null; }
                if (meu !== seq) { return; }
                if (r.ok || r.status === 404) { mostrar(j && j.documento ? j.documento : null); return; }
                avisar((j && j.erro) || T('erro'));
            } catch (e) {
                if (meu === seq) { avisar(T('erro')); }
            }
        }

        /* Código vindo de fora (o #hash da tela, dc:299): no campo e validado. */
        function preencherEValidar(c) { campo.value = c; validar(); }

        /* O som nasce no clique (câmera ou foto), para o navegador deixar tocar quando o código for achado. */
        function prepararSom() {
            try { const AC = window.AudioContext || window.webkitAudioContext; if (AC && !ctxSom) { ctxSom = new AC(); } } catch (e) { ctxSom = null; }
        }
        function som() {
            try {
                const c = ctxSom;
                if (!c) { return; }
                if (c.state === 'suspended') { c.resume(); }
                const tom = (f, i, dur, vol) => {
                    const t = c.currentTime + i, o = c.createOscillator(), g = c.createGain();
                    o.frequency.value = f;
                    g.gain.setValueAtTime(.0001, t);
                    g.gain.exponentialRampToValueAtTime(vol, t + .012);
                    g.gain.exponentialRampToValueAtTime(.0001, t + dur);
                    o.connect(g).connect(c.destination);
                    o.start(t); o.stop(t + dur + .05);
                };
                tom(1568, 0, .55, .26);
                tom(2093, .1, .85, .3);
            } catch (e) { /* sem som, segue */ }
        }

        /* Visível de fato: sem hidden, na página e sem ancestral escondido (sem caixa, getClientRects vem vazio). */
        const aberta = () => !janela.hidden && janela.isConnected && janela.getClientRects().length > 0;
        const focaveis = () => Array.from(janela.querySelectorAll(FOCAVEIS)).filter(x => !x.disabled && x.getClientRects().length > 0);
        function focarNaJanela(peloFim) {
            const f = focaveis(), alvo = peloFim ? f[f.length - 1] : f[0];
            if (alvo) { alvo.focus(); }
        }

        function pararCam() {
            camId = 0;
            if (fluxo) { fluxo.getTracks().forEach(t => t.stop()); fluxo = null; }
            video.srcObject = null;
        }
        /* Fechar invalida o pedido de câmera em curso (pedido++) e devolve o foco a quem abriu a janela. */
        function fecharCam() {
            pedido++;
            pararCam();
            const comFoco = janela.contains(document.activeElement);
            janela.hidden = true;
            if (comFoco && origem && origem.isConnected && typeof origem.focus === 'function') { origem.focus(); }
            origem = null;
        }

        /* achouQr do desenho: para a câmera, fica na aba Validar com o código, valida, som e vibração. */
        function achou(c) {
            fecharCam();
            campo.value = c;
            validar();
            som();
            try { if (navigator.vibrate) { navigator.vibrate(40); } } catch (e) { /* sem vibração */ }
        }

        function detector() {
            try { return window.BarcodeDetector ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null; } catch (e) { return null; }
        }

        /* abrirCam do desenho: as três mensagens (procurando, QR de outra origem, câmera indisponível). */
        async function abrirCam() {
            const meu = ++pedido;
            prepararSom();
            pararCam();
            msg.textContent = T('cam_procurando');
            if (janela.hidden) { origem = document.activeElement; }
            janela.hidden = false;
            focarNaJanela(false);
            try {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { throw new Error('sem'); }
                const st = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                /* Pedido velho (Cancelar e abrir de novo durante a permissão) ou janela que sumiu: stop no próprio fluxo. */
                if (meu !== pedido || !aberta()) {
                    st.getTracks().forEach(t => t.stop());
                    if (meu === pedido) { fecharCam(); }
                    return;
                }
                fluxo = st;
                video.srcObject = st;
                try { const p = video.play(); if (p && p.catch) { p.catch(() => {}); } } catch (e) { /* o autoplay segue */ }
                lerCamera(meu);
            } catch (e) {
                if (meu === pedido && aberta()) { msg.textContent = T('cam_indisponivel'); }
            }
        }

        function lerCamera(id) {
            camId = id;
            const cv = document.createElement('canvas'), g = cv.getContext('2d', { willReadFrequently: true }), bd = detector();
            const roda = async () => {
                if (camId !== id) { return; }
                /* A janela deixou de estar visível (escondida por fora): a câmera para e a luz apaga. */
                if (!aberta()) { fecharCam(); return; }
                if (video.videoWidth) {
                    let tx = '';
                    try {
                        if (bd) {
                            const r = await bd.detect(video);
                            tx = r[0] && r[0].rawValue;
                        } else if (window.jsQR) {
                            const w = LARGURA_CAMERA, h = Math.round(w * video.videoHeight / video.videoWidth);
                            cv.width = w; cv.height = h;
                            g.drawImage(video, 0, 0, w, h);
                            const q = window.jsQR(g.getImageData(0, 0, w, h).data, w, h);
                            tx = q && q.data;
                        }
                    } catch (e) { tx = ''; }
                    if (camId !== id) { return; }
                    if (tx) {
                        const c = codQr(tx);
                        if (c) { achou(c); return; }
                        msg.textContent = T('cam_outro');
                    }
                }
                setTimeout(roda, CICLO_MS);
            };
            roda();
        }

        /* lerFotoQr do desenho: a foto reduzida a 1600, 2400 e 900 px no lado maior até o jsQR achar. */
        async function lerFoto(f) {
            if (!f) { return; }
            const u = URL.createObjectURL(f);
            try {
                const i = await new Promise((ok, no) => { const x = new Image(); x.onload = () => ok(x); x.onerror = no; x.src = u; });
                let tx = '';
                for (const lim of LARGURAS_FOTO) {
                    const k = Math.min(1, lim / Math.max(i.width, i.height)), cv = document.createElement('canvas');
                    cv.width = i.width * k; cv.height = i.height * k;
                    const g = cv.getContext('2d');
                    g.drawImage(i, 0, 0, cv.width, cv.height);
                    const q = window.jsQR && window.jsQR(g.getImageData(0, 0, cv.width, cv.height).data, cv.width, cv.height);
                    if (q) { tx = q.data; break; }
                }
                const c = codQr(tx);
                if (c) { achou(c); } else { avisar(T('foto_sem_qr')); }
            } catch (e) {
                avisar(T('foto_ilegivel'));
            } finally {
                URL.revokeObjectURL(u);
            }
        }

        /* Os botões do componente e os da janela, que fica fora dele (raiz da página). */
        function aoClicar(ev) {
            const a = ev.target.closest && ev.target.closest('[data-bja-val]');
            if (!a || !ev.currentTarget.contains(a)) { return; }
            const qual = a.getAttribute('data-bja-val');
            if (qual === 'validar') { validar(); } else if (qual === 'camera') { abrirCam(); } else if (qual === 'fechar') { fecharCam(); } else if (qual === 'foto') { prepararSom(); }
        }
        function aoEscolherFoto(ev) {
            const t = ev.target;
            if (!t.matches || !t.matches('input[type="file"][data-bja-val="foto"]')) { return; }
            const f = t.files && t.files[0];
            t.value = '';
            lerFoto(f);
        }
        [raiz, janela].forEach(n => { n.addEventListener('click', aoClicar); n.addEventListener('change', aoEscolherFoto); });
        /* setVCod do desenho: digitar apaga o resultado anterior. */
        campo.addEventListener('input', () => { seq++; res.innerHTML = ''; });
        /* A janela da câmera fecha com Esc, como as janelas da tela. */
        document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && !janela.hidden) { fecharCam(); } });
        /* Foco preso na janela aberta: o que escapa (Tab, Shift+Tab, clique) volta. Escapou para trás da janela no
           documento, volta no último controle; para a frente, no primeiro (a volta do Tab). */
        document.addEventListener('focusin', ev => {
            if (janela.hidden || janela.contains(ev.target) || !(ev.target instanceof Node)) { return; }
            focarNaJanela(!!(janela.compareDocumentPosition(ev.target) & Node.DOCUMENT_POSITION_PRECEDING));
        });

        if (raiz.hasAttribute('data-resultado')) {
            const d = lerJson(raiz.dataset.resultado, null);
            mostrar(d && typeof d === 'object' ? d : null);
        }
    }

    /* Código vindo de fora (o #hash da tela, dc:299): o assinatura.js abre a aba e pede aqui a validação. */
    function validarCodigo(raiz, c) {
        montar(raiz);
        if (raiz && raiz.bjaValidar && c) { raiz.bjaValidar.validarCodigo(c); }
    }

    document.querySelectorAll('[data-bja-validar]').forEach(montar);
    window.BJAValidar = { montar: montar, codQr: codQr, validarCodigo: validarCodigo };
})();
