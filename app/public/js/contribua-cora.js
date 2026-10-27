/* Contribua C4: Integração bancária do Master e prêmio do apoiador (desenho 08, L80-101, L192-203, L249-256,
   L285-291). Usa o CSRF e o toast da página Contribua (data-contribua / data-toast); não mexe no contribua.js. */
(function () {
    'use strict';
    if (window.__bjContribuaCora) { return; }
    window.__bjContribuaCora = true;

    const $ = (s, el) => (el || document).querySelector(s);
    const raiz = () => $('[data-contribua]');
    let tempoToast = null;
    function avisar(texto) {
        const toast = $('[data-toast]');
        if (!toast) { return; }
        $('span', toast).textContent = texto;
        toast.hidden = false;
        clearTimeout(tempoToast);
        tempoToast = setTimeout(() => { toast.hidden = true; }, 3200);
    }
    async function postar(url, dados) {
        const corpo = dados instanceof FormData ? dados : new FormData();
        if (!(dados instanceof FormData)) { Object.entries(dados || {}).forEach(([k, v]) => corpo.append(k, v)); }
        corpo.append('_token', (raiz() || {}).dataset ? raiz().dataset.csrf : '');
        const r = await fetch(url, { method: 'POST', body: corpo, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
        const j = await r.json().catch(() => ({ ok: false, erro: 'Não foi possível concluir. Tente de novo.' }));
        if (!j.ok) { throw new Error(j.erro || 'Não foi possível concluir. Tente de novo.'); }
        return j;
    }

    /* ── Integração bancária (só Master) ─────────────────────────────── */
    const ESTADOS = { off: ['Não conectado', 'is-off'], pend: ['Aguardando servidor', 'is-pend'], ok: ['Conectado', 'is-ok'] };
    function iniciarIntegracao(sec) {
        const banco = $('[data-cc-banco]', sec);
        const hook = () => { $('[data-cc-hook]', sec).textContent = sec.dataset.webhookBase + banco.value + '/pix'; };
        const estado = e => { const el = $('[data-cc-estado]', sec); el.className = 'cc-estado ' + ESTADOS[e][1]; $('span', el).textContent = ESTADOS[e][0]; };
        let temCert = sec.dataset.temCertificado === '1';
        hook();
        banco.addEventListener('change', hook);
        sec.addEventListener('click', async ev => {
            const t = ev.target;
            const rec = t.closest('[data-cc-recurso]');
            if (rec) { rec.setAttribute('aria-pressed', rec.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); return; }
            if (t.closest('.cc-arquivo')) { $('[data-cc-cert]', sec).click(); return; }
            if (t.closest('[data-cc-copiar]')) {
                try { await navigator.clipboard.writeText($('[data-cc-hook]', sec).textContent); avisar('Endereço copiado.'); } catch (e) { avisar('Não foi possível copiar.'); }
                return;
            }
            if (t.closest('[data-cc-simular]')) { abrirPremio(true); return; }
            const testar = t.closest('[data-cc-testar]');
            if (testar) {
                if (!$('[data-cc-cid]', sec).value.trim() || !temCert) { avisar('Informe o Client ID e envie o certificado antes de testar.'); return; }
                testar.disabled = true;
                estado('pend');
                try {
                    const j = await postar(sec.dataset.urlTestar, {});
                    estado(j.conectado ? 'ok' : 'off');
                    avisar(j.mensagem);
                } catch (e) { estado('off'); avisar(e.message); }
                testar.disabled = false;
                return;
            }
            const salvar = t.closest('[data-cc-salvar]');
            if (salvar) {
                const cid = $('[data-cc-cid]', sec).value.trim();
                if (!cid) { avisar('Informe o Client ID fornecido pelo banco.'); return; }
                const dados = new FormData();
                dados.append('ambiente', $('[data-cc-ambiente]', sec).value);
                dados.append('clientId', cid);
                sec.querySelectorAll('[data-cc-recurso]').forEach(r => dados.append('recursos[' + r.dataset.ccRecurso + ']', r.getAttribute('aria-pressed') === 'true' ? '1' : '0'));
                salvar.disabled = true;
                try { const j = await postar(sec.dataset.urlSalvar, dados); estado(temCert ? 'pend' : 'off'); avisar(j.mensagem); } catch (e) { avisar(e.message); }
                salvar.disabled = false;
            }
        });
        $('[data-cc-cert]', sec).addEventListener('change', async ev => {
            const arquivos = Array.from(ev.target.files || []);
            if (!arquivos.length) { return; }
            const dados = new FormData();
            arquivos.forEach(f => dados.append('arquivos[]', f, f.name));
            try {
                const j = await postar(sec.dataset.urlCertificado, dados);
                $('[data-cc-cert-txt]', sec).textContent = j.arquivos.join(', ');
                temCert = true;
                estado('pend');
                avisar(j.mensagem);
            } catch (e) { avisar(e.message); }
            ev.target.value = '';
        });
    }

    /* ── Prêmio do apoiador ───────────────────────────────────────────── */
    const CORES = ['#f0b429', '#7fe0a8', '#3aa0d8', '#e66b8f', '#ffffff'];
    let simulado = false;
    function abrirPremio(simulacao) {
        const p = $('[data-cc-premio]');
        if (!p) { return; }
        simulado = !!simulacao;
        $('[data-cc-premio-nome]', p).textContent = p.dataset.nome || 'colega';
        $('[data-cc-premio-valor]', p).textContent = p.dataset.valor;
        const confete = $('.cc-premio-confete', p);
        confete.textContent = '';
        for (let i = 0; i < 22; i++) {
            const c = document.createElement('span');
            c.style.left = (i * 4.6 + 2) + '%';
            c.style.background = CORES[i % 5];
            c.style.animationDuration = (2.4 + (i % 5) * 0.5) + 's';
            c.style.animationDelay = (i * 0.13) + 's';
            confete.appendChild(c);
        }
        p.hidden = false;
        $('[data-cc-premio-ok]', p).focus();
    }
    async function fecharPremio(irParaPropostas) {
        const p = $('[data-cc-premio]');
        p.hidden = true;
        // O "Testar mensagem de prêmio" do Master só mostra: não grava nada.
        if (!simulado && p.dataset.apoio) {
            try { await postar(p.dataset.urlVisto.replace('/0/visto', '/' + encodeURIComponent(p.dataset.apoio) + '/visto'), {}); } catch (e) { /* aparece de novo na próxima visita */ }
            p.dataset.apoio = '';
        }
        if (irParaPropostas) { location.href = p.dataset.urlPropostas; }
    }
    /* Espera outro modal aberto fechar (spec mãe §8: ponto > iOS > prêmio > aviso de espaço > Central). */
    function quandoLivre(fn) {
        const aberto = document.querySelector('.modal.show');
        if (!aberto) { fn(); return; }
        aberto.addEventListener('hidden.bs.modal', () => quandoLivre(fn), { once: true });
    }

    function iniciar() {
        const sec = $('[data-cc-integracao]');
        if (sec) { iniciarIntegracao(sec); }
        const p = $('[data-cc-premio]');
        if (!p) { return; }
        p.addEventListener('click', ev => {
            if (ev.target.closest('[data-cc-premio-ok]')) { fecharPremio(false); }
            if (ev.target.closest('[data-cc-premio-te]')) { fecharPremio(true); }
        });
        document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && !p.hidden) { fecharPremio(false); } });
        if (p.dataset.abrir === '1') { quandoLivre(() => abrirPremio(false)); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
