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
        if (window.EditorRico) { return EditorRico.montar(textarea); }
        return null;
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
        focar(montar(textarea), textarea);
    }

    function fecharCompositor(bloco) {
        var editor   = bloco.querySelector('.ps-nota-editor');
        var textarea = editor && editor.querySelector('textarea');
        if (!editor || !textarea) { return; }
        limpar(textarea);
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
        fd.append('conteudo', textarea.value);
        fd.append('pasta_id', bloco.getAttribute('data-pasta-id') || '');
        var publicacaoId = bloco.getAttribute('data-publicacao-id');
        if (publicacaoId) { fd.append('publicacao_id', publicacaoId); }

        botao.disabled = true;
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
                botao.disabled = false;
                botao.textContent = 'Salvar nota';
            });
    }

    /* ── Editar no lugar: a caixa toma o lugar do texto, como nas observações ── */
    function editar(botaoEditar) {
        var bloco = blocoDe(botaoEditar);
        var item  = document.getElementById('nota-tecnica-' + botaoEditar.getAttribute('data-nota-id'));
        var texto = item && item.querySelector('.ps-nota-texto');
        if (!bloco || !item || !texto || item.querySelector('.ps-nota-edicao')) { return; }

        var htmlAtual = botaoEditar.getAttribute('data-conteudo-html') || '';

        var textarea = document.createElement('textarea');
        textarea.className = 'ps-nota-textarea';
        textarea.rows = 3;
        textarea.maxLength = 5000;
        textarea.setAttribute('aria-label', 'Texto da nota técnica');
        textarea.value = botaoEditar.getAttribute('data-conteudo') || '';

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
            var div = document.createElement('div');
            div.className = 'ps-nota-texto';
            var externo = document.createElement('span');
            externo.className = 'editor-rico-conteudo';
            var interno = document.createElement('span');
            interno.className = 'ql-editor';
            interno.innerHTML = html || '';   // sempre sanitizado no servidor
            externo.appendChild(interno);
            div.appendChild(externo);
            caixa.replaceWith(div);
        }

        focar(montar(textarea), textarea);

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
            fd.append('conteudo', textarea.value);
            fd.append('pasta_id', bloco.getAttribute('data-pasta-id') || '');

            salvar.disabled = true;
            fetch(botaoEditar.getAttribute('data-url'), { method: 'POST', body: fd, headers: CABECALHOS, credentials: 'same-origin' })
                .then(function (resp) {
                    return resp.json().catch(function () { return {}; }).then(function (dados) {
                        if (!resp.ok) {
                            erro.textContent = dados.erro || 'Erro ao editar.';
                            erro.hidden = false;
                            salvar.disabled = false;
                            return;
                        }
                        restaurar(dados.conteudoHtml);
                        botaoEditar.setAttribute('data-conteudo', dados.conteudo || '');
                        botaoEditar.setAttribute('data-conteudo-html', dados.conteudoHtml || '');
                        marcarEditada(item, dados.editadaEm);
                    });
                })
                .catch(function () {
                    erro.textContent = 'Erro de rede.';
                    erro.hidden = false;
                    salvar.disabled = false;
                });
        }

        salvar.addEventListener('click', confirmar);
        caixa.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); confirmar(); }
            if (e.key === 'Escape') { restaurar(htmlAtual); }
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
                    var item = document.getElementById('nota-tecnica-' + botaoExcluir.getAttribute('data-nota-id'));
                    if (item) { item.remove(); }
                    atualizarContagem(bloco);
                });
            })
            .catch(function () { window.alert('Erro de rede.'); });
    }

    /* ── Delegação ───────────────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var alvo = e.target;
        if (!alvo || !alvo.closest) { return; }

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

        var btnEditar = alvo.closest('.js-nota-editar');
        if (btnEditar) { editar(btnEditar); return; }

        var btnExcluir = alvo.closest('.js-nota-excluir');
        if (btnExcluir) { excluir(btnExcluir); }
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
}());
