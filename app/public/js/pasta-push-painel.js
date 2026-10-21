/* =============================================================================
   Coluna direita da aba Push: o cartão de Monitoramento e Situação atual
   (desenho 1.2.3, dc L.2567-2626; spec push-painel-monitoramento).

     1. switches "Monitoramento" (POST `pasta_push_monitoramento`, com o id do
        processo): o do principal troca no lugar o ponto, o título, o subtítulo, o
        "Buscar agora" e a Situação atual, que some pausada (dc L.5420); o de cada
        outro processo troca a própria linha ("· monitorado" / "· pausado");
     2. "Buscar agora" (POST `pasta_push_buscar`): enquanto roda, "Lendo o
        processo..." com `bi-arrow-repeat` (dc L.5443); no fim a página recarrega a
        aba e o aviso chega como flash ("N movimentação(ões) nova(s)");
     3. "Avisar quando chegar" (POST `pasta_push_avisos`, dc L.2629-2636): as três
        chaves; a resposta traz as três, já com a trava de cada uma.

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

        function postar(url, dados) {
            return fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: dados
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, dados: d }; });
            });
        }

        /* ── 1. Switches do monitoramento ─────────────────────────────────────
           O do cabeçalho é o do processo principal (mexe no cabeçalho, na Situação
           atual e no Buscar agora); os dos demais processos só mudam a própria linha. */
        function aplicarChave(botao, ativo, dica) {
            botao.classList.toggle('is-on', ativo);
            botao.setAttribute('aria-checked', ativo ? 'true' : 'false');
            if (dica) { botao.title = dica; } else { botao.removeAttribute('title'); }
        }

        function aplicarCabecalho(dados) {
            ponto.classList.toggle('is-ativo', !!dados.ativo);
            titulo.textContent = dados.statusTxt;
            sub.textContent = dados.statusSub;
            if (situacao) { situacao.hidden = !dados.ativo; }
            if (buscar && buscarPermitido) { buscar.disabled = !dados.ativo; }
        }

        function alternar(botao) {
            botao.disabled = true;
            avisar('');
            var dados = new FormData();
            dados.append('_token', painel.dataset.monitoramentoToken);
            dados.append('processo', botao.dataset.processoId);
            postar(painel.dataset.monitoramentoUrl, dados)
                .then(function (res) {
                    if (!res.ok || !res.dados.sucesso) {
                        throw new Error(res.dados.erro || BJ_T.t('pasta_push.js.painel.monitoramento_falhou'));
                    }
                    var processo = res.dados.processo || {};
                    aplicarChave(botao, !!processo.ativo, processo.dica);
                    aplicarCabecalho(res.dados);
                    var linha = botao.closest('[data-push-painel-processo]');
                    var linhaSub = linha && linha.querySelector('[data-push-painel-processo-sub]');
                    if (linhaSub && processo.sub) { linhaSub.textContent = processo.sub; }
                })
                .catch(function (e) { avisar(e.message); })
                .then(function () { botao.disabled = false; });
        }

        painel.querySelectorAll('[data-push-painel-switch]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                if (!botao.disabled && botao.dataset.processoId) { alternar(botao); }
            });
        });

        /* ── 2. Buscar agora ──────────────────────────────────────────────── */
        function marcarBuscando(sim) {
            buscar.disabled = sim;
            buscar.classList.toggle('is-buscando', sim);
            buscarIco.className = sim ? 'bi bi-arrow-repeat' : 'bi bi-cloud-download';
            buscarTxt.textContent = sim ? BJ_T.t('pasta_push.js.painel.lendo') : BJ_T.t('pasta_push.js.painel.buscar');
        }

        if (buscar) {
            buscar.addEventListener('click', function () {
                if (buscar.disabled) { return; }
                marcarBuscando(true);
                avisar('');
                var dados = new FormData();
                dados.append('_token', painel.dataset.buscarToken);
                postar(painel.dataset.buscarUrl, dados)
                    .then(function (res) {
                        if (!res.ok || !res.dados.sucesso) {
                            throw new Error(res.dados.erro || BJ_T.t('pasta_push.js.painel.buscar_falhou'));
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

    /* ── 3. Avisar quando chegar ─────────────────────────────────────────────
       Cada linha é um switch (POST `pasta_push_avisos` com a `chave` e o `ligado` desejado). O servidor devolve
       as três linhas: uma chave muda a outra ("Por e-mail" trava sem o aviso ao responsável). */
    function iniciarAvisos() {
        var cartao = document.querySelector('[data-push-avisos]');
        if (!cartao) { return; }

        var permitido = cartao.dataset.avisosPermitido === '1';
        var msg = cartao.querySelector('[data-push-avisos-msg]');

        function avisar(texto) {
            if (!msg) { return; }
            msg.textContent = texto || '';
            msg.hidden = !texto;
        }

        function aplicar(itens) {
            itens.forEach(function (item) {
                var botao = cartao.querySelector('[data-push-aviso="' + item.chave + '"]');
                if (!botao) { return; }
                botao.classList.toggle('is-on', !!item.ligado);
                botao.setAttribute('aria-checked', item.ligado ? 'true' : 'false');
                botao.disabled = !permitido || !!item.travado;
                if (item.dica) { botao.title = item.dica; } else { botao.removeAttribute('title'); }
            });
        }

        cartao.querySelectorAll('[data-push-aviso]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                if (botao.disabled) { return; }
                botao.disabled = true;
                avisar('');
                var dados = new FormData();
                dados.append('_token', cartao.dataset.avisosToken);
                dados.append('chave', botao.dataset.pushAviso);
                // O estado DESEJADO (o oposto do que a tela mostra), não "inverter": o clique
                // repetido ou outra aba não desfazem o primeiro.
                dados.append('ligado', botao.getAttribute('aria-checked') === 'true' ? '0' : '1');
                fetch(cartao.dataset.avisosUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: dados
                }).then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (d) {
                        if (!r.ok || !d.sucesso) { throw new Error(d.erro || BJ_T.t('pasta_push.js.painel.aviso_falhou')); }
                        aplicar(d.avisos || []);
                    });
                }).catch(function (e) {
                    botao.disabled = false;
                    avisar(e.message);
                });
            });
        });
    }

    function iniciarTudo() {
        iniciar();
        iniciarAvisos();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarTudo);
    } else {
        iniciarTudo();
    }
})();
