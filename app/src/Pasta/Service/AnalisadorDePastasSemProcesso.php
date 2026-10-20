<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\AnaliseSemProcessoDaPasta;
use App\Pasta\DTO\PastaParaAnaliseSemProcesso;

/**
 * Porte fiel de `spAnalisar` (desenho "02 - EXPEDIENTES 1.2.3.dc.html", L4513-4537): lê as fontes de
 * cada pasta (registros, documentos, metas e checklist), procura termos judiciais e administrativos
 * e diz em que situação a pasta está quanto ao processo vinculado, com a sugestão, a confiança, as
 * metas sugeridas (prazos em dias úteis), o motivo, o resumo para despacho e o próximo passo.
 *
 * Serviço puro: não lê banco. Quem junta os dados é o {@see ColetorDePastasSemProcesso}.
 *
 * Diferença conhecida do desenho: o `\b` das expressões roda com UTF-8 (modificador `u`), então
 * letra acentuada conta como letra. No JavaScript do desenho ela contava como separador. Só muda o
 * resultado em palavras coladas a acento ("varaç"), que não ocorrem no texto real.
 */
final class AnalisadorDePastasSemProcesso
{
    public const TERMO_CNJ = 'número de processo';

    /** SP_JUD, L4504: [termo, expressão, peso]. */
    public const TERMOS_JUDICIAIS = [
        [self::TERMO_CNJ, '/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/u', 3],
        ['petição inicial', '/peti[cç][aã]o inicial|ajuiza|propor (a )?a[cç][aã]o|entrar com (a )?a[cç][aã]o/u', 2],
        ['contestação', '/contesta[cç][aã]o/u', 2],
        ['citação/intimação', '/cita[cç][aã]o|intima[cç][aã]o|intimad/u', 2],
        ['audiência', '/audi[eê]ncia/u', 2],
        ['sentença/decisão', '/senten[cç]a|decis[aã]o judicial|despacho|liminar|tutela de urg/u', 2],
        ['recurso', '/recurso|apela[cç][aã]o|agravo|embargos/u', 2],
        ['execução/cumprimento', '/execu[cç][aã]o|cumprimento de senten[cç]a|penhora/u', 2],
        ['vara/tribunal', '/\bvara\b|tribunal|\btj[a-z]{2}\b|\btrt|\btrf|pje|ju[ií]zo/u', 2],
        ['protocolo', '/protocol/u', 1],
        ['processo', '/\bprocesso\b/u', 1],
        ['ação judicial', '/a[cç][aã]o (judicial|de cobran|indeniz|revisional|trabalhista)|reclama[cç][aã]o trabalhista/u', 2],
    ];

    /** SP_ADM, L4505: [termo, expressão]. */
    public const TERMOS_ADMINISTRATIVOS = [
        ['consultoria/parecer', '/consultoria|parecer|assessoria/u'],
        ['extrajudicial', '/extrajudicial|notifica[cç][aã]o extra/u'],
        ['requerimento administrativo', '/requerimento administrativo|\binss\b|procedimento administrativo|receita federal/u'],
        ['cartório/escritura', '/cart[oó]rio|escritura|registro de im[oó]vel|invent[aá]rio extrajudicial/u'],
        ['contrato/negociação', '/elabora[cç][aã]o de contrato|revis[aã]o de contrato|negocia[cç][aã]o|acordo extrajudicial/u'],
        ['administrativo', '/administrativ/u'],
    ];

    /** Metas sugeridas por categoria, L4531: [título, prazo em dias úteis]. 0 = hoje. */
    private const METAS = [
        AnaliseSemProcessoDaPasta::DEVERIA => [['Localizar o número do processo no PJe', 2], ['Vincular o processo na pasta', 3], ['Conferir procuração e contrato', 5]],
        AnaliseSemProcessoDaPasta::REVER => [['Revisar a classificação com o responsável', 2], ['Vincular o processo ou confirmar como administrativa', 4]],
        AnaliseSemProcessoDaPasta::INCONSISTENTE => [['Desmarcar "Administrativo sem processo"', 0]],
        AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA => [['Confirmar e marcar como administrativa', 2]],
        AnaliseSemProcessoDaPasta::CLASSIFICAR => [['Classificar a pasta: vincular processo ou marcar administrativa', 3]],
    ];

