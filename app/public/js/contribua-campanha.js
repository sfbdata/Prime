/* Modo campanha "BlueJus Black": aba do topo, janela da campanha e painel do Master.
   Desenho: bj-campanha.js (aba, abrir, alerta). A janela vem do servidor (GET contribua_campanha_janela);
   o JS só monta o overlay, envia os formulários e mostra o erro em toast. */
(function () {
    'use strict';
    if (window.BJCampanhaTela) { return; }
    window.BJCampanhaTela = true;

    const ERRO_PADRAO = 'Não foi possível concluir agora. Tente de novo.';
    let numeroDaBusca = 0;

    const raiz = () => document.getElementById('cp-raiz');

    /* ---------- Toast de erro (alerta() do desenho: embaixo, vermelho, 3,5 s) ---------- */
    function toast(texto) {
        let t = document.getElementById('cp-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'cp-toast';
            t.className = 'cp-toast';
            t.setAttribute('role', 'alert');
            document.body.appendChild(t);
        }
        t.textContent = texto;
        clearTimeout(t._t);
        t._t = setTimeout(() => t.remove(), 3500);
    }

    function postar(url, dados) {
        return fetch(url, {
            method: 'POST',
            body: dados,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        }).then((r) => r.json().catch(() => ({ ok: false, erro: ERRO_PADRAO })))
            .catch(() => ({ ok: false, erro: ERRO_PADRAO }));
    }

    /* ---------- Aba fixa: logo abaixo do cabeçalho da tela (cabecalho() do desenho) ---------- */
    function baseDoCabecalho() {
        const achados = Array.from(document.querySelectorAll('header, [role=banner], .main-header')).filter((h) => {
            const r = h.getBoundingClientRect();
            return r.top <= 2 && r.height >= 40 && r.height <= 140 && r.width >= window.innerWidth * 0.8;
        });
        return achados[0] ? achados[0].getBoundingClientRect().bottom : 0;
    }

    // A aba fica no centro do topo, logo abaixo do cabeçalho (não cobre o nome nem o avatar), e some sozinha.
    const ABA_SOME_EM_MS = 5000;

    function posicionarAba() {
        const aba = document.getElementById('cp-aba');
        if (aba) { aba.style.top = baseDoCabecalho() + 'px'; }
    }

    function esconderAba() {
        const aba = document.getElementById('cp-aba');
        if (!aba) { return; }
        window.removeEventListener('resize', posicionarAba);
        aba.classList.add('cp-aba--saindo');
        setTimeout(() => aba.remove(), 450);
    }

    /* ---------- Janela ---------- */
    function fechar() {
        const o = document.getElementById('cp-ov');
        if (o) { o.remove(); }
        document.removeEventListener('keydown', aoTeclar);
    }

    function aoTeclar(e) {
        if (e.key === 'Escape') { fechar(); }
    }

    function copiar(texto, botao) {
        const rotulo = botao.querySelector('span');
        const feito = () => {
            if (!rotulo) { return; }
            const antes = rotulo.textContent;
            rotulo.textContent = 'Código copiado';
            setTimeout(() => { rotulo.textContent = antes; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(feito, () => toast(ERRO_PADRAO));
            return;
        }
        const area = document.createElement('textarea');
        area.value = texto;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* o aviso sai mesmo assim */ }
        area.remove();
        feito();
    }

    /* QR do Pix: reaproveita a lib do Contribua (window.qrcode); carrega o script se a tela não tiver. */
    function desenharQr(caixa) {
        const q = window.qrcode(0, 'M');
        q.addData(caixa.dataset.codigo || '');
        q.make();
        caixa.innerHTML = q.createSvgTag({ cellSize: 5, margin: 0, scalable: true });
    }

    function prepararQr(o) {
        const caixa = o.querySelector('[data-cp-qr]');
        if (!caixa) { return; }
        if (window.qrcode) { desenharQr(caixa); return; }
        const r = raiz();
        if (r && r.dataset.urlQr && !document.getElementById('cp-qr-lib')) {
            const s = document.createElement('script');
            s.id = 'cp-qr-lib';
            s.src = r.dataset.urlQr;
            document.head.appendChild(s);
        }
        let tentativas = 0;
        const espera = setInterval(() => {
            tentativas += 1;
            if (window.qrcode) { clearInterval(espera); if (caixa.isConnected) { desenharQr(caixa); } }
            else if (tentativas > 40) { clearInterval(espera); }
        }, 200);
    }

    function enviarForm(form, o) {
        const botao = form.querySelector('[type=submit]');
        if (botao) { botao.disabled = true; }
        postar(form.action, new FormData(form)).then((d) => {
            if (!d || !d.ok) {
                if (botao) { botao.disabled = false; }
                toast((d && d.erro) || ERRO_PADRAO);
                return;
            }
            abrir();
        });
        return o;
    }

    function aoClicarNaJanela(e, o) {
        if (e.target === o) { fechar(); return; }
        const link = e.target.closest('a[href]');
        if (link) { fechar(); return; }
        const b = e.target.closest('[data-cp-a]');
        if (!b) { return; }
        const acao = b.getAttribute('data-cp-a');
        if (acao === 'fechar') { fechar(); }
        if (acao === 'copiar') {
            const cod = o.querySelector('[data-cp-codigo]');
            if (cod) { copiar(cod.value, b); }
        }
        if (acao === 'devForm') {
            const f = o.querySelector('form[hidden]');
            if (f) {
                f.hidden = false;
                b.style.display = 'none';
                const foco = f.querySelector('[data-cp-foco]');
                if (foco) { foco.focus(); }
            }
        }
    }

    function abrir() {
        const r = raiz();
        if (!r || !r.dataset.urlJanela) { return; }
        const eu = ++numeroDaBusca;
        fetch(r.dataset.urlJanela, { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((resp) => (resp.ok ? resp.text() : Promise.reject(new Error('janela'))))
            .then((html) => {
                if (eu !== numeroDaBusca) { return; }
                fechar();
                const o = document.createElement('div');
                o.id = 'cp-ov';
                o.className = 'cp-ov';
                o.setAttribute('role', 'dialog');
                o.setAttribute('aria-modal', 'true');
                o.innerHTML = html;
                const jan = o.querySelector('[data-cp-janela]');
                if (jan) { o.setAttribute('aria-label', jan.dataset.cpNome || 'Campanha'); }
                o.addEventListener('click', (e) => aoClicarNaJanela(e, o));
                o.addEventListener('submit', (e) => {
                    const form = e.target.closest('[data-cp-form]');
                    if (!form) { return; }
                    e.preventDefault();
                    enviarForm(form, o);
                });
                document.body.appendChild(o);
                document.addEventListener('keydown', aoTeclar);
                prepararQr(o);
            })
            .catch(() => { if (eu === numeroDaBusca) { toast('Não foi possível abrir a campanha agora.'); } });
    }

    /* ---------- Painel do Master: cada campo grava ao mudar; depois recarrega a página ---------- */
    function iniciarPainel(painel) {
        const csrf = painel.dataset.csrf || '';
        const urlDe = (modelo, id) => String(modelo || '').replace('/0/', '/' + id + '/');

        function enviar(url, campos, botao, aoFalhar) {
            const fd = new FormData();
            fd.append('_token', csrf);
            Object.keys(campos).forEach((k) => fd.append(k, campos[k]));
            if (botao) { botao.disabled = true; }
            postar(url, fd).then((d) => {
                if (d && d.ok) { window.location.reload(); return; }
                if (botao) { botao.disabled = false; }
                if (aoFalhar) { aoFalhar(); }
                toast((d && d.erro) || ERRO_PADRAO);
            });
        }

        painel.addEventListener('change', (e) => {
            const campo = e.target.closest('[data-cp-campo]');
            if (!campo) { return; }
            const dados = {};
            const valor = campo.type === 'checkbox' ? (campo.checked ? '1' : '0') : campo.value;
            dados[campo.dataset.cpCampo] = valor;
            enviar(painel.dataset.urlConfigurar, dados, null, () => {
                if (campo.type === 'checkbox') { campo.checked = !campo.checked; } else { campo.value = campo.defaultValue; }
            });
        });

        painel.addEventListener('click', (e) => {
            const alvo = e.target.closest('button');
            if (!alvo || !painel.contains(alvo)) { return; }
            if (alvo.hasAttribute('data-cp-ligar')) {
                enviar(painel.dataset.urlConfigurar, { ligada: alvo.getAttribute('aria-checked') === 'true' ? '0' : '1' }, alvo);
            } else if (alvo.hasAttribute('data-cp-mult')) {
                enviar(painel.dataset.urlConfigurar, { multiplicador: alvo.dataset.cpMult }, alvo);
            } else if (alvo.hasAttribute('data-cp-padrao')) {
                enviar(painel.dataset.urlPadrao, {}, alvo);
            } else if (alvo.hasAttribute('data-cp-confirmar')) {
                enviar(urlDe(painel.dataset.urlConfirmar, alvo.dataset.cpConfirmar), {}, alvo);
            } else if (alvo.hasAttribute('data-cp-cancelar')) {
                enviar(urlDe(painel.dataset.urlCancelar, alvo.dataset.cpCancelar), {}, alvo);
            } else if (alvo.hasAttribute('data-cp-devolvida')) {
                enviar(urlDe(painel.dataset.urlDevolvida, alvo.dataset.cpDevolvida), {}, alvo);
            }
        });
    }

    /* ---------- Abertura: aba, faixa, FAQ ([data-campanha-abrir]) e #black em qualquer tela ---------- */
    document.addEventListener('click', (e) => {
        const gatilho = e.target.closest('[data-campanha-abrir]');
        if (!gatilho) { return; }
        e.preventDefault();
        abrir();
    });

    function iniciar() {
        posicionarAba();
        setTimeout(posicionarAba, 600);
        window.addEventListener('resize', posicionarAba);
        setTimeout(esconderAba, ABA_SOME_EM_MS);
        const painel = document.querySelector('[data-cp-painel]');
        if (painel) { iniciarPainel(painel); }
        if (/[#&]black\b/.test(window.location.hash)) { abrir(); }
    }

    window.addEventListener('hashchange', () => {
        if (/[#&]black\b/.test(window.location.hash)) { abrir(); }
    });

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
