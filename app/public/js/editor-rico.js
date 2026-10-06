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
 * Barra no padrão do editor do desenho (bj-editor.js): desfazer/refazer · estilo · ênfase, cor e
 * realce · listas, recuo e alinhamento · citação · localizar/substituir e limpar. Rodapé com a
 * contagem de palavras e de caracteres contra o limite do servidor. Nada disso grava marcação
 * nova: realce é `ql-bg-*`, localizar/substituir só edita texto, contagem é só leitura.
 *
 * API pública (para telas que criam campos dinamicamente, como a edição inline):
 *   EditorRico.montar(textarea)                  → transforma a textarea (idempotente)
 *   EditorRico.montarTodos(raiz?)                → monta todas as `textarea[data-editor-rico]`
 *   EditorRico.definirConteudo(textarea, html)   → troca o conteúdo do editor
 *   EditorRico.limpar(textarea)                  → esvazia
 *   EditorRico.estaVazio(textarea)               → bool (ignora marcação sem texto)
 *   EditorRico.desmontar(textarea)               → devolve a textarea ao estado original
 *   EditorRico.instancia(textarea)               → a instância Quill (ou null)
 */
(function () {
    'use strict';

    /**
     * Limite de caracteres do TEXTO visível — o mesmo `> 5000` que os UseCases aplicam sobre
     * `SanitizadorTextoRico::comprimentoDoTexto()`. Contado do mesmo jeito (ver `comprimento()`),
     * senão o editor barraria antes do servidor, ou deixaria passar o que o servidor recusa.
     */
    var MAX_CARACTERES = 5000;

    /**
     * Cores por NOME, não por hexadecimal — e isso é obrigatório, não estilo.
     *
     * A cor sai por CLASSE (`ql-color-azul`, `ql-bg-amarelo`) e o sanitizador só preserva classe
     * que case `^ql-(color|bg)-[a-z0-9-]+$`. Com um hexadecimal na paleta, o editor geraria
     * `class="ql-color-#0f6fc4"`: classe inválida, descartada ao salvar. Cada nome tem a sua regra
     * em `editor-rico.css` (com variante para o tema escuro), que é carregado também nas telas de
     * exibição — o tom do desenho (bj-editor.js, `popCor`) fica lá, não aqui.
     *
     * Os nomes antigos (`red`, `orange`, `green`, `blue`, `purple`) saíram da paleta, mas o texto já
     * salvo com eles continua pintado: as regras vêm do `quill.snow.css` e do tema escuro abaixo.
     *
     * `false` é a opção "Automática" / "Sem realce" (volta ao padrão do tema).
     */
    var PALETA_TEXTO = ['grafite', 'azul', 'petroleo', 'verde', 'ocre', 'vermelho', 'roxo', 'cinza', false];
    var PALETA_REALCE = ['amarelo', 'menta', 'celeste', 'rosa', 'lilas', 'pessego', 'nevoa', 'limao', false];

    /** Dica de cada controle da barra (o Quill não põe `title` sozinho). */
    var DICAS = {
        'ql-undo': 'Desfazer (Ctrl+Z)',
        'ql-redo': 'Refazer (Ctrl+Y)',
        'ql-bold': 'Negrito (Ctrl+B)',
        'ql-italic': 'Itálico (Ctrl+I)',
        'ql-underline': 'Sublinhado (Ctrl+U)',
        'ql-strike': 'Tachado',
        'ql-blockquote': 'Citação',
        'ql-localizar': 'Localizar e substituir (Ctrl+H)',
        'ql-clean': 'Limpar formatação',
        'ql-color': 'Cor da fonte',
        'ql-background': 'Cor do realce',
        'ql-header': 'Estilo do parágrafo',
        'ql-align': 'Alinhamento',
    };

    /** Controles que se repetem com valores diferentes (lista, recuo, alinhamento, cores). */
    var DICAS_POR_VALOR = {
        'ql-list': { ordered: 'Numeração', bullet: 'Marcadores' },
        'ql-indent': { '+1': 'Aumentar recuo (Tab)', '-1': 'Diminuir recuo (Shift+Tab)' },
        'ql-align': { '': 'À esquerda', center: 'Centralizado', right: 'À direita', justify: 'Justificado' },
        'ql-color': {
            grafite: 'Grafite', azul: 'Azul', petroleo: 'Petróleo', verde: 'Verde',
            ocre: 'Ocre', vermelho: 'Vermelho', roxo: 'Roxo', cinza: 'Cinza', '': 'Automática',
        },
        'ql-background': {
            amarelo: 'Amarelo', menta: 'Verde', celeste: 'Azul', rosa: 'Rosa',
            lilas: 'Lilás', pessego: 'Pêssego', nevoa: 'Cinza', limao: 'Limão', '': 'Sem realce',
        },
    };

    /**
     * Ícones da barra (Bootstrap Icons, já carregado pelo `base.html.twig`), no lugar dos SVG do
     * tema Snow — são os mesmos do desenho. Chave: classe do controle, ou `classe:valor`.
     */
    var ICONES = {
        'ql-undo': 'bi-arrow-counterclockwise',
        'ql-redo': 'bi-arrow-clockwise',
        'ql-bold': 'bi-type-bold',
        'ql-italic': 'bi-type-italic',
        'ql-underline': 'bi-type-underline',
        'ql-strike': 'bi-type-strikethrough',
        'ql-list:ordered': 'bi-list-ol',
        'ql-list:bullet': 'bi-list-ul',
        'ql-indent:-1': 'bi-text-indent-right',
        'ql-indent:+1': 'bi-text-indent-left',
        'ql-blockquote': 'bi-quote',
        'ql-localizar': 'bi-search',
        'ql-clean': 'bi-eraser',
    };

    var ICONES_ALINHAMENTO = { '': 'bi-text-left', center: 'bi-text-center', right: 'bi-text-right', justify: 'bi-justify' };

    /**
     * Formatos aceitos — precisam ser um subconjunto do que o sanitizador `textoRico` permite.
     * Se divergissem, o usuário veria a formatação aplicada e a perderia ao salvar.
     */
    var FORMATOS = [
        'header', 'bold', 'italic', 'underline', 'strike',
        'color', 'background', 'list', 'indent', 'align', 'blockquote',
    ];

    /** Formatos de trecho (não de linha) — os que o substituir preserva no texto novo. */
    var FORMATOS_DE_TRECHO = ['bold', 'italic', 'underline', 'strike', 'color', 'background'];

    var BARRA = [
        ['undo', 'redo'],
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike', { color: PALETA_TEXTO }, { background: PALETA_REALCE }],
        [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }, { align: [] }],
        ['blockquote'],
        ['localizar', 'clean'],
    ];

    var registrado = false;

    /**
     * Faz cor e fundo saírem por CLASSE (`ql-color-azul`) em vez de `style="color:…"`. O atributo
     * `style` é o que o sanitizador não consegue filtrar por dentro, então ele não é liberado no
     * servidor — sem esta troca, toda cor seria descartada ao salvar.
     */
    function registrarAtributosPorClasse() {
        if (registrado || typeof Quill === 'undefined') { return; }

        Quill.register(Quill.import('attributors/class/color'), true);
        Quill.register(Quill.import('attributors/class/background'), true);
        registrado = true;
    }

    function el(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined) { e.textContent = texto; }

        return e;
    }

    function icone(nome) {
        return '<i class="bi ' + nome + '" aria-hidden="true"></i>';
    }

    function classeDoControle(controle) {
        return [].slice.call(controle.classList).find(function (c) {
            return c.indexOf('ql-') === 0 && c.indexOf('-picker') === -1
                && ['ql-picker', 'ql-active', 'ql-selected', 'ql-expanded'].indexOf(c) === -1;
        }) || null;
    }

    function formatarNumero(n) {
        return n.toLocaleString('pt-BR');
    }

    /**
     * Põe `title` (e `aria-label`) em cada controle da barra. O Quill entrega só ícones, sem rótulo
     * nem dica — quem não reconhece o desenho fica sem saber o que o botão faz.
     */
    function aplicarDicas(barra) {
        barra.querySelectorAll('button, .ql-picker').forEach(function (controle) {
            var classe = classeDoControle(controle);
            if (!classe) { return; }

            var valor = controle.tagName === 'BUTTON' ? controle.value : null;
            var porValor = DICAS_POR_VALOR[classe];
            var dica = (porValor && valor !== null && porValor[valor]) || DICAS[classe];

            if (dica) {
                controle.setAttribute('title', dica);
                if (controle.tagName === 'BUTTON') {
                    controle.setAttribute('aria-label', dica.replace(/\s*\(.*\)$/, ''));
                }
            }
        });

        // Itens de dentro dos seletores (cores, estilos, alinhamentos).
        barra.querySelectorAll('.ql-picker-item').forEach(function (item) {
            var seletor = item.closest('.ql-picker');
            var classe = seletor && classeDoControle(seletor);
            var valor = item.getAttribute('data-value') || '';
            var porValor = classe && DICAS_POR_VALOR[classe];

            if (porValor && porValor[valor]) {
                item.setAttribute('title', porValor[valor]);
            } else if (classe === 'ql-header') {
                item.setAttribute('title', { '': 'Normal', '2': 'Título 1', '3': 'Título 2' }[valor] || valor);
            }
        });
    }

    /**
     * Troca os SVG do Snow pelos ícones do desenho. Só o CONTEÚDO dos botões muda: o Quill liga os
     * cliques ao elemento (pela classe), então o comportamento segue intacto.
     *  - Seletor de alinhamento: o Quill copia o HTML do item escolhido para o rótulo, então basta
     *    trocar os itens (e o rótulo inicial).
     *  - Seletores de cor: o rótulo vira ícone + barrinha colorida; a cor da barrinha sai do CSS,
     *    pelo `data-value` que o Quill já põe no rótulo com a cor do trecho selecionado.
     */
    function aplicarIcones(barra) {
        barra.querySelectorAll('button').forEach(function (botao) {
            var classe = classeDoControle(botao);
            if (!classe) { return; }

            var nome = ICONES[classe + ':' + botao.value] || ICONES[classe];
            if (nome) { botao.innerHTML = icone(nome); }
        });

        var alinhar = barra.querySelector('.ql-picker.ql-align');
        if (alinhar) {
            alinhar.querySelectorAll('.ql-picker-item').forEach(function (item) {
                item.innerHTML = icone(ICONES_ALINHAMENTO[item.getAttribute('data-value') || '']);
            });
            var rotulo = alinhar.querySelector('.ql-picker-label');
            if (rotulo) {
                var valor = rotulo.getAttribute('data-value') || '';
                rotulo.innerHTML = icone(ICONES_ALINHAMENTO[valor] || ICONES_ALINHAMENTO['']);
            }
        }

        [['ql-color', 'bi-fonts'], ['ql-background', 'bi-highlighter']].forEach(function (par) {
            var rotulo = barra.querySelector('.ql-picker.' + par[0] + ' .ql-picker-label');
            if (rotulo) {
                rotulo.innerHTML = icone(par[1]) + '<span class="editor-rico-barra-cor" aria-hidden="true"></span>';
            }
        });

        // O seletor de estilo mostra o nome por CSS (`data-value` do rótulo); a seta é do desenho.
        var estilo = barra.querySelector('.ql-picker.ql-header .ql-picker-label');
        if (estilo) { estilo.innerHTML = icone('bi-chevron-expand'); }
    }

    function textoDoEditor(quill) {
        return (quill.getText() || '').replace(/\s| /g, '');
    }

    function vazio(quill) {
        return textoDoEditor(quill) === '';
    }

    /**
     * Comprimento como o servidor mede (`comprimentoDoTexto`): `strip_tags` do HTML, entidades
     * decodificadas, `trim` e `mb_strlen`. O HTML do Quill não tem quebra de linha entre os blocos
     * (`<p>a</p><p>b</p>` → "ab"), então as quebras de `getText()` não contam; e `mb_strlen` conta
     * pontos de código, não unidades UTF-16 — daí o `Array.from`.
     */
    function comprimento(quill) {
        var texto = (quill.getText() || '').replace(/\n/g, '').replace(/^[ \t\r\0\x0B]+|[ \t\r\0\x0B]+$/g, '');

        return Array.from(texto).length;
    }

    function contarPalavras(quill) {
        var texto = (quill.getText() || '').trim();

        return texto === '' ? 0 : texto.split(/\s+/).filter(Boolean).length;
    }

    /** Mantém a textarea igual ao editor — string vazia quando não há texto de verdade. */
    function sincronizar(textarea, quill) {
        textarea.value = vazio(quill) ? '' : quill.root.innerHTML;
    }

    /**
     * Quantas unidades UTF-16 do FIM de `texto` somam `alvo` caracteres visíveis (quebra de linha
     * não conta; par substituto conta como um). Devolve o tamanho a cortar e quanto foi coberto.
     */
    function cortePeloFim(texto, alvo) {
        var i = texto.length;
        var contados = 0;
        while (i > 0 && contados < alvo) {
            var passo = 1;
            var c = texto.charCodeAt(i - 1);
            if (c >= 0xDC00 && c <= 0xDFFF && i > 1) {
                var antes = texto.charCodeAt(i - 2);
                if (antes >= 0xD800 && antes <= 0xDBFF) { passo = 2; }
            }
            if (texto.charAt(i - 1) !== '\n') { contados++; }
            i -= passo;
        }

        return { tamanho: texto.length - i, coberto: contados };
    }

    /**
     * Tira o excedente do que ACABOU de entrar (digitado, colado, substituído) — e não do fim do
     * documento: colar no meio de um texto longo não pode apagar o parágrafo final. Se o texto já
     * chegou acima do limite (registro antigo), nada do que existia é apagado; só o acréscimo.
     * Devolve se cortou algo.
     */
    function aparaExcedente(quill, delta, excesso) {
        var pos = 0;
        var insercoes = [];
        (delta.ops || []).forEach(function (op) {
            if (typeof op.retain === 'number') {
                pos += op.retain;
            } else if (typeof op.insert === 'string') {
                insercoes.push({ inicio: pos, texto: op.insert });
                pos += op.insert.length;
            } else if (op.insert) {
                pos += 1;
            }
        });

        var cortes = [];
        for (var i = insercoes.length - 1; i >= 0 && excesso > 0; i--) {
            var corte = cortePeloFim(insercoes[i].texto, excesso);
            if (corte.tamanho > 0) {
                cortes.unshift({ inicio: insercoes[i].inicio + insercoes[i].texto.length - corte.tamanho, tamanho: corte.tamanho });
                excesso -= corte.coberto;
            }
        }
        if (cortes.length === 0) { return false; }

        var Delta = Quill.import('delta');
        var remocao = new Delta();
        var ultimo = 0;
        cortes.forEach(function (c) {
            remocao.retain(c.inicio - ultimo).delete(c.tamanho);
            ultimo = c.inicio + c.tamanho;
        });
        quill.updateContents(remocao, 'user');

        return true;
    }

    // ── Localizar e substituir ──────────────────────────────────────────────────────────────
    //
    // Tudo pela API do Quill (índices de `getText()`, que coincidem com os do documento porque o
    // editor não tem embutidos), nunca mexendo no DOM do editor por fora — senão o Quill perderia
    // o controle do conteúdo e o desfazer. Nada é gravado além do texto trocado.

    function montarBusca(refs) {
        var quill = refs.quill;
        var barra = el('div', 'editor-rico-busca');
        barra.hidden = true;
        barra.setAttribute('role', 'search');

        // Sem `name`: estes campos moram dentro do <form> da tela e não podem ir no FormData.
        var campoBusca = el('input', 'editor-rico-busca-campo');
        campoBusca.type = 'search';
        campoBusca.placeholder = 'Localizar';
        campoBusca.setAttribute('aria-label', 'Localizar');
        campoBusca.autocomplete = 'off';

        var campoTroca = el('input', 'editor-rico-busca-campo');
        campoTroca.type = 'text';
        campoTroca.placeholder = 'Substituir por';
        campoTroca.setAttribute('aria-label', 'Substituir por');
        campoTroca.autocomplete = 'off';

        var contador = el('span', 'editor-rico-busca-contador');
        contador.setAttribute('aria-live', 'polite');

        function botao(rotulo, titulo, classeExtra) {
            var b = el('button', 'editor-rico-busca-botao' + (classeExtra || ''));
            b.type = 'button';
            b.innerHTML = rotulo;
            if (titulo) { b.title = titulo; b.setAttribute('aria-label', titulo.replace(/\s*\(.*\)$/, '')); }

            return b;
        }

        var bProxima = botao(icone('bi-chevron-down'), 'Próxima (Enter)');
        var bUm = botao('Substituir');
        var bTodos = botao('Substituir todos', null, ' editor-rico-busca-botao-principal');
        var bFechar = botao(icone('bi-x-lg'), 'Fechar (Esc)', ' editor-rico-busca-botao-fechar');

        [campoBusca, campoTroca, contador, bProxima, bUm, bTodos, bFechar].forEach(function (e) { barra.appendChild(e); });

        // Marca a ocorrência atual sem tirar o foco do campo de busca (selecionar no editor
        // roubaria o foco, e o próximo Enter quebraria linha no texto em vez de buscar).
        var marca = el('div', 'editor-rico-busca-marca');
        marca.hidden = true;
        quill.container.appendChild(marca);

        var estado = { atual: -1 };

        function ocorrencias() {
            var q = campoBusca.value;
            var lista = [];
            if (q === '') { return lista; }

            var texto = quill.getText().toLowerCase();
            var alvo = q.toLowerCase();
            var i = texto.indexOf(alvo);
            while (i !== -1) {
                lista.push(i);
                i = texto.indexOf(alvo, i + alvo.length);
            }

            return lista;
        }

        function desenharMarca() {
            var lista = ocorrencias();
            if (barra.hidden || estado.atual < 0 || estado.atual >= lista.length) {
                marca.hidden = true;

                return;
            }

            var limites = quill.getBounds(lista[estado.atual], campoBusca.value.length);
            if (!limites) { marca.hidden = true; return; }

            // Rola o editor até a ocorrência, se ela estiver fora da área visível.
            var raiz = quill.root;
            var topoRaiz = raiz.offsetTop;
            if (limites.top < topoRaiz || limites.bottom > topoRaiz + raiz.clientHeight) {
                raiz.scrollTop += limites.top - topoRaiz - 30;
                limites = quill.getBounds(lista[estado.atual], campoBusca.value.length);
            }

            marca.style.left = limites.left + 'px';
            marca.style.top = limites.top + 'px';
            marca.style.width = Math.max(limites.width, 2) + 'px';
            marca.style.height = limites.height + 'px';
            marca.hidden = false;
        }

        function atualizarContador() {
            var n = ocorrencias().length;
            if (campoBusca.value === '') {
                contador.textContent = '';
            } else if (n === 0) {
                contador.textContent = 'Nada encontrado';
            } else if (estado.atual >= 0 && estado.atual < n) {
                contador.textContent = (estado.atual + 1) + ' de ' + n;
            } else {
                contador.textContent = n + (n === 1 ? ' ocorrência' : ' ocorrências');
            }
            desenharMarca();
        }

        function proxima() {
            var lista = ocorrencias();
            if (lista.length === 0) { estado.atual = -1; atualizarContador(); return; }

            estado.atual = (estado.atual + 1) % lista.length;
            atualizarContador();
        }

        function formatosDeTrecho(indice, tamanho) {
            var todos = quill.getFormat(indice, tamanho);
            var mantidos = {};
            FORMATOS_DE_TRECHO.forEach(function (f) {
                if (todos[f] !== undefined && !Array.isArray(todos[f])) { mantidos[f] = todos[f]; }
            });

            return mantidos;
        }

        function substituirUma() {
            var lista = ocorrencias();
            if (lista.length === 0) { atualizarContador(); return; }
            if (estado.atual < 0 || estado.atual >= lista.length) { proxima(); return; }

            var indice = lista[estado.atual];
            var tamanho = campoBusca.value.length;
            var Delta = Quill.import('delta');
            var troca = new Delta().retain(indice).delete(tamanho);
            if (campoTroca.value !== '') { troca.insert(campoTroca.value, formatosDeTrecho(indice, tamanho)); }
            quill.updateContents(troca, 'user');

            // Continua da ocorrência seguinte (a trocada saiu da lista).
            estado.atual -= 1;
            proxima();
        }

        function substituirTodas() {
            var lista = ocorrencias();
            if (lista.length === 0) { atualizarContador(); return; }

            var tamanho = campoBusca.value.length;
            var Delta = Quill.import('delta');
            var troca = new Delta();
            var ultimo = 0;
            // Um delta só: um único "desfazer" volta todas as trocas.
            lista.forEach(function (indice) {
                troca.retain(indice - ultimo).delete(tamanho);
                if (campoTroca.value !== '') { troca.insert(campoTroca.value, formatosDeTrecho(indice, tamanho)); }
                ultimo = indice + tamanho;
            });
            quill.updateContents(troca, 'user');

            estado.atual = -1;
            atualizarContador();
            contador.textContent = lista.length + (lista.length === 1 ? ' substituição' : ' substituições');
        }

        function abrir() {
            barra.hidden = false;
            refs.botaoLocalizar && refs.botaoLocalizar.classList.add('editor-rico-busca-aberta');
            var selecao = quill.getSelection();
            if (selecao && selecao.length > 0 && selecao.length < 60) {
                campoBusca.value = quill.getText(selecao.index, selecao.length).replace(/\n/g, ' ');
            }
            estado.atual = -1;
            atualizarContador();
            campoBusca.focus();
            campoBusca.select();
        }

        function fechar() {
            var lista = ocorrencias();
            var atual = estado.atual;
            barra.hidden = true;
            marca.hidden = true;
            refs.botaoLocalizar && refs.botaoLocalizar.classList.remove('editor-rico-busca-aberta');
            // Devolve o cursor ao texto — na ocorrência em que a busca parou, se houver.
            if (atual >= 0 && atual < lista.length) {
                quill.setSelection(lista[atual], campoBusca.value.length, 'user');
            } else {
                quill.focus();
            }
            estado.atual = -1;
        }

        function alternar() {
            if (barra.hidden) { abrir(); } else { fechar(); }
        }

        // Enter dentro destes campos submeteria o formulário da tela — aqui ele busca/substitui.
        campoBusca.addEventListener('input', function () { estado.atual = -1; atualizarContador(); });
        campoBusca.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); proxima(); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(); }
        });
        campoTroca.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); substituirUma(); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(); }
        });
        bProxima.addEventListener('click', proxima);
        bUm.addEventListener('click', substituirUma);
        bTodos.addEventListener('click', substituirTodas);
        bFechar.addEventListener('click', fechar);
        quill.root.addEventListener('scroll', desenharMarca);

        return {
            elemento: barra,
            alternar: alternar,
            abrir: abrir,
            // O texto mudou (digitação, desfazer): a ocorrência marcada pode ter mudado de lugar.
            aoMudarTexto: function () { if (!barra.hidden) { atualizarContador(); } },
        };
    }

    // ── Rodapé: palavras e caracteres ───────────────────────────────────────────────────────

    function montarRodape() {
        var rodape = el('div', 'editor-rico-rodape');
        var aviso = el('span', 'editor-rico-aviso');
        aviso.setAttribute('aria-live', 'polite');
        var contagem = el('span', 'editor-rico-contagem');
        rodape.appendChild(aviso);
        rodape.appendChild(contagem);

        return { elemento: rodape, aviso: aviso, contagem: contagem, temporizador: null };
    }

    function atualizarRodape(refs, cortou) {
        var r = refs.rodape;
        var n = comprimento(refs.quill);
        var palavras = contarPalavras(refs.quill);

        r.contagem.textContent = (palavras > 0 ? formatarNumero(palavras) + (palavras === 1 ? ' palavra · ' : ' palavras · ') : '')
            + formatarNumero(n) + ' / ' + formatarNumero(MAX_CARACTERES);
        r.contagem.title = 'Caracteres usados / limite';

        var acima = n > MAX_CARACTERES;
        r.elemento.classList.toggle('editor-rico-rodape-excedido', acima || n === MAX_CARACTERES);
        r.elemento.classList.toggle('editor-rico-rodape-perto', !acima && n < MAX_CARACTERES && n >= MAX_CARACTERES * 0.9);

        clearTimeout(r.temporizador);
        if (acima) {
            r.aviso.textContent = 'Acima do limite de ' + formatarNumero(MAX_CARACTERES) + ' caracteres — reduza '
                + formatarNumero(n - MAX_CARACTERES) + ' para salvar.';
        } else if (cortou) {
            r.aviso.textContent = 'Limite de ' + formatarNumero(MAX_CARACTERES) + ' caracteres atingido: o excedente não foi inserido.';
            r.temporizador = setTimeout(function () { r.aviso.textContent = ''; }, 6000);
        } else if (n < MAX_CARACTERES) {
            r.aviso.textContent = '';
        }
    }

    function atualizarHistorico(refs) {
        var pilha = refs.quill.history && refs.quill.history.stack;
        if (!pilha) { return; }
        if (refs.botaoDesfazer) { refs.botaoDesfazer.disabled = pilha.undo.length === 0; }
        if (refs.botaoRefazer) { refs.botaoRefazer.disabled = pilha.redo.length === 0; }
    }

    /** Conteúdo carregado (inicial ou trocado) não é ação do usuário: não entra no desfazer. */
    function zerarHistorico(refs) {
        if (refs.quill.history) { refs.quill.history.clear(); }
        atualizarHistorico(refs);
    }

    function carregar(quill, conteudo) {
        if (conteudo === '') {
            quill.setText('', 'silent');
        } else if (conteudo.indexOf('<') === -1) {
            quill.setText(conteudo, 'silent');
        } else {
            quill.clipboard.dangerouslyPasteHTML(conteudo, 'silent');
        }
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

        // As ações da barra precisam da instância desta casca (busca, histórico); `refs` é
        // preenchido logo após o `new Quill`, antes de qualquer clique ser possível.
        var refs = { casca: casca };

        var quill = new Quill(alvo, {
            theme: 'snow',
            formats: FORMATOS,
            placeholder: textarea.getAttribute('placeholder') || '',
            modules: {
                // `userOnly`: só o que a pessoa fez entra no desfazer — carregar conteúdo não.
                history: { delay: 1000, maxStack: 200, userOnly: true },
                toolbar: {
                    container: BARRA,
                    handlers: {
                        undo: function () { this.quill.history.undo(); },
                        redo: function () { this.quill.history.redo(); },
                        localizar: function () { refs.busca.alternar(); },
                    },
                },
                keyboard: {
                    bindings: {
                        localizar: {
                            key: 'h',
                            shortKey: true,
                            handler: function () { refs.busca.abrir(); return false; },
                        },
                    },
                },
            },
        });

        refs.quill = quill;

        var barraFerramentas = casca.querySelector('.ql-toolbar');
        if (barraFerramentas) {
            aplicarIcones(barraFerramentas);
            aplicarDicas(barraFerramentas);
            refs.botaoDesfazer = barraFerramentas.querySelector('button.ql-undo');
            refs.botaoRefazer = barraFerramentas.querySelector('button.ql-redo');
            refs.botaoLocalizar = barraFerramentas.querySelector('button.ql-localizar');
        }

        refs.busca = montarBusca(refs);
        casca.insertBefore(refs.busca.elemento, alvo);

        refs.rodape = montarRodape();
        casca.appendChild(refs.rodape.elemento);

        // Conteúdo pré-existente: HTML já sanitizado pelo servidor, ou legado em texto puro.
        if (conteudoInicial !== '') {
            carregar(quill, conteudoInicial);
        }

        sincronizar(textarea, quill);
        zerarHistorico(refs);
        atualizarRodape(refs, false);

        var aparando = false;
        quill.on('text-change', function (delta, anterior, origem) {
            var cortou = false;
            if (!aparando && origem === 'user') {
                var excesso = comprimento(quill) - MAX_CARACTERES;
                if (excesso > 0) {
                    aparando = true;
                    try {
                        cortou = aparaExcedente(quill, delta, excesso);
                    } finally {
                        aparando = false;
                    }
                }
            }
            sincronizar(textarea, quill);
            atualizarHistorico(refs);
            atualizarRodape(refs, cortou);
            refs.busca.aoMudarTexto();
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
        textarea._editorRico = refs;

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

        carregar(quill, html || '');

        var refs = textarea._editorRico;
        sincronizar(textarea, quill);
        zerarHistorico(refs);
        atualizarRodape(refs, false);
        refs.busca.aoMudarTexto();
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

        clearTimeout(refs.rodape && refs.rodape.temporizador);
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
