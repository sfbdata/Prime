<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Catálogo FIXO de documentos esperados por fase do processo e por tipo de ação.
 *
 * Transcrição de `docs/design/claude-design-2026-10-05 (1)/bj-docsug.js` (o "Sugerir documentos"
 * do desenho 02 - EXPEDIENTES 1.2.3). O desenho chama isso de inteligência; não é: é tabela +
 * regra determinística. Nada aqui lê o processo, nada aqui chama modelo de linguagem — e a tela
 * diz isso.
 *
 * As expressões regulares são as do JS, copiadas sem mudança de sentido, e são aplicadas sobre o
 * texto JÁ normalizado (minúsculas, sem acento — ver `SugestorDeDocumentos::normalizar`). Por isso
 * as classes `[cç]`, `[aã]` do original são redundantes aqui; ficaram para a transcrição ser
 * conferível linha a linha contra o JS.
 */
final class CatalogoDeDocumentos
{
    /** Classe padrão do item na fase (bj-docsug.js L39: nunca "exigido pelo juízo"). */
    public const OBRIGATORIO = 'req';
    public const RECOMENDAVEL = 'rec';
    public const OPCIONAL = 'opc';

    /**
     * Tipos de documento: chave => [nome exibido, padrão do nome do arquivo].
     * bj-docsug.js L12-38 (`const CAT`).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TIPOS = [
        'procuracao'  => ['Procuração', '/procura[cç][aã]o|substabelec/u'],                                                   // L13
        'identidade'  => ['Documento de identidade', '/\b(rg|cnh|identidade|cpf)\b|documento pessoal/u'],                   // L14
        'residencia'  => ['Comprovante de residência', '/comprovante de (residencia|endereco)|conta de (luz|agua)/u'],       // L15
        'hipossuf'    => ['Declaração de hipossuficiência', '/hipossufic|declara[cç][aã]o de pobreza|gratuidade/u'],          // L16
        'honorarios'  => ['Contrato de honorários', '/contrato de honorario|honorarios advocaticios/u'],                     // L17
        'inicial'     => ['Petição inicial', '/peti[cç][aã]o inicial|^inicial|exordial/u'],                                  // L18
        'contestacao' => ['Contestação', '/contesta[cç][aã]o/u'],                                                            // L19
        'replica'     => ['Réplica', '/r[eé]plica|impugna[cç][aã]o [aà] contesta/u'],                                        // L20
        'provasFato'  => ['Provas do fato (fotos, vídeos, laudos)', '/\bfoto|video|laudo|print|whatsapp|ata notarial/u'],    // L21
        'notificacao' => ['Notificação extrajudicial', '/notifica[cç][aã]o extrajudicial|notificacao/u'],                    // L22
        'contrato'    => ['Contrato discutido', '/\bcontrato(?![ _-]*(de[ _-]*)?honorario)/u'],                              // L23
        'rolTest'     => ['Rol de testemunhas', '/rol de testemunh/u'],                                                      // L24
        'quesitos'    => ['Quesitos e assistente técnico', '/quesito|assistente tecnico/u'],                                 // L25
        'especProvas' => ['Petição de especificação de provas', '/especifica[cç][aã]o de provas/u'],                         // L26
        'sentenca'    => ['Sentença', '/senten[cç]a/u'],                                                                     // L27
        'acordao'     => ['Acórdão', '/ac[oó]rd[aã]o/u'],                                                                    // L28
        'transito'    => ['Certidão de trânsito em julgado', '/tr[aâ]nsito em julgado/u'],                                   // L29
        'calculo'     => ['Memória de cálculo atualizada', '/c[aá]lculo|planilha|memoria discriminada|atualiza[cç][aã]o do debito/u'], // L30
        'cumprimento' => ['Petição de cumprimento de sentença', '/cumprimento de senten/u'],                                 // L31
        'pagamento'   => ['Comprovante de pagamento / depósito judicial', '/comprovante de (pagamento|deposito)|guia de deposito|deposito judicial|alvara/u'], // L32
        'razoes'      => ['Razões do recurso', '/apela[cç][aã]o|raz[oõ]es|agravo|recurso/u'],                                // L33
        'preparo'     => ['Guia e comprovante de preparo', '/preparo|custas recursais|guia de custas/u'],                    // L34
        'relMedico'   => ['Relatório médico atualizado', '/relatorio medico|laudo medico|prescri[cç][aã]o/u'],                // L35
        'negativa'    => ['Negativa administrativa', '/negativa|indeferimento administrativo|recusa/u'],                     // L36
        'matricula'   => ['Certidão de matrícula do imóvel', '/matricula/u'],                                                // L37
    ];

    /**
     * Documentos esperados em cada fase: fase => lista de [chave, classe padrão, porquê].
     * bj-docsug.js L40-50 (`const FASE`).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public const FASES = [
        'inicial' => [ // L41
            ['procuracao', self::OBRIGATORIO, 'CPC art. 104: sem procuração o advogado não postula'],
            ['identidade', self::RECOMENDAVEL, 'qualificação da parte (CPC art. 319, II)'],
            ['residencia', self::RECOMENDAVEL, 'competência e qualificação'],
            ['inicial', self::RECOMENDAVEL, 'peça que inaugura a demanda'],
            ['provasFato', self::RECOMENDAVEL, 'documentos indispensáveis à propositura (CPC art. 320)'],
            ['hipossuf', self::OPCIONAL, 'só se houver pedido de justiça gratuita'],
            ['honorarios', self::OPCIONAL, 'documento interno do escritório'],
            ['notificacao', self::OPCIONAL, 'mostra tentativa prévia de solução'],
        ],
        'contestacao' => [ // L42
            ['procuracao', self::OBRIGATORIO, 'CPC art. 104'],
            ['contestacao', self::RECOMENDAVEL, 'defesa dentro de 15 dias úteis (CPC art. 335)'],
            ['provasFato', self::RECOMENDAVEL, 'documentos que contrariem os fatos (CPC art. 434)'],
            ['contrato', self::OPCIONAL, 'se a defesa se apoia em contrato'],
        ],
        'replica' => [ // L43
            ['replica', self::RECOMENDAVEL, 'manifestação sobre a contestação (CPC arts. 350 e 351)'],
            ['provasFato', self::OPCIONAL, 'só documentos novos ou para contrapor os da defesa (CPC art. 435)'],
        ],
        'provas' => [ // L44
            ['especProvas', self::RECOMENDAVEL, 'especificação das provas pretendidas'],
            ['rolTest', self::OPCIONAL, 'se houver prova oral (CPC art. 357, § 4º)'],
            ['quesitos', self::OPCIONAL, 'se houver perícia (CPC art. 465, § 1º)'],
        ],
        'audiencia' => [ // L45
            ['rolTest', self::RECOMENDAVEL, 'rol e intimação das testemunhas (CPC art. 455)'],
            ['procuracao', self::OBRIGATORIO, 'poderes para transigir, se houver acordo'],
        ],
        'pericia' => [ // L46
            ['quesitos', self::RECOMENDAVEL, 'quesitos e assistente técnico (CPC art. 465, § 1º)'],
        ],
        'sentenca' => [ // L47
            ['sentenca', self::RECOMENDAVEL, 'decisão a ser cumprida ou recorrida'],
            ['razoes', self::OPCIONAL, 'se houver recurso (CPC art. 1.003, § 5º)'],
            ['preparo', self::OPCIONAL, 'se o recurso exigir preparo (CPC art. 1.007)'],
        ],
        'recurso' => [ // L48
            ['razoes', self::RECOMENDAVEL, 'razões recursais'],
            ['sentenca', self::RECOMENDAVEL, 'decisão recorrida'],
            ['preparo', self::OPCIONAL, 'quando não houver gratuidade (CPC art. 1.007)'],
            ['procuracao', self::OBRIGATORIO, 'representação no recurso'],
        ],
        'cumprimento' => [ // L49
            ['sentenca', self::RECOMENDAVEL, 'título executivo (CPC art. 515, I)'],
            ['acordao', self::OPCIONAL, 'se houve julgamento no tribunal'],
            ['transito', self::RECOMENDAVEL, 'cumprimento definitivo (CPC art. 523); sem ela, só provisório (art. 520)'],
            ['calculo', self::OBRIGATORIO, 'demonstrativo discriminado e atualizado do crédito (CPC art. 524)'],
            ['cumprimento', self::RECOMENDAVEL, 'requerimento do credor (CPC art. 523)'],
            ['procuracao', self::OBRIGATORIO, 'CPC art. 104'],
            ['pagamento', self::OPCIONAL, 'só se já houve pagamento ou depósito'],
        ],
    ];

    /** bj-docsug.js L51 (`const FASE_NOME`). */
    public const NOMES_DAS_FASES = [
        'inicial'     => 'Fase inicial',
        'contestacao' => 'Contestação',
        'replica'     => 'Réplica',
        'provas'      => 'Fase de provas',
        'audiencia'   => 'Audiência / instrução',
        'pericia'     => 'Perícia',
        'sentenca'    => 'Sentença',
        'recurso'     => 'Recurso',
        'cumprimento' => 'Cumprimento de sentença',
    ];

