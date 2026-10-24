/* Foto do cliente no cabeçalho da pasta (desenho 02 VS 18.10.26: botão L1255, foto L3853, menu L472-482,
   editor L483-505 e `fotoVals`, L3853-3884).
   O círculo abre o menu ("Carregar foto" / "Carregar nova foto", "Ajustar foto", "Remover foto"); o editor recorta no
   navegador (arrastar, roda ou faixa de 1 a 3 para o zoom, girar 90°) e "Salvar foto" envia um JPEG 320x320 de
   qualidade 0,88. Quem confere tudo (permissão, escritório, tipo e tamanho) é o servidor: pasta_cliente_foto_trocar
   e pasta_cliente_foto_remover. Sem foto original guardada: "Ajustar foto" reabre o editor sobre a foto atual.
   Os avisos são os da tela (`avisarPs`, pasta-dialogos.js). Nada de HTML montado aqui: só atributos e textContent. */
(function () {
    'use strict';

    var P = 240;          // lado do círculo do editor, em px (dc L488)
    var S = 320;          // lado do JPEG salvo (dc L3877)
    var QUALIDADE = 0.88; // dc L3881

    var botao = document.querySelector('.js-ps-foto');
    var menu = document.querySelector('[data-ps-foto-menu]');
    var fundo = document.querySelector('[data-ps-foto-fundo]');
    var editor = document.querySelector('[data-ps-foto-editor]');
    var entrada = document.querySelector('[data-ps-foto-arquivo]');
    if (!botao || !menu || !fundo || !editor || !entrada) { return; }

    // O menu e a janela são `position: fixed`: no body, nenhum ancestral com transform os desloca.
    [fundo, menu, editor, entrada].forEach(function (el) { document.body.appendChild(el); });

    var foto = botao.querySelector('.ps-cab-foto-img');
    var area = editor.querySelector('.js-ps-foto-area');
    var imagem = editor.querySelector('.js-ps-foto-imagem');
    var zoom = editor.querySelector('.js-ps-foto-zoom');
    var salvar = editor.querySelector('.js-ps-foto-salvar');
    var estado = null; // { src, w, h, zoom, x, y, rot }

    function avisar(texto, tipo) {
        if (tipo === 'erro') { (window.avisarErroPs || window.alert)(texto); return; }
        if (window.avisarPs) { window.avisarPs(texto); }
    }

    function temFoto() { return botao.getAttribute('data-tem-foto') === '1'; }

    /* ── Menu ─────────────────────────────────────────────────────────── */

    function pintarMenu() {
        var tem = temFoto();
        menu.querySelector('.js-ps-foto-carregar-texto').textContent = BJ_T.t(tem ? 'pasta_cabecalho.js.foto.carregar_nova' : 'pasta_cabecalho.js.foto.carregar');
        menu.querySelectorAll('[data-ps-foto-com-foto]').forEach(function (item) { item.hidden = !tem; });
    }

    function abrirMenu() {
        var r = botao.getBoundingClientRect();
        pintarMenu();
        menu.style.left = Math.min(r.left, window.innerWidth - 220) + 'px';
        menu.style.top = (r.bottom + 6) + 'px';
        fundo.hidden = false;
        menu.hidden = false;
        botao.setAttribute('aria-expanded', 'true');
        var primeiro = menu.querySelector('[role="menuitem"]:not([hidden])');
        if (primeiro) { primeiro.focus(); }
    }

    function fecharMenu() {
        fundo.hidden = true;
        menu.hidden = true;
        botao.setAttribute('aria-expanded', 'false');
    }

    /* ── Editor ───────────────────────────────────────────────────────── */

    // As medidas da imagem no círculo (dc `dims`): o lado menor cobre o círculo no zoom 1.
    function medidas() {
        var girada = estado.rot % 180 !== 0;
        var w = girada ? estado.h : estado.w;
        var h = girada ? estado.w : estado.h;
        var base = P / Math.min(w, h);

        return { dw: estado.w * base * estado.zoom, dh: estado.h * base * estado.zoom };
    }

    function pintarEditor() {
        var d = medidas();
        imagem.style.width = d.dw + 'px';
        imagem.style.height = d.dh + 'px';
        imagem.style.transform = 'translate(-50%,-50%) translate(' + estado.x + 'px,' + estado.y + 'px) rotate(' + estado.rot + 'deg)';
        zoom.value = String(estado.zoom);
    }

    function abrirEditor(src) {
        var img = new Image();
        img.onload = function () {
            estado = { src: src, w: img.naturalWidth, h: img.naturalHeight, zoom: 1, x: 0, y: 0, rot: 0 };
            imagem.src = src;
            fecharMenu();
            editor.hidden = false;
            salvar.disabled = false;
            pintarEditor();
            salvar.focus();
        };
        img.src = src;
    }

    function fecharEditor() {
        editor.hidden = true;
        estado = null;
    }

    function escolheu() {
        var f = entrada.files && entrada.files[0];
        entrada.value = '';
        if (!f || !/^image\//.test(f.type)) { return; }
        var leitor = new FileReader();
        leitor.onload = function () { abrirEditor(leitor.result); };
        leitor.readAsDataURL(f);
    }

    function arrastar(ev) {
        if (!estado) { return; }
        ev.preventDefault();
        var x0 = ev.clientX, y0 = ev.clientY, bx = estado.x, by = estado.y;
        function mover(m) {
            if (!estado) { return; }
            estado.x = bx + m.clientX - x0;
            estado.y = by + m.clientY - y0;
            pintarEditor();
        }
        function soltar() {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
        }
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
    }

    function roda(ev) {
        if (!estado) { return; }
        ev.preventDefault();
        estado.zoom = Math.max(1, Math.min(3, estado.zoom - ev.deltaY * 0.0015));
        pintarEditor();
    }

    // O recorte do desenho (dc L3876-3881): fundo branco, centro deslocado, giro, e a imagem na escala S/P.
    function recortar(feito) {
        var e = estado, d = medidas(), f = S / P, img = new Image();
        img.onload = function () {
            var cv = document.createElement('canvas');
            cv.width = cv.height = S;
            var g = cv.getContext('2d');
            g.fillStyle = '#fff';
            g.fillRect(0, 0, S, S);
            g.translate(S / 2 + e.x * f, S / 2 + e.y * f);
            g.rotate(e.rot * Math.PI / 180);
            g.drawImage(img, -d.dw * f / 2, -d.dh * f / 2, d.dw * f, d.dh * f);
            cv.toBlob(feito, 'image/jpeg', QUALIDADE);
        };
        img.src = e.src;
    }

    /* ── Servidor ─────────────────────────────────────────────────────── */

    function enviar(url, corpo, aoDarCerto, chaveDoErro) {
        corpo.append('_token', botao.getAttribute('data-token'));
        return fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok && res.j.ok) { aoDarCerto(res.j); return true; }
                avisar(res.j.erro || res.j.mensagem || BJ_T.t(chaveDoErro), 'erro');
                return false;
            })
            .catch(function () { avisar(BJ_T.t(chaveDoErro), 'erro'); return false; });
    }

    function mostrarFoto(url) {
        if (url) {
            foto.src = url;
            foto.hidden = false;
        } else {
            foto.removeAttribute('src');
            foto.hidden = true;
        }
        botao.setAttribute('data-tem-foto', url ? '1' : '0');
    }

    function salvarFoto() {
        if (!estado) { return; }
        salvar.disabled = true;
        recortar(function (blob) {
            if (!blob) { salvar.disabled = false; avisar(BJ_T.t('pasta_cabecalho.js.foto.erro'), 'erro'); return; }
            var corpo = new FormData();
            corpo.append('foto', blob, 'foto.jpg');
            enviar(botao.getAttribute('data-url-trocar'), corpo, function (j) {
                mostrarFoto(j.url);
                fecharEditor();
                avisar(BJ_T.t('pasta_cabecalho.js.foto.salva'));
            }, 'pasta_cabecalho.js.foto.erro').then(function (deu) { if (!deu) { salvar.disabled = false; } });
        });
    }

    function removerFoto() {
        fecharMenu();
        enviar(botao.getAttribute('data-url-remover'), new FormData(), function () {
            mostrarFoto(null);
            avisar(BJ_T.t('pasta_cabecalho.js.foto.removida'));
        }, 'pasta_cabecalho.js.foto.erro_remover');
    }

    /* ── Ligações ─────────────────────────────────────────────────────── */

    botao.addEventListener('click', function () { if (menu.hidden) { abrirMenu(); } else { fecharMenu(); } });
    fundo.addEventListener('click', fecharMenu);
    menu.querySelector('.js-ps-foto-carregar').addEventListener('click', function () { fecharMenu(); entrada.click(); });
    menu.querySelector('.js-ps-foto-ajustar').addEventListener('click', function () { if (foto.getAttribute('src')) { abrirEditor(foto.getAttribute('src')); } });
    menu.querySelector('.js-ps-foto-remover').addEventListener('click', removerFoto);
    entrada.addEventListener('change', escolheu);
    area.addEventListener('pointerdown', arrastar);
    area.addEventListener('wheel', roda, { passive: false });
    zoom.addEventListener('input', function () { if (estado) { estado.zoom = +zoom.value; pintarEditor(); } });
    editor.querySelector('.js-ps-foto-girar').addEventListener('click', function () {
        if (!estado) { return; }
        estado.rot = (estado.rot + 90) % 360;
        estado.x = 0;
        estado.y = 0;
        pintarEditor();
    });
    editor.querySelector('.js-ps-foto-trocar').addEventListener('click', function () { entrada.click(); });
    editor.querySelectorAll('.js-ps-foto-cancelar').forEach(function (b) { b.addEventListener('click', fecharEditor); });
    salvar.addEventListener('click', salvarFoto);
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') { return; }
        if (!editor.hidden) { fecharEditor(); return; }
        if (!menu.hidden) { fecharMenu(); botao.focus(); }
    });
})();
