/* =============================================================================
   assinatura-publica.js: tela do signatário (/assinar/{token}) da Assinatura de documentos, etapa 2.
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (blocos tAss, aBloq, aForm, mRec e
   ovOk; funções abrirAss, rubRef, rubrica, som, assinar e confRecusa).
   Contrato: docs/specs/assinatura-documentos-etapa-2.md, seções 2, 5.2, 5.3 e 7.
   Inventário: docs/design/redesign-08-11-26/inventario-assinatura-etapa2-0911.md (regiões F a M).

   O template (assinatura_eletronica/publica.html.twig) entrega só a raiz com data-estado (o JSON Publico da seção
   5.3), data-csrf e as URLs de POST em data-url-*; a página inteira é desenhada aqui: cabeçalho, cartão de bloqueio ou
   formulário Simples ao lado da folha, janela de recusa, tela "Assinatura registrada" e avisos. A folha é a mesma da
   tela interna (assinatura-folha.js).

   O código de 6 dígitos chega só por e-mail: nunca aparece nesta tela. Na prévia do escritório (previa: true) não há
   URL de POST, e Enviar código, Assinar e Recusar assinatura ficam visíveis e desabilitados. Sem código BJA e sem
   "Baixar PDF com selo" (etapa 3): a folha fica no estado sem código do desenho e a tela verde sai sem os dois.
   ============================================================================= */
