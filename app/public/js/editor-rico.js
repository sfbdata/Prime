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

    /**
     * Cores por NOME, não por hexadecimal — e isso é obrigatório, não estilo.
     *
     * A cor sai por CLASSE (`ql-color-red`), e o CSS do Quill só define regra para os nomes que ele
     * conhece. Com um hexadecimal na paleta, o editor gera `class="ql-color-#e60000"`: nome de
     * classe inválido, nenhuma regra casa, e a cor simplesmente não aparece.
     *
     * `false` é a opção "sem cor" (volta ao padrão do tema). Branco fica de fora: some no tema claro.
     */
    var PALETA = ['red', 'orange', 'green', 'blue', 'purple', false];

    /** Dica de cada controle da barra (o Quill não põe `title` sozinho). */
    var DICAS = {
        'ql-bold': 'Negrito',
        'ql-italic': 'Itálico',
        'ql-underline': 'Sublinhado',
        'ql-strike': 'Tachado',
        'ql-blockquote': 'Citação',
        'ql-clean': 'Limpar formatação',
        'ql-color': 'Cor do texto',
        'ql-header': 'Título',
        'ql-align': 'Alinhamento',
        'ql-link': 'Inserir link',
    };

    /** Controles que se repetem com valores diferentes (lista, recuo, alinhamento). */
    var DICAS_POR_VALOR = {
        'ql-list': { ordered: 'Lista numerada', bullet: 'Lista com marcadores' },
        'ql-indent': { '+1': 'Aumentar recuo', '-1': 'Diminuir recuo' },
        'ql-align': { '': 'Alinhar à esquerda', center: 'Centralizar', right: 'Alinhar à direita', justify: 'Justificar' },
        'ql-color': {
            red: 'Vermelho', orange: 'Laranja', green: 'Verde',
            blue: 'Azul', purple: 'Roxo', '': 'Sem cor',
        },
    };

    /**
     * Formatos aceitos — precisam ser um subconjunto do que o sanitizador `textoRico` permite.
     * Se divergissem, o usuário veria a formatação aplicada e a perderia ao salvar.
     */
    var FORMATOS = [
        'header', 'bold', 'italic', 'underline', 'strike',
        'color', 'list', 'indent', 'align', 'blockquote', 'link',
    ];

    var BARRA = [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: PALETA }],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ indent: '-1' }, { indent: '+1' }],
        [{ align: [] }],
        ['blockquote', 'link'],
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

    /**
     * Põe `title` em cada controle da barra. O Quill entrega só ícones, sem rótulo nem dica — quem
     * não reconhece o desenho fica sem saber o que o botão faz. Roda uma vez, após montar.
     */
    function aplicarDicas(casca) {
        var barra = casca.querySelector('.ql-toolbar');
        if (!barra) { return; }

        barra.querySelectorAll('button, .ql-picker').forEach(function (controle) {
            var classe = [].slice.call(controle.classList).find(function (c) {
                return c.indexOf('ql-') === 0 && c !== 'ql-picker';
            });
            if (!classe) { return; }

            var valor = controle.value !== undefined ? controle.value : null;
            var porValor = DICAS_POR_VALOR[classe];
            var dica = (porValor && valor !== null && porValor[valor]) || DICAS[classe];

            if (dica) { controle.setAttribute('title', dica); }
        });

        // Itens de dentro dos seletores (cores, níveis de título, alinhamentos).
        barra.querySelectorAll('.ql-picker-item').forEach(function (item) {
            var seletor = item.closest('.ql-picker');
            var classe = seletor && [].slice.call(seletor.classList).find(function (c) {
                return c.indexOf('ql-') === 0 && c !== 'ql-picker';
            });
            var valor = item.getAttribute('data-value') || '';
            var porValor = classe && DICAS_POR_VALOR[classe];

            if (porValor && porValor[valor]) {
                item.setAttribute('title', porValor[valor]);
            } else if (classe === 'ql-header') {
                item.setAttribute('title', valor === '' ? 'Texto normal' : 'Título ' + valor);
            }
        });
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

        aplicarDicas(casca);
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
