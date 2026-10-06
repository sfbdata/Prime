/* =============================================================================
   BlueJus IA — agentes da pasta (spec inteligencia-agentes-da-pasta §5).

     1. abre/fecha o drawer do cabeçalho (botão, X, fundo, Esc) e, na PRIMEIRA
        abertura, carrega o painel (GET `inteligencia_agentes_painel`) no lugar
        da lista estática;
     2. "Gerar análise" de um agente: POST `inteligencia_agente_solicitar` e o
        retorno honesto (202 em andamento, 200 "nada novo", 409/429 motivo) —
        motivo de disponibilidade trava TODOS os agentes até a página recarregar;
     3. polling do status (GET `inteligencia_analise_status`) enquanto houver
        análise pendente/processando no drawer — 2 s, 5 s depois de 30 s, 10 s
        depois de 2 min; PAUSA com a página oculta ou o drawer fechado —, e
        recarga do fragmento DO AGENTE (GET `inteligencia_agente_listar`) quando
        ela termina;
     4. ações da análise (lida · excluir · interna) por XHR + recarga do agente;
     5. menu ⋮ do cartão;
     6. "Criar tarefa da providência": abre o `#modalCriarTarefa` da pasta
        pré-preenchido, no mesmo contrato do pasta-ia-push.js.

   Tudo por delegação no drawer: o painel e as listas são trocados inteiros.
   Sem JS, os formulários dos fragmentos seguem por POST comum.
   ============================================================================= */