    /**
     * Acréscimos pelo TIPO DE AÇÃO: [padrão sobre "ação + classe", chave, classe, porquê].
     * bj-docsug.js L103-106 (`tAcao`). A ordem é a do JS; a primeira linha acrescenta dois itens.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const POR_TIPO_DE_ACAO = [
        ['/saude|medic|plano|tratamento|fazenda/u', 'relMedico', self::RECOMENDAVEL, 'ações de saúde dependem de prova atual da necessidade'], // L104
        ['/saude|medic|plano|tratamento|fazenda/u', 'negativa', self::RECOMENDAVEL, 'mostra a recusa do plano ou do órgão'],                  // L104
        ['/imovel|usucap|posse/u', 'matricula', self::RECOMENDAVEL, 'situação registral do imóvel'],                                            // L105
        ['/obrigac|fazer/u', 'notificacao', self::OPCIONAL, 'mostra a tentativa prévia de cumprimento voluntário'],                             // L106
    ];

    /**
     * Classe processual que leva à fase de cumprimento quando o processo não foi lido.
     * bj-docsug.js L59 (`/cumprimento|execu/.test(c) && !an`).
     */
    public const CLASSE_DE_CUMPRIMENTO = '/cumprimento|execu/u';

    /**
     * Categoria com que o documento foi enviado à pasta => tipo do catálogo.
     *
     * Não existe no JS (lá só há nome de arquivo): é o dado que o sistema real TEM a mais. Quem
     * classificou o arquivo como "Procuração" no upload já disse o que ele é, com qualquer nome.
     * `CONTRATO` é o contrato do cliente com o escritório (cartão do Financeiro) — por isso
     * honorários, e não o "contrato discutido" da ação. `PECA` e `DEMAIS` não dizem o tipo.
     *
     * @var array<string, string>
     */
    public const POR_CATEGORIA_DO_UPLOAD = [
        'PROCURACAO'             => 'procuracao',
        'IDENTIFICACAO'          => 'identidade',
        'COMPROVANTE_RESIDENCIA' => 'residencia',
        'GRATUIDADE_JUSTICA'     => 'hipossuf',
        'CONTRATO'               => 'honorarios',
    ];

    private function __construct()
    {
    }
}
