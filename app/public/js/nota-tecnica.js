/* =============================================================================
   nota-tecnica.js — notas técnicas do processo, na tela da pasta (aba Processo
   e teor de uma movimentação do Push Processual).

   Tudo delegado no `document`, de propósito: o bloco das notas chega de três
   jeitos — no HTML da página, na troca da aba Processo por XHR (vincular /
   desvincular / tornar principal) e no teor do Push, que só é buscado no
   primeiro clique. Nenhum deles precisa (nem consegue) reinicializar este
   arquivo: `innerHTML` não executa <script>.

   Contrato com os partials `processo/_notas_tecnicas.html.twig` e
   `processo/_nota_tecnica_item.html.twig` (classes e data-* de lá).

   O editor é o `EditorRico` da casa (Quill), montado SÓ quando o compositor
   abre — montar na carga seria pagar por um editor por processo, escondido.
   O cartão da nota recém-criada vem PRONTO do servidor (`html`), pelo mesmo
   partial do Twig: não há segunda versão da estrutura aqui.
   ============================================================================= */
(function () {
    'use strict';

    if (window.__notaTecnicaIniciado) { return; }
    window.__notaTecnicaIniciado = true;

    var CABECALHOS = { 'X-Requested-With': 'XMLHttpRequest' };

    /* ── Editor (fallback para textarea crua se o editor-rico não carregou) ── */
    function montar(textarea) {
        if (!window.EditorRico) { return null; }
        // `montar` devolve null se já estava montado (o compositor reaberto): a instância segue lá.
        return EditorRico.montar(textarea) || (EditorRico.instancia ? EditorRico.instancia(textarea) : null);
    }

    /* ── @menção (PMC-019) ──────────────────────────────────────────────────
       O MESMO pasta-mencoes.js do Registro (carregado pela pasta_show), com a URL
       da lista vinda do bloco: só colegas que veem esta pasta. No editor a menção
       é "@Nome"; ao salvar, `paraEnvio` a troca pelo token `@[Nome](user:ID)` e o
       servidor confere o id. Sem o script, a nota segue sem menção. */
    function ligarMencoes(bloco, textarea, mencoesIniciais) {
        if (!window.PastaMencoes || !bloco) { return; }
        var url = bloco.getAttribute('data-url-mencionaveis');
        if (!url) { return; }
        textarea._mencoesUrl = url;
        PastaMencoes.ligar(textarea, mencoesIniciais || []);
    }
    function conteudoParaEnvio(textarea) {
        return window.PastaMencoes ? PastaMencoes.paraEnvio(textarea, textarea.value) : textarea.value;
    }
    function esquecerMencoes(textarea) {
        if (window.PastaMencoes) { PastaMencoes.esquecer(textarea); }
    }
    function desmontar(textarea) { if (window.EditorRico) { EditorRico.desmontar(textarea); } }
    function limpar(textarea) {
        if (window.EditorRico) { EditorRico.limpar(textarea); } else { textarea.value = ''; }
    }
    function estaVazio(textarea) {
        return window.EditorRico ? EditorRico.estaVazio(textarea) : textarea.value.trim() === '';
    }
    function focar(quill, textarea) {
        if (quill && typeof quill.focus === 'function') { quill.focus(); } else { textarea.focus(); }
    }
    /* EDT-021: o "Salvar nota" é o botão de envio do editor (`data-editor-rico-envio`) — o
       editor o mantém apagado enquanto estiver vazio. Durante o envio quem apaga é
       `EditorRico.marcarEnvio`: mexer no `disabled` direto seria desfeito na próxima tecla
       (e reabriria o envio em dobro). Sem o editor, a textarea crua segue como antes. */
    function marcarEnvio(textarea, botao, enviando) {
        if (window.EditorRico && EditorRico.marcarEnvio) {
            EditorRico.marcarEnvio(textarea, enviando);
        } else {
            botao.disabled = enviando;
        }
    }

    /* ── Bloco ───────────────────────────────────────────────────────────── */
    function blocoDe(el) { return el.closest('.ps-notas'); }

    function mostrarErro(bloco, msg) {
        var erro = bloco.querySelector('.ps-nota-erro');
        if (!erro) { return; }
        erro.textContent = msg;
        erro.hidden = false;
    }

    function esconderErro(bloco) {
        var erro = bloco.querySelector('.ps-nota-erro');
        if (erro) { erro.hidden = true; erro.textContent = ''; }
    }

    function atualizarContagem(bloco) {
        var contagem = bloco.querySelector('.ps-notas-contagem');
        if (contagem) {
            contagem.textContent = String(bloco.querySelectorAll('.ps-notas-lista > .ps-nota').length);
        }
    }

    function abrirCompositor(bloco) {
        var editor   = bloco.querySelector('.ps-nota-editor');
        var textarea = editor && editor.querySelector('textarea');
        if (!editor || !textarea) { return; }
        esconderErro(bloco);
        editor.hidden = false;
        var quill = montar(textarea);
        ligarMencoes(bloco, textarea);
        focar(quill, textarea);
    }

    function fecharCompositor(bloco) {
        var editor   = bloco.querySelector('.ps-nota-editor');
        var textarea = editor && editor.querySelector('textarea');
        if (!editor || !textarea) { return; }
        limpar(textarea);
        esquecerMencoes(textarea);
        esconderErro(bloco);
        editor.hidden = true;
    }

    function salvarNova(bloco) {
        var editor   = bloco.querySelector('.ps-nota-editor');
        var textarea = editor && editor.querySelector('textarea');
        var botao    = bloco.querySelector('.js-nota-salvar');
        if (!editor || !textarea || !botao || botao.disabled) { return; }

        if (estaVazio(textarea)) {
            mostrarErro(bloco, 'A nota técnica não pode ser vazia.');
            return;
        }

        var fd = new FormData();
        fd.append('_token', bloco.getAttribute('data-csrf-criar') || '');
        fd.append('conteudo', conteudoParaEnvio(textarea));
        fd.append('pasta_id', bloco.getAttribute('data-pasta-id') || '');
        var publicacaoId = bloco.getAttribute('data-publicacao-id');
        if (publicacaoId) { fd.append('publicacao_id', publicacaoId); }

        marcarEnvio(textarea, botao, true);
        botao.textContent = 'Salvando…';
        esconderErro(bloco);

        fetch(bloco.getAttribute('data-url-criar'), { method: 'POST', body: fd, headers: CABECALHOS, credentials: 'same-origin' })
            .then(function (resp) {
                return resp.json().catch(function () { return {}; }).then(function (dados) {
                    if (!resp.ok) {
                        mostrarErro(bloco, dados.erro || 'Erro ao salvar a nota.');
                        return;
                    }
                    var lista = bloco.querySelector('.ps-notas-lista');
                    if (lista) { lista.insertAdjacentHTML('afterbegin', dados.html || ''); }
                    atualizarContagem(bloco);
                    fecharCompositor(bloco);
                });
            })
            .catch(function () { mostrarErro(bloco, 'Erro de rede. Tente novamente.'); })
            .then(function () {
                botao.textContent = 'Salvar nota';
                // Volta ao estado do editor: depois de salvar ele está vazio, e o botão segue apagado.
                marcarEnvio(textarea, botao, false);
            });
    }

    /* ── Editar no lugar: a caixa toma o lugar do texto, como nas observações ── */
    function editar(botaoEditar) {
        var bloco = blocoDe(botaoEditar);
        // O cartão DESTE botão (o menu mora dentro dele): a mesma nota pode estar duas vezes na
        // página — aba Processo e pendurada no Push (FPN-020, ids com sufixo) —, e o id buscaria
        // a outra cópia.
        var item  = botaoEditar.closest('.ps-nota');
        var texto = item && item.querySelector('.ps-nota-texto');
        if (!bloco || !item || !texto || item.querySelector('.ps-nota-edicao')) { return; }

        var htmlAtual = botaoEditar.getAttribute('data-conteudo-html') || '';

        var textarea = document.createElement('textarea');
        textarea.className = 'ps-nota-textarea';
        textarea.rows = 3;
        textarea.maxLength = 5000;
        textarea.setAttribute('aria-label', 'Texto da nota técnica');
        // @menção: o token `@[Nome](user:ID)` volta a "@Nome" no editor e fica lembrado para
        // virar token de novo ao salvar (o mesmo caminho da edição do Registro).
        var edicao = window.PastaMencoes
            ? PastaMencoes.paraEdicao(botaoEditar.getAttribute('data-conteudo') || '')
            : { html: botaoEditar.getAttribute('data-conteudo') || '', mencoes: [] };
        textarea.value = edicao.html;

        var erro = document.createElement('div');
        erro.className = 'ps-nota-erro';
        erro.hidden = true;

        var cancelar = document.createElement('button');
        cancelar.type = 'button';
        cancelar.className = 'ps-btn';
        cancelar.textContent = 'Cancelar';

        var salvar = document.createElement('button');
        salvar.type = 'button';
        salvar.className = 'ps-btn ps-btn--primario';
        salvar.textContent = 'Salvar nota';
        // EDT-021: apagado com o editor vazio, como o do compositor (o id é o elo do contrato).
        salvar.id = 'nota-tecnica-' + botaoEditar.getAttribute('data-nota-id') + '-salvar';
        textarea.setAttribute('data-editor-rico-envio', salvar.id);

        var acoes = document.createElement('div');
        acoes.className = 'ps-nota-editor-acoes';
        acoes.appendChild(cancelar);
        acoes.appendChild(salvar);

        var rotulo = document.createElement('div');
        rotulo.className = 'ps-nota-editor-rotulo';
        rotulo.innerHTML = '<i class="bi bi-journal-plus" aria-hidden="true"></i>Editar nota técnica';

        var caixa = document.createElement('div');
        caixa.className = 'ps-nota-edicao';
        caixa.appendChild(rotulo);
        caixa.appendChild(textarea);
        caixa.appendChild(erro);
        caixa.appendChild(acoes);
        texto.replaceWith(caixa);

        function restaurar(html) {
            desmontar(textarea);
            caixa.replaceWith(textoDaNota(html));
        }

        var quillEd = montar(textarea);
        ligarMencoes(bloco, textarea, edicao.mencoes);
        focar(quillEd, textarea);

        cancelar.addEventListener('click', function () { restaurar(htmlAtual); });

        function confirmar() {
            if (salvar.disabled) { return; }
            if (estaVazio(textarea)) {
                erro.textContent = 'A nota técnica não pode ser vazia.';
                erro.hidden = false;
                return;
            }

            var fd = new FormData();
            fd.append('_token', botaoEditar.getAttribute('data-csrf') || '');
            fd.append('conteudo', conteudoParaEnvio(textarea));
            fd.append('pasta_id', bloco.getAttribute('data-pasta-id') || '');

            marcarEnvio(textarea, salvar, true);
            fetch(botaoEditar.getAttribute('data-url'), { method: 'POST', body: fd, headers: CABECALHOS, credentials: 'same-origin' })
                .then(function (resp) {
                    return resp.json().catch(function () { return {}; }).then(function (dados) {
                        if (!resp.ok) {
                            erro.textContent = dados.erro || 'Erro ao editar.';
                            erro.hidden = false;
                            marcarEnvio(textarea, salvar, false);
                            return;
                        }
                        restaurar(dados.conteudoHtml);
                        atualizarCopias(item, dados);
                    });
                })
                .catch(function () {
                    erro.textContent = 'Erro de rede.';
                    erro.hidden = false;
                    marcarEnvio(textarea, salvar, false);
                });
        }

        salvar.addEventListener('click', confirmar);
        caixa.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); confirmar(); }
            if (e.key === 'Escape') { restaurar(htmlAtual); }
        });
    }

    /* O texto da nota como o partial do Twig o monta (`.ps-nota-texto` > `.editor-rico-conteudo`
       > `.ql-editor`). O HTML vem sempre sanitizado do servidor. */
    function textoDaNota(html) {
        var div = document.createElement('div');
        div.className = 'ps-nota-texto';
        var externo = document.createElement('span');
        externo.className = 'editor-rico-conteudo';
        var interno = document.createElement('span');
        interno.className = 'ql-editor';
        interno.innerHTML = html || '';   // sempre sanitizado no servidor
        externo.appendChild(interno);
        div.appendChild(externo);
        return div;
    }

    /* FPN-020c: a mesma nota pode estar duas vezes na página — aba Processo e pendurada no
       Push (ids com sufixo `-push`). Depois de editar uma, TODAS as cópias com o mesmo
       `data-nota-id` recebem os data-* novos do Editar e a marca "editada", e as outras
       cópias, o texto novo — sem esperar o recarregar. A cópia editada já teve o texto
       restaurado por quem chama; uma cópia com a caixa de edição aberta não tem
       `.ps-nota-texto` e fica com o que a pessoa está escrevendo. */
    function atualizarCopias(item, dados) {
        var id = item.getAttribute('data-nota-id');
        var copias = document.querySelectorAll('.ps-nota[data-nota-id="' + id + '"]');
        Array.prototype.forEach.call(copias, function (copia) {
            var botao = copia.querySelector('.js-nota-editar');
            if (botao) {
                botao.setAttribute('data-conteudo', dados.conteudo || '');
                botao.setAttribute('data-conteudo-html', dados.conteudoHtml || '');
            }
            marcarEditada(copia, dados.editadaEm);
            if (copia === item) { return; }
            var texto = copia.querySelector('.ps-nota-texto');
            if (texto) { texto.replaceWith(textoDaNota(dados.conteudoHtml)); }
        });
    }

    /* "hoje, 14:32 · editada" — a mesma marca que o Twig põe na carga. */
    function marcarEditada(item, editadaEm) {
        var quando = item.querySelector('.ps-nota-quando');
        if (!quando || quando.querySelector('.ps-nota-editada')) { return; }
        var marca = document.createElement('span');
        marca.className = 'ps-nota-editada';
        marca.textContent = 'editada';
        if (editadaEm) { marca.title = 'Editada em ' + editadaEm; }
        quando.appendChild(document.createTextNode(' · '));
        quando.appendChild(marca);
    }

    /* ── Excluir ─────────────────────────────────────────────────────────── */
    function excluir(botaoExcluir) {
        var bloco = blocoDe(botaoExcluir);
        if (!bloco) { return; }
        if (!window.confirm('Excluir esta nota técnica? Esta ação não pode ser desfeita.')) { return; }

        var fd = new FormData();
        fd.append('_token', botaoExcluir.getAttribute('data-csrf') || '');
        fd.append('pasta_id', bloco.getAttribute('data-pasta-id') || '');

        fetch(botaoExcluir.getAttribute('data-url'), { method: 'POST', body: fd, headers: CABECALHOS, credentials: 'same-origin' })
            .then(function (resp) {
                return resp.json().catch(function () { return {}; }).then(function (dados) {
                    if (!resp.ok) {
                        window.alert(dados.erro || 'Erro ao excluir.');
                        return;
                    }
                    // Some de todos os blocos da página (aba Processo e Push pendurado, FPN-020).
                    var copias = document.querySelectorAll('.ps-nota[data-nota-id="' + botaoExcluir.getAttribute('data-nota-id') + '"]');
                    Array.prototype.forEach.call(copias, function (copia) {
                        var blocoDaCopia = blocoDe(copia);
                        copia.remove();
                        if (blocoDaCopia) { atualizarContagem(blocoDaCopia); }
                    });
                    atualizarContagem(bloco);
                });
            })
            .catch(function () { window.alert('Erro de rede.'); });
    }

    /* ── ⋮ da nota (PMC-017) ─────────────────────────────────────────────
       Menu fixo (o teor do Push corta o que transborda), aberto pelo ⋮ — ao lado
       do gatilho — ou pelo botão direito no cartão — no ponto do clique, como o
       `onContextMenu` do desenho. Um menu por vez; fora, Esc, rolar e redimensionar
       fecham. Editar/Excluir do menu são os próprios `.js-nota-editar/-excluir`;
       "Responder com outra nota" (`.js-nota-responder`) abre o compositor do bloco. */
    var menuAberto = null; // { menu, gatilho }

    function fecharMenu(devolverFoco) {
        if (!menuAberto) { return; }
        menuAberto.menu.hidden = true;
        menuAberto.gatilho.setAttribute('aria-expanded', 'false');
        if (devolverFoco) { menuAberto.gatilho.focus(); }
        menuAberto = null;
    }

    function abrirMenu(gatilho, x, y) {
        var menu = document.getElementById(gatilho.getAttribute('aria-controls') || '');
        if (!menu) { return; }
        var porClique = typeof x === 'number';
        if (!porClique && menuAberto && menuAberto.menu === menu) { fecharMenu(false); return; }
        fecharMenu(false);
        // Um menu por vez: fecha os popovers da pasta (pasta-show.js e os outros ⋮).
        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        menu.hidden = false;

        var left;
        var top;
        if (porClique) {
            left = x;
            top = y;
            if (top + menu.offsetHeight > window.innerHeight - 8) { top = y - menu.offsetHeight; }
        } else {
            var r = gatilho.getBoundingClientRect();
            left = r.right - menu.offsetWidth;
            top = r.bottom + 4;
            if (top + menu.offsetHeight > window.innerHeight - 8) { top = r.top - menu.offsetHeight - 4; }
        }
        menu.style.left = Math.max(8, Math.min(left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        menu.style.top = Math.max(8, top) + 'px';
        gatilho.setAttribute('aria-expanded', 'true');
        menuAberto = { menu: menu, gatilho: gatilho };
        var primeiro = menu.querySelector('.ps-processo-menu-item:not([disabled])');
        if (primeiro) { primeiro.focus(); }
    }

    /* "Copiar texto": o texto como se lê na tela (menção como "@Nome", sem a
       marcação do editor). O rótulo vira o aviso por um instante e o menu fecha,
       como no ⋮ das observações. */
    function copiar(item) {
        var artigo = item.closest('.ps-nota');
        var corpo = artigo ? artigo.querySelector('.ps-nota-texto .ql-editor') : null;
        var texto = corpo ? corpo.innerText.trim() : '';
        var rotulo = item.querySelector('span');
        var original = rotulo ? rotulo.textContent : '';
        var menuDaVez = menuAberto ? menuAberto.menu : null;
        function avisar(msg) {
            if (rotulo) { rotulo.textContent = msg; }
            setTimeout(function () {
                if (rotulo) { rotulo.textContent = original; }
                if (menuAberto && menuAberto.menu === menuDaVez) { fecharMenu(false); }
            }, 1200);
        }
        if (texto && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(
                function () { avisar('Nota copiada'); },
                function () { avisar('Não foi possível copiar'); }
            );
        } else {
            avisar('Não foi possível copiar');
        }
    }

    // Clique FORA fecha (em captura, antes de qualquer outra ação).
    document.addEventListener('click', function (e) {
        if (!menuAberto) { return; }
        if (menuAberto.menu.contains(e.target) || menuAberto.gatilho.contains(e.target)) { return; }
        fecharMenu(false);
    }, true);
    document.addEventListener('keydown', function (e) {
        if (menuAberto && e.key === 'Escape') { fecharMenu(true); }
    });
    window.addEventListener('resize', function () { fecharMenu(false); });
    window.addEventListener('scroll', function (e) {
        if (menuAberto && !(e.target instanceof Node && menuAberto.menu.contains(e.target))) { fecharMenu(false); }
    }, true);
    document.addEventListener('ps:fechar-popovers', function () { fecharMenu(false); });

    // Botão direito no cartão: o mesmo menu, no ponto do clique. Links, botões, a
    // caixa de edição e texto selecionado seguem com o menu do navegador.
    document.addEventListener('contextmenu', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }
        if (menuAberto && !menuAberto.menu.contains(alvo)) { fecharMenu(false); }

        var nota = alvo.closest('.ps-nota');
        if (!nota) { return; }
        if (alvo.closest('a, button, input, textarea, select, [contenteditable="true"], .ps-nota-edicao, .ps-nota-menu')) { return; }
        var selecao = window.getSelection ? String(window.getSelection()) : '';
        if (selecao.trim() !== '') { return; }

        var gatilho = nota.querySelector('.js-nota-menu');
        if (!gatilho) { return; }
        e.preventDefault();
        abrirMenu(gatilho, e.clientX, e.clientY);
    });

    /* ── Delegação ───────────────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }

        var gatilhoMenu = alvo.closest('.js-nota-menu');
        if (gatilhoMenu) { abrirMenu(gatilhoMenu); return; }

        // PMC-057: "Responder com outra nota" é o `abrirNova` do desenho — o compositor de nota
        // nova do bloco, com o foco. Não há vínculo de resposta (nota técnica não tem no modelo).
        var itemResponder = alvo.closest('.js-nota-responder');
        if (itemResponder) {
            fecharMenu(false);
            var blocoResp = blocoDe(itemResponder);
            if (blocoResp) { abrirCompositor(blocoResp); }
            return;
        }

        var itemCopiar = alvo.closest('.js-nota-copiar');
        if (itemCopiar) { copiar(itemCopiar); return; }

        var nova = alvo.closest('.js-nota-nova');
        if (nova) {
            var bloco = blocoDe(nova) || document.querySelector(nova.getAttribute('data-notas') || '');
            if (bloco) { abrirCompositor(bloco); }
            return;
        }

        var cancelar = alvo.closest('.js-nota-cancelar');
        if (cancelar) {
            var b1 = blocoDe(cancelar);
            if (b1) { fecharCompositor(b1); }
            return;
        }

        var salvar = alvo.closest('.js-nota-salvar');
        if (salvar) {
            var b2 = blocoDe(salvar);
            if (b2) { salvarNova(b2); }
            return;
        }

        // Editar/Excluir moram no ⋮: o menu fecha antes da ação (a caixa de edição, o confirm).
        var btnEditar = alvo.closest('.js-nota-editar');
        if (btnEditar) { fecharMenu(false); editar(btnEditar); return; }

        var btnExcluir = alvo.closest('.js-nota-excluir');
        if (btnExcluir) { fecharMenu(false); excluir(btnExcluir); }
    });

    /* Ctrl+Enter salva e Esc fecha — no compositor de nota nova (o de edição
       escuta na própria caixa). O Quill vive dentro de .ps-nota-editor, então
       as teclas sobem até aqui. */
    document.addEventListener('keydown', function (e) {
        var editor = e.target && e.target.closest ? e.target.closest('.ps-nota-editor') : null;
        if (!editor) { return; }
        var bloco = blocoDe(editor);
        if (!bloco) { return; }
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); salvarNova(bloco); }
        if (e.key === 'Escape') { fecharCompositor(bloco); }
    });

    /* ── Âncora de uma nota (`#nota-tecnica-<id>`) ─────────────────────────
       É o destino do aviso de @menção (ResolucaoDeMencoesDoRegistro). A nota
       mora na aba Processo, que não é a aba aberta na carga: abre a aba pelo
       próprio gatilho do Bootstrap (`<painel>-tab`, a convenção da pasta_show)
       e rola até o cartão, depois do reflow. */
    function notaDoFragmento() {
        var hash = window.location.hash;
        if (!/^#nota-tecnica-\d+$/.test(hash)) { return; }

        var alvo = document.getElementById(hash.slice(1));
        if (!alvo) { return; }

        var painel = alvo.closest('.tab-pane');
        var gatilho = painel && painel.id ? document.getElementById(painel.id + '-tab') : null;
        if (gatilho && !painel.classList.contains('active') && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(gatilho).show();
        }

        window.requestAnimationFrame(function () {
            alvo.scrollIntoView({ block: 'center' });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', notaDoFragmento);
    } else {
        notaDoFragmento();
    }
}());
