/* =============================================================================
   BlueJus IA na aba Push da pasta (spec inteligencia-resumo-do-push §3.6).

     1. "Resumir com IA" / "Gerar nova análise": POST `inteligencia_push_solicitar`
        e mostra o retorno honesto (202 em andamento, 200 "nada novo", 409/429 motivo);
     2. polling do status (GET `inteligencia_analise_status`) a cada 2 s enquanto
        houver análise pendente/processando, e recarga do fragmento da lista
        (GET `inteligencia_push_listar`) quando ela termina — sem F5;
     3. ações da análise (lida · excluir · interna) por XHR, com recarga da lista;
     4. menu ⋮ do cartão;
     5. "Criar tarefa da providência": abre o `#modalCriarTarefa` da pasta
        pré-preenchido, no mesmo contrato do "Criar tarefa" da publicação
        (pasta-push.js): marca `data-origem-push` e o pasta-push.js limpa os
        campos ao fechar. O prazo nasce em +7 dias, como o desenho (editável).

   A lista é trocada inteira a cada recarga, então todo clique é por delegação
   na seção `.ps-push`. Sem JS, os formulários do fragmento seguem por POST comum.
   ============================================================================= */
(function () {
    'use strict';

    var INTERVALO_MS = 2000;
    // ~10 min: depois disso para de perguntar (o worker pode estar parado) e diz isso.
    var MAX_TENTATIVAS = 300;
    var EM_ANDAMENTO = ['pendente', 'processando'];

    function iniciar() {
        var secao = document.querySelector('.ps-push');
        if (!secao || !secao.querySelector('[data-ia-lista]')) { return; }

        var botao = secao.querySelector('[data-ia-gerar]');
        var msg = secao.querySelector('[data-ia-msg]');
        var timer = null;
        var tentativas = 0;

        function lista() { return secao.querySelector('[data-ia-lista]'); }

        /* ── Faixa de retorno ─────────────────────────────────────────────── */
        function avisar(texto, erro) {
            if (!msg) { return; }
            msg.textContent = texto || '';
            msg.classList.toggle('is-erro', !!erro);
            msg.hidden = !texto;
        }

        /* ── Botão do cabeçalho ───────────────────────────────────────────── */
        function rotuloDoBotao(texto) {
            var r = botao ? botao.querySelector('.ps-ia-gerar-rotulo') : null;
            if (r) { r.textContent = texto; }
        }

        function botaoCarregando(sim) {
            if (!botao || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            botao.disabled = !!sim;
            botao.classList.toggle('is-carregando', !!sim);
            if (sim) { rotuloDoBotao('Analisando…'); }
        }

        // Rótulo e title saem do que a lista recém-carregada diz (mesma regra do template).
        function sincronizarBotao() {
            var l = lista();
            if (!botao || !l || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            if (l.getAttribute('data-ia-em-andamento') === '1') {
                botaoCarregando(true);
                return;
            }
            botaoCarregando(false);
            var temConcluida = l.getAttribute('data-ia-tem-concluida') === '1';
            var naoAnalisadas = parseInt(l.getAttribute('data-ia-nao-analisadas') || '0', 10);
            rotuloDoBotao(temConcluida ? 'Gerar nova análise' : 'Resumir com IA');
            botao.title = !temConcluida
                ? 'A IA lê as movimentações e indica prazos e providências'
                : (naoAnalisadas > 0
                    ? naoAnalisadas + ' movimentação(ões) ainda não analisada(s)'
                    : 'Nova análise considerando o histórico');
        }

        // Um 409/429 de disponibilidade vale até a página recarregar: o botão passa a dizer o motivo.
        function botaoIndisponivel(mensagem, motivo) {
            if (!botao) { return; }
            botao.setAttribute('data-ia-disponivel', '0');
            if (motivo) { botao.setAttribute('data-ia-motivo', motivo); }
            botao.classList.remove('is-carregando');
            botao.classList.add('ps-ia-gerar--indisponivel');
            botao.disabled = true;
            botao.setAttribute('aria-disabled', 'true');
            botao.title = mensagem;
            rotuloDoBotao(mensagem);
        }

        /* ── Rede ─────────────────────────────────────────────────────────── */
        function lerJson(r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                return { ok: r.ok, status: r.status, dados: d || {} };
            });
        }

        function recarregarLista(idNova) {
            var l = lista();
            if (!l) { return Promise.resolve(); }
            var url = l.getAttribute('data-ia-url');

            return fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error('Não foi possível atualizar as análises.'); }
                    return r.text();
                })
                .then(function (html) {
                    var molde = document.createElement('template');
                    molde.innerHTML = html.trim();
                    var nova = molde.content.querySelector('[data-ia-lista]');
                    var atual = lista();
                    if (!nova || !atual) { return; }
                    atual.replaceWith(nova);
                    if (idNova) {
                        var cartao = nova.querySelector('[data-ia-analise="' + idNova + '"]');
                        if (cartao) { cartao.classList.add('is-nova'); }
                    }
                    sincronizarBotao();
                    acompanhar();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : 'Não foi possível atualizar as análises.', true);
                });
        }

        /* ── 2. Polling ───────────────────────────────────────────────────── */
        function emAndamento() {
            var l = lista();
            if (!l) { return []; }

            return Array.prototype.filter.call(l.querySelectorAll('[data-ia-analise]'), function (c) {
                return EM_ANDAMENTO.indexOf(c.getAttribute('data-ia-status')) !== -1;
            });
        }

        function perguntar() {
            timer = null;
            var cartoes = emAndamento();
            if (cartoes.length === 0) { return; }

            tentativas++;
            if (tentativas > MAX_TENTATIVAS) {
                avisar('A análise está demorando mais que o normal. Recarregue a página mais tarde para ver o resultado.', true);
                return;
            }

            Promise.all(cartoes.map(function (c) {
                return fetch(c.getAttribute('data-ia-status-url'), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(lerJson)
                    .then(function (res) {
                        // 404: a análise sumiu (excluída em outra aba) — a recarga resolve.
                        return !res.ok || !!res.dados.terminal;
                    })
                    .catch(function () { return false; });
            })).then(function (terminou) {
                if (terminou.indexOf(true) !== -1) {
                    recarregarLista();
                    return;
                }
                agendar();
            });
        }

        function agendar() {
            if (timer === null) { timer = setTimeout(perguntar, INTERVALO_MS); }
        }

        function acompanhar() {
            if (emAndamento().length > 0) {
                agendar();
                return;
            }
            tentativas = 0;
        }

        /* ── 1. Pedir a análise ───────────────────────────────────────────── */
        if (botao) {
            botao.addEventListener('click', function () {
                if (botao.disabled || botao.getAttribute('data-ia-disponivel') !== '1') { return; }

                var dados = new FormData();
                dados.append('_token', botao.getAttribute('data-ia-token') || '');

                avisar('');
                botaoCarregando(true);

                fetch(botao.getAttribute('data-ia-solicitar-url'), {
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
                            // Motivos de disponibilidade (enum Disponibilidade) travam o botão; os
                            // demais (sessão, ritmo, contexto vazio/bloqueado) só avisam.
                            var travam = ['nao_configurada_na_plataforma', 'desligada_no_escritorio', 'sem_permissao', 'limite_atingido'];
                            if (travam.indexOf(d.motivo) !== -1) {
                                botaoIndisponivel(texto, d.motivo);
                            } else {
                                botaoCarregando(false);
                                sincronizarBotao();
                            }
                            return;
                        }
                        if (d.aviso) { avisar(d.aviso, false); }
                        recarregarLista(d.id);
                    })
                    .catch(function () {
                        avisar('Não foi possível pedir a análise agora. Tente novamente.', true);
                        botaoCarregando(false);
                        sincronizarBotao();
                    });
            });
        }

        /* ── 4. Menu ⋮ ────────────────────────────────────────────────────── */
        function fecharMenus(exceto) {
            secao.querySelectorAll('[data-ia-menu]').forEach(function (g) {
                var menu = g.parentNode.querySelector('.ps-ia-menu');
                if (!menu || menu === exceto) { return; }
                menu.hidden = true;
                g.setAttribute('aria-expanded', 'false');
            });
        }

        secao.addEventListener('click', function (e) {
            var gatilho = e.target.closest('[data-ia-menu]');
            if (gatilho) {
                e.stopPropagation();
                var menu = gatilho.parentNode.querySelector('.ps-ia-menu');
                if (!menu) { return; }
                var abrir = menu.hidden;
                fecharMenus(abrir ? menu : null);
                menu.hidden = !abrir;
                gatilho.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            }
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('.ps-ia-menu') && !e.target.closest('.ps-ia-menu-item')) { return; }
            if (e.target.closest('[data-ia-menu]')) { return; }
            fecharMenus(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharMenus(null); }
        });

        /* ── 3. Ações da análise ──────────────────────────────────────────── */
        secao.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-ia-acao]');
            if (!form || !secao.contains(form)) { return; }
            e.preventDefault();

            var confirmar = form.getAttribute('data-ia-confirmar');
            if (confirmar && !window.confirm(confirmar)) { return; }

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
                    return recarregarLista();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : 'Não foi possível atualizar a análise.', true);
                    if (enviar) { enviar.disabled = false; }
                });
        });

        /* ── 5. Criar tarefa da providência ───────────────────────────────── */
        secao.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ia-criar-meta]');
            if (!item) { return; }

            var modal = document.getElementById('modalCriarTarefa');
            if (!modal || !window.bootstrap) { return; }

            var d = item.dataset;
            var linhas = ['Providência indicada pela análise da BlueJus IA de ' + (d.metaQuando || '') + '.'];
            linhas.push('Gerado por IA · não é movimentação oficial. Confira o documento original antes de agir.');
            if (d.metaPonto) { linhas.push(''); linhas.push((d.metaRotulo ? d.metaRotulo + ': ' : '') + d.metaPonto); }
            if (d.metaQuem) { linhas.push('Quem deve agir: ' + d.metaQuem); }
            if (d.metaResumo) { linhas.push(''); linhas.push('Resumo: ' + d.metaResumo); }

            var titulo = document.getElementById('tarefaTitulo');
            var descricao = document.getElementById('tarefaDescricao');
            var prazo = document.getElementById('tarefaPrazo');
            if (titulo) { titulo.value = (d.metaTitulo || 'Providência indicada pela análise IA').slice(0, 255); }
            if (descricao) { descricao.value = linhas.join('\n').slice(0, 5000); }
            if (prazo) {
                // Desenho: a tarefa da providência nasce com prazo de 7 dias; a pessoa ajusta.
                var p = new Date(); p.setDate(p.getDate() + 7);
                prazo.value = p.getFullYear() + '-' + String(p.getMonth() + 1).padStart(2, '0') + '-' + String(p.getDate()).padStart(2, '0');
            }

            // Mesmo sinal do "Criar tarefa" da publicação: o pasta-push.js limpa ao fechar.
            modal.dataset.origemPush = '1';
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });

        acompanhar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