    /** Motivo curto do resumo para despacho, L4533. */
    private const MOTIVOS = [
        AnaliseSemProcessoDaPasta::DEVERIA => 'conteúdo indica processo',
        AnaliseSemProcessoDaPasta::REVER => 'marcada administrativa, conteúdo judicial',
        AnaliseSemProcessoDaPasta::INCONSISTENTE => 'tem processo e está marcada administrativa',
        AnaliseSemProcessoDaPasta::CLASSIFICAR => 'sem processo e sem classificação',
        AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA => 'parece administrativa, falta marcar',
    ];

    /**
     * @param list<PastaParaAnaliseSemProcesso>                                      $pastas
     * @param array<int, array{decisao: string, por: string, termos: list<string>}> $decisoes pastaId => decisão anterior
     *
     * @return list<AnaliseSemProcessoDaPasta>
     */
    public function analisar(array $pastas, array $decisoes, \DateTimeImmutable $hoje): array
    {
        $descontos = self::descontosAprendidos($decisoes);

        return array_map(
            fn (PastaParaAnaliseSemProcesso $p): AnaliseSemProcessoDaPasta => $this->analisarPasta($p, $descontos, $decisoes[$p->id] ?? null, $hoje),
            $pastas,
        );
    }

    /**
     * Só os rótulos dos termos judiciais achados (é o que a decisão grava, L4510).
     *
     * @param list<array{rotulo: string, texto: string}> $fontes
     *
     * @return list<string>
     */
    public function termosJudiciais(array $fontes): array
    {
        return array_map(static fn (array $t): string => $t[0], self::procurarTermosJudiciais($fontes, [])['termos']);
    }

    /**
     * Quantas decisões `administrativo` citam cada termo (L4515, `desc`).
     *
     * @param array<int, array{decisao: string, por: string, termos: list<string>}> $decisoes
     *
     * @return array<string, int>
     */
    private static function descontosAprendidos(array $decisoes): array
    {
        $descontos = [];
        foreach ($decisoes as $decisao) {
            if ($decisao['decisao'] !== 'administrativo') {
                continue;
            }
            foreach ($decisao['termos'] as $termo) {
                $descontos[$termo] = ($descontos[$termo] ?? 0) + 1;
            }
        }

        return $descontos;
    }

    /**
     * @param array<string, int>                                             $descontos
     * @param array{decisao: string, por: string, termos: list<string>}|null $decisao
     */
    private function analisarPasta(PastaParaAnaliseSemProcesso $p, array $descontos, ?array $decisao, \DateTimeImmutable $hoje): AnaliseSemProcessoDaPasta
    {
        $jud = self::procurarTermosJudiciais($p->fontes, $descontos);
        $adm = self::procurarTermosAdministrativos($p->fontes);
        [$categoria, $sugestao, $confianca] = self::classificar($p, $jud, $adm);
        $metas = self::metasSugeridas($categoria, $jud['cnj'], $hoje);

        return new AnaliseSemProcessoDaPasta(
            pastaId: $p->id,
            numero: $p->numero,
            cliente: $p->cliente,
            responsavel: $p->responsavel,
            responsavelId: $p->responsavelId,
            processoNumero: $p->processoNumero,
            processoTribunal: $p->processoTribunal,
            marcada: $p->marcada,
            categoria: $categoria,
            sugestao: $sugestao,
            confianca: $confianca,
            termos: $jud['termos'],
            adm: $adm,
            cnj: $jud['cnj'],
            totalFontes: \count($p->fontes),
            decisaoAnterior: $decisao !== null ? ['decisao' => $decisao['decisao'], 'por' => $decisao['por']] : null,
            resumo: self::resumo($p, $categoria, $jud['cnj']),
            proximoPasso: $metas !== [] ? $metas[0]['titulo'] . ' até ' . $metas[0]['prazo'] : '',
            metas: array_map(static fn (array $m): array => $m + ['responsavel' => $p->responsavel], $metas),
        );
    }

