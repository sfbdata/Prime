/* =============================================================================
   pasta-clientes.js — L7 do trilho da aba Dados (tela da pasta)
   Desenho aprovado: docs/design/claude-design-2026-10-05 (1)/
   ("02 - EXPEDIENTES 1.2.3.dc.html": janela do cliente L.779-836, linha do
   cliente L.1381, ⋮ do prazo L.1348 e L.6150-6151).

   1. Janela "Detalhes do cliente": clique no icone de cadastro da linha ou botao
      direito na linha. O conteudo e um fragmento do servidor (`cliente_resumo`),
      que ja decide permissao e filtra as pastas — aqui so se posiciona, arrasta
      e fecha.
   2. Copiar (nome, documento, contatos, qualificacao, prazo): `.js-ps-copiar`
      com o texto em `data-ps-copiar`.
   3. "Adicionar a agenda (.ics)": arquivo gerado no navegador a partir dos
      data-* do item, que vem da meta real (dia com ano, titulo, responsavel).
   4. Botao direito na linha do prazo abre o mesmo menu ⋮.

   Tudo por delegacao no document: a linha de cliente que o JS do show cria ao
   vincular sem recarregar tambem responde ao botao direito.
   ============================================================================= */
(function () {
    'use strict';

    var LARGURA = 320;
    var MARGEM  = 12;
    var janelaAberta = null;
    var overlay = null;

    /* ── Aviso curto (o "gmAviso" do desenho) ─────────────────────────────── */
    var avisoTimer = null;
    function avisar(texto) {
        var el = document.querySelector('.ps-l7-aviso');
        if (!el) {
            el = document.createElement('div');
            el.className = 'ps-l7-aviso';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.textContent = texto;
        el.classList.add('is-visivel');
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { el.classList.remove('is-visivel'); }, 1800);
    }

    /* ── Area de transferencia ────────────────────────────────────────────── */
    function copiarFallback(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        area.remove();

        return ok;
    }

    function copiar(texto) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(texto).then(
                function () { return true; },
                function () { return copiarFallback(texto); }
            );
        }

        return Promise.resolve(copiarFallback(texto));
    }

    function aoCopiar(botao) {
        var texto = botao.getAttribute('data-ps-copiar') || '';
        if (texto === '') { return; }

        copiar(texto).then(function (ok) {
            if (!ok) { avisar('Não foi possível copiar'); return; }

            var aviso = botao.getAttribute('data-ps-copiado');
            if (aviso) { avisar(aviso); }

            // Botao de icone da janela: o icone vira ✓ por um instante (desenho).
            var icone = botao.querySelector('i.bi-copy');
            if (icone) {
                icone.classList.replace('bi-copy', 'bi-check2');
                botao.classList.add('is-copiado');
                setTimeout(function () {
                    icone.classList.replace('bi-check2', 'bi-copy');
                    botao.classList.remove('is-copiado');
                }, 1400);
            }
        });
    }

    /* ── .ics de um prazo ─────────────────────────────────────────────────── */
    // RFC 5545 §3.3.11: barra, ponto e virgula, virgula e quebra de linha escapados.
    function icsTexto(valor) {
        return String(valor || '')
            .replace(/\\/g, '\\\\')
            .replace(/;/g, '\\;')
            .replace(/,/g, '\\,')
            .replace(/\r?\n/g, '\\n');
    }

    function carimbo() {
        var d = new Date();
        function p(n) { return String(n).padStart(2, '0'); }

        return d.getUTCFullYear() + p(d.getUTCMonth() + 1) + p(d.getUTCDate())
            + 'T' + p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds()) + 'Z';
    }

    function baixarIcs(botao) {
        var dia    = botao.getAttribute('data-ics-dia') || '';
        var titulo = botao.getAttribute('data-ics-titulo') || '';
        var resp   = botao.getAttribute('data-ics-responsavel') || '';
        var pasta  = botao.getAttribute('data-ics-pasta') || '';
        var uid    = botao.getAttribute('data-ics-uid') || String(Date.now());
        if (!/^\d{8}$/.test(dia)) { avisar('Prazo sem data'); return; }

        var linhas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BlueJus//PT',
            'BEGIN:VEVENT',
            'UID:' + uid + '@bluejus',
            'DTSTAMP:' + carimbo(),
            'DTSTART;VALUE=DATE:' + dia,
            'SUMMARY:' + icsTexto(titulo + ' (Pasta ' + pasta + ')'),
        ];
        if (resp !== '') { linhas.push('DESCRIPTION:' + icsTexto('Responsável: ' + resp)); }
        linhas.push('END:VEVENT', 'END:VCALENDAR', '');

        var blob = new Blob([linhas.join('\r\n')], { type: 'text/calendar;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'prazo-' + String(pasta).replace(/[^\w.-]+/g, '-') + '.ics';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    }

    /* ── Janela "Detalhes do cliente" ─────────────────────────────────────── */
    function fecharJanela() {
        if (janelaAberta) { janelaAberta.remove(); janelaAberta = null; }
        if (overlay) { overlay.remove(); overlay = null; }
    }

    function posicionar(janela, x, y) {
        var altura = janela.offsetHeight || 480;
        var maxX = window.innerWidth - Math.min(LARGURA, window.innerWidth - 2 * MARGEM) - MARGEM;
        var maxY = window.innerHeight - altura - MARGEM;
        janela.style.left = Math.max(MARGEM, Math.min(x, maxX)) + 'px';
        janela.style.top  = Math.max(MARGEM, Math.min(y, maxY)) + 'px';
    }

    function urlDoResumo(clienteId) {
        var secao = document.querySelector('[data-trilho="clientes"]');
        if (!secao) { return null; }
        var modelo = secao.getAttribute('data-resumo-url') || '';
        var pasta  = secao.getAttribute('data-pasta-id') || '';
        if (modelo === '' || !/^\d+$/.test(String(clienteId))) { return null; }

        return modelo.replace(/\/0\/resumo$/, '/' + clienteId + '/resumo') + (pasta ? '?pasta=' + encodeURIComponent(pasta) : '');
    }

    function abrirJanela(clienteId, x, y) {
        var url = urlDoResumo(clienteId);
        if (!url) { return; }

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) { throw new Error(String(r.status)); }

                return r.text();
            })
            .then(function (html) {
                fecharJanela();

                overlay = document.createElement('div');
                overlay.className = 'ps-cli-overlay';
                overlay.addEventListener('click', fecharJanela);
                overlay.addEventListener('contextmenu', function (e) { e.preventDefault(); fecharJanela(); });
                document.body.appendChild(overlay);

                var molde = document.createElement('div');
                molde.innerHTML = html.trim();
                var janela = molde.firstElementChild;
                if (!janela) { fecharJanela(); return; }
                document.body.appendChild(janela);
                janelaAberta = janela;
                posicionar(janela, x, y);

                var fechar = janela.querySelector('.js-ps-cli-fechar');
                if (fechar) { fechar.focus({ preventScroll: true }); }
            })
            .catch(function () { avisar('Não foi possível abrir os detalhes do cliente'); });
    }

    // Arrastar pelo cabecalho (desenho: "Arraste para mover").
    document.addEventListener('pointerdown', function (e) {
        var cab = e.target.closest('[data-ps-cli-arrastar]');
        if (!cab || !janelaAberta || e.target.closest('button')) { return; }
        if (e.button !== undefined && e.button !== 0) { return; }
        e.preventDefault();

        var janela = janelaAberta;
        var ox = e.clientX - janela.offsetLeft;
        var oy = e.clientY - janela.offsetTop;
        janela.classList.add('is-arrastando');

        function mover(ev) { posicionar(janela, ev.clientX - ox, ev.clientY - oy); }
        function soltar() {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            janela.classList.remove('is-arrastando');
        }
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
    });

    /* ── Delegacao ────────────────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var copiarBtn = e.target.closest('.js-ps-copiar');
        if (copiarBtn) { aoCopiar(copiarBtn); return; }

        var ics = e.target.closest('.js-prazo-ics');
        if (ics) { baixarIcs(ics); return; }

        if (e.target.closest('.js-ps-cli-fechar')) { fecharJanela(); return; }

        var detalhes = e.target.closest('.js-ps-cli-detalhes');
        if (detalhes) {
            var linha = detalhes.closest('[data-cliente-id]');
            if (!linha) { return; }
            var r = detalhes.getBoundingClientRect();
            abrirJanela(linha.getAttribute('data-cliente-id'), r.left, r.bottom + 6);
        }
    });

    document.addEventListener('contextmenu', function (e) {
        var linhaCliente = e.target.closest('[data-trilho="clientes"] .cliente-linha[data-cliente-id]');
        if (linhaCliente) {
            e.preventDefault();
            abrirJanela(linhaCliente.getAttribute('data-cliente-id'), e.clientX, e.clientY);

            return;
        }

        var linhaPrazo = e.target.closest('[data-trilho="prazos"] .ps-prazo');
        if (linhaPrazo && !e.target.closest('.ps-pop')) {
            var mais = linhaPrazo.querySelector('.ps-prazo-mais');
            if (mais) {
                e.preventDefault();
                if (mais.getAttribute('aria-expanded') !== 'true') { mais.click(); }
            }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && janelaAberta) { fecharJanela(); }
    });
}());
