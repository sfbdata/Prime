/**
 * Revisão de texto comum aos editores do sistema (parte PURA, sem DOM nem editor).
 *
 * Saiu do editor da pasta (`editor-rico.js`, Quill) para servir também ao editor de artigos dos
 * blogs (`master/blog-editor.js`, TinyMCE), sem duplicar dicionário nem regra. Aqui ficam só os
 * dados e as funções que trabalham sobre TEXTO: dicionários (autocorreção e vocabulário do
 * desenho), símbolos, `dobrar`, a correção e a sugestão de uma palavra e o motor da revisão
 * (`problemasDoTexto` e os `trechos*`). Aplicar a troca no texto é de cada editor.
 *
 * Cada problema de `problemasDoTexto(texto)` é `{tipo, de, para, trechos(texto)}`; `trechos`
 * devolve `[{inicio, tamanho, para}]` com índices do texto recebido. Quebra de parágrafo entra no
 * texto como "\n" (palavra repetida não junta fim e começo de parágrafos).
 *
 * Carregar ANTES do editor que o usa: expõe `window.RevisaoDeTexto`.
 */
(function () {
    'use strict';

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

    window.RevisaoDeTexto = {
        SIMBOLOS: SIMBOLOS,
        AUTOCORRECAO: AUTOCORRECAO,
        VOCABULARIO_EXTRA: VOCABULARIO_EXTRA,
        VOCABULARIO: VOCABULARIO,
        VOCABULARIO_DOBRADO: VOCABULARIO_DOBRADO,
        LETRA: LETRA,
        dobrar: dobrar,
        mesmaCaixa: mesmaCaixa,
        distancia: distancia,
        palavraProxima: palavraProxima,
        correcaoDe: correcaoDe,
        sugestaoDe: sugestaoDe,
        escaparRegex: escaparRegex,
        problemasDoTexto: problemasDoTexto,
        trechosDaPalavra: trechosDaPalavra,
        trechosRepetidos: trechosRepetidos,
        trechosDeEspacoDuplo: trechosDeEspacoDuplo,
    };
}());