    /**
     * L4521: para cada termo, a PRIMEIRA fonte que casa; peso ×0,4 com 2+ decisões administrativas.
     *
     * @param list<array{rotulo: string, texto: string}> $fontes
     * @param array<string, int>                         $descontos
     *
     * @return array{termos: list<array{0: string, 1: string}>, score: float, cnj: ?string}
     */
    private static function procurarTermosJudiciais(array $fontes, array $descontos): array
    {
        $termos = [];
        $score  = 0.0;
        $cnj    = null;
        foreach (self::TERMOS_JUDICIAIS as [$termo, $expressao, $peso]) {
            $fonte = self::primeiraFonteQueCasa($fontes, $expressao);
            if ($fonte === null) {
                continue;
            }
            $score   += ($descontos[$termo] ?? 0) >= 2 ? $peso * 0.4 : $peso;
            $termos[] = [$termo, $fonte['rotulo']];
            if ($termo === self::TERMO_CNJ && preg_match($expressao, $fonte['texto'], $m) === 1) {
                $cnj = $m[0];
            }
        }

        return ['termos' => $termos, 'score' => $score, 'cnj' => $cnj];
    }

    /**
     * L4522.
     *
     * @param list<array{rotulo: string, texto: string}> $fontes
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function procurarTermosAdministrativos(array $fontes): array
    {
        $achados = [];
        foreach (self::TERMOS_ADMINISTRATIVOS as [$termo, $expressao]) {
            $fonte = self::primeiraFonteQueCasa($fontes, $expressao);
            if ($fonte !== null) {
                $achados[] = [$termo, $fonte['rotulo']];
            }
        }

        return $achados;
    }

    /**
     * @param list<array{rotulo: string, texto: string}> $fontes
     *
     * @return array{rotulo: string, texto: string}|null
     */
    private static function primeiraFonteQueCasa(array $fontes, string $expressao): ?array
    {
        foreach ($fontes as $fonte) {
            if (preg_match($expressao, mb_strtolower($fonte['texto'])) === 1) {
                return $fonte;
            }
        }

        return null;
    }

    /**
     * L4523-4530: categoria, sugestão e confiança.
     *
     * @param array{termos: list<array{0: string, 1: string}>, score: float, cnj: ?string} $jud
     * @param list<array{0: string, 1: string}>                                            $adm
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function classificar(PastaParaAnaliseSemProcesso $p, array $jud, array $adm): array
    {
        $liquido = $jud['score'] - \count($adm) * 1.5;

        if ($p->processoNumero !== null) {
            return $p->marcada
                ? [AnaliseSemProcessoDaPasta::INCONSISTENTE, 'A pasta tem o processo ' . $p->processoNumero . ' vinculado mas está marcada como administrativa. Desmarcar "Administrativo sem processo".', 'confirmado']
                : [AnaliseSemProcessoDaPasta::COM_PROCESSO, '', 'confirmado'];
        }
        if ($p->marcada) {
            return self::classificarMarcada($jud, $adm, $liquido);
        }
        if ($liquido >= 2 || $jud['cnj'] !== null) {
            return [AnaliseSemProcessoDaPasta::DEVERIA, self::sugestaoDeveria($jud), $jud['cnj'] !== null || $liquido >= 4 ? 'provavel' : 'hipotese'];
        }
        if ($adm !== []) {
            return [AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA, 'O conteúdo indica atuação administrativa (' . self::rotulos($adm) . '). Sugestão: marcar como "Administrativo sem processo".', 'hipotese'];
        }

        return [
            AnaliseSemProcessoDaPasta::CLASSIFICAR,
            ($p->fontes !== [] ? 'Conteúdo insuficiente para decidir.' : 'Pasta sem conteúdo registrado.') . ' O responsável deve vincular o processo ou marcar como administrativa.',
            'sem dados',
        ];
    }

    /**
     * L4526-4527: marcada como administrativa, sem processo.
     *
     * @param array{termos: list<array{0: string, 1: string}>, score: float, cnj: ?string} $jud
     * @param list<array{0: string, 1: string}>                                            $adm
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function classificarMarcada(array $jud, array $adm, float $liquido): array
    {
        if ($liquido >= 3) {
            $sugestao = 'Marcada como administrativa, mas o conteúdo indica atuação judicial (' . self::rotulos($jud['termos'], 3)
                . '). Rever o cadastro: ' . ($jud['cnj'] !== null ? 'vincular o processo ' . $jud['cnj'] . ' encontrado no texto' : 'localizar o processo e vincular')
                . ', ou manter a marcação se for só consultoria.';

            return [AnaliseSemProcessoDaPasta::REVER, $sugestao, $liquido >= 5 ? 'provavel' : 'hipotese'];
        }

        return $adm !== []
            ? [AnaliseSemProcessoDaPasta::ADMINISTRATIVA, 'Coerente: ' . self::rotulos($adm) . '.', 'provavel']
            : [AnaliseSemProcessoDaPasta::ADMINISTRATIVA, 'Marcada manualmente; não há conteúdo que contradiga.', 'declarado'];
    }

    /**
     * L4528.
     *
     * @param array{termos: list<array{0: string, 1: string}>, score: float, cnj: ?string} $jud
     */
    private static function sugestaoDeveria(array $jud): string
    {
        if ($jud['cnj'] === null) {
            return 'O conteúdo indica processo judicial (' . self::rotulos($jud['termos'], 3) . '). Cadastrar o número do processo.';
        }

        $onde = '';
        foreach ($jud['termos'] as [$termo, $fonte]) {
            if ($termo === self::TERMO_CNJ) {
                $onde = mb_strtolower($fonte);
                break;
            }
        }

        return 'Vincular o processo ' . $jud['cnj'] . ' citado em ' . $onde . '.';
    }

