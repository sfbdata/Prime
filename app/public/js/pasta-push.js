/* =============================================================================
   Aba Push Processual da pasta — o que o desenho 1.2.3 pede além do acordeão.

   O acordeão (abrir o teor por XHR) continua no pasta-show.js e não é tocado
   aqui. Este arquivo cuida de:
     1. filtro Todas · Novas · Geram prazo (no navegador: a lista inteira já está na página);
     2. selo e linha de pendência da aba, que acompanham as linhas "novas";
     3. copiar o ID do documento (numeroComunicacao);
     4. marcar como lida / não lida (POST `pasta_push_lida`);
     5. "Criar tarefa": abre o modal de nova meta da pasta pré-preenchido;
     6. botão direito no cartão (FPN-014): Adicionar nota técnica, Criar tarefa e
        Copiar texto — cada item aciona o botão que JÁ existe no teor.

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

        /* ── 6. Botão direito no cartão (dc L.5394 `ctx`, README L.125-127) ───
           Um menu só (`#pushMenuContexto`), preenchido com o cartão clicado e
           posto no ponto do clique, com as medidas do menu do desenho (`gmVals`:
           borda direita no cursor, sempre dentro da janela). Nenhum item tem
           caminho próprio: "Adicionar nota técnica" e "Criar tarefa" clicam os
           botões que o TEOR já traz (`.js-nota-nova` do bloco
           `#notas-publicacao-<id>` e `.js-push-criar-meta`). O teor vem do
           servidor no primeiro clique do cartão (pasta-show.js), então, se ainda
           não veio, o menu abre o cartão como o clique faria e espera o teor
           chegar. "Copiar texto" copia o teor inteiro quando ele já está na
           página; antes disso, o trecho do título — sem abrir o cartão, que
           marcaria a publicação como lida. */
        var menu = document.getElementById('pushMenuContexto');
        var menuDe = null; // { item, cab } do cartão que abriu o menu
        var ESPERA_TEOR_MS = 15000;
        var avisoTimer = null;

        function avisar(texto) {
            // Mesma caixa de aviso curto da tela (`gmAviso` do desenho).
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

        function textoLimpo(el) {
            return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
        }

        function fecharMenu(devolverFoco) {
            if (!menu || menu.hidden) { return; }
            menu.hidden = true;
            var de = menuDe;
            menuDe = null;
            if (de && devolverFoco) { de.cab.focus(); }
        }

        /** x = borda DIREITA desejada do menu; y = topo. Sempre dentro da janela. */
        function posicionarMenu(x, y) {
            menu.style.left = '0px';
            menu.style.top = '0px';
            var largura = menu.offsetWidth;
            var altura = menu.offsetHeight;
            menu.style.left = Math.max(8, Math.min(x - largura, window.innerWidth - largura - 8)) + 'px';
            menu.style.top = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';
        }

        /* Garante o teor carregado e o cartão aberto, e entrega o painel. Abre
           pelo próprio cartão (o clique do pasta-show.js), para não haver um
           segundo caminho de leitura do teor. */
        function comTeor(cab, feito) {
            var painel = document.getElementById(cab.getAttribute('aria-controls') || '');
            if (!painel) { return; }
            var aberto = cab.getAttribute('aria-expanded') === 'true';

            if (painel.dataset.carregado === '1') {
                if (!aberto) { cab.click(); }
                feito(painel);
                return;
            }
            // Aberto, sem teor e sem carregar: a leitura anterior falhou. Fecha e
            // reabre, que é o "tente de novo" do próprio cartão.
            if (aberto && !painel.querySelector('.ps-push-carregando')) { cab.click(); aberto = false; }
            if (!aberto) { cab.click(); }

            var observador = null;
            var prazo = setTimeout(function () {
                if (observador) { observador.disconnect(); }
                avisar('Não foi possível carregar o teor. Tente de novo.');
            }, ESPERA_TEOR_MS);

            function conferir() {
                if (painel.dataset.carregado === '1') {
                    clearTimeout(prazo);
                    if (observador) { observador.disconnect(); }
                    feito(painel);
                    return true;
                }
                if (painel.querySelector('.ps-push-sem-teor') && !painel.querySelector('.ps-push-carregando')) {
                    // O pasta-show.js pôs no painel a mensagem de falha.
                    clearTimeout(prazo);
                    if (observador) { observador.disconnect(); }
                    return true;
                }
                return false;
            }
            if (conferir() || !window.MutationObserver) { return; }
            observador = new MutationObserver(conferir);
            observador.observe(painel, { attributes: true, attributeFilter: ['data-carregado'], childList: true });
        }

        function copiarTexto(texto) {
            if (texto && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(
                    function () { avisar('Texto copiado'); },
                    function () { avisar('Não foi possível copiar'); }
                );
            } else {
                avisar('Não foi possível copiar');
            }
        }

        if (menu && lista) {
            lista.addEventListener('contextmenu', function (e) {
                // Só o cartão: o teor aberto, as notas e os campos seguem com o menu do
                // navegador (o desenho ignora `a, button, textarea, [data-nota]`; aqui o
                // cartão É o botão, então só os outros controles ficam de fora).
                var cartao = e.target.closest('.ps-push-cartao');
                if (!cartao || e.target.closest('a, input, textarea, select')) { return; }
                var item = cartao.closest('.ps-push-item');
                var cab = cartao.querySelector('.ps-push-cab');
                if (!item || !cab) { return; }

                e.preventDefault();
                fecharMenu(false);
                // Um menu por vez: fecha os popovers do cabeçalho (pasta-show.js).
                document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));

                var titulo = textoLimpo(cartao.querySelector('.ps-push-assunto-txt'));
                menu.querySelector('.ps-push-menu-titulo').textContent = titulo.slice(0, 60);
                menuDe = { item: item, cab: cab };
                menu.hidden = false;

                // Tecla de menu / Shift+F10 chegam sem ponto de clique: o menu sai no cartão.
                if (e.clientX === 0 && e.clientY === 0) {
                    var r = cab.getBoundingClientRect();
                    posicionarMenu(r.right, r.top + 8);
                    var primeiro = menu.querySelector('.ps-push-menu-item');
                    if (primeiro) { primeiro.focus(); }
                } else {
                    posicionarMenu(e.clientX, e.clientY);
                }
            });

            menu.addEventListener('click', function (e) {
                var opcao = e.target.closest('.ps-push-menu-item');
                if (!opcao || !menuDe) { return; }
                var de = menuDe;
                fecharMenu(false);

                if (opcao.classList.contains('js-push-menu-copiar')) {
                    var painel = document.getElementById(de.cab.getAttribute('aria-controls') || '');
                    var teor = painel && painel.dataset.carregado === '1' ? painel.querySelector('.djen-teor') : null;
                    copiarTexto(teor ? textoLimpo(teor) : textoLimpo(de.item.querySelector('.ps-push-assunto-txt')));
                    return;
                }
                if (opcao.classList.contains('js-push-menu-tarefa')) {
                    comTeor(de.cab, function (p) {
                        var botao = p.querySelector('.js-push-criar-meta');
                        if (botao) { botao.click(); } else { avisar('Não foi possível criar a tarefa por aqui.'); }
                    });
                    return;
                }
                if (opcao.classList.contains('js-push-menu-nota')) {
                    var id = de.item.getAttribute('data-push-id');
                    comTeor(de.cab, function (p) {
                        var bloco = p.querySelector('#notas-publicacao-' + id);
                        var nova = bloco ? bloco.querySelector('.js-nota-nova') : null;
                        if (!nova) { avisar('Esta movimentação não aceita nota técnica.'); return; }
                        nova.click();
                        bloco.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    });
                }
            });

            // Clique fora fecha, em captura: outros gatilhos da tela param a propagação.
            document.addEventListener('click', function (e) {
                if (!menu.hidden && !menu.contains(e.target)) { fecharMenu(false); }
            }, true);
            // Botão direito em outro lugar também fecha (no cartão, o handler acima reabre).
            document.addEventListener('contextmenu', function (e) {
                if (!menu.hidden && !menu.contains(e.target)) { fecharMenu(false); }
            }, true);
            document.addEventListener('keydown', function (e) {
                if (!menu.hidden && e.key === 'Escape') { fecharMenu(true); }
            });
            document.addEventListener('ps:fechar-popovers', function () { fecharMenu(false); });
            // Menu fixo não acompanha a rolagem: rolou a página, fecha.
            window.addEventListener('scroll', function (e) {
                if (!menu.hidden && !(e.target instanceof Node && menu.contains(e.target))) { fecharMenu(false); }
            }, true);
            window.addEventListener('resize', function () { fecharMenu(false); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