(function () {
    'use strict';

    var BACKOFF = [[30000, 2000], [120000, 5000], [Infinity, 10000]];
    var LIMITE_MS = 600000;
    var EM_ANDAMENTO = ['pendente', 'processando'];
    var MOTIVOS_QUE_TRAVAM = ['nao_configurada_na_plataforma', 'desligada_no_escritorio', 'sem_permissao', 'limite_atingido'];

    function intervaloPara(decorrido) {
        for (var i = 0; i < BACKOFF.length; i++) {
            if (decorrido < BACKOFF[i][0]) { return BACKOFF[i][1]; }
        }

        return BACKOFF[BACKOFF.length - 1][1];
    }

    function iniciar() {
        var drawer = document.querySelector('[data-ia-drawer]');
        var abrirBotao = document.querySelector('[data-ia-abrir]');
        var fundo = document.querySelector('[data-ia-fundo]');
        if (!drawer || !abrirBotao) { return; }

        var msg = drawer.querySelector('[data-ia-msg]');
        var timer = null;
        var consultando = false;
        var inicio = Date.now();
        var limiteAvisado = false;
        var carregandoPainel = false;

        function painel() { return drawer.querySelector('[data-ia-painel]'); }
        function aberto() { return !drawer.hidden; }

        function avisar(texto, erro) {
            if (!msg) { return; }
            msg.textContent = texto || '';
            msg.classList.toggle('is-erro', !!erro);
            msg.hidden = !texto;
        }

        function lerJson(r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                return { ok: r.ok, status: r.status, dados: d || {} };
            });
        }

        function buscarFragmento(url) {
            return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error('Não foi possível atualizar as análises.'); }
                    return r.text();
                })
                .then(function (html) {
                    var molde = document.createElement('template');
                    molde.innerHTML = html.trim();
                    return molde.content;
                });
        }

        /* ── 1. Abrir / fechar ───────────────────────────────────────────── */
        function abrir() {
            drawer.hidden = false;
            if (fundo) { fundo.hidden = false; }
            abrirBotao.setAttribute('aria-expanded', 'true');
            document.body.classList.add('ia-drawer-aberto');
            var fechar = drawer.querySelector('[data-ia-fechar]');
            if (fechar) { fechar.focus(); }
            carregarPainel();
            retomar();
        }

        function fechar() {
            drawer.hidden = true;
            if (fundo) { fundo.hidden = true; }
            abrirBotao.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('ia-drawer-aberto');
            pausar();
            abrirBotao.focus();
        }

        abrirBotao.addEventListener('click', function () {
            if (aberto()) { fechar(); return; }
            abrir();
        });
        drawer.addEventListener('click', function (e) {
            if (e.target.closest('[data-ia-fechar]')) { fechar(); }
        });
        if (fundo) { fundo.addEventListener('click', fechar); }
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !aberto()) { return; }
            if (fecharMenus(null)) { return; }
            fechar();
        });

        function carregarPainel() {
            var p = painel();
            if (!p || p.getAttribute('data-ia-carregado') === '1' || carregandoPainel) { return; }
            carregandoPainel = true;

            buscarFragmento(drawer.getAttribute('data-ia-painel-url'))
                .then(function (conteudo) {
                    var novo = conteudo.querySelector('[data-ia-painel]');
                    var atual = painel();
                    if (!novo || !atual) { return; }
                    atual.replaceWith(novo);
                    acompanhar();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : 'Não foi possível carregar as análises.', true);
                })
                .then(function () { carregandoPainel = false; });
        }

        /* ── Botões e listas por agente ───────────────────────────────────── */
        function secaoDe(el) { return el ? el.closest('[data-ia-agente]') : null; }
        function listaDe(secao) { return secao ? secao.querySelector('[data-ia-lista]') : null; }
        function botaoDe(secao) { return secao ? secao.querySelector('[data-ia-gerar]') : null; }

        function rotuloDoBotao(botao, texto) {
            var r = botao ? botao.querySelector('.ia-cta-rotulo') : null;
            if (r) { r.textContent = texto; }
        }

        function botaoCarregando(botao, sim) {
            if (!botao || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            botao.disabled = !!sim;
            botao.classList.toggle('is-carregando', !!sim);
            if (sim) { rotuloDoBotao(botao, 'Analisando…'); }
        }

        // Rótulo e title saem do que a lista recém-carregada diz (mesma regra do template).
        function sincronizarBotao(secao) {
            var botao = botaoDe(secao);
            var l = listaDe(secao);
            if (!botao || !l || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            if (l.getAttribute('data-ia-em-andamento') === '1') {
                botaoCarregando(botao, true);
                botao.title = 'Análise em andamento';
                return;
            }
            botaoCarregando(botao, false);
            var temConcluida = l.getAttribute('data-ia-tem-concluida') === '1';
            rotuloDoBotao(botao, temConcluida ? 'Gerar nova análise' : 'Gerar análise');
            botao.title = temConcluida ? 'Nova análise deste agente considerando o histórico' : 'Lê os dados desta pasta e responde';
        }

        // Um 409/429 de disponibilidade vale para todos os agentes até a página recarregar.
        function travarTodos(mensagem, motivo) {
            drawer.querySelectorAll('[data-ia-gerar]').forEach(function (botao) {
                botao.setAttribute('data-ia-disponivel', '0');
                if (motivo) { botao.setAttribute('data-ia-motivo', motivo); }
                botao.classList.remove('is-carregando');
                botao.classList.add('ia-cta--indisponivel');
                botao.disabled = true;
                botao.setAttribute('aria-disabled', 'true');
                botao.title = mensagem;
                rotuloDoBotao(botao, mensagem);
            });
        }

        function recarregarAgente(secao, idNova) {
            var l = listaDe(secao);
            if (!l) { return Promise.resolve(); }

            return buscarFragmento(l.getAttribute('data-ia-url'))
                .then(function (conteudo) {
                    var nova = conteudo.querySelector('[data-ia-lista]');
                    var atual = listaDe(secao);
                    if (!nova || !atual) { return; }
                    atual.replaceWith(nova);
                    if (idNova) {
                        var cartao = nova.querySelector('[data-ia-analise="' + idNova + '"]');
                        if (cartao) { cartao.classList.add('is-nova'); }
                    }
                    sincronizarBotao(secao);
                    acompanhar();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : 'Não foi possível atualizar as análises.', true);
                });
        }

        /* ── 3. Polling ───────────────────────────────────────────────────── */
        function emAndamento() {
            return Array.prototype.filter.call(drawer.querySelectorAll('[data-ia-analise]'), function (c) {
                return EM_ANDAMENTO.indexOf(c.getAttribute('data-ia-status')) !== -1;
            });
        }

        function ativo() { return !document.hidden && aberto(); }

        function pausar() {
            if (timer !== null) { clearTimeout(timer); }
            timer = null;
        }

        function retomar() {
            if (!ativo() || timer !== null || consultando || emAndamento().length === 0) { return; }
            inicio = Date.now();
            limiteAvisado = false;
            perguntar();
        }

        function perguntar() {
            timer = null;
            if (!ativo()) { return; }
            var cartoes = emAndamento();
            if (cartoes.length === 0) { return; }

            if (Date.now() - inicio > LIMITE_MS) {
                if (!limiteAvisado) {
                    limiteAvisado = true;
                    avisar('A análise está demorando mais que o normal. Recarregue a página mais tarde para ver o resultado.', true);
                }
                return;
            }

            consultando = true;
            Promise.all(cartoes.map(function (c) {
                return fetch(c.getAttribute('data-ia-status-url'), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(lerJson)
                    .then(function (res) {
                        // 404: a análise sumiu (excluída em outra aba) — a recarga resolve.
                        return (!res.ok || !!res.dados.terminal) ? secaoDe(c) : null;
                    })
                    .catch(function () { return null; });
            })).then(function (secoes) {
                consultando = false;
                var terminadas = secoes.filter(function (s, i, arr) { return s && arr.indexOf(s) === i; });
                if (terminadas.length > 0) {
                    Promise.all(terminadas.map(function (s) { return recarregarAgente(s); })).then(agendar);
                    return;
                }
                agendar();
            });
        }

        function agendar() {
            if (timer !== null || consultando || !ativo()) { return; }
            if (emAndamento().length === 0) { return; }
            timer = setTimeout(perguntar, intervaloPara(Date.now() - inicio));
        }

        function acompanhar() {
            if (emAndamento().length > 0) {
                agendar();
                return;
            }
            inicio = Date.now();
            limiteAvisado = false;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { pausar(); return; }
            retomar();
        });

        /* ── 2. Gerar análise ─────────────────────────────────────────────── */
        drawer.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ia-gerar]');
            if (!botao || !drawer.contains(botao)) { return; }
            if (botao.disabled || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            var secao = secaoDe(botao);

            var dados = new FormData();
            dados.append('_token', drawer.getAttribute('data-ia-token') || '');

            avisar('');
            botaoCarregando(botao, true);
            inicio = Date.now();
            limiteAvisado = false;

            fetch(botao.getAttribute('data-ia-url'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: dados
            })
                .then(lerJson)
                .then(function (res) {
                    var d = res.dados;
                    if (!res.ok) {
                        var texto = d.mensagem || 'Não foi possível pedir a análise agora. Tente novamente.';
                        avisar(texto, true);
                        if (MOTIVOS_QUE_TRAVAM.indexOf(d.motivo) !== -1) {
                            travarTodos(texto, d.motivo);
                        } else {
                            botaoCarregando(botao, false);
                            sincronizarBotao(secao);
                        }
                        return;
                    }
                    if (d.aviso) { avisar(d.aviso, false); }
                    recarregarAgente(secao, d.id);
                })
                .catch(function () {
                    avisar('Não foi possível pedir a análise agora. Tente novamente.', true);
                    botaoCarregando(botao, false);
                    sincronizarBotao(secao);
                });
        });

        /* ── 5. Menu ⋮ ────────────────────────────────────────────────────── */
        function fecharMenus(exceto) {
            var fechou = false;
            drawer.querySelectorAll('[data-ia-menu]').forEach(function (g) {
                var menu = g.parentNode.querySelector('.ia-menu');
                if (!menu || menu === exceto || menu.hidden) { return; }
                menu.hidden = true;
                g.setAttribute('aria-expanded', 'false');
                fechou = true;
            });

            return fechou;
        }

        drawer.addEventListener('click', function (e) {
            var gatilho = e.target.closest('[data-ia-menu]');
            if (!gatilho) { return; }
            e.stopPropagation();
            var menu = gatilho.parentNode.querySelector('.ia-menu');
            if (!menu) { return; }
            var abrirMenu = menu.hidden;
            fecharMenus(abrirMenu ? menu : null);
            menu.hidden = !abrirMenu;
            gatilho.setAttribute('aria-expanded', abrirMenu ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('.ia-menu') && !e.target.closest('.ia-menu-item')) { return; }
            if (e.target.closest('[data-ia-menu]')) { return; }
            fecharMenus(null);
        });

        /* ── 4. Ações da análise ──────────────────────────────────────────── */
        drawer.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-ia-acao]');
            if (!form || !drawer.contains(form)) { return; }
            e.preventDefault();

            var confirmar = form.getAttribute('data-ia-confirmar');
            if (confirmar && !window.confirm(confirmar)) { return; }

            var secao = secaoDe(form);
            var enviar = form.querySelector('button[type="submit"]');
            if (enviar) { enviar.disabled = true; }
            avisar('');

            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: new FormData(form)
            })
                .then(lerJson)
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error(res.dados.mensagem || res.dados.message || 'Não foi possível atualizar a análise.');
                    }
                    return recarregarAgente(secao);
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : 'Não foi possível atualizar a análise.', true);
                    if (enviar) { enviar.disabled = false; }
                });
        });

        /* ── 6. Criar tarefa da providência ───────────────────────────────── */
        drawer.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ia-criar-meta]');
            if (!item) { return; }

            var modal = document.getElementById('modalCriarTarefa');
            if (!modal || !window.bootstrap) { return; }

            var d = item.dataset;
            var linhas = ['Providência indicada pelo ' + (d.metaAgente || 'agente') + ' da BlueJus IA em ' + (d.metaQuando || '') + '.'];
            linhas.push('Gerado por IA · não é ato oficial do processo. Confira os documentos originais antes de agir.');
            if (d.metaPonto) { linhas.push(''); linhas.push((d.metaRotulo ? d.metaRotulo + ': ' : '') + d.metaPonto); }
            if (d.metaQuem) { linhas.push('Quem deve agir: ' + d.metaQuem); }
            if (d.metaResumo) { linhas.push(''); linhas.push('Resumo: ' + d.metaResumo); }

            var titulo = document.getElementById('tarefaTitulo');
            var descricao = document.getElementById('tarefaDescricao');
            var prazo = document.getElementById('tarefaPrazo');
            if (titulo) { titulo.value = (d.metaTitulo || 'Providência indicada pela análise IA').slice(0, 255); }
            if (descricao) { descricao.value = linhas.join('\n').slice(0, 5000); }
            if (prazo) {
                var p = new Date(); p.setDate(p.getDate() + 7);
                prazo.value = p.getFullYear() + '-' + String(p.getMonth() + 1).padStart(2, '0') + '-' + String(p.getDate()).padStart(2, '0');
            }

            // Mesmo sinal do "Criar tarefa" da publicação: o pasta-push.js limpa ao fechar.
            modal.dataset.origemPush = '1';
            fechar();
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
