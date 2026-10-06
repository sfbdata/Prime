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
 * realce · listas, recuo e alinhamento · citação, símbolos e data de hoje · revisão,
 * localizar/substituir e limpar. Rodapé com a contagem de palavras e de caracteres contra o limite
 * do servidor. Ao digitar, a autocorreção do desenho (dicionário pt-BR/jurídico, acento e
 * maiúscula no começo da frase) com aviso e "Desfazer". Nada disso grava marcação nova: realce é
 * `ql-bg-*`; símbolos, data, revisão, autocorreção e localizar/substituir só inserem ou trocam
 * TEXTO; contagem é só leitura.
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
        'ql-simbolo': 'Inserir símbolo',
        'ql-data': 'Inserir data de hoje',
        'ql-revisar': 'Revisar texto',
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
        'ql-data': 'bi-calendar-plus',
        'ql-revisar': 'bi-spellcheck',
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
        ['blockquote', 'simbolo', 'data'],
        ['revisar', 'localizar', 'clean'],
    ];

    /**
     * Símbolos do desenho (`popSimbolo`), na mesma ordem. Entram como TEXTO puro — nenhum precisa
     * de marcação, então passam pelo sanitizador sem mudança nele.
     */
    var SIMBOLOS = ['§', 'º', 'ª', 'nº', 'R$', '°', '½', '¼', '©', '®', '™', '€', '•', '✓', '«', '»', '±', '×'];

    /**
     * Autocorreção do desenho (`AC` em bj-editor.js): digitação sem acento, abreviações e erros
     * comuns, com vocabulário jurídico. Copiado literalmente — regra nova aqui é desvio do desenho.
     */
    var AUTOCORRECAO = {
        nao: 'não', voce: 'você', voces: 'vocês', vc: 'você', vcs: 'vocês', tambem: 'também', tbm: 'também', entao: 'então', sao: 'são', ja: 'já', ate: 'até', apos: 'após', porem: 'porém', alem: 'além', atraves: 'através', pq: 'porque', mt: 'muito', mto: 'muito', hj: 'hoje', amanha: 'amanhã', manha: 'manhã', mes: 'mês', tres: 'três', possivel: 'possível', necessario: 'necessário', necessaria: 'necessária', responsavel: 'responsável', responsaveis: 'responsáveis', ultimo: 'último', ultima: 'última', proximo: 'próximo', proxima: 'próxima', unico: 'único', publico: 'público', pagina: 'página', conteudo: 'conteúdo', historico: 'histórico', saude: 'saúde', familia: 'família', endereco: 'endereço', numero: 'número', codigo: 'código', orgao: 'órgão', analise: 'análise', informacao: 'informação', informacoes: 'informações', situacao: 'situação', reuniao: 'reunião', alteracao: 'alteração', solicitacao: 'solicitação', obrigacao: 'obrigação', obrigatorio: 'obrigatório', consequencia: 'consequência', frequencia: 'frequência', excessao: 'exceção', excecao: 'exceção', concerteza: 'com certeza', derrepente: 'de repente', seje: 'seja', esteje: 'esteja', impecilho: 'empecilho', previlegio: 'privilégio', adevogado: 'advogado', cidadao: 'cidadão', orcamento: 'orçamento', credito: 'crédito', debito: 'débito', cobranca: 'cobrança',
        peticao: 'petição', peticoes: 'petições', audiencia: 'audiência', audiencias: 'audiências', honorarios: 'honorários', procuracao: 'procuração', procuracoes: 'procurações', acao: 'ação', acoes: 'ações', execucao: 'execução', contestacao: 'contestação', sentenca: 'sentença', decisao: 'decisão', intimacao: 'intimação', intimacoes: 'intimações', citacao: 'citação', notificacao: 'notificação', juridico: 'jurídico', juridica: 'jurídica', juizo: 'juízo', apelacao: 'apelação', certidao: 'certidão', documentacao: 'documentação', previdenciario: 'previdenciário', indenizacao: 'indenização', rescisao: 'rescisão', clausula: 'cláusula', clausulas: 'cláusulas', providencia: 'providência', providencias: 'providências', pendencia: 'pendência', pendencias: 'pendências', urgencia: 'urgência', sessao: 'sessão', distribuicao: 'distribuição', conciliacao: 'conciliação', homologacao: 'homologação', transito: 'trânsito', diligencia: 'diligência', diligencias: 'diligências', pericia: 'perícia', beneficio: 'benefício', beneficios: 'benefícios', competencia: 'competência', materia: 'matéria', autorizacao: 'autorização', declaracao: 'declaração', hipossuficiencia: 'hipossuficiência', residencia: 'residência', prescricao: 'prescrição', decadencia: 'decadência', jurisprudencia: 'jurisprudência', sumula: 'súmula', paragrafo: 'parágrafo', alinea: 'alínea', acordao: 'acórdão', manifestacao: 'manifestação', contrarrazoes: 'contrarrazões', atualizacao: 'atualização', observacao: 'observação', observacoes: 'observações', protocolizacao: 'protocolização', exequente: 'exequente', liquidacao: 'liquidação', penhora: 'penhora', avaliacao: 'avaliação', concessao: 'concessão', revisao: 'revisão', impugnacao: 'impugnação', reconvencao: 'reconvenção', publicacao: 'publicação', publicacoes: 'publicações', tramitacao: 'tramitação', instrucao: 'instrução', ceilandia: 'Ceilândia', brasilia: 'Brasília',
    };

    /** Palavras que só servem para reconhecer a forma acentuada / sugerir (`EXTRA` do desenho). */
    var VOCABULARIO_EXTRA = ['processo', 'processual', 'prazo', 'prazos', 'cliente', 'contrato', 'recurso', 'agravo', 'embargos', 'liminar', 'tutela', 'protocolo', 'protocolado', 'advogado', 'advogada', 'assinatura', 'assinado', 'comprovante', 'pagamento', 'parcela', 'acordo', 'atendimento', 'encaminhado', 'encaminhar', 'aguardando', 'solicitado', 'conforme', 'referente', 'mediante', 'expediente', 'expedientes', 'testemunha', 'depoimento', 'laudo', 'deferido', 'indeferido', 'julgado', 'julgamento', 'requerimento', 'requerente', 'requerido', 'executado', 'notificado', 'intimado', 'citado', 'cumprimento', 'embargante', 'embargado', 'agravante', 'agravado', 'apelante', 'apelado', 'reclamante', 'reclamada', 'TJDFT', 'PJe'];

    /** Tira acento e caixa: "Petição" → "peticao". */
    function dobrar(t) {
        return String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    var VOCABULARIO = Object.keys(AUTOCORRECAO).map(function (k) { return AUTOCORRECAO[k]; })
        .filter(function (v) { return v.indexOf(' ') === -1; })
        .concat(VOCABULARIO_EXTRA)
        .filter(function (v, i, todos) { return todos.indexOf(v) === i; })
        .sort(function (a, b) { return a.length - b.length; });

    /** Forma sem acento → forma correta ("peticao" → "petição"). */
    var VOCABULARIO_DOBRADO = new Map(VOCABULARIO.map(function (v) { return [dobrar(v), v]; }));

    /** Copia a caixa do que foi digitado para a correção ("NAO" → "NÃO", "Nao" → "Não"). */
    function mesmaCaixa(origem, saida) {
        if (/^[A-Z0-9]{2,}$/.test(saida)) { return saida; }
        if (origem.length > 1 && origem === origem.toUpperCase()) { return saida.toUpperCase(); }
        if (origem[0] && origem[0] === origem[0].toUpperCase() && origem[0] !== origem[0].toLowerCase()) {
            return saida[0].toUpperCase() + saida.slice(1);
        }

        return saida;
    }

    /** Distância de edição com teto (`lev` do desenho): passa do teto, devolve teto + 1. */
    function distancia(a, b, teto) {
        if (Math.abs(a.length - b.length) > teto) { return teto + 1; }
        var anterior = [];
        for (var k = 0; k <= b.length; k++) { anterior.push(k); }
        for (var i = 1; i <= a.length; i++) {
            var atual = [i];
            var melhor = i;
            for (var j = 1; j <= b.length; j++) {
                atual[j] = Math.min(anterior[j] + 1, atual[j - 1] + 1, anterior[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                melhor = Math.min(melhor, atual[j]);
            }
            if (melhor > teto) { return teto + 1; }
            anterior = atual;
        }

        return anterior[b.length];
    }

    /** Palavra conhecida mais próxima (`proximo` do desenho): 1 erro até 8 letras, 2 a partir de 9. */
    function palavraProxima(dobrada) {
        if (dobrada.length < 5) { return null; }
        var melhor = null;
        var menor = 9;
        var teto = dobrada.length >= 9 ? 2 : 1;
        VOCABULARIO_DOBRADO.forEach(function (v, vd) {
            if (vd[0] !== dobrada[0]) { return; }
            var d = distancia(dobrada, vd, teto);
            if (d <= teto && d < menor) { menor = d; melhor = v; }
        });

        return melhor;
    }

    /**
     * Correção automática de uma palavra, pelas regras do desenho: dicionário (`AC`) primeiro;
     * senão, a forma acentuada de uma palavra conhecida digitada sem acento. Null = nada a trocar.
     */
    function correcaoDe(palavra) {
        var minuscula = palavra.toLowerCase();
        var dobrada = dobrar(palavra);
        if (Object.prototype.hasOwnProperty.call(AUTOCORRECAO, minuscula)) {
            return mesmaCaixa(palavra, AUTOCORRECAO[minuscula]);
        }
        var conhecida = VOCABULARIO_DOBRADO.get(dobrada);
        if (conhecida !== undefined && conhecida !== minuscula && conhecida !== palavra && !/[^\x00-\x7f]/.test(palavra)) {
            return mesmaCaixa(palavra, conhecida);
        }

        return null;
    }

    /** Sugestão (não automática) para palavra desconhecida de 5+ letras que não é sigla. */
    function sugestaoDe(palavra) {
        var dobrada = dobrar(palavra);
        if (palavra.length < 5 || VOCABULARIO_DOBRADO.has(dobrada) || /^[A-Z]{2,}$/.test(palavra)) { return null; }
        var p = palavraProxima(dobrada);

        return p && p !== palavra.toLowerCase() ? mesmaCaixa(palavra, p) : null;
    }

    function escaparRegex(t) {
        return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

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
            // O desenho usa o próprio caractere "§" como ícone do botão de símbolos.
            if (classe === 'ql-simbolo') { botao.innerHTML = '<span class="editor-rico-icone-simbolo" aria-hidden="true">§</span>'; }
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

    /** Formatos de trecho (negrito, cor…) num ponto do texto — o texto inserido/trocado os herda. */
    function formatosDoTrecho(quill, indice, tamanho) {
        var todos = quill.getFormat(indice, tamanho);
        var mantidos = {};
        FORMATOS_DE_TRECHO.forEach(function (f) {
            if (todos[f] !== undefined && !Array.isArray(todos[f])) { mantidos[f] = todos[f]; }
        });

        return mantidos;
    }

    /**
     * Troca trechos do texto (`[{inicio, tamanho, para}]`, índices de `getText()`) num delta só,
     * isolado no histórico: um "desfazer" volta exatamente essa troca, nem mais nem menos.
     */
    function trocarTrechos(quill, trechos) {
        if (trechos.length === 0) { return; }
        var Delta = Quill.import('delta');
        var troca = new Delta();
        var ultimo = 0;
        trechos.slice().sort(function (a, b) { return a.inicio - b.inicio; }).forEach(function (t) {
            if (t.inicio < ultimo) { return; } // sobreposto a uma troca anterior: fica para a próxima rodada
            troca.retain(t.inicio - ultimo).delete(t.tamanho);
            if (t.para !== '') { troca.insert(t.para, formatosDoTrecho(quill, t.inicio, t.tamanho)); }
            ultimo = t.inicio + t.tamanho;
        });
        if (quill.history) { quill.history.cutoff(); }
        quill.updateContents(troca, 'user');
        if (quill.history) { quill.history.cutoff(); }
    }

    /** Insere texto puro no lugar da seleção (ou no fim), herdando a formatação do ponto. */
    function inserirTexto(quill, faixa, texto) {
        var f = faixa || { index: Math.max(quill.getLength() - 1, 0), length: 0 };
        var Delta = Quill.import('delta');
        var formatos = formatosDoTrecho(quill, f.index, f.length);
        quill.updateContents(new Delta().retain(f.index).delete(f.length).insert(texto, formatos), 'user');
        quill.setSelection(Math.min(f.index + texto.length, quill.getLength() - 1), 0, 'user');
    }

    // ── Janelinhas da barra (`.bjed-pop`) ──────────────────────────────────────────────────
    //
    // Abrem dentro da casca, logo abaixo do botão. Os botões de dentro não roubam o foco do texto
    // (`mousedown` cancelado), e a seleção é guardada ao abrir — o clique na janela a perderia.

    function fecharJanela(refs) {
        if (!refs.janela) { return; }
        refs.janela.elemento.remove();
        refs.janela.botao && refs.janela.botao.classList.remove('editor-rico-busca-aberta');
        document.removeEventListener('mousedown', refs.janela.foraDela, true);
        refs.janela = null;
    }

    function semRoubarFoco(botao) {
        botao.addEventListener('mousedown', function (e) { e.preventDefault(); });

        return botao;
    }

    function abrirJanela(refs, botao, conteudo) {
        fecharJanela(refs);
        var janela = el('div', 'editor-rico-janela');
        janela.setAttribute('role', 'dialog');
        janela.appendChild(conteudo);
        refs.casca.appendChild(janela);

        var caixa = refs.casca.getBoundingClientRect();
        var b = botao ? botao.getBoundingClientRect() : caixa;
        var esquerda = b.left - caixa.left;
        if (esquerda + janela.offsetWidth > caixa.width) { esquerda = Math.max(0, caixa.width - janela.offsetWidth); }
        janela.style.left = esquerda + 'px';
        janela.style.top = (b.bottom - caixa.top + 4) + 'px';

        var foraDela = function (e) {
            if (!janela.contains(e.target) && !(botao && botao.contains(e.target))) { fecharJanela(refs); }
        };
        document.addEventListener('mousedown', foraDela, true);
        botao && botao.classList.add('editor-rico-busca-aberta');
        refs.janela = { elemento: janela, botao: botao, foraDela: foraDela };
    }

    /** Clicar de novo no botão da janela aberta fecha a janela (como o desenho). */
    function alternarJanela(refs, botao, montarConteudo) {
        if (refs.janela && refs.janela.botao === botao) { fecharJanela(refs); refs.quill.focus(); return; }
        abrirJanela(refs, botao, montarConteudo());
    }

    function cabecalhoDaJanela(texto) {
        return el('div', 'editor-rico-janela-titulo', texto);
    }

    // ── Símbolos e data de hoje ────────────────────────────────────────────────────────────

    function abrirSimbolos(refs) {
        var quill = refs.quill;
        refs.faixaSalva = quill.getSelection(true);
        alternarJanela(refs, refs.botaoSimbolo, function () {
            var caixa = el('div');
            caixa.appendChild(cabecalhoDaJanela('Símbolos'));
            var grade = el('div', 'editor-rico-simbolos');
            SIMBOLOS.forEach(function (s) {
                var b = semRoubarFoco(el('button', '', s));
                b.type = 'button';
                b.title = 'Inserir ' + s;
                b.setAttribute('aria-label', 'Inserir ' + s);
                b.addEventListener('click', function () {
                    fecharJanela(refs);
                    quill.focus();
                    inserirTexto(quill, refs.faixaSalva, s);
                });
                grade.appendChild(b);
            });
            caixa.appendChild(grade);

            return caixa;
        });
    }

    /** Data de hoje no formato do desenho: `toLocaleDateString('pt-BR')` → "05/10/2026". */
    function inserirDataDeHoje(refs) {
        fecharJanela(refs);
        inserirTexto(refs.quill, refs.quill.getSelection(true), new Date().toLocaleDateString('pt-BR'));
    }

    // ── Revisão (`problemas` / `popRevisar` do desenho) ────────────────────────────────────
    //
    // Três regras, as do desenho: ortografia (dicionário + forma acentuada + palavra conhecida
    // mais próxima), palavra repetida ("de de") e espaço duplo entre palavras. Só aponta: cada
    // correção é um clique, e cada clique é um "desfazer".

    var LETRA = '\\p{L}\\p{M}';

    function problemasDoTexto(texto) {
        var lista = [];
        var vistas = {};

        (texto.match(/[\p{L}\p{M}]+/gu) || []).forEach(function (palavra) {
            if (vistas[palavra]) { return; }
            var para = correcaoDe(palavra) || sugestaoDe(palavra);
            if (para && para !== palavra) {
                vistas[palavra] = true;
                lista.push({ tipo: 'Ortografia', de: palavra, para: para, trechos: function (t) {
                    return trechosDaPalavra(t, palavra, para);
                } });
            }
        });

        // Palavra repetida só dentro da mesma linha — juntar fim e começo de parágrafos não é erro.
        var repetidas = {};
        var re = new RegExp('(^|[^' + LETRA + '])([' + LETRA + ']{2,})[ \\u00a0\\t]+\\2(?![' + LETRA + '])', 'giu');
        var m;
        while ((m = re.exec(texto)) !== null) {
            var chave = m[2].toLowerCase();
            if (!repetidas[chave]) {
                repetidas[chave] = true;
                var repetida = m[2];
                var de = m[0].slice(m[1].length);
                lista.push({ tipo: 'Palavra repetida', de: de, para: repetida, trechos: function (t) {
                    return trechosRepetidos(t, this.para);
                } });
            }
            re.lastIndex = m.index + m[1].length + m[2].length;
        }

        if (/\S[  ]{2,}(?=\S)/.test(texto)) {
            lista.push({ tipo: 'Espaço duplo', de: '··', para: '·', trechos: trechosDeEspacoDuplo });
        }

        return lista;
    }

    function trechosDaPalavra(texto, palavra, para) {
        var re = new RegExp('(^|[^' + LETRA + '])(' + escaparRegex(palavra) + ')(?![' + LETRA + '])', 'gu');
        var lista = [];
        var m;
        while ((m = re.exec(texto)) !== null) {
            lista.push({ inicio: m.index + m[1].length, tamanho: palavra.length, para: para });
            re.lastIndex = m.index + m[1].length + palavra.length;
        }

        return lista;
    }

    /** Apaga a SEGUNDA ocorrência (e o espaço antes dela): "de de" → "de". */
    function trechosRepetidos(texto, palavra) {
        var w = escaparRegex(palavra);
        var re = new RegExp('(^|[^' + LETRA + '])(' + w + ')([ \\u00a0\\t]+' + w + ')(?![' + LETRA + '])', 'giu');
        var lista = [];
        var m;
        while ((m = re.exec(texto)) !== null) {
            lista.push({ inicio: m.index + m[1].length + m[2].length, tamanho: m[3].length, para: '' });
            re.lastIndex = m.index + m[0].length;
        }

        return lista;
    }

    /** Deixa um espaço só entre palavras (recuo no começo da linha não é tocado). */
    function trechosDeEspacoDuplo(texto) {
        var re = /\S([  ])([  ]+)(?=\S)/g;
        var lista = [];
        var m;
        while ((m = re.exec(texto)) !== null) {
            lista.push({ inicio: m.index + 2, tamanho: m[2].length, para: '' });
        }

        return lista;
    }

    function corrigirProblema(quill, problema) {
        trocarTrechos(quill, problema.trechos.call(problema, quill.getText()));
    }

    function conteudoDaRevisao(refs) {
        var quill = refs.quill;
        var caixa = el('div', 'editor-rico-revisao');
        caixa.appendChild(cabecalhoDaJanela('Revisão do texto'));
        var problemas = problemasDoTexto(quill.getText());

        if (problemas.length === 0) {
            var ok = el('div', 'editor-rico-revisao-ok');
            ok.innerHTML = icone('bi-check-circle-fill');
            ok.appendChild(document.createTextNode('Nenhum problema encontrado.'));
            caixa.appendChild(ok);

            return caixa;
        }

        problemas.forEach(function (p) {
            var item = semRoubarFoco(el('button', 'editor-rico-revisao-item'));
            item.type = 'button';
            var corpo = el('span', 'editor-rico-revisao-corpo');
            corpo.appendChild(el('span', 'editor-rico-revisao-tipo', p.tipo));
            var troca = el('span');
            troca.appendChild(el('s', 'editor-rico-revisao-de', p.de));
            troca.insertAdjacentHTML('beforeend', ' ' + icone('bi-arrow-right editor-rico-revisao-seta') + ' ');
            troca.appendChild(el('b', 'editor-rico-revisao-para', p.para));
            corpo.appendChild(troca);
            item.appendChild(corpo);
            item.appendChild(el('span', 'editor-rico-revisao-acao', 'Corrigir'));
            item.addEventListener('click', function () {
                corrigirProblema(quill, p);
                abrirJanela(refs, refs.botaoRevisar, conteudoDaRevisao(refs));
            });
            caixa.appendChild(item);
        });

        var todos = semRoubarFoco(el('button', 'editor-rico-busca-botao editor-rico-busca-botao-principal editor-rico-revisao-todos',
            'Corrigir tudo (' + problemas.length + ')'));
        todos.type = 'button';
        todos.addEventListener('click', function () {
            // Recalcula a cada regra: a troca anterior pode ter mudado as posições.
            problemas.forEach(function (p) { corrigirProblema(quill, p); });
            abrirJanela(refs, refs.botaoRevisar, conteudoDaRevisao(refs));
        });
        caixa.appendChild(todos);

        return caixa;
    }

    function abrirRevisao(refs) {
        alternarJanela(refs, refs.botaoRevisar, function () { return conteudoDaRevisao(refs); });
    }

    // ── Autocorreção ao digitar (`autoCorrigir` do desenho) ────────────────────────────────
    //
    // Dispara quando a pessoa digita um separador (espaço, pontuação, ")") logo depois de uma
    // palavra: aplica o dicionário/acento e a maiúscula no começo de frase, e avisa num chip com
    // "Desfazer". Palavra desconhecida parecida com uma conhecida só ganha SUGESTÃO, nunca troca.

    var SEPARADOR = /^[ \t.,;:!?) ]$/;

    /** O delta é "digitou UM separador"? Devolve a posição dele, ou -1. */
    function separadorDigitado(delta) {
        var ops = delta.ops || [];
        var pos = 0;
        var i = 0;
        if (ops[i] && typeof ops[i].retain === 'number' && !ops[i].attributes) { pos = ops[i].retain; i++; }
        if (ops.length !== i + 1 || typeof ops[i].insert !== 'string' || !SEPARADOR.test(ops[i].insert)) { return -1; }

        return pos;
    }

    function fecharChip(refs) {
        if (!refs.chip) { return; }
        clearTimeout(refs.chip.temporizador);
        refs.chip.elemento.remove();
        refs.chip = null;
    }

    function mostrarChip(refs, indice, conteudo, botoes, duracao) {
        fecharChip(refs);
        var quill = refs.quill;
        var chip = el('div', 'editor-rico-chip');
        chip.setAttribute('role', 'status');
        chip.appendChild(conteudo);
        botoes.forEach(function (b) {
            var botao = semRoubarFoco(el('button', '', b.rotulo));
            botao.type = 'button';
            botao.addEventListener('click', function () { fecharChip(refs); quill.focus(); b.acao(); });
            chip.appendChild(botao);
        });
        refs.casca.appendChild(chip);

        var limites = quill.getBounds(indice, 0);
        var base = quill.container.offsetTop;
        if (limites) {
            var topo = base + limites.top - chip.offsetHeight - 8;
            chip.style.top = (topo < 0 ? base + limites.bottom + 8 : topo) + 'px';
            chip.style.left = Math.max(0, Math.min(limites.left - 10, refs.casca.clientWidth - chip.offsetWidth)) + 'px';
        }
        refs.chip = { elemento: chip, temporizador: setTimeout(function () { fecharChip(refs); }, duracao) };
    }

    function textoDoChip(iconeNome, partes) {
        var span = el('span');
        span.innerHTML = icone(iconeNome + ' editor-rico-chip-icone');
        partes.forEach(function (p) { span.appendChild(p.negrito ? el('b', '', p.texto) : document.createTextNode(p.texto)); });

        return span;
    }

    function autoCorrigir(refs, posSeparador, separador) {
        var quill = refs.quill;
        var texto = quill.getText();
        if (texto.charAt(posSeparador) !== separador) { return; } // o separador foi aparado/desfeito

        var inicioLinha = texto.lastIndexOf('\n', posSeparador - 1) + 1;
        var m = texto.slice(inicioLinha, posSeparador).match(/[\p{L}\p{M}]+$/u);
        if (!m) { return; }

        var palavra = m[0];
        var inicio = posSeparador - palavra.length;
        var saida = correcaoDe(palavra) || palavra;

        // Maiúscula no começo do parágrafo ou depois de ". ", "! ", "? ".
        var antes = texto.slice(inicioLinha, inicio).replace(/ /g, ' ');
        if ((/^\s*$/.test(antes) || /[.!?]\s+$/.test(antes)) && saida[0] !== saida[0].toUpperCase()) {
            saida = saida[0].toUpperCase() + saida.slice(1);
        }

        if (saida !== palavra) {
            trocarTrechos(quill, [{ inicio: inicio, tamanho: palavra.length, para: saida }]);
            // "Desfazer" só volta a correção se ela ainda for o último passo do histórico — se a
            // pessoa já digitou depois, desfazer apagaria a digitação dela, não a correção.
            var pilha = quill.history && quill.history.stack;
            var passos = pilha ? pilha.undo.length : -1;
            mostrarChip(refs, inicio, textoDoChip('bi-magic', [
                { texto: palavra, negrito: true }, { texto: ' → ' + saida },
            ]), [{ rotulo: 'Desfazer', acao: function () {
                if (pilha && pilha.undo.length === passos) { quill.history.undo(); }
            } }], 3600);

            return;
        }

        var sugestao = sugestaoDe(palavra);
        if (sugestao) {
            mostrarChip(refs, inicio, textoDoChip('bi-spellcheck', [
                { texto: 'Você quis dizer ' }, { texto: sugestao, negrito: true }, { texto: '?' },
            ]), [
                { rotulo: 'Corrigir', acao: function () {
                    // A palavra pode ter mudado de lugar desde o aviso: troca a última ocorrência dela.
                    var ocorrencias = trechosDaPalavra(quill.getText(), palavra, sugestao);
                    if (ocorrencias.length) { trocarTrechos(quill, [ocorrencias[ocorrencias.length - 1]]); }
                } },
                { rotulo: 'Ignorar', acao: function () {} },
            ], 6000);
        }
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
            return formatosDoTrecho(quill, indice, tamanho);
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
                        localizar: function () { fecharJanela(refs); refs.busca.alternar(); },
                        simbolo: function () { abrirSimbolos(refs); },
                        data: function () { inserirDataDeHoje(refs); },
                        revisar: function () { abrirRevisao(refs); },
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
            refs.botaoSimbolo = barraFerramentas.querySelector('button.ql-simbolo');
            refs.botaoRevisar = barraFerramentas.querySelector('button.ql-revisar');
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

            // Autocorreção: depois do Quill terminar de aplicar a digitação (como o desenho).
            if (origem === 'user' && !cortou) {
                var pos = separadorDigitado(delta);
                if (pos >= 0) {
                    var separador = delta.ops[delta.ops.length - 1].insert;
                    setTimeout(function () { if (refs.quill) { autoCorrigir(refs, pos, separador); } }, 0);
                }
            }
        });

        // Esc fecha a janelinha e o aviso de correção (sem fechar o modal em volta, se houver).
        casca.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && (refs.janela || refs.chip)) {
                evento.stopPropagation();
                fecharJanela(refs);
                fecharChip(refs);
            }
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
        fecharJanela(refs);
        fecharChip(refs);
        refs.casca.remove();
        refs.quill = null;
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