(function () {
    'use strict';

    const raiz = document.getElementById('bjaPublica') || document.querySelector('[data-estado]');
    if (!raiz || !window.BJAFolha || !window.BJ_T) { return; }

    const F = window.BJAFolha;
    const T = (chave, p) => BJ_T.t(['assinatura_eletronica_publica', 'js', chave].join('.'), p);
    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' }[c]));
    const lerJson = (texto, padrao) => { try { const v = JSON.parse(texto || ''); return v == null ? padrao : v; } catch (e) { return padrao; } };
    const digitos = v => String(v == null ? '' : v).replace(/\D/g, '');
    const ds = raiz.dataset;
    const csrf = ds.csrf || '';
    // O nome curto da rota (codigo, assinar, recusa) ou o nome com o prefixo publica_: os dois servem.
    const url = {
        codigo: ds.urlCodigo || ds.urlPublicaCodigo || '',
        assinar: ds.urlAssinar || ds.urlPublicaAssinar || '',
        recusa: ds.urlRecusa || ds.urlPublicaRecusa || '',
    };
    // Logo branca do cabeçalho (a mesma imagem do desenho, bluejus-logo-nav-branco.png).
    const MARCA = ds.urlMarca || '/images/logo/bluejus-white-transparent-nav.png';

    /* Ícone de cada bloqueio (desenho, linha 437); "concluido" usa o de quem já assinou. */
    const BLOQUEIOS = {
        ja_assinado: 'bi-check-circle',
        recusado: 'bi-x-circle',
        cancelado: 'bi-slash-circle',
        prazo_encerrado: 'bi-clock',
        aguardando_anterior: 'bi-hourglass-split',
        invalido: 'bi-link-45deg',
    };

    function normalizar(e) {
        const o = Object.assign({ estado: 'invalido', previa: false, anterior: null, documento: null, lugares: [], eu: null, aceite: null, codigo: null }, e && typeof e === 'object' ? e : {});
        o.lugares = Array.isArray(o.lugares) ? o.lugares : [];
        o.aceite = o.aceite || { versao: 0, texto: '' };
        o.codigo = o.codigo || { enviado: false, validoAte: null, bloqueadoAte: null };
        return o;
    }

    let est = normalizar(lerJson(ds.estado, null));
    const eu0 = est.eu || {};
    /* Estado do formulário: nome e CPF cadastrados preenchidos (decisão B), aba Desenhar, código e aceite vazios. */
    const a = { nome: String(eu0.nome || ''), cpf: String(eu0.cpf || ''), tab: 'des', cod: '', li: false, erro: '', pediu: !!est.codigo.enviado, recusa: false, motivo: '' };
    let canv = null, vazio = true, ctxSom = null, tToast = null;

    const comFormulario = () => est.estado === 'pronto' && !!est.documento && !!est.eu;

    /* ── Servidor: JSON com o token CSRF da página no cabeçalho ─────────── */
    async function postar(endereco, corpo) {
        const h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf };
        if (corpo != null) { h['Content-Type'] = 'application/json'; }
        const r = await fetch(endereco, { method: 'POST', credentials: 'same-origin', headers: h, body: corpo == null ? undefined : JSON.stringify(corpo) });
        let j = null;
        try { j = await r.json(); } catch (e) { j = null; }
        if (!r.ok || !j || j.ok === false) { const erro = new Error('HTTP ' + r.status); erro.texto = (j && j.erro) || ''; throw erro; }
        return j;
    }

    /* ── Página ─────────────────────────────────────────────────────────── */
    function desenhar() {
        raiz.classList.add('bjaPub');
        /* Tema comemorativo (M18): o tema-comemorativo.js põe os ícones (.bjtDeco) no [data-bjt-cabecalho] e o brilho
           no [data-bjt-logo] uma vez só; ao redesenhar, os dois passam para o cabeçalho novo. */
        const deco = raiz.querySelector('[data-bjt-cabecalho] > .bjtDeco');
        const brilho = !!raiz.querySelector('[data-bjt-logo].bjt-logo-anim');
        raiz.innerHTML = cabecalho()
            + '<main class="bjaPubMain">' + (comFormulario() ? formulario() : bloqueio()) + '</main>'
            + '<div class="bjaCamada" data-bja-cam="modal"></div><div class="bjaCamada" data-bja-cam="ok"></div><div class="bjaCamada" data-bja-cam="toast"></div>';
        const cab = raiz.querySelector('[data-bjt-cabecalho]');
        if (deco && cab) { cab.insertBefore(deco, cab.firstChild); }
        if (brilho) { raiz.querySelector('[data-bjt-logo]').classList.add('bjt-logo-anim'); }
        canv = null; vazio = true;
        if (comFormulario()) {
            ligarCanvas();
            requestAnimationFrame(pintarFolha);
        }
    }

    const camada = nome => raiz.querySelector('[data-bja-cam="' + nome + '"]');

    /* Cabeçalho do signatário (F2 a F6): logo, separador, nome do documento e, na prévia, "Fechar pré-visualização". */
    function cabecalho() {
        const d = est.documento;
        return '<header class="bjaPubCab" data-bjt-cabecalho><img class="bjaPubMarca" src="' + esc(MARCA) + '" alt="BlueJus" data-bjt-logo><span class="bjaOc bjaPubSep"></span>'
            + '<span class="bjaPubNome">' + esc(d ? d.nome : '') + '</span>'
            + (est.previa ? '<button type="button" class="bjaB bjaPubFechar" data-bja-acao="fechar-previa">' + esc(T('fechar_previa')) + '</button>' : '')
            + '</header>';
    }

    /* Cartão de bloqueio (G1 a G9). Link inválido não tem tela no desenho: o mesmo cartão, com o texto mínimo. */
    function bloqueio() {
        const k0 = est.estado === 'concluido' ? 'ja_assinado' : est.estado;
        const k = BLOQUEIOS[k0] ? k0 : 'invalido';
        return '<div class="bjaCard bjaBloq"><i class="bi ' + BLOQUEIOS[k] + '"></i>'
            + '<div class="bjaBloqT">' + esc(T(['bloqueio', k, 'titulo'].join('.'))) + '</div>'
            + '<div class="bjaBloqD">' + esc(T(['bloqueio', k, 'texto'].join('.'), { nome: est.anterior || '' })) + '</div></div>';
    }

    /* Formulário Simples (H1 a H20): a folha antes no DOM, o painel de 420px depois. */
    function formulario() {
        const d = est.documento, eu = est.eu, dis = est.previa ? ' disabled' : '', msg = String(d.mensagem || '').trim();
        const ola = esc(T('ola')).replace('{nome}', () => '<b>' + esc(eu.nome) + '</b>');
        return '<div class="bjaGrid">'
            + '<div class="bjaCent"><div class="bjaPv" data-folha="a"></div></div>'
            + '<div class="bjaLat"><section class="bjaCard bjaOculto"><div class="bjaHd"><i class="bi bi-pen"></i>' + esc(T('assinar_documento')) + '</div>'
            + '<div class="bjaPainel">'
            + '<div class="bjaOla">' + ola + ' ' + esc(msg || T('sem_mensagem')) + '</div>'
            + '<label class="bjaL">' + esc(T('nome_completo')) + '<input class="bjaF" type="text" data-bja-a="nome" value="' + esc(a.nome) + '"></label>'
            + '<label class="bjaL">' + esc(T('cpf')) + '<input class="bjaF" type="text" data-bja-a="cpf" placeholder="' + esc(T('cpf_exemplo')) + '" value="' + esc(a.cpf) + '"></label>'
            + '<div class="bjaRubCx"><div class="bjaAbasAss">' + abaHtml('des', T('desenhar')) + abaHtml('txt', T('digitar')) + '</div><div data-bja-rub>' + areaRubrica() + '</div></div>'
            + '<div class="bjaCodLin"><label class="bjaL">' + esc(T('codigo_rotulo')) + '<input class="bjaF bjaCodIn" type="text" inputmode="numeric" maxlength="6" data-bja-a="cod" value="' + esc(a.cod) + '"></label>'
            + '<button type="button" class="bjaB bjaCodBt" data-bja-acao="codigo"' + dis + '>' + esc(a.pediu ? T('reenviar') : T('enviar_codigo')) + '</button></div>'
            + '<label class="bjaAceite"><input type="checkbox" data-bja-a="li"' + (a.li ? ' checked' : '') + '><span>' + esc(est.aceite.texto) + '</span></label>'
            + '<div class="bjaErroA" data-bja-erro' + (a.erro ? '' : ' hidden') + '>' + esc(a.erro) + '</div>'
            + '<button type="button" class="bjaP bjaAssinar" data-bja-acao="assinar"' + dis + '><i class="bi bi-pen"></i>' + esc(T('assinar')) + '</button>'
            + '<button type="button" class="bjaB bjaR" data-bja-acao="recusar"' + dis + '>' + esc(T('recusar_assinatura')) + '</button>'
            + '</div></section></div></div>';
    }

    function abaHtml(k, rotulo) {
        return '<button type="button" class="bjaB bjaAbaAss' + (a.tab === k ? ' is-on' : '') + '" data-bja-tab="' + k + '" aria-pressed="' + (a.tab === k) + '">' + esc(rotulo) + '</button>';
    }

    function areaRubrica() {
        return a.tab === 'des'
            ? '<canvas class="bjaRubTela" width="760" height="240" data-bja-canvas aria-label="' + esc(T('area_desenho')) + '"></canvas><button type="button" class="bjaB bjaN bjaLimpar" data-bja-acao="limpar">' + esc(T('limpar')) + '</button>'
            : '<div class="bjaDigitada" data-bja-digitada>' + esc(a.nome) + '</div>';
    }

    /* ── Folha: a mesma da tela interna, com os lugares de assinatura do contrato ── */
    function pintarFolha() {
        const pv = raiz.querySelector('[data-folha="a"]'), d = est.documento;
        if (!pv || !d) { return; }
        const sign = est.lugares.map(l => ({ nome: l.rotulo || '', situacao: l.assinadoEm ? 'assinado' : 'pendente', assinadoEm: l.assinadoEm || null, rubrica: l.rubrica || '' }));
        F.pintar(pv, {
            blocos: d.blocos || [], sign: sign, logo: (d.logo && d.logo.url) || '', layout: d.layout || '', lateral: d.lateral || '',
            limpa: !!d.folhaBranca, cod: '', hash: d.hashEnviado || '', fonte: d.arquivo && d.arquivo.url ? F.fonteDaUrl(d.arquivo.url) : null,
        });
    }

    /* ── Assinatura desenhada (rubRef do desenho): traço de 3,4 px em #0b3550, só o movimento conta ── */
    function ligarCanvas() {
        const el = raiz.querySelector('[data-bja-canvas]');
        canv = el; vazio = true;
        if (!el) { return; }
        const g = el.getContext('2d');
        g.lineWidth = 3.4; g.lineCap = 'round'; g.lineJoin = 'round'; g.strokeStyle = '#0b3550';
        let on = false;
        const p = e => { const r = el.getBoundingClientRect(); return [(e.clientX - r.left) * el.width / r.width, (e.clientY - r.top) * el.height / r.height]; };
        el.addEventListener('pointerdown', e => { on = true; const q = p(e); g.beginPath(); g.moveTo(q[0], q[1]); try { el.setPointerCapture(e.pointerId); } catch (x) { /* sem captura */ } });
        el.addEventListener('pointermove', e => { if (!on) { return; } const q = p(e); g.lineTo(q[0], q[1]); g.stroke(); vazio = false; });
        el.addEventListener('pointerup', () => { on = false; });
    }

    /* PNG da assinatura: o canvas desenhado, ou o nome em Georgia itálico (76 px, diminui 4 px até caber em 720 px). */
    function rubrica() {
        if (a.tab === 'txt') {
            const c = document.createElement('canvas');
            c.width = 760; c.height = 200;
            const g = c.getContext('2d');
            g.font = 'italic 76px Georgia, serif'; g.fillStyle = '#0b3550'; g.textAlign = 'center'; g.textBaseline = 'middle';
            const t = a.nome.trim();
            let f = 76;
            while (g.measureText(t).width > 720 && f > 24) { f -= 4; g.font = 'italic ' + f + 'px Georgia, serif'; }
            g.fillText(t, 380, 100);
            return c.toDataURL('image/png');
        }
        return canv && !vazio ? canv.toDataURL('image/png') : '';
    }

    function trocarAba(k) {
        if (a.tab === k) { return; }
        a.tab = k;
        raiz.querySelectorAll('[data-bja-tab]').forEach(b => { const on = b.getAttribute('data-bja-tab') === k; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', String(on)); });
        const area = raiz.querySelector('[data-bja-rub]');
        if (area) { area.innerHTML = areaRubrica(); }
        ligarCanvas();
    }

    function limparRubrica() {
        if (canv) { canv.getContext('2d').clearRect(0, 0, canv.width, canv.height); }
        vazio = true;
    }

    /* ── Erro do painel (H18): some ao editar nome, CPF, código ou aceite ── */
    function erro(texto) {
        a.erro = texto || '';
        const e = raiz.querySelector('[data-bja-erro]');
        if (e) { e.textContent = a.erro; e.hidden = !a.erro; }
    }

    function cpfOk(cpf) {
        const n = digitos(cpf);
        if (!/^\d{11}$/.test(n) || /^(\d)\1+$/.test(n)) { return false; }
        const dv = t => { let soma = 0; for (let i = 0; i < t; i++) { soma += +n[i] * (t + 1 - i); } const r = soma * 10 % 11; return r === 10 ? 0 : r; };
        return dv(9) === +n[9] && dv(10) === +n[10];
    }

    /* ── Enviar código: o servidor manda por e-mail; o botão passa a "Reenviar" ── */
    async function pedirCodigo(botao) {
        if (est.previa || !url.codigo) { return; }
        botao.disabled = true;
        try {
            await postar(url.codigo, null);
            a.pediu = true;
            botao.textContent = T('reenviar');
            erro('');
        } catch (e) {
            if (e && !e.texto) { console.error(e); }
            erro((e && e.texto) || T('erro.geral'));
        } finally {
            if (botao.isConnected) { botao.disabled = false; }
        }
    }

    /* ── Assinar: as conferências do desenho na mesma ordem; o servidor confere de novo, inclusive o código ── */
    async function assinar(botao) {
        if (est.previa || !url.assinar) { return; }
        const nome = a.nome.trim(), cpf = digitos(a.cpf), cadastrado = digitos((est.eu || {}).cpf), cod = a.cod.trim();
        if (nome.split(/\s+/).length < 2) { erro(T('erro.nome')); return; }
        if (!cpfOk(cpf)) { erro(T('erro.cpf')); return; }
        if (cadastrado && cadastrado !== cpf) { erro(T('erro.cpf_cadastro')); return; }
        const rub = rubrica();
        if (!rub) { erro(T('erro.rubrica')); return; }
        if (!a.pediu) { erro(T('erro.pedir_codigo')); return; }
        if (!/^\d{6}$/.test(cod)) { erro(T('erro.codigo')); return; }
        if (!a.li) { erro(T('erro.aceite')); return; }
        prepararSom();
        botao.disabled = true;
        try {
            const r = await postar(url.assinar, { nome: nome, cpf: cpf, codigo: cod, modo: a.tab === 'txt' ? 'digitada' : 'desenhada', rubrica: rub, aceite: true });
            est = normalizar(r.estado || Object.assign({}, est, { estado: 'ja_assinado' }));
            som();
            try { if (navigator.vibrate) { navigator.vibrate(40); } } catch (e) { /* sem vibração */ }
            desenhar();
            mostrarOk(est.estado === 'concluido');
        } catch (e) {
            if (e && !e.texto) { console.error(e); }
            erro((e && e.texto) || T('erro.geral'));
        } finally {
            if (botao.isConnected) { botao.disabled = false; }
        }
    }

    /* Som da tela verde (dois tons, 1568 Hz e 2093 Hz). O contexto nasce no clique, para o navegador deixar tocar depois. */
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
        } catch (e) { /* falha silenciosa, como o desenho */ }
    }

    /* Tela "Assinatura registrada" (J1 a J13), sem a caixa do código e sem "Baixar PDF com selo" (etapa 3). */
    function mostrarOk(todas) {
        const barras = Array.from({ length: 24 }, (_, i) => '<span class="bjaRaio" style="transform:rotate(' + (i * 15) + 'deg)"><span class="bjaBar" style="animation-delay:' + (100 + i * 14) + 'ms"></span></span>').join('');
        camada('ok').innerHTML = '<div class="bjaOk bjaMov" role="dialog" aria-modal="true" aria-label="' + esc(T('ok.titulo')) + '"><div class="bjaOkCorpo">'
            + '<div class="bjaRing">' + barras + '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M12 34 L27 49 L54 17" pathLength="1" class="bjaMov"></path></svg></div>'
            + '<div class="bjaOkT">' + esc(T('ok.titulo')) + '</div><div class="bjaOkD">' + esc(todas ? T('ok.todas') : T('ok.demais')) + '</div>'
            + '<div class="bjaOkBts"><button type="button" class="bjaOkFim" data-bja-acao="concluir">' + esc(T('ok.concluir')) + '</button></div>'
            + '</div></div>';
        const b = camada('ok').querySelector('[data-bja-acao="concluir"]');
        if (b) { b.focus(); }
    }

    /* ── Recusa (I1 a I8): sem identificação, motivo obrigatório ────────── */
    function desenharRecusa() {
        const cam = camada('modal');
        if (!cam) { return; }
        cam.innerHTML = !a.recusa ? '' : '<div class="bjaModal"><div class="bjaCard bjaOculto bjaModalCx" role="dialog" aria-modal="true" aria-label="' + esc(T('recusa.rotulo')) + '">'
            + '<div class="bjaHd is-perigo">' + esc(T('recusa.titulo')) + '</div>'
            + '<div class="bjaModalCorpo"><label class="bjaL">' + esc(T('recusa.motivo')) + '<textarea class="bjaF" rows="3" maxlength="500" data-bja-a="motivo">' + esc(a.motivo) + '</textarea></label></div>'
            + '<div class="bjaModalPe"><button type="button" class="bjaB bjaN" data-bja-acao="recusa-nao">' + esc(T('recusa.voltar')) + '</button>'
            + '<button type="button" class="bjaB bjaR" data-bja-acao="recusa-sim">' + esc(T('recusa.recusar')) + '</button></div></div></div>';
        const tx = cam.querySelector('textarea');
        if (tx) { tx.focus(); }
    }

    async function confirmarRecusa(botao) {
        const motivo = a.motivo.trim();
        if (!motivo) { avisar(T('recusa.informe'), 'erro'); return; }
        if (est.previa || !url.recusa) { return; }
        botao.disabled = true;
        try {
            const r = await postar(url.recusa, { motivo: motivo });
            a.recusa = false;
            est = normalizar(r.estado || Object.assign({}, est, { estado: 'recusado' }));
            desenhar();
        } catch (e) {
            if (e && !e.texto) { console.error(e); }
            avisar((e && e.texto) || T('erro.geral'), 'erro');
        } finally {
            if (botao.isConnected) { botao.disabled = false; }
        }
    }

    /* ── Avisos (toast do desenho; some em 3,4 s) ───────────────────────── */
    function avisar(texto, tipo) {
        const cam = camada('toast');
        if (!cam) { return; }
        cam.innerHTML = '<div class="bjaToast' + (tipo ? ' is-' + tipo : '') + '" role="status">' + esc(texto) + '</div>';
        clearTimeout(tToast);
        tToast = setTimeout(() => { cam.innerHTML = ''; }, 3400);
    }

    /* Prévia aberta pelo escritório numa aba nova: fecha a aba; se o navegador não deixar, volta. */
    function fecharPrevia() {
        window.close();
        setTimeout(() => { if (history.length > 1) { history.back(); } }, 300);
    }

    /* ── Eventos ────────────────────────────────────────────────────────── */
    raiz.addEventListener('click', ev => {
        const aba = ev.target.closest('[data-bja-tab]');
        if (aba) { trocarAba(aba.getAttribute('data-bja-tab')); return; }
        const ac = ev.target.closest('[data-bja-acao]');
        if (!ac || ac.disabled) { return; }
        switch (ac.getAttribute('data-bja-acao')) {
            case 'fechar-previa': fecharPrevia(); break;
            case 'limpar': limparRubrica(); break;
            case 'codigo': pedirCodigo(ac); break;
            case 'assinar': assinar(ac); break;
            case 'recusar': if (!est.previa && url.recusa) { a.recusa = true; a.motivo = ''; desenharRecusa(); } break;
            case 'recusa-nao': a.recusa = false; desenharRecusa(); break;
            case 'recusa-sim': confirmarRecusa(ac); break;
            case 'concluir': camada('ok').innerHTML = ''; break;
            default: break;
        }
    });

    raiz.addEventListener('input', ev => {
        const t = ev.target, k = t.getAttribute && t.getAttribute('data-bja-a');
        if (!k || k === 'li') { return; }
        a[k] = t.value;
        if (k === 'motivo') { return; }
        erro('');
        if (k === 'nome') { const dg = raiz.querySelector('[data-bja-digitada]'); if (dg) { dg.textContent = a.nome; } }
    });

    raiz.addEventListener('change', ev => {
        const t = ev.target;
        if (t.getAttribute && t.getAttribute('data-bja-a') === 'li') { a.li = t.checked; erro(''); }
    });

    document.addEventListener('keydown', ev => {
        if (ev.key === 'Escape' && a.recusa) { a.recusa = false; desenharRecusa(); }
    });

    desenhar();
})();