    /**
     * @param list<array{0: string, 1: string}> $termos
     */
    private static function rotulos(array $termos, ?int $limite = null): string
    {
        $nomes = array_map(static fn (array $t): string => $t[0], $termos);

        return implode(', ', $limite !== null ? \array_slice($nomes, 0, $limite) : $nomes);
    }

    /**
     * L4531: as metas da categoria com o prazo em dias úteis ("dd/mm", ou "hoje").
     *
     * @return list<array{titulo: string, prazo: string}>
     */
    private static function metasSugeridas(string $categoria, ?string $cnj, \DateTimeImmutable $hoje): array
    {
        $metas = [];
        foreach (self::METAS[$categoria] ?? [] as $i => [$titulo, $dias]) {
            if ($categoria === AnaliseSemProcessoDaPasta::DEVERIA && $i === 0 && $cnj !== null) {
                $titulo .= ' (' . $cnj . ')';
            }
            $metas[] = ['titulo' => $titulo, 'prazo' => $dias > 0 ? self::diasUteis($hoje, $dias)->format('d/m') : 'hoje'];
        }

        return $metas;
    }

    /** `du` do desenho (L4530): soma dias pulando sábado e domingo (sem feriados). */
    public static function diasUteis(\DateTimeImmutable $de, int $dias): \DateTimeImmutable
    {
        $data   = $de;
        $contou = 0;
        while ($contou < $dias) {
            $data = $data->modify('+1 day');
            if ((int) $data->format('N') <= 5) {
                ++$contou;
            }
        }

        return $data;
    }

    /** L4534: "motivo · N registro(s), último dd/mm por Fulano · N doc(s)". Vazio para com e admin. */
    private static function resumo(PastaParaAnaliseSemProcesso $p, string $categoria, ?string $cnj): string
    {
        $motivo = self::MOTIVOS[$categoria] ?? null;
        if ($motivo === null) {
            return '';
        }
        if ($categoria === AnaliseSemProcessoDaPasta::DEVERIA && $cnj !== null) {
            $motivo .= ' nº ' . $cnj;
        }

        return implode(' · ', [
            $motivo,
            self::resumoDosRegistros($p->registros),
            $p->totalDocumentos > 0 ? $p->totalDocumentos . ' doc(s)' : 'sem documentos',
        ]);
    }

    /**
     * @param list<array{em: \DateTimeImmutable, autor: string}> $registros
     */
    private static function resumoDosRegistros(array $registros): string
    {
        if ($registros === []) {
            return 'nenhum registro';
        }

        $ultimo = $registros[0];
        foreach ($registros as $registro) {
            if ($registro['em'] > $ultimo['em']) {
                $ultimo = $registro;
            }
        }
        $primeiroNome = explode(' ', trim($ultimo['autor']))[0];

        return \count($registros) . ' registro(s), último ' . $ultimo['em']->format('d/m') . ' por ' . $primeiroNome;
    }
}
