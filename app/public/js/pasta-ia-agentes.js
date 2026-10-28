/* =============================================================================
   BlueJus IA — drawer da pasta (specs inteligencia-agentes-da-pasta §5 e
   inteligencia-drawer-da-pasta F1/F2/F4).

     1. abre/fecha o drawer do cabeçalho (botão, X, fundo, Esc) e, na PRIMEIRA
        abertura, carrega o painel (GET `inteligencia_agentes_painel`) no lugar
        da lista estática;
     1b. abas Análise, Documentos e Relatórios (`data-ia-aba` → `data-ia-aba-painel`, setas
        do teclado no tablist) e a escolha do comando/relatório na grade
        (`data-ia-escolher` → `data-ia-comando`); aba e escolhas sobrevivem à
        troca do painel;
     2. "Gerar análise" de um comando (ou agente): POST no `data-ia-url` do botão
        (`inteligencia_comando_solicitar`) e o retorno honesto (202 em andamento,
        200 "nada novo", 409/429 motivo); motivo de disponibilidade trava TODOS
        os botões até a página recarregar;
     2b. aba Documentos (F4 e PR F): marcar e desmarcar (`data-ia-doc`), "Selecionar
        todos" (`data-ia-docs-todos`), a contagem "N de M", o "Ler N documentos"
        (`data-ia-ler`, rótulo pela contagem) e o "Índice inteligente", os dois
        `data-ia-exige-selecao`, liberados só com seleção; o POST leva os ids
        marcados como `documentos[]`. Os dois botões moram fora das seções e
        apontam para a sua por `data-ia-secao`;
     3. polling do status (GET `inteligencia_analise_status`) enquanto houver
        análise pendente/processando no drawer — 2 s, 5 s depois de 30 s, 10 s
        depois de 2 min; PAUSA com a página oculta ou o drawer fechado —, e
        recarga do fragmento DO COMANDO (GET no `data-ia-url` da lista,
        `inteligencia_comando_listar`) quando ela termina;
     4. ações da análise (lida · excluir · interna) por XHR + recarga da lista;
     5. menu ⋮ do cartão;
     6. "Criar tarefa" (embaixo do resultado, F5): abre o `#modalCriarTarefa` da pasta
        pré-preenchido, no mesmo contrato do pasta-ia-push.js;
     7. Copiar e Imprimir do cartão concluído, sobre o mesmo texto do "Baixar .txt"
        (GET `inteligencia_analise_txt`); baixar e imprimir avisam o servidor
        (POST `inteligencia_analise_registro`) para o registro de auditoria —
        copiar, não (C6-IA-01);
     8. rodapé "Registro de auditoria (N)": GET `inteligencia_auditoria_da_pasta`
        a cada abertura do drawer e depois de cada recarga de lista; o chevron
        abre e fecha a lista.

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
                    if (!r.ok) { throw new Error(BJ_T.t('inteligencia.js.agentes.falha_atualizar')); }
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
            abrirBotao.setAttribute('data-mini', '1');
            document.body.classList.add('ia-drawer-aberto');
            var fechar = drawer.querySelector('[data-ia-fechar]');
            if (fechar) { fechar.focus(); }
            carregarPainel();
            recarregarAuditoria();
            retomar();
        }

        function fechar() {
            drawer.hidden = true;
            if (fundo) { fundo.hidden = true; }
            abrirBotao.setAttribute('aria-expanded', 'false');
            abrirBotao.setAttribute('data-mini', '0');
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

        /* ── 8. Registro de auditoria (IA-008) ─────────────────────────────── */
        // O rodapé é trocado inteiro a cada recarga; o aberto/fechado sobrevive à troca.
        var auditoriaAberta = false;
        var carregandoAuditoria = false;

        function rodape() { return drawer.querySelector('[data-ia-auditoria]'); }

        function aplicarAuditoria(r) {
            var botao = r ? r.querySelector('[data-ia-audit-alternar]') : null;
            var lista = r ? r.querySelector('.ia-audit-lista') : null;
            var seta = r ? r.querySelector('.ia-audit-seta') : null;
            if (!botao || !lista) { return; }
            lista.hidden = !auditoriaAberta;
            botao.setAttribute('aria-expanded', auditoriaAberta ? 'true' : 'false');
            if (seta) { seta.className = 'bi ia-audit-seta ' + (auditoriaAberta ? 'bi-chevron-down' : 'bi-chevron-up'); }
        }

        function recarregarAuditoria() {
            var r = rodape();
            if (!r || carregandoAuditoria) { return Promise.resolve(); }
            carregandoAuditoria = true;

            return buscarFragmento(r.getAttribute('data-ia-auditoria-url'))
                .then(function (conteudo) {
                    var novo = conteudo.querySelector('[data-ia-auditoria]');
                    var atual = rodape();
                    if (!novo || !atual) { return; }
                    atual.replaceWith(novo);
                    aplicarAuditoria(novo);
                })
                .catch(function () { /* o rodapé fica como estava; a lista principal avisa os erros */ })
                .then(function () { carregandoAuditoria = false; });
        }

        drawer.addEventListener('click', function (e) {
            if (!e.target.closest('[data-ia-audit-alternar]')) { return; }
            auditoriaAberta = !auditoriaAberta;
            aplicarAuditoria(rodape());
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
                    aplicarAbaEEscolhas();
                    acompanhar();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : BJ_T.t('inteligencia.js.agentes.falha_carregar'), true);
                })
                .then(function () { carregandoPainel = false; });
        }

        /* ── 1b. Abas e escolha do comando ────────────────────────────────── */
        // A aba ativa e o comando escolhido em cada aba moram aqui: o painel é trocado inteiro
        // (primeira abertura) e a escolha do usuário não pode voltar ao padrão do servidor.
        var abaAtiva = 'analise';
        var escolhidos = {};

        function abas() { return drawer.querySelectorAll('[data-ia-aba]'); }

        function mostrarAba(nome, focar) {
            abaAtiva = nome;
            abas().forEach(function (b) {
                var ativa = b.getAttribute('data-ia-aba') === nome;
                b.classList.toggle('is-ativa', ativa);
                b.setAttribute('aria-selected', ativa ? 'true' : 'false');
                b.tabIndex = ativa ? 0 : -1;
                if (ativa && focar) { b.focus(); }
            });
            drawer.querySelectorAll('[data-ia-aba-painel]').forEach(function (p) {
                p.hidden = p.getAttribute('data-ia-aba-painel') !== nome;
            });
        }

        function escolher(valor) {
            var secao = drawer.querySelector('[data-ia-comando="' + valor + '"]');
            var aba = secao ? secao.closest('[data-ia-aba-painel]') : null;
            if (!aba) { return; }
            escolhidos[aba.getAttribute('data-ia-aba-painel')] = valor;
            aba.querySelectorAll('[data-ia-escolher]').forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-ia-escolher') === valor ? 'true' : 'false');
            });
            aba.querySelectorAll('[data-ia-comando]').forEach(function (s) {
                s.hidden = s.getAttribute('data-ia-comando') !== valor;
            });
        }

        function aplicarAbaEEscolhas() {
            mostrarAba(abaAtiva, false);
            Object.keys(escolhidos).forEach(function (aba) { escolher(escolhidos[aba]); });
        }

        drawer.addEventListener('click', function (e) {
            var aba = e.target.closest('[data-ia-aba]');
            if (aba) { mostrarAba(aba.getAttribute('data-ia-aba'), false); return; }
            var botao = e.target.closest('[data-ia-escolher]');
            if (!botao) { return; }
            var valor = botao.getAttribute('data-ia-escolher');
            escolher(valor);
            // Comando: o clique já roda, como no desenho (dc `CMDS`, `onClick` do botão). Sem nada novo, o
            // servidor devolve a última análise sem gastar cota. Relatório: escolhe e o "Gerar ..." roda.
            var secao = drawer.querySelector('[data-ia-comando="' + valor + '"]');
            var gerar = secao && secao.closest('[data-ia-aba-painel="analise"]') ? botaoDe(secao) : null;
            if (gerar && !gerar.disabled && gerar.getAttribute('data-ia-disponivel') !== '0') { gerar.click(); }
        });

        // Tablist do WAI-ARIA: setas trocam de aba (e levam o foco), Home/End vão às pontas.
        drawer.addEventListener('keydown', function (e) {
            var atual = e.target.closest('[data-ia-aba]');
            if (!atual) { return; }
            var lista = Array.prototype.slice.call(abas());
            var i = lista.indexOf(atual);
            var destino = null;
            if (e.key === 'ArrowRight') { destino = lista[(i + 1) % lista.length]; }
            if (e.key === 'ArrowLeft') { destino = lista[(i - 1 + lista.length) % lista.length]; }
            if (e.key === 'Home') { destino = lista[0]; }
            if (e.key === 'End') { destino = lista[lista.length - 1]; }
            if (!destino) { return; }
            e.preventDefault();
            mostrarAba(destino.getAttribute('data-ia-aba'), true);
        });

        /* ── Botões e listas por comando (ou agente) ──────────────────────── */
        // O botão da aba Documentos fica fora da seção e aponta para ela (`data-ia-secao`).
        function secaoDe(el) {
            if (!el) { return null; }
            var alvo = el.closest('[data-ia-secao]');
            if (alvo) { return drawer.querySelector('[data-ia-comando="' + alvo.getAttribute('data-ia-secao') + '"]'); }
            return el.closest('[data-ia-comando]') || el.closest('[data-ia-agente]');
        }
        function listaDe(secao) { return secao ? secao.querySelector('[data-ia-lista]') : null; }
        function botaoDe(secao) {
            if (!secao) { return null; }
            var valor = secao.getAttribute('data-ia-comando');
            return secao.querySelector('[data-ia-gerar]') || (valor ? drawer.querySelector('[data-ia-gerar][data-ia-secao="' + valor + '"]') : null);
        }

        function rotuloDoBotao(botao, texto) {
            var r = botao ? botao.querySelector('.ia-cta-rotulo') : null;
            if (r) { r.textContent = texto; }
        }

        function botaoCarregando(botao, sim) {
            if (!botao || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            botao.disabled = !!sim || semSelecao(botao);
            botao.classList.toggle('is-carregando', !!sim);
            if (sim) { rotuloDoBotao(botao, BJ_T.t('inteligencia.js.agentes.analisando')); }
        }

        // O botão do comando na grade pulsa enquanto houver análise em andamento nele.
        function marcarNaGrade(secao, emAndamentoAgora) {
            var valor = secao ? secao.getAttribute('data-ia-comando') : null;
            if (!valor) { return; }
            var botao = drawer.querySelector('[data-ia-escolher="' + valor + '"]');
            if (botao) { botao.classList.toggle('is-andamento', !!emAndamentoAgora); }
        }

        // Rótulo e title saem do que a lista recém-carregada diz (mesma regra do template).
        function sincronizarBotao(secao) {
            var botao = botaoDe(secao);
            var l = listaDe(secao);
            if (!botao || !l || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            if (l.getAttribute('data-ia-em-andamento') === '1') {
                botaoCarregando(botao, true);
                botao.title = BJ_T.t('inteligencia.js.agentes.em_andamento');
                marcarNaGrade(secao, true);
                return;
            }
            botaoCarregando(botao, false);
            marcarNaGrade(secao, false);
            var temConcluida = l.getAttribute('data-ia-tem-concluida') === '1';
            var rotulo = botao.getAttribute('data-ia-rotulo') || BJ_T.t('inteligencia.js.agentes.gerar_analise');
            var deNovo = botao.getAttribute('data-ia-rotulo-de-novo') || BJ_T.t('inteligencia.js.agentes.gerar_nova');
            rotuloDoBotao(botao, temConcluida ? deNovo : rotulo);
            botao.title = temConcluida ? BJ_T.t('inteligencia.js.agentes.dica_nova') : BJ_T.t('inteligencia.js.agentes.dica_primeira');
            if (botao.hasAttribute('data-ia-exige-selecao')) { botao.title = tituloDoIndice(botao); }
            if (botao.hasAttribute('data-ia-ler')) { rotuloDoBotao(botao, rotuloDaLeitura(idsMarcados().length)); }
        }

        // Um 409/429 de disponibilidade vale para todos os botões até a página recarregar.
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
                    recarregarAuditoria();
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : BJ_T.t('inteligencia.js.agentes.falha_atualizar'), true);
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
                    avisar(BJ_T.t('inteligencia.js.agentes.demorando'), true);
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

        /* ── 2b. Seleção de documentos (aba Documentos, F4) ──────────────── */
        // A seleção mora no DOM (aria-pressed das linhas): a lista só é trocada com o painel inteiro.
        function linhasDeDocumento() { return drawer.querySelectorAll('[data-ia-doc]'); }

        function idsMarcados() {
            return Array.prototype.filter.call(linhasDeDocumento(), function (l) {
                return l.getAttribute('aria-pressed') === 'true';
            }).map(function (l) { return l.getAttribute('data-ia-doc'); });
        }

        // Só o botão que exige seleção (o "Índice inteligente") fica preso sem documento marcado.
        function semSelecao(botao) {
            return botao.hasAttribute('data-ia-exige-selecao') && idsMarcados().length === 0;
        }

        // O title do Índice (e do Ler) segue a seleção, não o histórico (nenhum dos dois recebe o anterior).
        function tituloDoIndice(botao) {
            if (botao.hasAttribute('data-ia-ler')) {
                return semSelecao(botao) ? BJ_T.t('inteligencia.js.agentes.dica_ler_marque') : BJ_T.t('inteligencia.js.agentes.dica_ler');
            }
            return semSelecao(botao) ? BJ_T.t('inteligencia.js.agentes.dica_indice_marque') : BJ_T.t('inteligencia.js.agentes.dica_indice_monta');
        }

        // "Selecione" / "Ler 1 documento" / "Ler N documentos", como o `lerTxt` do desenho.
        function rotuloDaLeitura(n) {
            if (n === 0) { return BJ_T.t('inteligencia.js.agentes.ler_selecione'); }
            return n === 1 ? BJ_T.t('inteligencia.js.agentes.ler_um') : BJ_T.t('inteligencia.js.agentes.ler_varios', { n: String(n) });
        }

        function atualizarSelecao() {
            var total = linhasDeDocumento().length;
            var marcados = idsMarcados().length;
            var contagem = drawer.querySelector('[data-ia-docs-contagem]');
            var todos = drawer.querySelector('[data-ia-docs-todos]');
            if (contagem) { contagem.textContent = BJ_T.t('inteligencia.js.agentes.contagem', { marcados: String(marcados), total: String(total) }); }
            if (todos) { todos.setAttribute('aria-pressed', total > 0 && marcados === total ? 'true' : 'false'); }
            drawer.querySelectorAll('[data-ia-gerar][data-ia-exige-selecao]').forEach(function (botao) {
                if (botao.getAttribute('data-ia-disponivel') !== '1' || botao.classList.contains('is-carregando')) { return; }
                botao.disabled = marcados === 0;
                botao.title = tituloDoIndice(botao);
                if (botao.hasAttribute('data-ia-ler')) { rotuloDoBotao(botao, rotuloDaLeitura(marcados)); }
            });
        }

        drawer.addEventListener('click', function (e) {
            var linha = e.target.closest('[data-ia-doc]');
            var todos = e.target.closest('[data-ia-docs-todos]');
            if (!linha && !todos) { return; }
            if (linha) {
                linha.setAttribute('aria-pressed', linha.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            } else {
                var marcarTodos = idsMarcados().length !== linhasDeDocumento().length;
                linhasDeDocumento().forEach(function (l) { l.setAttribute('aria-pressed', marcarTodos ? 'true' : 'false'); });
            }
            atualizarSelecao();
        });

        /* ── 2. Gerar análise ─────────────────────────────────────────────── */
        drawer.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ia-gerar]');
            if (!botao || !drawer.contains(botao)) { return; }
            if (botao.disabled || botao.getAttribute('data-ia-disponivel') !== '1') { return; }
            if (semSelecao(botao)) { return; }
            if (botao.hasAttribute('data-ia-secao')) { escolher(botao.getAttribute('data-ia-secao')); }
            var secao = secaoDe(botao);

            var dados = new FormData();
            dados.append('_token', drawer.getAttribute('data-ia-token') || '');
            if (botao.hasAttribute('data-ia-exige-selecao')) {
                idsMarcados().forEach(function (id) { dados.append('documentos[]', id); });
            }

            avisar('');
            botaoCarregando(botao, true);
            marcarNaGrade(secao, true);
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
                        var texto = d.mensagem || BJ_T.t('inteligencia.js.agentes.falha_pedir');
                        avisar(texto, true);
                        if (MOTIVOS_QUE_TRAVAM.indexOf(d.motivo) !== -1) {
                            travarTodos(texto, d.motivo);
                            marcarNaGrade(secao, false);
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
                    avisar(BJ_T.t('inteligencia.js.agentes.falha_pedir'), true);
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
        drawer.addEventListener('submit', async function (e) {
            var form = e.target.closest('form[data-ia-acao]');
            if (!form || !drawer.contains(form)) { return; }
            e.preventDefault();

            // E7: janela vermelha da tela quando ela existe (Pasta; dc `confirmarUI(…, true)`,
            // L.5351); sem ela, o confirm do navegador. Uma pergunta por vez.
            var confirmar = form.getAttribute('data-ia-confirmar');
            if (confirmar) {
                // Marca no formulário, não `disabled` no botão: o foco volta a ele ao fechar a janela,
                // e botão desabilitado não recebe foco.
                if (form.dataset.perguntando) { return; }
                form.dataset.perguntando = '1';
                // O menu fecha no clique, antes do envio: o foco volta explicitamente ao ⋮ da análise.
                var envoltorio = form.closest('.ia-menu-wrap');
                var voltar = envoltorio ? envoltorio.querySelector('[aria-haspopup]') : null;
                var sim = window.confirmarUIPs ? await window.confirmarUIPs(confirmar, true, voltar) : window.confirm(confirmar);
                delete form.dataset.perguntando;
                if (!sim || !document.contains(form)) { return; }
            }

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
                        throw new Error(res.dados.mensagem || res.dados.message || BJ_T.t('inteligencia.js.agentes.falha_atualizar_analise'));
                    }
                    return recarregarAgente(secao);
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : BJ_T.t('inteligencia.js.agentes.falha_atualizar_analise'), true);
                    if (enviar) { enviar.disabled = false; }
                });
        });

        /* ── 7. Copiar · Imprimir (IA-007) ────────────────────────────────── */
        // As duas usam o MESMO texto do "Baixar .txt" (GET `inteligencia_analise_txt`): o que se
        // copia, imprime e baixa é a análise gravada, montada num lugar só (o servidor).
        function urlDoTexto(el) {
            var cartao = el.closest('[data-ia-analise]');
            var link = cartao ? cartao.querySelector('[data-ia-baixar]') : null;

            return link ? link.getAttribute('href') : null;
        }

        function buscarTexto(url) {
            return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error(BJ_T.t('inteligencia.js.agentes.falha_ler')); }
                    return r.text();
                });
        }

        function copiar(url) {
            if (!navigator.clipboard) { return Promise.reject(new Error(BJ_T.t('inteligencia.js.agentes.sem_copiar'))); }
            // ClipboardItem com promessa preserva o gesto do clique enquanto o texto chega.
            if (window.ClipboardItem && navigator.clipboard.write) {
                var blob = buscarTexto(url).then(function (t) { return new Blob([t], { type: 'text/plain' }); });

                return navigator.clipboard.write([new window.ClipboardItem({ 'text/plain': blob })]);
            }

            return buscarTexto(url).then(function (t) { return navigator.clipboard.writeText(t); });
        }

        drawer.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ia-copiar]');
            if (!botao) { return; }
            var url = urlDoTexto(botao);
            if (!url) { return; }
            var icone = botao.querySelector('i');

            copiar(url)
                .then(function () {
                    botao.classList.add('is-copiado');
                    if (icone) { icone.className = 'bi bi-check2'; }
                    setTimeout(function () {
                        botao.classList.remove('is-copiado');
                        if (icone) { icone.className = 'bi bi-copy'; }
                    }, 1400);
                })
                .catch(function (falha) {
                    avisar(falha && falha.message ? falha.message : BJ_T.t('inteligencia.js.agentes.falha_copiar'), true);
                });
        });

        /* ── 7b. Registro de auditoria do download e da impressão (C6-IA-01) ── */
        // Desenho, dc L5742-5743: baixar e imprimir entram no "Registro de auditoria"; copiar,
        // não. POST com o token do cartão (`inteligencia_analise_registro`), disparado ao lado da
        // ação e sem bloqueá-la: o download segue pelo link comum e a impressão pela janela. Se o
        // registro falhar, a ação já aconteceu e nada é avisado; o rodapé recarrega quando dá certo.
        function registrarExportacao(el, acao) {
            var cartao = el.closest('[data-ia-analise]');
            var url = cartao ? cartao.getAttribute('data-ia-registro-url') : null;
            if (!url) { return; }

            var dados = new FormData();
            dados.append('_token', cartao.getAttribute('data-ia-registro-token') || '');
            dados.append('acao', acao);

            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                // keepalive: o download por link pode trocar o documento antes da resposta.
                keepalive: true,
                body: dados
            })
                .then(function (r) { if (r.ok) { recarregarAuditoria(); } })
                .catch(function () { /* a ação do usuário não depende do registro */ });
        }

        // Sem preventDefault: o link `download` continua baixando o .txt (GET puro).
        drawer.addEventListener('click', function (e) {
            var link = e.target.closest('[data-ia-baixar]');
            if (!link) { return; }
            registrarExportacao(link, 'download');
        });

        // A janela abre NO clique (senão o bloqueador de pop-up a recusa) e é preenchida por
        // textContent: o texto do modelo nunca vira HTML, nem na impressão.
        drawer.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ia-imprimir]');
            if (!botao) { return; }
            var url = urlDoTexto(botao);
            if (!url) { return; }

            var janela = window.open('', '_blank');
            if (!janela) {
                avisar(BJ_T.t('inteligencia.js.agentes.popup_bloqueado'), true);
                return;
            }
            buscarTexto(url)
                .then(function (texto) {
                    // Registra só com o texto em mãos: falha ao buscar não deixa "Impressão" na trilha.
                    registrarExportacao(botao, 'impressao');
                    var doc = janela.document;
                    var linhas = texto.split('\n');
                    doc.title = linhas[0] || 'BLUEJUS IA';
                    doc.body.style.cssText = 'font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:40px auto;color:#12242f';
                    var corpo = doc.createElement('div');
                    corpo.style.cssText = 'white-space:pre-wrap;line-height:1.6;font-size:13px';
                    corpo.textContent = texto;
                    doc.body.appendChild(corpo);
                    janela.focus();
                    setTimeout(function () { janela.print(); }, 300);
                })
                .catch(function (falha) {
                    janela.close();
                    avisar(falha && falha.message ? falha.message : BJ_T.t('inteligencia.js.agentes.falha_imprimir'), true);
                });
        });

        /* ── 6. Criar tarefa (ações do resultado, F5) ─────────────────────── */
        drawer.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ia-criar-meta]');
            if (!item) { return; }

            var modal = document.getElementById('modalCriarTarefa');
            if (!modal || !window.bootstrap) { return; }

            var d = item.dataset;
            var linhas = [BJ_T.t('inteligencia.js.agentes.meta_linha', { agente: d.metaAgente || BJ_T.t('inteligencia.js.agentes.meta_agente'), quando: d.metaQuando || '' })];
            linhas.push(BJ_T.t('inteligencia.js.agentes.meta_aviso'));
            if (d.metaPonto) { linhas.push(''); linhas.push((d.metaRotulo ? d.metaRotulo + ': ' : '') + d.metaPonto); }
            if (d.metaQuem) { linhas.push(BJ_T.t('inteligencia.js.agentes.meta_quem', { quem: d.metaQuem })); }
            if (d.metaResumo) { linhas.push(''); linhas.push(BJ_T.t('inteligencia.js.agentes.meta_resumo', { resumo: d.metaResumo })); }

            var titulo = document.getElementById('tarefaTitulo');
            var descricao = document.getElementById('tarefaDescricao');
            var prazo = document.getElementById('tarefaPrazo');
            if (titulo) { titulo.value = (d.metaTitulo || BJ_T.t('inteligencia.js.agentes.meta_titulo')).slice(0, 255); }
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
