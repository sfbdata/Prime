/* =============================================================================
   assinatura-vinculo.js: "Vincular à pasta de Expediente" da tela Assinatura (spec assinatura-documentos-etapa-3,
   E3-10 e E3-11). Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (linha 149: texto e
   botão da linha; 222: botão e link do detalhe; 277: a janela; vincExec e o menu ⋮ na 337 e 443).

   O assinatura.js chama BJAVinculo.criar(ctx) com as utilidades dele e só costura: texto e botão da linha, item do
   menu, botão e link do detalhe e as ações "lin-vincular", "ctx-vincular" e "det-vincular". A janela mora na camada
   própria #bjaCamVinc, fora do redesenho das outras camadas.

   Regras: o vínculo é sempre pelo id da pasta escolhida na lista (sem botão de NUP digitado nem aviso da aba
   Expediente, E3-10); a lista vem do servidor (só pastas que a pessoa edita, até 60; vazio lista as mais recentes).
   ============================================================================= */
(function () {
    'use strict';

    function criar(ctx) {
        const T = (chave, p) => ctx.T('vinculo.' + chave, p);
        const esc = ctx.esc;
        const url = ctx.url || {};
        const camada = ctx.camada;
        let v = null, tBusca = null, seq = 0;

        const ligado = () => !!(url.vincular && url.pastas && camada);
        /* podeVinc do desenho, sem a Lixeira (E3-10): aguardando ou concluído, ainda sem pasta. */
        const pode = (d, st) => ligado() && !!d && !d.vinculado && !d.naLixeira && (st === 'concluido' || st === 'aguardando');
        /* pastaTxt / dPastaTxt: "Na pasta" depois de salvo, "Irá para a pasta" enquanto pendente; só para quem vê a pasta. */
        const texto = x => T((x.feito ? 'na_pasta' : 'ira_para') + (x.cliente ? '_cliente' : ''), { nup: x.nup, cliente: x.cliente });
        const ancora = (classe, d) => (d && d.vinculo
            ? '<a class="' + classe + '" href="' + esc(d.vinculo.url) + '"><i class="bi bi-folder-check"></i>' + esc(texto(d.vinculo)) + '</a>'
            : '');

        function textoDaLinha(d) { return ancora('bjaVincLin', d); }
        function linkDoDetalhe(d) { return ancora('bjaVincDet', d); }

        function botaoDaLinha(d, st) {
            if (!pode(d, st)) { return ''; }
            const r = T('vincular_expediente');
            return '<button type="button" class="bjaB bjaI" data-bja-acao="lin-vincular" aria-label="' + esc(r) + '" data-tip="' + esc(r) + '"><i class="bi bi-folder-symlink"></i></button> ';
        }

        function botaoDoDetalhe(d, st) {
            return pode(d, st) ? '<button type="button" class="bjaB" data-bja-acao="det-vincular"><i class="bi bi-folder-symlink"></i>' + esc(T('vincular')) + '</button>' : '';
        }

        function itemDoMenu(d, st) {
            return pode(d, st) ? ['bi-folder-symlink', T('vincular'), 'ctx-vincular'] : null;
        }

        /* ── A janela (linha 277 do desenho) ─────────────────────────────── */
        function itemHtml(p) {
            return '<button type="button" class="bjaVincIt" data-bja-vinc-pasta="' + esc(p.id) + '">'
                + '<b>' + esc(p.nup) + '</b><span class="bjaVincCli">' + esc(p.cliente) + '</span><span class="bjaVincAcao">' + esc(p.acao) + '</span></button>';
        }

        function listaHtml() {
            if (v.carregando && !v.pastas.length) { return ''; }
            if (!v.pastas.length) { return '<div class="bjaVincVazio">' + esc(T('vazio')) + '</div>'; }
            return v.pastas.map(itemHtml).join('');
        }

        function janelaHtml() {
            const info = T(v.st === 'concluido' ? 'info_concluido' : 'info_aguardando', { nome: v.doc.nome });
            return '<div class="bjaVincFundo">'
                + '<div class="bjaCard bjaVincCx" role="dialog" aria-modal="true" aria-label="' + esc(T('vincular')) + '">'
                + '<div class="bjaHd"><i class="bi bi-folder-symlink"></i>' + esc(T('vincular_expediente')) + '</div>'
                + '<div class="bjaVincCorpo"><div class="bjaVincInfo">' + esc(info) + '</div>'
                + '<input class="bjaF" data-bja-vinc-q aria-label="' + esc(T('buscar')) + '" placeholder="' + esc(T('buscar_placeholder')) + '" value="' + esc(v.q) + '">'
                + '<div class="bjaVincLista" data-bja-vinc-lista>' + listaHtml() + '</div></div>'
                + '<div class="bjaModalPe"><button type="button" class="bjaB bjaN" data-bja-vinc-fechar>' + esc(T('fechar')) + '</button></div>'
                + '</div></div>';
        }

        function desenhar() {
            camada.innerHTML = v ? janelaHtml() : '';
        }

        function redesenharLista() {
            const l = camada.querySelector('[data-bja-vinc-lista]');
            if (l) { l.innerHTML = listaHtml(); }
        }

        async function buscar() {
            if (!v) { return; }
            const meu = ++seq;
            v.carregando = true;
            let r = null;
            try { r = await ctx.pedir(url.pastas + '?q=' + encodeURIComponent(v.q.trim())); } catch (e) { if (meu === seq) { v.carregando = false; ctx.falha(e); } return; }
            if (!v || meu !== seq) { return; }
            v.carregando = false;
            v.pastas = (r && r.pastas) || [];
            redesenharLista();
        }

        function abrir(d, st) {
            if (!pode(d, st)) { return; }
            v = { doc: d, st: st, q: '', pastas: [], carregando: true, enviando: false };
            desenhar();
            const q = camada.querySelector('[data-bja-vinc-q]');
            if (q) { q.focus(); }
            buscar();
        }

        function fechar() {
            v = null; seq++;
            clearTimeout(tBusca);
            desenhar();
        }

        /* vincExec: fecha a janela e avisa como o desenho; o servidor diz se o arquivo já foi para a pasta ou se o
           concluído espera o PDF com selo (o worker gera e o vínculo vai depois). */
        async function vincular(pastaId) {
            if (!v || v.enviando) { return; }
            const p = v.pastas.find(x => String(x.id) === String(pastaId));
            if (!p) { return; }
            v.enviando = true;
            const d = v.doc;
            let r = null;
            try { r = await ctx.postJson(ctx.comId(url.vincular, d.id), { pasta: p.id }); } catch (e) {
                if (v) { v.enviando = false; }
                ctx.avisar((e && e.texto) || T('erro'), 'erro');
                return;
            }
            fechar();
            const concluido = !!(r && r.documento && r.documento.situacao === 'concluido');
            if (!concluido) { ctx.avisar(T('sera_salvo', { nup: p.nup })); } else if (r.aplicado) { ctx.avisar(T('salvo', { nup: p.nup })); } else if (r.pendente) { ctx.avisar(T('sera_salvo_pdf', { nup: p.nup })); } else { ctx.avisar(T('erro'), 'erro'); }
            if (ctx.depois) { await ctx.depois(r && r.documento); }
        }

        if (camada) {
            camada.addEventListener('click', ev => {
                const t = ev.target;
                const it = t.closest('[data-bja-vinc-pasta]');
                if (it) { vincular(it.getAttribute('data-bja-vinc-pasta')); return; }
                if (t.closest('[data-bja-vinc-fechar]')) { fechar(); }
            });
            camada.addEventListener('input', ev => {
                if (!v || !ev.target.hasAttribute('data-bja-vinc-q')) { return; }
                v.q = ev.target.value;
                clearTimeout(tBusca);
                tBusca = setTimeout(buscar, 250);
            });
            document.addEventListener('keydown', ev => {
                if (ev.key === 'Escape' && v) { ev.stopImmediatePropagation(); fechar(); }
            }, true);
        }

        return {
            pode: pode,
            textoDaLinha: textoDaLinha,
            botaoDaLinha: botaoDaLinha,
            itemDoMenu: itemDoMenu,
            botaoDoDetalhe: botaoDoDetalhe,
            linkDoDetalhe: linkDoDetalhe,
            abrir: abrir,
            fechar: fechar,
            aberto: () => v !== null,
        };
    }

    window.BJAVinculo = { criar: criar };
})();
