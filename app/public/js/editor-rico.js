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
 *   EditorRico.marcarEnvio(textarea, enviando)   → envio em curso: o Enviar fica desabilitado
 *   EditorRico.inserirCitacao(textarea, html)    → "Citar no novo registro": o trecho entra como citação
 *
 * Botão Enviar do compositor: a textarea aponta o seu botão por `data-editor-rico-envio="<id>"`,
 * e o editor o mantém desabilitado enquanto estiver vazio (desenho, `mudou`: `env.disabled = v`).
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

    /**
     * EDT-018: "Citação" no seletor de estilo do parágrafo, como o `popEstilo` do desenho (Normal ·
     * Título 1 · Título 2 · Citação). O seletor é o do formato `header`; este valor não é um nível
     * de título — o handler `header` o traduz para o formato `blockquote` (já permitido pelo
     * sanitizador), e nunca chega a virar `header` no texto.
     */
    var ESTILO_CITACAO = 'citacao';

    var BARRA = [
        ['undo', 'redo'],
        [{ header: [false, 2, 3, ESTILO_CITACAO] }],
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

    /**
     * Sinônimos do menu do botão direito (EDT-012): o dicionário local `SIN` do desenho
     * (bj-editor.js l.166), copiado literalmente — chave sem acento e minúscula, como `dobrar()`
     * devolve. Estático: nada sai do navegador nem vem de serviço externo.
     */
    var SINONIMOS = { urgente: ['prioritário', 'imediato', 'inadiável'], cliente: ['constituinte', 'contratante', 'assistido'], prazo: ['termo', 'período', 'limite'], processo: ['ação', 'feito', 'autos'], documento: ['arquivo', 'peça', 'registro'], solicitar: ['requerer', 'pedir', 'pleitear'], informar: ['comunicar', 'notificar', 'cientificar'], analisar: ['examinar', 'avaliar', 'verificar'], problema: ['questão', 'pendência', 'impasse'], importante: ['relevante', 'essencial', 'fundamental'], rapido: ['ágil', 'célere', 'breve'], resolver: ['solucionar', 'sanar', 'regularizar'], acordo: ['composição', 'ajuste', 'transação'], reuniao: ['encontro', 'conferência', 'atendimento'], enviar: ['encaminhar', 'remeter', 'despachar'], receber: ['obter', 'acolher'], verificar: ['conferir', 'checar', 'apurar'], pedido: ['requerimento', 'solicitação', 'pleito'], decisao: ['deliberação', 'despacho', 'julgamento'], necessario: ['indispensável', 'imprescindível', 'essencial'], possivel: ['viável', 'factível'], atraso: ['demora', 'morosidade'], pagamento: ['quitação', 'liquidação', 'adimplemento'], contrato: ['instrumento', 'ajuste', 'avença'], advogado: ['patrono', 'procurador', 'causídico'], juiz: ['magistrado', 'julgador'], informacao: ['dado', 'esclarecimento', 'comunicação'], providencia: ['medida', 'diligência', 'ação'], concluir: ['finalizar', 'encerrar', 'terminar'], iniciar: ['começar', 'instaurar', 'dar início'], aguardar: ['esperar', 'acompanhar'], atualizar: ['renovar', 'revisar', 'complementar'], ajuizar: ['propor', 'distribuir', 'protocolar'] };

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
                item.setAttribute('title', { '': 'Normal', '2': 'Título 1', '3': 'Título 2', citacao: 'Citação' }[valor] || valor);
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

    /**
     * Estilo do parágrafo escolhido no seletor (`bloco(t)` do desenho): Citação liga o
     * `blockquote`; Normal desliga o que houver (título ou citação); Título 1/2 trocam o bloco —
     * no Quill, título e citação são blocos excludentes, então o título substitui a citação.
     */
    function aplicarEstilo(quill, valor) {
        if (valor === ESTILO_CITACAO) {
            quill.format('blockquote', true, 'user');

            return;
        }
        if (!valor) {
            quill.format('blockquote', false, 'user');
        }
        quill.format('header', valor, 'user');
    }

    /**
     * O Quill só sabe marcar no seletor o formato dele (`header`): com o cursor numa citação, o
     * rótulo voltaria para "Normal". Depois de cada atualização da barra, se o bloco é citação, a
     * opção Citação é marcada no seletor (e no rótulo, pelo `update` do próprio seletor do Quill).
     */
    function marcarCitacaoNoEstilo(refs) {
        var seletor = refs.seletorEstilo;
        var faixa = refs.quill.selection.getRange()[0];
        if (!seletor || !faixa || !refs.quill.getFormat(faixa).blockquote) { return; }

        var opcao = seletor.select.querySelector('option[value="' + ESTILO_CITACAO + '"]');
        if (!opcao) { return; }
        opcao.selected = true;
        seletor.update();
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

    /** Tab fora de lista: 4 espaços não separáveis (`onKey` do desenho, bj-editor.js l.682-684). */
    var TABULACAO = '\u00a0\u00a0\u00a0\u00a0';

    /**
     * Tecla Tab (sem Shift; Shift+Tab fora de lista segue para o navegador, como no desenho).
     * Na lista, o desenho recua o item em qualquer posição do cursor — o Quill só recuava com o
     * cursor no começo e, no meio da linha, inseria "\t".
     */
    function tabulacao(quill, faixa, contexto) {
        if (contexto.format && contexto.format.list) {
            quill.format('indent', '+1', 'user');

            return false;
        }
        inserirTexto(quill, faixa, TABULACAO);

        return false;
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

    // ── Autoformatação ao digitar (`autoFormatar` do desenho, bj-editor.js l.618-626) ───────
    //
    // No começo de um parágrafo, "1." ou "1)" + espaço vira numeração, "-" ou "*" + espaço vira
    // marcadores e "> " vira citação — e um chip avisa "Formatação automática aplicada", com
    // Desfazer. As listas seguem com a conversão do próprio Quill (`list autofill`, que também
    // aceita "2.", "[ ]" e "[x]"); a citação é feita aqui, do mesmo jeito: o espaço entra, o
    // histórico é cortado e a conversão vira UM passo — Desfazer volta exatamente o "> " digitado.

    /** Prefixos do `list autofill` do Quill, mais "1)" e ">" (este só no começo exato da linha). */
    var PREFIXO_AUTOFORMATACAO = /^(?:\s*?(\d+\.|1\)|-|\*|\[ ?\]|\[x\])|>)$/;

    var autopreencherLista = null;

    /** O handler original do Quill para as listas (lido do próprio Quill, não copiado). */
    function conversaoDeListaDoQuill() {
        if (!autopreencherLista) {
            autopreencherLista = Quill.import('modules/keyboard').DEFAULTS.bindings['list autofill'].handler;
        }

        return autopreencherLista;
    }

    function autoFormatar(refs, teclado, faixa, contexto) {
        var quill = refs.quill;
        var inicio = faixa.index - contexto.offset;
        var convertido;

        if (contexto.prefix === '>') {
            convertido = citacaoAutomatica(quill, faixa, contexto);
        } else {
            convertido = conversaoDeListaDoQuill().call(teclado, faixa, contexto) === false;
        }
        if (!convertido) { return true; } // não converteu: o espaço segue como espaço

        // "Desfazer" só volta a conversão se ela ainda for o último passo do histórico.
        var pilha = quill.history && quill.history.stack;
        var passos = pilha ? pilha.undo.length : -1;
        mostrarChip(refs, inicio, textoDoChip('bi-magic', [{ texto: 'Formatação automática aplicada' }]), [
            { rotulo: 'Desfazer', acao: function () {
                if (pilha && pilha.undo.length === passos) { quill.history.undo(); }
            } },
        ], 3600);

        return false;
    }

    /** "> " no começo de um parágrafo (fora de lista, como o desenho): vira citação. */
    function citacaoAutomatica(quill, faixa, contexto) {
        if (contexto.format.list || contexto.offset !== 1) { return false; }

        var inicio = faixa.index - contexto.offset;
        var Delta = Quill.import('delta');
        quill.insertText(faixa.index, ' ', 'user');
        if (quill.history) { quill.history.cutoff(); }
        quill.updateContents(new Delta().retain(inicio).delete(contexto.offset + 1), 'user');
        quill.formatLine(inicio, 1, 'blockquote', true, 'user');
        if (quill.history) { quill.history.cutoff(); }
        quill.setSelection(inicio, 0, 'silent');

        return true;
    }

    // ── Menu do botão direito (`ctxMenu` do desenho, bj-editor.js l.693-762) ───────────────
    //
    // No padrão do Word: mini barra de formatação; Recortar, Copiar, Colar, Colar somente texto;
    // Parágrafo (alinhar e recuar); Localizar e substituir, Revisar texto e Selecionar tudo. Cada
    // item chama a MESMA função que a barra e os atalhos já usam (API do Quill: `format`,
    // `removeFormat`, `setSelection`, a busca e a revisão) — o menu não grava marcação nova.
    //
    // Só o botão direito do MOUSE abre este menu. Shift + botão direito (como no desenho), o teclado
    // (tecla de menu, Shift+F10) e o toque longo — casos que o desenho não cobre — seguem com o
    // menu do navegador.
    //
    // Sinônimos (EDT-012): com a palavra sob o cursor no dicionário local `SINONIMOS`, o bloco
    // "Sinônimos de "palavra"" entra antes de Localizar, e cada item troca a palavra (na caixa
    // dela) num passo só do histórico. Como no desenho, o botão direito fora da seleção leva o
    // cursor ao ponto do clique — a palavra é a que está sob o mouse.
    //
    // Sugestões de correção (C6-EDT-05): com correção para a palavra sob o cursor no dicionário
    // local (`correcoesDoMenu`), o bloco "Sugestões de correção" entra antes de Recortar, com até
    // 3 palavras em verde, e cada uma troca a palavra como os sinônimos.
    //
    // "Inserir qualificação do cliente" (EDT-014) entra antes de Localizar, só na página que traz
    // a qualificação do cliente.
    //
    // Fora deste menu: "Definir", "Traduzir" e "Pesquisar na web" (mandariam o texto a serviço de
    // terceiros) e "Hiperlink…" (EDT-025: a barra não tem link).
    //
    // Área de transferência: Recortar e Copiar usam a Clipboard API quando ela existe (texto e
    // HTML do próprio editor) e, sem ela, o comando do navegador, que o Quill trata como Ctrl+C/X.
    // Colar só lê a área de transferência com a permissão do navegador; sem ela, o aviso do
    // desenho ("Use Ctrl+V para colar…") — e nada é colado. O que vem colado passa pelo MESMO
    // conversor da colagem do Quill (`clipboard.convert`), que só deixa os FORMATOS do editor.

    /** Últimas cores usadas na barra (desenho: `corAtual` #c0392f e `realceAtual` #fff3a3 no início). */
    var COR_INICIAL = 'vermelho';
    var REALCE_INICIAL = 'amarelo';

    function fecharMenuContexto(refs) {
        var m = refs.menuContexto;
        if (!m) { return; }
        m.elemento.remove();
        document.removeEventListener('mousedown', m.foraDele, true);
        document.removeEventListener('keydown', m.tecla, true);
        window.removeEventListener('scroll', m.rolagem, true);
        window.removeEventListener('resize', m.redimensionar);
        refs.menuContexto = null;
    }

    /** Aviso curto no chip do editor (o `chip(html)` do desenho, sem "Desfazer"). */
    function avisar(refs, texto) {
        var faixa = refs.quill.getSelection();
        mostrarChip(refs, faixa ? faixa.index : 0, textoDoChip('bi-magic', [{ texto: texto }]), [], 3600);
    }

    /** "Limpar formatação" igual ao botão da barra (handler `clean` do Quill). */
    function limparFormatacao(quill, faixa) {
        if (!faixa) { return; }
        if (faixa.length === 0) {
            var formatos = quill.getFormat();
            Object.keys(formatos).forEach(function (nome) {
                if (FORMATOS_DE_TRECHO.indexOf(nome) !== -1) { quill.format(nome, false, 'user'); }
            });

            return;
        }
        quill.removeFormat(faixa.index, faixa.length, 'user');
    }

    /** Recuo igual ao botão da barra (handler `indent` do Quill: relativo ao recuo atual). */
    function recuar(quill, passo) {
        var atual = parseInt(quill.getFormat().indent || 0, 10);
        quill.format('indent', atual + passo, 'user');
    }

    function copiarSelecao(refs, faixa, recortar) {
        var quill = refs.quill;
        if (!faixa || faixa.length === 0) { return; }
        var falhou = function () {
            avisar(refs, recortar
                ? 'Use Ctrl+X para recortar (o navegador bloqueou o acesso direto)'
                : 'Use Ctrl+C para copiar (o navegador bloqueou o acesso direto)');
        };
        var depois = function () {
            if (!recortar) { return; }
            quill.deleteText(faixa.index, faixa.length, 'user');
            quill.setSelection(faixa.index, 0, 'user');
        };
        var texto = quill.getText(faixa.index, faixa.length);
        var api = navigator.clipboard;

        if (api && api.write && typeof ClipboardItem !== 'undefined') {
            var html = quill.getSemanticHTML(faixa.index, faixa.length);
            api.write([new ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([texto], { type: 'text/plain' }),
            })]).then(depois, function () {
                // Sem permissão para HTML (alguns navegadores): tenta só o texto.
                (api.writeText ? api.writeText(texto) : Promise.reject(new Error('sem writeText'))).then(depois, falhou);
            });

            return;
        }
        if (api && api.writeText) {
            api.writeText(texto).then(depois, falhou);

            return;
        }
        // Sem Clipboard API: o comando do navegador dispara o copiar/recortar do próprio Quill.
        quill.setSelection(faixa.index, faixa.length, 'silent');
        var ok = false;
        try { ok = document.execCommand(recortar ? 'cut' : 'copy'); } catch (erro) { ok = false; }
        if (!ok) { falhou(); }
    }

    /**
     * Cola no lugar guardado. HTML vai pelo conversor da colagem do Quill (`clipboard.convert`):
     * o mesmo filtro de formatos do Ctrl+V. Texto puro herda a formatação do ponto.
     */
    function colarConteudo(refs, faixa, html, texto) {
        var quill = refs.quill;
        if (!quill) { return; }
        var f = faixa || { index: Math.max(quill.getLength() - 1, 0), length: 0 };
        quill.focus();
        if (!html) {
            if (texto) { inserirTexto(quill, f, texto); }

            return;
        }
        var Delta = Quill.import('delta');
        var conteudo = quill.clipboard.convert({ html: html, text: texto || '' });
        quill.updateContents(new Delta().retain(f.index).delete(f.length).concat(conteudo), 'user');
        quill.setSelection(Math.min(f.index + conteudo.length(), quill.getLength() - 1), 0, 'user');
    }

    function colarDaAreaDeTransferencia(refs, faixa, somenteTexto) {
        var api = navigator.clipboard;
        var bloqueado = function () { avisar(refs, 'Use Ctrl+V para colar (o navegador bloqueou o acesso direto)'); };
        if (!api || !api.readText) { bloqueado(); return; }

        var soTexto = function () {
            api.readText().then(function (t) { colarConteudo(refs, faixa, '', t); }, bloqueado);
        };
        if (somenteTexto || !api.read) { soTexto(); return; }

        // Imagem colada fica para o EDT-026: aqui só HTML e texto.
        api.read().then(function (itens) {
            return Promise.all(itens.map(function (item) {
                if (item.types.indexOf('text/html') !== -1) {
                    return item.getType('text/html').then(function (b) { return b.text(); }).then(function (h) { return { html: h }; });
                }
                if (item.types.indexOf('text/plain') !== -1) {
                    return item.getType('text/plain').then(function (b) { return b.text(); }).then(function (t) { return { texto: t }; });
                }

                return {};
            }));
        }).then(function (partes) {
            var html = partes.map(function (p) { return p.html || ''; }).join('');
            var texto = partes.map(function (p) { return p.texto || ''; }).join('');
            colarConteudo(refs, faixa, html, texto);
        }, soTexto);
    }

    function botaoDoMenu(iconeHtml, titulo, ativo, acao) {
        var b = semRoubarFoco(el('button', 'editor-rico-menu-botao' + (ativo ? ' editor-rico-ativo' : '')));
        b.type = 'button';
        b.title = titulo;
        b.setAttribute('aria-label', titulo);
        b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        b.innerHTML = iconeHtml; // só ícone constante deste arquivo — nunca dado
        b.addEventListener('click', acao);

        return b;
    }

    function itemDoMenu(iconeNome, rotulo, atalho, desabilitado, acao) {
        var b = semRoubarFoco(el('button', 'editor-rico-menu-item'));
        b.type = 'button';
        b.setAttribute('role', 'menuitem');
        b.innerHTML = icone(iconeNome);
        b.appendChild(el('span', '', rotulo));
        if (atalho) { b.appendChild(el('span', 'editor-rico-menu-atalho', atalho)); }
        if (desabilitado) { b.disabled = true; }
        b.addEventListener('click', acao);

        return b;
    }

    /** Ícone + barrinha da última cor (`.bjed-bar`); a cor é a variável CSS da paleta, por nome. */
    function iconeComCor(nome, variavel) {
        var caixa = el('span');
        caixa.innerHTML = icone(nome);
        var barra = el('span', 'editor-rico-menu-cor');
        barra.setAttribute('aria-hidden', 'true');
        barra.style.backgroundColor = 'var(' + variavel + ')';
        caixa.appendChild(barra);

        return caixa.innerHTML;
    }

    /** Nó e deslocamento do texto sob o ponto (padrão e WebKit). */
    function pontoDoTexto(x, y) {
        if (document.caretPositionFromPoint) {
            var p = document.caretPositionFromPoint(x, y);

            return p ? { no: p.offsetNode, deslocamento: p.offset } : null;
        }
        if (document.caretRangeFromPoint) {
            var r = document.caretRangeFromPoint(x, y);

            return r ? { no: r.startContainer, deslocamento: r.startOffset } : null;
        }

        return null;
    }

    /**
     * Botão direito FORA da seleção leva o cursor ao ponto do clique (`ctxMenu` do desenho,
     * `dentroSel`); dentro dela, a seleção fica. O índice sai do blot do Quill sob o ponto.
     */
    function moverCursorParaOClique(quill, x, y) {
        var atual = quill.getSelection();
        var nativa = window.getSelection();
        if (atual && atual.length > 0 && nativa && nativa.rangeCount) {
            var dentro = [].slice.call(nativa.getRangeAt(0).getClientRects()).some(function (q) {
                return x >= q.left && x <= q.right && y >= q.top && y <= q.bottom;
            });
            if (dentro) { return; }
        }

        var ponto = pontoDoTexto(x, y);
        if (!ponto || !ponto.no || !quill.root.contains(ponto.no)) { return; }
        var blot = Quill.find(ponto.no, true);
        if (!blot || typeof blot.offset !== 'function') { return; }

        var indice = blot.offset(quill.scroll) + (ponto.no.nodeType === 3 ? ponto.deslocamento : 0);
        quill.setSelection(Math.min(indice, Math.max(quill.getLength() - 1, 0)), 0, 'silent');
    }

    /**
     * Palavra sob o cursor (ou que contém a seleção), em índices de `getText()`: a seleção é
     * estendida às letras vizinhas. Seleção com mais de uma palavra não tem sinônimo — `null`.
     */
    function palavraDoMenu(quill, faixa) {
        if (!faixa) { return null; }
        var texto = quill.getText();
        var letra = /[\p{L}\p{M}]/u;
        var inicio = faixa.index;
        var fim = faixa.index + faixa.length;
        while (inicio > 0 && letra.test(texto.charAt(inicio - 1))) { inicio--; }
        while (fim < texto.length && letra.test(texto.charAt(fim))) { fim++; }
        var palavra = texto.slice(inicio, fim);

        return /^[\p{L}\p{M}]+$/u.test(palavra) ? { inicio: inicio, texto: palavra } : null;
    }

    /** Sinônimos do dicionário local para a palavra (busca sem acento e sem caixa). */
    function sinonimosDe(palavra) {
        var lista = Object.prototype.hasOwnProperty.call(SINONIMOS, dobrar(palavra)) ? SINONIMOS[dobrar(palavra)] : null;

        return Array.isArray(lista) ? lista : [];
    }

    /**
     * "Sugestões de correção" do menu (C6-EDT-05, bj-editor.js l.719-724), pelas regras do
     * desenho: a correção do dicionário (`AUTOCORRECAO`) ou a forma acentuada da palavra
     * conhecida; sem ela, para palavra DESCONHECIDA de 5+ letras, até 3 palavras do vocabulário
     * com a mesma inicial a 1 erro (2 a partir de 9 letras). Só o dicionário local — nada sai do
     * navegador. Já vêm na caixa da palavra.
     */
    function correcoesDoMenu(palavra) {
        var minuscula = palavra.toLowerCase();
        var dobrada = dobrar(palavra);
        if (Object.prototype.hasOwnProperty.call(AUTOCORRECAO, minuscula)) {
            return [mesmaCaixa(palavra, AUTOCORRECAO[minuscula])];
        }
        var conhecida = VOCABULARIO_DOBRADO.get(dobrada);
        if (conhecida !== undefined) {
            return conhecida !== minuscula && conhecida !== palavra ? [mesmaCaixa(palavra, conhecida)] : [];
        }
        if (palavra.length < 5) { return []; }
        var teto = dobrada.length >= 9 ? 2 : 1;
        var saida = [];
        VOCABULARIO_DOBRADO.forEach(function (v, vd) {
            if (saida.length < 3 && vd[0] === dobrada[0] && distancia(dobrada, vd, teto) <= teto) {
                saida.push(mesmaCaixa(palavra, v));
            }
        });

        return saida;
    }

    /** Troca a palavra pelo sinônimo, na caixa dela, num passo só do histórico. */
    function trocarPorSinonimo(quill, palavra, sinonimo) {
        var para = mesmaCaixa(palavra.texto, sinonimo);
        trocarTrechos(quill, [{ inicio: palavra.inicio, tamanho: palavra.texto.length, para: para }]);
        quill.setSelection(palavra.inicio + para.length, 0, 'user');
    }

    /**
     * "Inserir qualificação do cliente" (EDT-014): o texto já montado no servidor
     * (`QualificacaoDoCliente`, o mesmo do "Copiar qualificação") entra como TEXTO no cursor, com
     * um espaço depois, e o chip diz o que entrou — como o desenho. Sem cadastro, só o aviso.
     * A fonte é um elemento da página com `data-editor-qualificacao` (`data-texto`, `data-nome`,
     * `data-pendencias`).
     */
    function inserirQualificacao(refs, fonte) {
        var quill = refs.quill;
        var texto = fonte.getAttribute('data-texto') || '';
        var faixa = quill.getSelection();
        if (texto === '') {
            mostrarChip(refs, faixa ? faixa.index : 0, textoDoChip('bi-person-vcard', [
                { texto: 'Cliente sem cadastro. Abra a qualificação pelo botão ao lado do nome.' },
            ]), [], 3600);

            return;
        }
        inserirTexto(quill, faixa, texto + ' ');
        var pendencias = Number(fonte.getAttribute('data-pendencias') || 0);
        var primeiro = (fonte.getAttribute('data-nome') || '').trim().split(/\s+/)[0] || '';
        var aviso = pendencias > 0
            ? 'Qualificação inserida com ' + pendencias + ' pendência(s) no cadastro'
            : 'Qualificação de ' + primeiro + ' inserida';
        var depois = quill.getSelection();
        mostrarChip(refs, depois ? depois.index : 0, textoDoChip('bi-person-vcard', [{ texto: aviso }]), [], 3600);
    }

    function abrirMenuContexto(refs, x, y) {
        var quill = refs.quill;
        fecharMenuContexto(refs);
        fecharJanela(refs);
        fecharChip(refs);
        fecharSugestoes(refs);
        fecharOpcoesDeColar(refs);
        moverCursorParaOClique(quill, x, y);

        var faixa = quill.getSelection() || quill.getSelection(true);
        var formatos = faixa ? quill.getFormat(faixa) : {};
        var temSelecao = !!(faixa && faixa.length > 0);
        var cor = refs.ultimaCor || COR_INICIAL;
        var realce = refs.ultimoRealce || REALCE_INICIAL;

        // Toda ação fecha o menu e devolve o cursor/seleção de antes ao texto.
        function executar(acao) {
            return function () {
                fecharMenuContexto(refs);
                quill.focus();
                if (faixa) { quill.setSelection(faixa.index, faixa.length, 'silent'); }
                acao();
            };
        }

        var menu = el('div', 'editor-rico-menu');
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', 'Menu do editor');

        var barra = el('div', 'editor-rico-menu-barra');
        [
            [icone('bi-type-bold'), 'Negrito', !!formatos.bold, function () { quill.format('bold', !formatos.bold, 'user'); }],
            [icone('bi-type-italic'), 'Itálico', !!formatos.italic, function () { quill.format('italic', !formatos.italic, 'user'); }],
            [icone('bi-type-underline'), 'Sublinhado', !!formatos.underline, function () { quill.format('underline', !formatos.underline, 'user'); }],
            [icone('bi-type-strikethrough'), 'Tachado', !!formatos.strike, function () { quill.format('strike', !formatos.strike, 'user'); }],
            [iconeComCor('bi-highlighter', '--er-realce-' + realce), 'Realçar com a última cor', false, function () { quill.format('background', realce, 'user'); }],
            [iconeComCor('bi-fonts', '--er-cor-' + cor), 'Cor da fonte (última usada)', false, function () { quill.format('color', cor, 'user'); }],
            [icone('bi-list-ul'), 'Marcadores', formatos.list === 'bullet', function () { quill.format('list', formatos.list === 'bullet' ? false : 'bullet', 'user'); }],
            [icone('bi-list-ol'), 'Numeração', formatos.list === 'ordered', function () { quill.format('list', formatos.list === 'ordered' ? false : 'ordered', 'user'); }],
            [icone('bi-type-h2'), 'Título', formatos.header === 2, function () { quill.format('header', formatos.header === 2 ? false : 2, 'user'); }],
            [icone('bi-eraser'), 'Limpar formatação', false, function () { limparFormatacao(quill, quill.getSelection()); }],
        ].forEach(function (b) { barra.appendChild(botaoDoMenu(b[0], b[1], b[2], executar(b[3]))); });
        menu.appendChild(barra);

        // C6-EDT-05: as correções da palavra sob o cursor vêm antes de Recortar, em verde e
        // negrito, e trocam a palavra num passo só do histórico (a mesma troca dos sinônimos;
        // a correção já está na caixa da palavra, então reaplicar a caixa não a altera).
        var palavra = palavraDoMenu(quill, faixa);
        var correcoes = palavra ? correcoesDoMenu(palavra.texto) : [];
        if (correcoes.length) {
            menu.appendChild(cabecalhoDaJanela('Sugestões de correção'));
            correcoes.forEach(function (correcao) {
                var item = itemDoMenu('bi-spellcheck', correcao, '', false, executar(function () {
                    trocarPorSinonimo(quill, palavra, correcao);
                }));
                item.classList.add('editor-rico-menu-correcao');
                menu.appendChild(item);
            });
            menu.appendChild(el('div', 'editor-rico-menu-separador'));
        }

        menu.appendChild(itemDoMenu('bi-scissors', 'Recortar', 'Ctrl+X', !temSelecao, executar(function () { copiarSelecao(refs, faixa, true); })));
        menu.appendChild(itemDoMenu('bi-copy', 'Copiar', 'Ctrl+C', !temSelecao, executar(function () { copiarSelecao(refs, faixa, false); })));
        menu.appendChild(itemDoMenu('bi-clipboard', 'Colar', 'Ctrl+V', false, executar(function () { colarDaAreaDeTransferencia(refs, faixa, false); })));
        menu.appendChild(itemDoMenu('bi-clipboard-check', 'Colar somente texto', 'Ctrl+Shift+V', false, executar(function () { colarDaAreaDeTransferencia(refs, faixa, true); })));
        menu.appendChild(el('div', 'editor-rico-menu-separador'));

        menu.appendChild(cabecalhoDaJanela('Parágrafo'));
        var paragrafo = el('div', 'editor-rico-menu-barra editor-rico-menu-barra-paragrafo');
        [
            ['bi-text-left', 'À esquerda', !formatos.align, function () { quill.format('align', false, 'user'); }],
            ['bi-text-center', 'Centralizado', formatos.align === 'center', function () { quill.format('align', 'center', 'user'); }],
            ['bi-text-right', 'À direita', formatos.align === 'right', function () { quill.format('align', 'right', 'user'); }],
            ['bi-justify', 'Justificado', formatos.align === 'justify', function () { quill.format('align', 'justify', 'user'); }],
            ['bi-text-indent-right', 'Diminuir recuo', false, function () { recuar(quill, -1); }],
            ['bi-text-indent-left', 'Aumentar recuo', false, function () { recuar(quill, 1); }],
        ].forEach(function (b) { paragrafo.appendChild(botaoDoMenu(icone(b[0]), b[1], b[2], executar(b[3]))); });
        menu.appendChild(paragrafo);
        menu.appendChild(el('div', 'editor-rico-menu-separador'));

        var sinonimos = palavra ? sinonimosDe(palavra.texto) : [];
        if (sinonimos.length) {
            menu.appendChild(cabecalhoDaJanela('Sinônimos de "' + palavra.texto + '"'));
            sinonimos.forEach(function (sinonimo) {
                menu.appendChild(itemDoMenu('bi-arrow-left-right', sinonimo, '', false, executar(function () {
                    trocarPorSinonimo(quill, palavra, sinonimo);
                })));
            });
            menu.appendChild(el('div', 'editor-rico-menu-separador'));
        }

        // EDT-014 (bj-editor.js l.751, `__bjQualificacao`): só na página que traz a qualificação
        // do cliente — e ela só vem para quem pode ver o cliente.
        var qualificacao = document.querySelector('[data-editor-qualificacao]');
        if (qualificacao) {
            menu.appendChild(itemDoMenu('bi-person-vcard', 'Inserir qualificação do cliente', '', false, executar(function () {
                inserirQualificacao(refs, qualificacao);
            })));
        }

        menu.appendChild(itemDoMenu('bi-search', 'Localizar e substituir', 'Ctrl+H', false, executar(function () { refs.busca.abrir(); })));
        menu.appendChild(itemDoMenu('bi-spellcheck', 'Revisar texto', '', false, executar(function () {
            abrirJanela(refs, refs.botaoRevisar, conteudoDaRevisao(refs));
        })));
        menu.appendChild(itemDoMenu('bi-check2-all', 'Selecionar tudo', 'Ctrl+A', false, function () {
            fecharMenuContexto(refs);
            quill.focus();
            quill.setSelection(0, Math.max(quill.getLength() - 1, 0), 'user');
        }));

        refs.casca.appendChild(menu);
        var largura = menu.offsetWidth;
        var altura = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - largura - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - altura - 8)) + 'px';

        var estado = {
            elemento: menu,
            foraDele: function (e) { if (!menu.contains(e.target)) { fecharMenuContexto(refs); } },
            // Esc fecha o menu (e só ele: um modal em volta continua aberto).
            tecla: function (e) {
                if (e.key !== 'Escape') { return; }
                e.preventDefault();
                e.stopPropagation();
                fecharMenuContexto(refs);
                quill.focus();
            },
            rolagem: function (e) { if (!menu.contains(e.target)) { fecharMenuContexto(refs); } },
            redimensionar: function () { fecharMenuContexto(refs); },
        };
        document.addEventListener('mousedown', estado.foraDele, true);
        document.addEventListener('keydown', estado.tecla, true);
        window.addEventListener('scroll', estado.rolagem, true);
        window.addEventListener('resize', estado.redimensionar);
        refs.menuContexto = estado;
    }

    /**
     * Liga o menu ao editor. Só abre para o botão direito do MOUSE (lembrado no `pointerdown` logo
     * antes): o `contextmenu` do teclado e do toque não tem esse `pointerdown` e fica com o menu
     * do navegador. No Mac, Ctrl+clique é o botão direito.
     */
    function ligarMenuContexto(refs) {
        var raiz = refs.quill.root;
        raiz.addEventListener('pointerdown', function (e) {
            var direito = e.button === 2 || (e.button === 0 && e.ctrlKey);
            refs.botaoDireitoEm = e.pointerType === 'mouse' && direito ? Date.now() : 0;
        });
        raiz.addEventListener('contextmenu', function (e) {
            var doMouse = refs.botaoDireitoEm && Date.now() - refs.botaoDireitoEm < 1500;
            refs.botaoDireitoEm = 0;
            if (e.shiftKey || !doMouse) { return; } // Shift + botão direito: menu do navegador
            e.preventDefault();
            abrirMenuContexto(refs, e.clientX, e.clientY);
        });
    }

    // ── Colar com opções (`onPaste` + `opcoesColar` do desenho, bj-editor.js l.763-780, 948-957) ─
    //
    // Depois de colar HTML (de outro programa, de um e-mail, da web), aparece por 7 s a faixa
    // "Colar · Manter formatação · Mesclar formatação · Somente texto". A colagem em si continua a
    // do Quill: o HTML passa pelo conversor dele (`clipboard.convert`), que só deixa os FORMATOS do
    // editor — é a sanitização do cliente, a mesma de sempre; o servidor sanitiza de novo ao salvar.
    // As opções só reaproveitam o que já entrou, sem ler nem escrever HTML:
    //  - Manter formatação  → fica como colou;
    //  - Mesclar formatação → tira do trecho colado a cor e o realce (o "style" que o desenho
    //    remove), mantendo ênfase, listas, títulos e alinhamento;
    //  - Somente texto      → troca o trecho colado pelo texto puro da área de transferência, com
    //    a formatação do ponto em que se colou.
    // Texto puro colado não mostra a faixa (o desenho também não). Imagem colada é o EDT-026.

    var DURACAO_OPCOES_DE_COLAR = 7000;

    function fecharOpcoesDeColar(refs) {
        var c = refs.colagem;
        if (!c) { return; }
        clearTimeout(c.temporizador);
        c.elemento.remove();
        refs.colagem = null;
    }

    /** Troca o trecho colado num passo só do histórico (um "desfazer" volta a troca inteira). */
    function aplicarOpcaoDeColar(refs, opcao) {
        var quill = refs.quill;
        var c = refs.colagem;
        if (!quill || !c) { return; }
        var faixa = c.faixa;
        fecharOpcoesDeColar(refs);
        if (opcao === 'manter') { quill.focus(); return; }

        if (quill.history) { quill.history.cutoff(); }
        if (opcao === 'mesclar') {
            quill.formatText(faixa.index, faixa.length, { color: false, background: false }, 'user');
        } else {
            var Delta = Quill.import('delta');
            var troca = new Delta().retain(faixa.index).delete(faixa.length);
            if (c.texto !== '') { troca.insert(c.texto, c.formatos); }
            quill.updateContents(troca, 'user');
        }
        if (quill.history) { quill.history.cutoff(); }

        var fim = opcao === 'mesclar' ? faixa.index + faixa.length : faixa.index + c.texto.length;
        quill.focus();
        quill.setSelection(Math.min(fim, quill.getLength() - 1), 0, 'user');
    }

    function mostrarOpcoesDeColar(refs, faixa, texto, formatos) {
        var quill = refs.quill;
        fecharOpcoesDeColar(refs);

        var faixaDeOpcoes = el('div', 'editor-rico-colar');
        faixaDeOpcoes.setAttribute('role', 'toolbar');
        faixaDeOpcoes.setAttribute('aria-label', 'Opções de colagem');
        var rotulo = el('span', 'editor-rico-colar-rotulo');
        rotulo.innerHTML = icone('bi-clipboard editor-rico-colar-icone');
        rotulo.appendChild(document.createTextNode('Colar'));
        faixaDeOpcoes.appendChild(rotulo);

        [['manter', 'Manter formatação'], ['mesclar', 'Mesclar formatação'], ['texto', 'Somente texto']].forEach(function (o) {
            var b = semRoubarFoco(el('button', '', o[1]));
            b.type = 'button';
            b.addEventListener('click', function () { aplicarOpcaoDeColar(refs, o[0]); });
            faixaDeOpcoes.appendChild(b);
        });
        refs.casca.appendChild(faixaDeOpcoes);

        // Logo abaixo do fim do trecho colado (fixo na tela, como o `.bjed-paste`).
        var caixa = quill.container.getBoundingClientRect();
        var b = quill.getBounds(faixa.index + faixa.length, 0) || { left: 0, bottom: 0 };
        var esquerda = caixa.left + b.left;
        var base = caixa.top + b.bottom;
        faixaDeOpcoes.style.left = Math.max(8, Math.min(esquerda, window.innerWidth - faixaDeOpcoes.offsetWidth - 8)) + 'px';
        faixaDeOpcoes.style.top = Math.min(window.innerHeight - 40, base + 8) + 'px';

        refs.colagem = {
            elemento: faixaDeOpcoes,
            faixa: faixa,
            texto: texto,
            formatos: formatos,
            temporizador: setTimeout(function () { fecharOpcoesDeColar(refs); }, DURACAO_OPCOES_DE_COLAR),
        };
    }

    /**
     * Ouvido em CAPTURA na casca: roda antes da colagem do Quill (que é quem converte e insere).
     * Guarda onde se colou e, terminada a colagem, mede o trecho que entrou — já aparado pelo
     * limite de caracteres, se for o caso.
     */
    function aoColar(refs, evento) {
        var quill = refs.quill;
        if (!quill || !quill.root.contains(evento.target)) { return; } // campos da busca: colagem comum
        var dados = evento.clipboardData;
        var html = dados ? dados.getData('text/html') : '';
        fecharOpcoesDeColar(refs);
        if (!html) { return; }

        var faixa = quill.getSelection(true);
        if (!faixa) { return; }
        var texto = dados.getData('text/plain') || '';
        var formatos = formatosDoTrecho(quill, faixa.index, 0);
        var tamanhoAntes = quill.getLength() - faixa.length;

        setTimeout(function () {
            if (!refs.quill) { return; }
            var colado = quill.getLength() - tamanhoAntes;
            if (colado <= 0) { return; }
            mostrarOpcoesDeColar(refs, { index: faixa.index, length: colado }, texto, formatos);
        }, 0);
    }

    // ── Sugestão de palavras ao digitar (`sugerir`/`onKey` do desenho, bj-editor.js l.640-680) ──
    //
    // A partir de 3 letras, até 4 palavras do vocabulário que começam pelo que foi digitado (sem
    // acento e sem caixa), a parte digitada marcada. Tab aceita a escolhida (a 1ª, de início);
    // ↑/↓ navegam e, depois de navegar, Enter também aceita; Esc fecha. Separador, colagem ou
    // qualquer mudança que não seja digitar/apagar uma letra fecha a lista.
    //
    // O vocabulário é o do desenho (`VOCAB` = palavras do `AC` + `EXTRA`), o mesmo `VOCABULARIO`
    // da autocorreção: um dicionário jurídico ESTÁTICO, sem nenhum dado de cliente ou de processo.
    //
    // Com a lista de @menção aberta (pasta-mencoes.js), ela tem prioridade sobre Tab, Enter e as
    // setas: ouve em captura no contêiner (antes daqui) e, além disso, esta lista se recolhe quando
    // vê a outra aberta. Palavra logo depois de "@" é o termo da menção: não ganha sugestão.

    var MAX_SUGESTOES = 4;
    var MIN_LETRAS_SUGESTAO = 3;

    function mencaoAberta(quill) {
        return !!(window.PastaMencoes && typeof window.PastaMencoes.listaAberta === 'function'
            && window.PastaMencoes.listaAberta(quill));
    }

    /** O delta é "digitou UMA letra" ou "apagou UM caractere"? (`onInput` do desenho). */
    function digitouOuApagouLetra(delta) {
        var ops = delta.ops || [];
        var i = ops[0] && typeof ops[0].retain === 'number' && !ops[0].attributes ? 1 : 0;
        if (ops.length !== i + 1) { return false; }
        var op = ops[i];

        return (typeof op.insert === 'string' && /^[\p{L}\p{M}]$/u.test(op.insert)) || op.delete === 1;
    }

    /** A palavra que termina no cursor (cursor sem seleção, no fim da palavra). */
    function palavraNoCursor(quill) {
        var faixa = quill.getSelection();
        if (!faixa || faixa.length > 0) { return null; }
        var texto = quill.getText();
        var inicioLinha = texto.lastIndexOf('\n', faixa.index - 1) + 1;
        var m = texto.slice(inicioLinha, faixa.index).match(/[\p{L}\p{M}]+$/u);
        if (!m) { return null; }
        if (/[\p{L}\p{M}]/u.test(texto.charAt(faixa.index))) { return null; } // no meio da palavra
        var inicio = faixa.index - m[0].length;
        if (texto.charAt(inicio - 1) === '@') { return null; } // termo da @menção

        return { inicio: inicio, palavra: m[0] };
    }

    function opcoesDeSugestao(palavra) {
        var dobrada = dobrar(palavra);

        return VOCABULARIO.filter(function (v) {
            var vd = dobrar(v);

            return vd.indexOf(dobrada) === 0 && vd !== dobrada;
        }).slice(0, MAX_SUGESTOES);
    }

    function fecharSugestoes(refs) {
        var s = refs.sugestao;
        if (!s) { return; }
        s.elemento.remove();
        window.removeEventListener('scroll', s.rolagem, true);
        refs.sugestao = null;
    }

    function marcarSugestao(refs) {
        var s = refs.sugestao;
        [].forEach.call(s.elemento.children, function (b, i) {
            b.classList.toggle('editor-rico-ativo', i === s.i);
            b.setAttribute('aria-selected', i === s.i ? 'true' : 'false');
        });
    }

    function aceitarSugestao(refs, i) {
        var quill = refs.quill;
        var s = refs.sugestao;
        var opcao = s && s.opcoes[i];
        fecharSugestoes(refs);
        var w = palavraNoCursor(quill);
        if (!w || !opcao) { return; }
        var para = mesmaCaixa(w.palavra, opcao);
        quill.focus();
        trocarTrechos(quill, [{ inicio: w.inicio, tamanho: w.palavra.length, para: para }]);
        quill.setSelection(w.inicio + para.length, 0, 'user');
    }

    function sugerir(refs) {
        var quill = refs.quill;
        if (!quill || mencaoAberta(quill)) { fecharSugestoes(refs); return; }
        var w = palavraNoCursor(quill);
        if (!w || w.palavra.length < MIN_LETRAS_SUGESTAO) { fecharSugestoes(refs); return; }
        var opcoes = opcoesDeSugestao(w.palavra);
        if (opcoes.length === 0) { fecharSugestoes(refs); return; }

        fecharSugestoes(refs);
        var lista = el('div', 'editor-rico-sugestoes');
        lista.setAttribute('role', 'listbox');
        lista.setAttribute('aria-label', 'Sugestões de palavras');
        opcoes.forEach(function (o, i) {
            var palavra = mesmaCaixa(w.palavra, o);
            var b = semRoubarFoco(el('button', 'editor-rico-sugestao'));
            b.type = 'button';
            b.setAttribute('role', 'option');
            var corpo = el('span');
            corpo.appendChild(el('mark', '', palavra.slice(0, w.palavra.length)));
            corpo.appendChild(document.createTextNode(palavra.slice(w.palavra.length)));
            b.appendChild(corpo);
            if (i === 0) { b.appendChild(el('span', 'editor-rico-menu-atalho', 'Tab')); }
            b.addEventListener('click', function () { aceitarSugestao(refs, i); });
            lista.appendChild(b);
        });
        refs.casca.appendChild(lista);

        // Fixa na tela, logo abaixo do cursor (acima, se não couber).
        var caixa = quill.container.getBoundingClientRect();
        var limites = quill.getBounds(w.inicio + w.palavra.length, 0) || { left: 0, top: 0, bottom: 0 };
        var esquerda = caixa.left + limites.left;
        var topo = caixa.top + limites.top;
        var base = caixa.top + limites.bottom;
        lista.style.left = Math.max(8, Math.min(esquerda, window.innerWidth - lista.offsetWidth - 8)) + 'px';
        lista.style.top = (base + lista.offsetHeight + 8 > window.innerHeight ? topo - lista.offsetHeight - 6 : base + 6) + 'px';

        var rolagem = function (e) { if (!lista.contains(e.target)) { fecharSugestoes(refs); } };
        window.addEventListener('scroll', rolagem, true);
        refs.sugestao = { elemento: lista, opcoes: opcoes, i: 0, nav: false, rolagem: rolagem };
        marcarSugestao(refs);
    }

    /**
     * Teclas da lista. Ouvido em CAPTURA na raiz do editor: roda antes do teclado do Quill (Tab
     * não insere tabulação nem recua a lista com a sugestão aberta) e DEPOIS do da @menção, que
     * ouve no contêiner e para a propagação quando a lista dela está aberta.
     */
    function teclaDaSugestao(refs, e) {
        var s = refs.sugestao;
        if (!s) { return; }
        // A lista de @menção aberta tem prioridade: esta se recolhe e deixa a tecla para ela.
        if (mencaoAberta(refs.quill)) { fecharSugestoes(refs); return; }
        var ctrl = e.ctrlKey || e.metaKey;

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            e.stopImmediatePropagation();
            s.nav = true;
            s.i = (s.i + (e.key === 'ArrowDown' ? 1 : -1) + s.opcoes.length) % s.opcoes.length;
            marcarSugestao(refs);
        } else if (e.key === 'Tab' || (e.key === 'Enter' && s.nav && !ctrl)) {
            e.preventDefault();
            e.stopImmediatePropagation();
            aceitarSugestao(refs, s.i);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            fecharSugestoes(refs);
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

    // ── Citar no novo registro (desenho: evento `bj-editor-inserir` com
    //    `<blockquote>…</blockquote><p><br></p>`, e o aviso "Citação inserida no editor") ──────
    //
    // O trecho citado NÃO entra como HTML no editor: passa pelo MESMO conversor da colagem
    // (`clipboard.convert`), que só deixa sobreviver os FORMATOS do editor. Cada linha vira
    // citação (formato de linha `blockquote`, que o sanitizador do servidor aceita) e o cursor
    // fica numa linha normal logo abaixo, para a pessoa escrever. Entra pelo caminho do usuário
    // ('user'): o limite de caracteres vale para a citação como vale para a digitação.

    function deltaDaCitacao(quill, html) {
        var Delta = Quill.import('delta');
        var citado = quill.clipboard.convert({ html: String(html || ''), text: '' });
        var saida = new Delta();
        var temTexto = false;
        (citado.ops || []).forEach(function (op) {
            if (typeof op.insert !== 'string') { return; }
            var trecho = {};
            FORMATOS_DE_TRECHO.forEach(function (f) {
                if (op.attributes && op.attributes[f] !== undefined) { trecho[f] = op.attributes[f]; }
            });
            op.insert.split(/(\n)/).forEach(function (parte) {
                if (parte === '') { return; }
                if (parte === '\n') { saida.insert('\n', { blockquote: true }); return; }
                if (parte.trim() !== '') { temTexto = true; }
                saida.insert(parte, trecho);
            });
        });
        if (!temTexto) { return null; }

        // A última linha também é citação (o conversor tira o "\n" final).
        var ultimo = saida.ops[saida.ops.length - 1];
        if (typeof ultimo.insert !== 'string' || ultimo.insert !== '\n') { saida.insert('\n', { blockquote: true }); }

        return saida;
    }

    function inserirCitacao(textarea, html) {
        var refs = textarea && textarea._editorRico;
        var quill = refs && refs.quill;
        if (!quill) { return false; }

        var citacao = deltaDaCitacao(quill, html);
        if (!citacao) { return false; }

        // No cursor, se ele estiver no editor; senão, no fim (como o desenho).
        var faixa = quill.hasFocus() ? quill.getSelection() : null;
        var indice = faixa ? faixa.index : Math.max(quill.getLength() - 1, 0);
        var Delta = Quill.import('delta');
        var mudanca = new Delta().retain(indice);
        if (faixa && faixa.length) { mudanca.delete(faixa.length); }
        // Começa numa linha própria: no meio de uma linha, quebra antes.
        var antes = indice > 0 ? quill.getText(indice - 1, 1) : '\n';
        var quebra = antes !== '\n' ? 1 : 0;
        if (quebra) { mudanca.insert('\n'); }
        mudanca = mudanca.concat(citacao);
        // A linha normal vazia depois da citação: no fim do texto ela já existe (o "\n" final).
        var noFim = indice + (faixa ? faixa.length : 0) >= quill.getLength() - 1;
        if (!noFim) { mudanca.insert('\n'); }

        fecharJanela(refs);
        fecharChip(refs);
        if (quill.history) { quill.history.cutoff(); }
        quill.updateContents(mudanca, 'user');
        if (quill.history) { quill.history.cutoff(); }

        var cursor = Math.min(indice + quebra + citacao.length(), quill.getLength() - 1);
        quill.focus();
        quill.setSelection(cursor, 0, 'user');
        refs.casca.scrollIntoView({ block: 'center', behavior: 'smooth' });
        mostrarChip(refs, cursor, textoDoChip('bi-quote', [{ texto: 'Citação inserida no editor' }]), [], 3600);

        return true;
    }

    // ── Botão Enviar do compositor (`mudou` do desenho: `this.env.disabled = v`) ─────────────
    //
    // O compositor aponta o seu botão por `data-editor-rico-envio="<id>"` na textarea. O botão
    // fica desabilitado enquanto o editor estiver vazio — a regra é a de `vazio()`: só espaço, ou o
    // `<p><br></p>` que o Quill deixa, conta como vazio — e enquanto um envio estiver em curso
    // (`marcarEnvio`), senão digitar durante o envio reabilitaria o botão e permitiria envio em
    // dobro. O id é procurado a cada atualização: tanto faz a tela marcar a textarea antes ou
    // depois da montagem. Sem editor montado (Quill ausente), só o envio em curso desabilita — a
    // textarea crua segue como antes e o servidor continua recusando o vazio.

    function botaoDeEnvio(textarea) {
        var id = textarea.getAttribute('data-editor-rico-envio');

        return id ? document.getElementById(id) : null;
    }

    function atualizarEnvio(textarea) {
        var botao = botaoDeEnvio(textarea);
        if (!botao) { return; }
        var quill = instancia(textarea);
        botao.disabled = textarea._editorRicoEnviando === true || (quill ? vazio(quill) : false);
    }

    function marcarEnvio(textarea, enviando) {
        if (!textarea) { return; }
        textarea._editorRicoEnviando = !!enviando;
        atualizarEnvio(textarea);
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
                        header: function (valor) { aplicarEstilo(this.quill, valor); },
                        localizar: function () { fecharJanela(refs); refs.busca.alternar(); },
                        simbolo: function () { abrirSimbolos(refs); },
                        data: function () { inserirDataDeHoje(refs); },
                        revisar: function () { abrirRevisao(refs); },
                        // A cor/realce escolhidos ficam lembrados para a mini barra do botão direito.
                        color: function (valor) {
                            if (valor) { refs.ultimaCor = valor; }
                            this.quill.format('color', valor, 'user');
                        },
                        background: function (valor) {
                            if (valor) { refs.ultimoRealce = valor; }
                            this.quill.format('background', valor, 'user');
                        },
                    },
                },
                keyboard: {
                    bindings: {
                        localizar: {
                            key: 'h',
                            shortKey: true,
                            handler: function () { refs.busca.abrir(); return false; },
                        },
                        // EDT-019: a dica da barra promete Ctrl+Y, mas o Quill só liga esse atalho
                        // no Windows. Aqui vale em todo sistema (Cmd+Y no macOS), como no desenho.
                        refazer: {
                            key: 'y',
                            shortKey: true,
                            handler: function () { this.quill.history.redo(); return false; },
                        },
                        // EDT-020: substitui o `tab` do Quill, que insere "\t" (some na exibição,
                        // porque o espaço em branco colapsa). Fora de lista: 4 espaços não
                        // separáveis, como o desenho; dentro de lista: aumenta o recuo.
                        tab: {
                            key: 'Tab',
                            handler: function (faixa, contexto) { return tabulacao(this.quill, faixa, contexto); },
                        },
                        // EDT-016: substitui o `list autofill` do Quill (mesmo nome). Soma "1)" e
                        // "> " ao que ele já convertia ("1.", "-", "*") e avisa com o chip do desenho.
                        'list autofill': {
                            key: ' ',
                            shiftKey: null,
                            collapsed: true,
                            prefix: PREFIXO_AUTOFORMATACAO,
                            handler: function (faixa, contexto) { return autoFormatar(refs, this, faixa, contexto); },
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

        // Citação no seletor de estilo (EDT-018). Registrado DEPOIS da barra e do tema do Quill:
        // roda após eles terem marcado o seletor pelo `header`, e só corrige o caso da citação.
        refs.seletorEstilo = ((quill.theme && quill.theme.pickers) || []).find(function (p) {
            return p.select && p.select.classList.contains('ql-header');
        }) || null;
        quill.on('editor-change', function () { marcarCitacaoNoEstilo(refs); });

        refs.busca = montarBusca(refs);
        casca.insertBefore(refs.busca.elemento, alvo);

        refs.rodape = montarRodape();
        casca.appendChild(refs.rodape.elemento);

        refs.ultimaCor = COR_INICIAL;
        refs.ultimoRealce = REALCE_INICIAL;
        ligarMenuContexto(refs);
        // Captura: chega antes da colagem do Quill, que segue sendo quem converte e insere.
        casca.addEventListener('paste', function (evento) { aoColar(refs, evento); }, true);
        // Sugestão de palavras: teclas antes do Quill; sair do editor fecha (como o `blur` do desenho).
        quill.root.addEventListener('keydown', function (evento) { teclaDaSugestao(refs, evento); }, true);
        quill.on('selection-change', function (faixa) {
            if (!faixa) { setTimeout(function () { fecharSugestoes(refs); }, 120); }
        });

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
            atualizarEnvio(textarea);
            refs.busca.aoMudarTexto();
            // A faixa de opções vale para a colagem que acabou de entrar: mexeu depois, ela sai.
            fecharOpcoesDeColar(refs);

            // Sugestão de palavras: só ao digitar ou apagar uma letra; o resto fecha a lista.
            if (origem === 'user' && !cortou && digitouOuApagouLetra(delta)) {
                setTimeout(function () { if (refs.quill) { sugerir(refs); } }, 0);
            } else {
                fecharSugestoes(refs);
            }

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
            if (evento.key === 'Escape' && (refs.janela || refs.chip || refs.colagem)) {
                evento.stopPropagation();
                fecharJanela(refs);
                fecharChip(refs);
                fecharOpcoesDeColar(refs);
            }
        });

        // Ctrl+Enter continua enviando o formulário (comportamento que estas telas já tinham).
        quill.root.addEventListener('keydown', function (evento) {
            if ((evento.ctrlKey || evento.metaKey) && evento.key === 'Enter') {
                evento.preventDefault();
                // Enviar apagado (vazio ou envio em curso): o atalho também não envia.
                var envio = botaoDeEnvio(textarea);
                if (envio && envio.disabled) { return; }
                var formulario = textarea.closest('form');
                if (formulario) { formulario.requestSubmit(); }
            }
        });

        textarea.dataset.editorRicoMontado = '1';
        textarea._editorRico = refs;
        atualizarEnvio(textarea);

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
        atualizarEnvio(textarea);
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
        fecharMenuContexto(refs);
        fecharOpcoesDeColar(refs);
        fecharSugestoes(refs);
        refs.casca.remove();
        refs.quill = null;
        textarea.classList.remove('editor-rico-oculta');
        textarea.removeAttribute('aria-hidden');
        textarea.removeAttribute('tabindex');
        delete textarea.dataset.editorRicoMontado;
        delete textarea._editorRico;
        atualizarEnvio(textarea);
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
        marcarEnvio: marcarEnvio,
        inserirCitacao: inserirCitacao,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { montarTodos(); });
    } else {
        montarTodos();
    }
})();
