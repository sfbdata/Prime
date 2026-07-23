/**
 * Editor rico reutilizável (barra de formatação em campos de anotação livre).
 *
 * Liga-se a qualquer `<textarea data-editor-rico>` e a transforma num editor Quill, mantendo a
 * textarea original no DOM (escondida) como fonte da verdade do valor. Essa é a decisão central
 * do componente: o código que já existia em volta (`new FormData(form)`, `textarea.value.trim()`,
 * `textarea.value = ''`) continua funcionando SEM alteração, porque a textarea segue sendo lida e
 * escrita normalmente — o editor só a mantém sincronizada.
 *
 * Quando o editor está vazio, a textarea recebe string vazia (e não o `<p><br></p>` que o Quill
 * produz) — assim as validações de "não pode ser vazio" que já existem continuam valendo.
 *
 * SEGURANÇA: o HTML que sai daqui NÃO é confiável (é o navegador do usuário). Quem limpa é o
 * servidor, em `App\Shared\Service\SanitizadorTextoRico`, antes de persistir e ao exibir. Este
 * arquivo só reduz o que o editor produz para o conjunto que o sanitizador aceita — cor, fundo,
 * alinhamento e recuo saem por CLASSE `ql-*`, nunca por `style` inline, que o sanitizador barra.
 *
 * API pública (para telas que criam campos dinamicamente, como a edição inline):
 *   EditorRico.montar(textarea)                  → transforma a textarea (idempotente)
 *   EditorRico.definirConteudo(textarea, html)   → troca o conteúdo do editor
 *   EditorRico.limpar(textarea)                  → esvazia
 *   EditorRico.estaVazio(textarea)               → bool (ignora marcação sem texto)
 *   EditorRico.desmontar(textarea)               → devolve a textarea ao estado original
 */
(function () {
    'use strict';

    /** Espelha `max_input_length` do sanitizador `textoRico`, com folga para a marcação. */
    var MAX_CARACTERES = 5000;

    /** Cores que o CSS do Quill já estiliza dentro de `.ql-editor` (menos branco, que some no claro). */
    var PALETA = ['#e60000', '#ff9900', '#008a00', '#0066cc', '#9933ff', false];

    /**
     * Formatos aceitos — precisam ser um subconjunto do que o sanitizador `textoRico` permite.
     * Se divergissem, o usuário veria a formatação aplicada e a perderia ao salvar.
     */
    var FORMATOS = [
        'header', 'bold', 'italic', 'underline', 'strike',
        'color', 'list', 'indent', 'align', 'blockquote',
    ];

    var BARRA = [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: PALETA }],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ indent: '-1' }, { indent: '+1' }],
        [{ align: [] }],
        ['blockquote'],
        ['clean'],
    ];

    var registrado = false;

    /**
     * Faz cor e fundo saírem por CLASSE (`ql-color-red`) em vez de `style="color:…"`. O atributo
     * `style` é o que o sanitizador não consegue filtrar por dentro, então ele não é liberado no
     * servidor — sem esta troca, toda cor seria descartada ao salvar.
     */
    function registrarAtributosPorClasse() {
        if (registrado || typeof Quill === 'undefined') { return; }

        Quill.register(Quill.import('attributors/class/color'), true);
        Quill.register(Quill.import('attributors/class/background'), true);
        registrado = true;
    }

    function textoDoEditor(quill) {
        return (quill.getText() || '').replace(/\s| /g, '');
    }

    function vazio(quill) {
        return textoDoEditor(quill) === '';
    }

    /** Mantém a textarea igual ao editor — string vazia quando não há texto de verdade. */
    function sincronizar(textarea, quill) {
        textarea.value = vazio(quill) ? '' : quill.root.innerHTML;
    }

    function montar(textarea) {
        if (!textarea || textarea.dataset.editorRicoMontado === '1') { return null; }
        if (typeof Quill === 'undefined') {
            console.warn('editor-rico: Quill não carregado; a textarea segue funcionando como texto puro.');

            return null;
        }

        registrarAtributosPorClasse();

        var conteudoInicial = textarea.value || '';

        var casca = document.createElement('div');
        casca.className = 'editor-rico';

        var alvo = document.createElement('div');
        alvo.className = 'editor-rico-campo';

        casca.appendChild(alvo);
        textarea.parentNode.insertBefore(casca, textarea);

        textarea.classList.add('editor-rico-oculta');
        textarea.setAttribute('aria-hidden', 'true');
        textarea.setAttribute('tabindex', '-1');

        var quill = new Quill(alvo, {
            theme: 'snow',
            formats: FORMATOS,
            placeholder: textarea.getAttribute('placeholder') || '',
            modules: { toolbar: BARRA },
        });

        // Conteúdo pré-existente: HTML já sanitizado pelo servidor, ou legado em texto puro.
        if (conteudoInicial !== '') {
            if (conteudoInicial.indexOf('<') === -1) {
                quill.setText(conteudoInicial);
            } else {
                quill.clipboard.dangerouslyPasteHTML(conteudoInicial, 'silent');
            }
        }

        sincronizar(textarea, quill);

        quill.on('text-change', function () {
            var excedente = quill.getLength() - 1 - MAX_CARACTERES;
            if (excedente > 0) {
                quill.deleteText(MAX_CARACTERES, excedente, 'user');
            }
            sincronizar(textarea, quill);
        });

        // Ctrl+Enter continua enviando o formulário (comportamento que estas telas já tinham).
        quill.root.addEventListener('keydown', function (evento) {
            if ((evento.ctrlKey || evento.metaKey) && evento.key === 'Enter') {
                evento.preventDefault();
                var formulario = textarea.closest('form');
                if (formulario) { formulario.requestSubmit(); }
            }
        });

        textarea.dataset.editorRicoMontado = '1';
        textarea._editorRico = { quill: quill, casca: casca };

        return quill;
    }

    function instancia(textarea) {
        return textarea && textarea._editorRico ? textarea._editorRico.quill : null;
    }

    function definirConteudo(textarea, html) {
        var quill = instancia(textarea);
        if (!quill) {
            textarea.value = html || '';

            return;
        }

        var conteudo = html || '';
        if (conteudo === '') {
            quill.setText('');
        } else if (conteudo.indexOf('<') === -1) {
            quill.setText(conteudo);
        } else {
            quill.clipboard.dangerouslyPasteHTML(conteudo, 'silent');
        }

        sincronizar(textarea, quill);
    }

    function limpar(textarea) {
        definirConteudo(textarea, '');
    }

    function estaVazio(textarea) {
        var quill = instancia(textarea);

        return quill ? vazio(quill) : (textarea.value || '').trim() === '';
    }

    function desmontar(textarea) {
        var refs = textarea && textarea._editorRico;
        if (!refs) { return; }

        refs.casca.remove();
        textarea.classList.remove('editor-rico-oculta');
        textarea.removeAttribute('aria-hidden');
        textarea.removeAttribute('tabindex');
        delete textarea.dataset.editorRicoMontado;
        delete textarea._editorRico;
    }

    function montarTodos(raiz) {
        (raiz || document).querySelectorAll('textarea[data-editor-rico]').forEach(montar);
    }

    window.EditorRico = {
        montar: montar,
        montarTodos: montarTodos,
        definirConteudo: definirConteudo,
        limpar: limpar,
        estaVazio: estaVazio,
        desmontar: desmontar,
        instancia: instancia,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { montarTodos(); });
    } else {
        montarTodos();
    }
})();
