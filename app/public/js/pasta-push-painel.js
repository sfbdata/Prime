/* =============================================================================
   Coluna direita da aba Push: o cartão de Monitoramento e Situação atual
   (desenho 1.2.3, dc L.2567-2626; spec push-painel-monitoramento).

     1. switch "Monitoramento" (POST `pasta_push_monitoramento`): troca no lugar o
        ponto, o título, o subtítulo, o switch, o "Buscar agora" e a Situação atual,
        que some com o monitoramento pausado (dc L.5420);
     2. "Buscar agora" (POST `pasta_push_buscar`): enquanto roda, "Lendo o
        processo..." com `bi-arrow-repeat` (dc L.5443); no fim a página recarrega a
        aba e o aviso chega como flash ("N movimentação(ões) nova(s)").

   Sem innerHTML com dado: só texto (`textContent`), classe e atributo. A recusa do
   servidor (403, 422, 429) aparece na linha de status sob o botão.
   ============================================================================= */
(function () {
    'use strict';

    function iniciar() {
        var painel = document.querySelector('[data-push-painel]');
        if (!painel) { return; }

        var ponto = painel.querySelector('[data-push-painel-ponto]');
        var titulo = painel.querySelector('[data-push-painel-titulo]');
        var sub = painel.querySelector('[data-push-painel-sub]');
        var chave = painel.querySelector('[data-push-painel-switch]');
        var situacao = painel.querySelector('[data-push-painel-situacao]');
        var buscar = painel.querySelector('[data-push-painel-buscar]');
        var buscarIco = painel.querySelector('[data-push-painel-buscar-ico]');
        var buscarTxt = painel.querySelector('[data-push-painel-buscar-txt]');
        var msg = painel.querySelector('[data-push-painel-msg]');
        // Na lápide o "Buscar agora" fica desabilitado em qualquer estado do monitoramento.
        var buscarPermitido = painel.dataset.buscarPermitido === '1';

        function avisar(texto) {
            if (!msg) { return; }
            msg.textContent = texto || '';
            msg.hidden = !texto;
        }

        function postar(url, token) {
            var dados = new FormData();
            dados.append('_token', token);

            return fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: dados
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, dados: d }; });
            });
        }

        /* ── 1. Switch do monitoramento ───────────────────────────────────── */
        function aplicarEstado(ativo, textoTitulo, textoSub) {
            ponto.classList.toggle('is-ativo', ativo);
            titulo.textContent = textoTitulo;
            sub.textContent = textoSub;
            chave.classList.toggle('is-on', ativo);
            chave.setAttribute('aria-checked', ativo ? 'true' : 'false');
            if (situacao) { situacao.hidden = !ativo; }
            if (buscar && buscarPermitido) { buscar.disabled = !ativo; }
        }

        if (chave) {
            chave.addEventListener('click', function () {
                if (chave.disabled) { return; }
                chave.disabled = true;
                avisar('');
                postar(painel.dataset.monitoramentoUrl, painel.dataset.monitoramentoToken)
                    .then(function (res) {
                        if (!res.ok || !res.dados.sucesso) {
                            throw new Error(res.dados.erro || 'Não foi possível alterar o monitoramento.');
                        }
                        aplicarEstado(!!res.dados.ativo, res.dados.statusTxt, res.dados.statusSub);
                    })
                    .catch(function (e) { avisar(e.message); })
                    .then(function () { chave.disabled = false; });
            });
        }

        /* ── 2. Buscar agora ──────────────────────────────────────────────── */
        function marcarBuscando(sim) {
            buscar.disabled = sim;
            buscar.classList.toggle('is-buscando', sim);
            buscarIco.className = sim ? 'bi bi-arrow-repeat' : 'bi bi-cloud-download';
            buscarTxt.textContent = sim ? 'Lendo o processo...' : 'Buscar agora';
        }

        if (buscar) {
            buscar.addEventListener('click', function () {
                if (buscar.disabled) { return; }
                marcarBuscando(true);
                avisar('');
                postar(painel.dataset.buscarUrl, painel.dataset.buscarToken)
                    .then(function (res) {
                        if (!res.ok || !res.dados.sucesso) {
                            throw new Error(res.dados.erro || 'Não foi possível buscar agora.');
                        }
                        // O aviso volta como flash; a aba Push reabre pelo fragmento (pasta-show.js).
                        window.location.hash = 'push';
                        window.location.reload();
                    })
                    .catch(function (e) {
                        marcarBuscando(false);
                        avisar(e.message);
                    });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
