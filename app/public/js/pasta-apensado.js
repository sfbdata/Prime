/* =============================================================================
   Processos apensados da pasta (P4f, spec docs/specs/processo-apensados.md).

     1. Modo apenso do modal "Vincular processo" (#modalVincularProcesso): o botão
        "Vincular apensado" (`data-vincular-modo="apenso"`) abre o MESMO modal com o
        título e o botão "Vincular apensado" e o select "Tipo de apenso" (desenho
        `pvVals`, N:3135-3151; padrão Embargos). Qualquer outro gatilho abre no modo
        de sempre. O envio continua o do show.html.twig (`modo` e `tipo_apenso` vão no
        mesmo POST); ao dar certo, ele avisa por `mp:processo-vinculado` e aqui sai o
        aviso do desenho (N:4556).
     2. X e ⋮ "Desvincular da pasta" do cartão do apensado (`form.js-desvincular-apensado`):
        confirmação vermelha "Desvincular apensado" (N:6711), POST por XHR e troca do
        cartão pelo HTML do servidor (`mpSwapProcessos`).
     3. Dica da pílula "N apensados" da aba Dados (N:7000): "Ver" / "Recolher".

   Não monta marcação: só textContent, atributos e o HTML que o servidor já renderizou.
   ============================================================================= */
(function () {
    'use strict';

    /* O aviso do modo apenso sai do catálogo na hora do uso (este script pode rodar antes do bj-traducao.js). */

    /* ── 1. Modo apenso do modal ─────────────────────────────────────────── */
    function modoDoModal() {
        var modal = document.getElementById('modalVincularProcesso');
        var campoModo = document.getElementById('mp-modo');
        var blocoTipo = document.getElementById('mp-apenso-tipo');
        var selectTipo = document.getElementById('mp-tipoApenso');
        var titulo = document.getElementById('mp-titulo-texto');
        var salvar = document.getElementById('mp-salvar-texto');
        if (!modal || !campoModo || !blocoTipo || !selectTipo || !titulo || !salvar) { return; }

        var tituloPadrao = titulo.textContent;
        var salvarPadrao = salvar.textContent;

        function aplicar(apenso) {
            campoModo.value = apenso ? 'apenso' : 'principal';
            blocoTipo.hidden = !apenso;
            // Desabilitado no modo de sempre: o `tipo_apenso` nem vai no POST.
            selectTipo.disabled = !apenso;
            if (apenso) { selectTipo.selectedIndex = 0; }
            titulo.textContent = apenso ? BJ_T.t('pasta_show.js.apensado.vincular') : tituloPadrao;
            salvar.textContent = apenso ? BJ_T.t('pasta_show.js.apensado.vincular') : salvarPadrao;
        }

        // A janela do desenho (show.html.twig) avisa a abertura com o botão que a abriu.
        modal.addEventListener('vp:abrir', function (e) {
            var gatilho = e.detail && e.detail.gatilho;
            aplicar(!!(gatilho && gatilho.getAttribute('data-vincular-modo') === 'apenso'));
        });

        document.addEventListener('mp:processo-vinculado', function () {
            if (campoModo.value === 'apenso' && window.avisarPs) { window.avisarPs(BJ_T.t('pasta_show.js.apensado.aviso')); }
        });
    }

    /* ── 2. Desvincular o apensado ───────────────────────────────────────── */
    function perguntar(form, botao) {
        var texto = form.getAttribute('data-confirmar-texto') || BJ_T.t('pasta_show.js.apensado.confirmar');
        if (!window.confirmarPs) { return Promise.resolve(window.confirm(texto)); }

        return window.confirmarPs({
            titulo: BJ_T.t('pasta_show.js.apensado.desvincular_titulo'),
            texto: texto,
            rotulo: BJ_T.t('pasta_show.js.apensado.desvincular'),
            icone: 'bi-x-lg',
            foco: botao
        });
    }

    async function desvincular(form) {
        var botao = form.querySelector('button[type="submit"]');
        var sim = await perguntar(form, botao);
        if (!sim) { return; }
        if (botao) { botao.disabled = true; }

        try {
            var resp = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            });
            var dados = await resp.json().catch(function () { return {}; });
            if (resp.ok && dados.sucesso && window.mpSwapProcessos) {
                window.mpSwapProcessos(dados.html);

                return;
            }
            avisarErro(dados.erro || BJ_T.t('pasta_show.js.apensado.erro'));
        } catch (err) {
            avisarErro(BJ_T.t('pasta_show.js.apensado.erro_comunicacao'));
        } finally {
            if (botao && document.contains(botao)) { botao.disabled = false; }
        }
    }

    function avisarErro(mensagem) {
        if (window.avisarPs) { window.avisarPs(mensagem, 'erro'); return; }
        (window.avisarErroPs || window.alert)(mensagem);
    }

    function desvincularApensado() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('form.js-desvincular-apensado')) { return; }
            e.preventDefault();
            desvincular(form);
        });
    }

    /* ── 3. Dica da pílula da aba Dados ──────────────────────────────────── */
    function dicaDaPilula() {
        document.addEventListener('click', function (e) {
            var chip = e.target.closest('.ps-chip-vinc--apensados');
            if (!chip) { return; }
            var aberto = chip.getAttribute('aria-expanded') === 'true';
            chip.title = chip.getAttribute(aberto ? 'data-dica-aberto' : 'data-dica-fechado') || chip.title;
        });
    }

    function iniciar() {
        modoDoModal();
        desvincularApensado();
        dicaDaPilula();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
