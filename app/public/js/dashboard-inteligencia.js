/* Marcas dos alertas da Central de Inteligência (painel BlueJus Intelligence).
   Botões Resolvido, Ignorar, Aplicar e Não se aplica (desenho 01, L966-975). Cada clique grava
   POST /dashboard/inteligencia/alerta no servidor (marca pessoal do usuário) e só então muda a tela
   com o que o servidor devolveu. O painel é trocado junto com o fragmento dos filtros, então o
   clique é delegado no document (sobrevive à troca). Os rótulos vêm do HTML (data-rotulo-*),
   traduzidos no servidor. */
(function () {
    'use strict';

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function pressionar(alerta, acao, ligado) {
        var botoes = alerta.querySelectorAll('[data-ia-marcar="' + acao + '"]');
        Array.prototype.forEach.call(botoes, function (b) {
            b.setAttribute('aria-pressed', ligado ? 'true' : 'false');
        });
    }

    /* Marcado desce para o fim; desmarcado volta à posição do servidor (data-ordem), entre os não marcados. */
    function reposicionar(alerta, lista, marcado) {
        var itens = Array.prototype.slice.call(lista.querySelectorAll('[data-alerta]')).filter(function (a) { return a !== alerta; });
        var ordem = parseInt(alerta.getAttribute('data-ordem'), 10);
        var ref = null;
        if (marcado) {
            ref = null;
        } else {
            ref = itens.filter(function (a) {
                return !a.classList.contains('is-marcado') && parseInt(a.getAttribute('data-ordem'), 10) > ordem;
            })[0] || itens.filter(function (a) { return a.classList.contains('is-marcado'); })[0] || null;
        }
        lista.insertBefore(alerta, ref);
    }

    function mostrar(alerta, lista, marca) {
        var status = alerta.querySelector('[data-ia-status]');
        var rotulo = marca.status ? lista.getAttribute('data-rotulo-' + marca.status) : '';
        alerta.classList.toggle('is-marcado', !!marca.status);
        if (status) {
            status.textContent = rotulo || '';
            status.hidden = !marca.status;
        }
        pressionar(alerta, 'resolvido', marca.status === 'resolvido');
        pressionar(alerta, 'ignorado', marca.status === 'ignorado');
        pressionar(alerta, 'sim', marca.feedback === 'sim');
        pressionar(alerta, 'nao', marca.feedback === 'nao');
        reposicionar(alerta, lista, !!marca.status);
    }

    function marcar(botao) {
        var alerta = botao.closest('[data-alerta]');
        var lista = botao.closest('[data-ia-alertas]');
        if (!alerta || !lista || botao.disabled) { return; }
        botao.disabled = true;
        fetch(lista.getAttribute('data-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify({ alerta: alerta.getAttribute('data-alerta'), acao: botao.getAttribute('data-ia-marcar') })
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (marca) {
            mostrar(alerta, lista, marca);
        }).catch(function () {
            /* sem gravar, a tela fica como estava */
        }).then(function () {
            botao.disabled = false;
        });
    }

    /* Teste · simular dia (dc L1341-1347). O controle mora no rodapé, FORA do corpo trocado, para não
       perder o foco ao arrastar. Cada parada pede ao servidor a leitura com `dia_simulado` (data) e
       troca só o `.db-ia-corpo`; o dia de hoje não manda o parâmetro (desfaz). Nada é gravado. */
    var simPedido = 0;
    var simTimer = null;

    function dataDoDia(controle) {
        var base = controle.getAttribute('data-inicio').split('-');
        var d = new Date(Date.UTC(+base[0], +base[1] - 1, +base[2] + parseInt(controle.value, 10) - 1));
        return d.toISOString().slice(0, 10);
    }

    function carregarSimulacao(controle) {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        var params = new URLSearchParams(form ? new FormData(form) : '');
        if (controle.value !== controle.getAttribute('data-dia-real')) {
            params.set('dia_simulado', dataDoDia(controle));
        }
        var pedido = ++simPedido;
        fetch(window.location.pathname + '?' + params.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.text();
        }).then(function (html) {
            if (pedido !== simPedido) { return; }
            var novo = new DOMParser().parseFromString(html, 'text/html').querySelector('#dbIaPainel > .db-ia-corpo');
            var atual = document.querySelector('#dbIaPainel > .db-ia-corpo');
            if (novo && atual) { atual.replaceWith(novo); }
        }).catch(function () {
            /* sem resposta, o painel fica como estava */
        });
    }

    document.addEventListener('input', function (e) {
        var controle = e.target.closest ? e.target.closest('[data-ia-simular]') : null;
        if (!controle) { return; }
        var saida = document.querySelector('[data-ia-sim-dia]');
        if (saida) { saida.textContent = controle.value; }
        window.clearTimeout(simTimer);
        simTimer = window.setTimeout(function () { carregarSimulacao(controle); }, 250);
    });

    /* Fechar o painel desfaz a simulação (dc: abrir zera o dia simulado). */
    document.addEventListener('toggle', function (e) {
        if (!e.target || e.target.id !== 'dbIaPainel' || e.newState !== 'closed') { return; }
        var controle = e.target.querySelector('[data-ia-simular]');
        if (!controle || controle.value === controle.getAttribute('data-dia-real')) { return; }
        controle.value = controle.getAttribute('data-dia-real');
        var saida = e.target.querySelector('[data-ia-sim-dia]');
        if (saida) { saida.textContent = controle.value; }
        carregarSimulacao(controle);
    }, true);

    /* Perguntas guiadas (dc L1009-1018): uma pílula ativa e uma caixa de resposta só; tocar de novo fecha. */
    function escolherPergunta(pilula) {
        var bloco = pilula.closest('.db-ia-perguntas');
        var caixa = bloco ? bloco.querySelector('[data-ia-resposta-caixa]') : null;
        if (!caixa) { return; }
        var abrir = pilula.getAttribute('aria-pressed') !== 'true';
        bloco.querySelectorAll('[data-ia-pergunta]').forEach(function (p) {
            p.setAttribute('aria-pressed', p === pilula && abrir ? 'true' : 'false');
        });
        caixa.querySelector('span').textContent = abrir ? pilula.getAttribute('data-ia-pergunta') : '';
        caixa.hidden = !abrir;
    }

    /* Aumentar o nível (dc L1284-1325): os 4 níveis chegam prontos do servidor; escolher só alterna o painel visível. */
    function escolherNivel(botao) {
        var plano = botao.closest('[data-ia-plano]');
        if (!plano) { return; }
        var id = botao.getAttribute('data-ia-nivel');
        plano.querySelectorAll('[data-ia-nivel]').forEach(function (b) {
            b.setAttribute('aria-pressed', b === botao ? 'true' : 'false');
        });
        plano.querySelectorAll('[data-ia-plano-nivel]').forEach(function (n) {
            n.hidden = n.getAttribute('data-ia-plano-nivel') !== id;
        });
    }

    function avisarPlano(plano, texto, erro) {
        var aviso = plano.querySelector('[data-ia-plano-aviso]');
        if (!aviso) { return; }
        aviso.textContent = texto;
        aviso.classList.toggle('is-erro', !!erro);
        aviso.hidden = false;
    }

    /* A tela do filtro é trocada por inteiro (cards e painel): reabre o painel quando o novo chega. */
    function reabrirPainelQuandoTrocar() {
        var antigo = document.getElementById('dbIaPainel');
        var obs = new MutationObserver(function () {
            var novo = document.getElementById('dbIaPainel');
            if (novo && novo !== antigo) {
                obs.disconnect();
                if (novo.showPopover) { novo.showPopover(); }
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(function () { obs.disconnect(); }, 8000);
    }

    function recarregarPainel() {
        var form = document.querySelector('[data-filtro-root] [data-filtro-form]');
        if (!form) { window.location.reload(); return; }
        reabrirPainelQuandoTrocar();
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    /* "Definir ... como meta do mês": o mesmo POST do editor de meta (só o primário; o servidor confere). */
    function aplicarPlano(botao) {
        var plano = botao.closest('[data-ia-plano]');
        var valor = parseInt(botao.getAttribute('data-valor'), 10);
        if (!plano || !valor || botao.disabled) { return; }
        botao.disabled = true;
        avisarPlano(plano, window.BJ_T.t('dashboard.js.plano.aplicando'), false);
        fetch(plano.getAttribute('data-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token() },
            body: JSON.stringify({ valor: valor })
        }).then(function (r) {
            if (!r.ok) {
                var erro = new Error('HTTP ' + r.status);
                erro.status = r.status;
                throw erro;
            }
            avisarPlano(plano, window.BJ_T.t('dashboard.js.plano.aplicada', { meta: valor }), false);
            recarregarPainel();
        }).catch(function (erro) {
            botao.disabled = false;
            avisarPlano(plano, window.BJ_T.t(erro && erro.status === 403 ? 'dashboard.js.plano.sem_permissao' : 'dashboard.js.plano.erro'), true);
        });
    }

    document.addEventListener('click', function (e) {
        var botao = e.target.closest ? e.target.closest('[data-ia-marcar]') : null;
        if (botao) { marcar(botao); }
        var pilula = e.target.closest ? e.target.closest('[data-ia-pergunta]') : null;
        if (pilula) { escolherPergunta(pilula); }
        var nivel = e.target.closest ? e.target.closest('[data-ia-nivel]') : null;
        if (nivel) { escolherNivel(nivel); }
        var aplicar = e.target.closest ? e.target.closest('[data-ia-plano-aplicar]') : null;
        if (aplicar) { aplicarPlano(aplicar); }
    });
}());
