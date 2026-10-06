/* =============================================================================
   Aba Push Processual da pasta — o que o desenho 1.2.3 pede além do acordeão.

   O acordeão (abrir o teor por XHR) continua no pasta-show.js e não é tocado
   aqui. Este arquivo cuida de:
     1. filtro Todas · Novas · Geram prazo (no navegador: a lista inteira já está na página);
     2. selo e linha de pendência da aba, que acompanham as linhas "novas";
     3. copiar o ID do documento (numeroComunicacao);
     4. marcar como lida / não lida (POST `pasta_push_lida`);
     5. "Criar tarefa": abre o modal de nova meta da pasta pré-preenchido.

   O teor chega DEPOIS do carregamento da página, então tudo que é clique dentro
   dele é por delegação. E o estado "nova" de uma linha muda por dois caminhos —
   o pasta-show.js tira a classe ao abrir o teor (abrir é ler) e o botão daqui a
   põe ou tira —, por isso o selo é recalculado observando a CLASSE das linhas,
   não os cliques: um caminho que esquecesse de avisar deixaria o selo mentindo.
   ============================================================================= */
(function () {
    'use strict';

    var CLASSE_NOVA = 'ps-push-item--nova';

    function iniciar() {
        var secao = document.querySelector('.ps-push');
        if (!secao) { return; }

        var lista = secao.querySelector('.ps-push-lista');
        var filtroAtual = 'todas';

        /* ── 1. Filtro ─────────────────────────────────────────────────────── */
        function aplicarFiltro() {
            if (!lista) { return; }
            var itens = lista.querySelectorAll('.ps-push-item');
            var diaAnterior = null;
            var visiveis = 0;

            Array.prototype.forEach.call(itens, function (item) {
                // "prazo" lê a marca que o servidor pôs pela regra do desenho
                // (PastaPushOutput::tipoGeraPrazo): o JS não reclassifica o tipo.
                var mostra = filtroAtual === 'novas'
                    ? item.classList.contains(CLASSE_NOVA)
                    : (filtroAtual === 'prazo' ? item.getAttribute('data-push-gera-prazo') === '1' : true);
                item.hidden = !mostra;
                if (!mostra) { return; }

                visiveis++;
                // A pílula do dia vai na primeira linha VISÍVEL de cada dia.
                var dia = item.getAttribute('data-push-dia');
                var pilula = item.querySelector('.ps-push-dia');
                if (pilula) { pilula.classList.toggle('ps-push-dia--repetido', dia === diaAnterior); }
                diaAnterior = dia;
            });

            var vazio = document.getElementById('push-vazio-filtro');
            if (vazio) { vazio.hidden = visiveis > 0; }
        }

        secao.addEventListener('click', function (e) {
            var botao = e.target.closest('.ps-push-filtro');
            if (!botao) { return; }

            filtroAtual = botao.getAttribute('data-push-filtro') || 'todas';
            secao.querySelectorAll('.ps-push-filtro').forEach(function (b) {
                var ativo = b === botao;
                b.classList.toggle('is-ativo', ativo);
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
            aplicarFiltro();
        });

        /* ── 2. Selo da aba + selo "Novo" da linha ─────────────────────────── */
        function sincronizarNovas() {
            if (!lista) { return; }
            var itens = lista.querySelectorAll('.ps-push-item');
            var novas = 0;

            Array.prototype.forEach.call(itens, function (item) {
                var nova = item.classList.contains(CLASSE_NOVA);
                if (nova) { novas++; }

                var assunto = item.querySelector('.ps-push-assunto');
                var selo = item.querySelector('.ps-push-novo');
                if (nova && !selo && assunto) {
                    selo = document.createElement('span');
                    selo.className = 'ps-push-novo';
                    selo.title = 'Não lida';
                    selo.textContent = 'Novo';
                    assunto.appendChild(selo);
                } else if (!nova && selo) {
                    selo.remove();
                }
            });

            var aba = document.getElementById('push-tab');
            if (!aba) { return; }

            var badge = aba.querySelector('.ps-aba-badge');
            if (novas > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'ps-aba-badge ps-num';
                    aba.appendChild(badge);
                }
                badge.textContent = String(novas);
            } else if (badge) {
                badge.remove();
            }

            // Linha de pendência: mesma regra e mesmo texto do PastaPendenciasOutput.
            aba.classList.toggle('ps-aba--pend', novas > 0);
            var pend = aba.querySelector('.ps-aba-pend');
            if (novas > 0 && !pend) {
                pend = document.createElement('span');
                pend.className = 'ps-aba-pend';
                pend.setAttribute('aria-hidden', 'true');
                aba.insertBefore(pend, aba.firstChild);
            } else if (novas === 0 && pend) {
                pend.remove();
            }
            aba.title = 'Push Processual' + (novas > 0
                ? ' · ' + novas + ' ' + (novas === 1 ? 'movimentação nova sem leitura' : 'movimentações novas sem leitura')
                : '');
        }

        if (lista && window.MutationObserver) {
            new MutationObserver(function (mudancas) {
                var mudouClasse = mudancas.some(function (m) {
                    return m.attributeName === 'class' && m.target.classList && m.target.classList.contains('ps-push-item');
                });
                if (mudouClasse) { sincronizarNovas(); }
            }).observe(lista, { subtree: true, attributes: true, attributeFilter: ['class'] });
        }

        /* ── 3. Copiar ID do documento ─────────────────────────────────────── */
        secao.addEventListener('click', function (e) {
            var botao = e.target.closest('.js-push-copiar-id');
            if (!botao) { return; }

            var id = botao.getAttribute('data-copiar-id') || '';
            var icone = botao.querySelector('i');
            var tituloOriginal = botao.getAttribute('title');

            function avisar(texto, ok) {
                botao.setAttribute('title', texto);
                if (icone && ok) { icone.className = 'bi bi-check2'; }
                setTimeout(function () {
                    botao.setAttribute('title', tituloOriginal);
                    if (icone) { icone.className = 'bi bi-clipboard'; }
                }, 1500);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(id).then(
                    function () { avisar('ID ' + id + ' copiado', true); },
                    function () { avisar('Não foi possível copiar', false); }
                );
            } else {
                avisar('Não foi possível copiar', false);
            }
        });

        /* ── 4. Marcar como lida / não lida ────────────────────────────────── */
        function pintarBotaoLida(botao, lida) {
            botao.setAttribute('data-lida', lida ? '1' : '0');
            var icone = botao.querySelector('i');
            if (icone) { icone.className = 'bi ' + (lida ? 'bi-envelope' : 'bi-envelope-open'); }
            var rotulo = botao.querySelector('.js-push-lida-rotulo');
            if (rotulo) { rotulo.textContent = lida ? 'Marcar como não lida' : 'Marcar como lida'; }
        }

        secao.addEventListener('click', function (e) {
            var botao = e.target.closest('.js-push-lida');
            if (!botao || botao.disabled) { return; }

            var item = botao.closest('.ps-push-item');
            var acoes = botao.closest('.ps-push-acoes');
            var erro = acoes ? acoes.querySelector('.ps-push-acao-erro') : null;
            var querLida = botao.getAttribute('data-lida') !== '1';

            var dados = new FormData();
            dados.append('_token', botao.getAttribute('data-token') || '');
            dados.append('lida', querLida ? '1' : '0');

            botao.disabled = true;
            if (erro) { erro.hidden = true; }

            fetch(botao.getAttribute('data-url'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: dados
            })
                .then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, dados: d }; });
                })
                .then(function (res) {
                    if (!res.ok || !res.dados.sucesso) {
                        throw new Error(res.dados.erro || res.dados.message || 'Não foi possível atualizar a publicação.');
                    }
                    var lida = !!res.dados.lida;
                    pintarBotaoLida(botao, lida);
                    // A classe da linha é a fonte do selo: o observador acima recalcula.
                    if (item) { item.classList.toggle(CLASSE_NOVA, !lida); }
                })
                .catch(function (falha) {
                    if (erro) {
                        erro.textContent = falha && falha.message ? falha.message : 'Não foi possível atualizar a publicação.';
                        erro.hidden = false;
                    }
                })
                .then(function () { botao.disabled = false; });
        });

        /* ── 5. Criar tarefa a partir da publicação ────────────────────────────
           Reusa o modal de nova meta da pasta (`#modalCriarTarefa`, mesma rota e
           mesmo CSRF de sempre). Título no formato do desenho ("Providência:
           intimação de 20/08/2026"); prazo em branco — quem decide o prazo é a
           pessoa, a data da publicação não é prazo. Ao fechar, o que foi
           pré-preenchido sai, para o "Nova meta" da aba Metas abrir limpo. */
        var modal = document.getElementById('modalCriarTarefa');

        function campo(id) { return document.getElementById(id); }

        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                if (modal.dataset.origemPush !== '1') { return; }
                delete modal.dataset.origemPush;
                ['tarefaTitulo', 'tarefaDescricao', 'tarefaPrazo'].forEach(function (id) {
                    var el = campo(id);
                    if (el) { el.value = ''; }
                });
            });
        }

        secao.addEventListener('click', function (e) {
            var botao = e.target.closest('.js-push-criar-meta');
            if (!botao || !modal || !window.bootstrap) { return; }

            var d = botao.dataset;
            var tipo = d.metaTipo || 'Publicação';
            var titulo = 'Providência: ' + tipo.toLowerCase() + (d.metaData ? ' de ' + d.metaData : '');

            var linhas = ['Movimentação recebida pelo Push Processual.', 'Tipo: ' + tipo];
            if (d.metaOrgao) { linhas.push('Órgão: ' + d.metaOrgao); }
            if (d.metaProcesso) { linhas.push('Processo: ' + d.metaProcesso); }
            if (d.metaData) { linhas.push('Disponibilizada em: ' + d.metaData); }
            if (d.metaDocumento) { linhas.push('ID do documento: ' + d.metaDocumento); }
            if (d.metaLink) { linhas.push('Documento no PJe: ' + d.metaLink); }

            var elTitulo = campo('tarefaTitulo');
            var elDescricao = campo('tarefaDescricao');
            var elPrazo = campo('tarefaPrazo');
            if (elTitulo) { elTitulo.value = titulo.slice(0, 255); }
            if (elDescricao) { elDescricao.value = linhas.join('\n').slice(0, 5000); }
            if (elPrazo) { elPrazo.value = ''; }

            modal.dataset.origemPush = '1';
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
