<?php

declare(strict_types=1);

namespace App\Processo\Service;

use App\Agenda\Service\DiasUteisNacionais;
use App\Processo\DTO\MovimentacaoParaPrevisao;
use App\Processo\DTO\SituacaoAtualOutput;

/**
 * Motor da "Situação atual" do painel do Push, variante SEM leitura do processo (spec
 * push-painel-monitoramento §3). Porte fiel do `previsaoProc` do desenho 1.2.3 (dc L.5092-5117):
 * a movimentação oficial é a fonte e não é alterada; aqui só se interpreta, calcula e projeta.
 *
 *   1. a última movimentação (a mais recente) escolhe a regra: a PRIMEIRA de `REGRAS` cujo padrão
 *      casa com o texto normalizado (tipo + teor, minúsculo, sem acento, até 5.000 caracteres);
 *   2. o contexto ajusta a confiança e escreve as notas (dc L.5097-5102);
 *   3. o prazo em dias úteis dá a data provável e o selo (dc L.5103-5104).
 *
 * Sem estado e sem banco: a taxa de acertos é do `MontarPainelDoPushUseCase`.
 */
final class PrevisaoProcessual
{
    /**
     * `REGRAS_PROC` do desenho (dc L.5064-5078), na MESMA ordem e com os MESMOS textos. Base de
     * conhecimento (CPC); `dias` é número, `null` (sem prazo legal para a parte) ou 'texto' (o prazo
     * vem do próprio texto: "prazo de N dias"). `exige` = movimentação que deveria estar no histórico.
     *
     * @var list<array{id: string, re: string, fase: string, quem: string, dias: int|string|null, base: string, prox: string, post: string, conf: string, exige?: string}>
     */
    public const REGRAS = [
        ['id' => 'citacao', 're' => '/cita[cç][aã]o|mandado de cita/u', 'fase' => 'Prazo para contestação', 'quem' => 'Parte ré', 'dias' => 15, 'base' => 'CPC art. 335', 'prox' => 'Contestação da parte ré', 'post' => 'Intimação da parte autora para réplica', 'conf' => 'Alta'],
        ['id' => 'contestacao', 're' => '/juntada de contesta|contesta[cç][aã]o (apresentada|juntada|protocolada)/u', 'fase' => 'Aguardando intimação para réplica', 'quem' => 'Cartório / parte autora', 'dias' => null, 'base' => 'CPC arts. 350 e 351', 'prox' => 'Intimação da parte autora para réplica (15 dias úteis)', 'post' => 'Réplica', 'conf' => 'Alta'],
        ['id' => 'replica', 're' => '/conclusos? para r[eé]plica|r[eé]plica|manifesta[cç][aã]o sobre a contesta/u', 'fase' => 'Prazo para réplica', 'quem' => 'Parte autora', 'dias' => 15, 'base' => 'CPC arts. 350 e 351', 'prox' => 'Réplica / manifestação da parte autora', 'post' => 'Conclusão para decisão / saneamento (CPC art. 357)', 'conf' => 'Alta', 'exige' => 'contestacao'],
        ['id' => 'replica_ok', 're' => '/juntada de r[eé]plica|r[eé]plica (apresentada|juntada)/u', 'fase' => 'Análise judicial após a réplica', 'quem' => 'Juízo', 'dias' => null, 'base' => 'CPC arts. 347 a 357', 'prox' => 'Especificação de provas ou saneamento', 'post' => 'Instrução ou julgamento antecipado', 'conf' => 'Média'],
        ['id' => 'provas', 're' => '/especifica[cç][aã]o de provas|especifiquem as provas/u', 'fase' => 'Prazo para especificar provas', 'quem' => 'Partes', 'dias' => null, 'base' => 'Despacho do juízo', 'prox' => 'Petição de especificação de provas', 'post' => 'Decisão de saneamento (CPC art. 357)', 'conf' => 'Média'],
        ['id' => 'saneamento', 're' => '/saneamento|saneador/u', 'fase' => 'Prazo para esclarecimentos do saneamento', 'quem' => 'Partes', 'dias' => 5, 'base' => 'CPC art. 357, § 1º', 'prox' => 'Pedido de esclarecimentos ou ajustes', 'post' => 'Instrução / audiência', 'conf' => 'Alta'],
        ['id' => 'audiencia', 're' => '/audi[eê]ncia (designada|marcada)|designa[cç][aã]o de audi/u', 'fase' => 'Audiência designada', 'quem' => 'Partes e advogados', 'dias' => null, 'base' => 'Despacho do juízo', 'prox' => 'Realização da audiência', 'post' => 'Alegações finais ou sentença', 'conf' => 'Média'],
        ['id' => 'sentenca', 're' => '/senten[cç]a (publicada|proferida)|julgo (procedente|improcedente)|^senten[cç]a/u', 'fase' => 'Prazo recursal', 'quem' => 'Partes', 'dias' => 15, 'base' => 'CPC art. 1.003, § 5º (apelação) e art. 1.023 (embargos, 5 dias)', 'prox' => 'Apelação ou embargos de declaração', 'post' => 'Contrarrazões ou trânsito em julgado', 'conf' => 'Alta'],
        ['id' => 'apelacao', 're' => '/apela[cç][aã]o (interposta|juntada)|interp[oô]s apela/u', 'fase' => 'Prazo para contrarrazões', 'quem' => 'Parte apelada', 'dias' => 15, 'base' => 'CPC art. 1.010, § 1º', 'prox' => 'Contrarrazões', 'post' => 'Remessa ao tribunal', 'conf' => 'Alta'],
        ['id' => 'transito', 're' => '/tr[aâ]nsito em julgado|transitou/u', 'fase' => 'Fase de cumprimento', 'quem' => 'Parte vencedora', 'dias' => null, 'base' => 'CPC art. 523', 'prox' => 'Pedido de cumprimento de sentença', 'post' => 'Intimação para pagamento em 15 dias', 'conf' => 'Média'],
        ['id' => 'conclusos', 're' => '/conclusos? (para|ao) (julgamento|senten|decis)/u', 'fase' => 'Concluso ao juízo', 'quem' => 'Juízo', 'dias' => null, 'base' => 'CPC art. 226 (prazo impróprio)', 'prox' => 'Decisão ou sentença', 'post' => 'Intimação das partes', 'conf' => 'Baixa'],
        ['id' => 'interlocutoria', 're' => '/decis[aã]o interlocut|tutela .*deferid|tutela .*indeferid/u', 'fase' => 'Prazo para recorrer da decisão', 'quem' => 'Parte prejudicada', 'dias' => 15, 'base' => 'CPC art. 1.015 (agravo, se cabível) e art. 1.023 (embargos, 5 dias)', 'prox' => 'Agravo de instrumento ou cumprimento da decisão', 'post' => 'Seguimento do rito', 'conf' => 'Média'],
        ['id' => 'prazo_texto', 're' => '/prazo de \d+ dias/u', 'fase' => 'Prazo em curso', 'quem' => 'Parte intimada', 'dias' => 'texto', 'base' => 'Prazo fixado na movimentação', 'prox' => 'Manifestação da parte intimada', 'post' => 'Conclusão ao juízo', 'conf' => 'Média'],
    ];

    /** Dica da previsão (dc L.5116, `dica`). */
    public const DICA = 'Contagem em dias úteis a partir do 1º dia útil após a publicação (CPC arts. 219 e 224), com feriados nacionais e recesso de 20/12 a 20/01. Feriados locais e suspensões do tribunal não estão incluídos.';

    /** Regras da fase de conhecimento, rebaixadas quando a classe é de cumprimento (dc L.5101). */
    private const REGRAS_DE_CONHECIMENTO = ['citacao', 'contestacao', 'replica', 'replica_ok', 'provas', 'saneamento'];

    private const LIMITE_TEXTO = 5000;

    public function __construct(
        private readonly DiasUteisNacionais $diasUteis,
    ) {
    }

    /**
     * @param MovimentacaoParaPrevisao[] $movimentacoes as publicações dos processos da pasta
     * @param string                     $classe        classe processual do processo principal
     */
    public function prever(array $movimentacoes, string $classe, \DateTimeImmutable $hoje): ?SituacaoAtualOutput
    {
        if ($movimentacoes === []) {
            return null;
        }

        $ms = $this->maisRecentePrimeiro($movimentacoes);
        $ultima = $ms[0];
        $historico = array_slice($ms, 1);
        $regra = self::regraDe($ultima['n']);
        [$conf, $notas] = self::ajustarConfianca($regra, $ultima, $historico, $classe);
        [$dias, $notaDoPrazo] = self::diasDoPrazo($regra, $ultima['n']);
        if ($notaDoPrazo !== null) {
            $notas[] = $notaDoPrazo;
        }
        $fim = $dias ? $this->diasUteis->somarUteis($ultima['mov']->data, $dias) : null;
        $falta = $fim !== null ? $this->diasUteis->uteisAte($fim, $hoje) : null;

        return $this->montar($regra, $ultima, count($ms), $conf, $notas, $dias, $fim, $falta);
    }

    /**
     * Comparação da previsão anterior com o que aconteceu (dc L.5105): acertou se alguma palavra
     * com mais de 4 letras do previsto aparece no texto da movimentação que chegou depois.
     */
    public static function acertou(string $previsto, string $textoNormalizadoDoEvento): bool
    {
        $palavras = preg_split('/[ \/]+/u', self::normalizar($previsto)) ?: [];
        foreach ($palavras as $palavra) {
            if (mb_strlen($palavra) > 4 && str_contains($textoNormalizadoDoEvento, $palavra)) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculo e sem acento (dc L.5094: `toLowerCase().normalize('NFD')` sem as marcas). */
    public static function normalizar(string $texto): string
    {
        $decomposto = \Normalizer::normalize(mb_strtolower($texto), \Normalizer::FORM_D);

        return (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', is_string($decomposto) ? $decomposto : mb_strtolower($texto));
    }

    /**
     * @param MovimentacaoParaPrevisao[] $movimentacoes
     *
     * @return list<array{mov: MovimentacaoParaPrevisao, n: string, data: string}>
     */
    private function maisRecentePrimeiro(array $movimentacoes): array
    {
        $ms = array_map(static fn (MovimentacaoParaPrevisao $m): array => [
            'mov'  => $m,
            'n'    => mb_substr(self::normalizar($m->tipo . ' ' . $m->texto), 0, self::LIMITE_TEXTO),
            'data' => $m->data->format('d/m/Y'),
        ], array_values($movimentacoes));
        // usort é estável: no mesmo dia vale a ordem de chegada, como o `sort` do desenho.
        usort($ms, static fn (array $a, array $b): int => $b['mov']->data <=> $a['mov']->data);

        return $ms;
    }

    /** @return array{id: string, re: string, fase: string, quem: string, dias: int|string|null, base: string, prox: string, post: string, conf: string, exige?: string}|null */
    private static function regraDe(string $textoNormalizado): ?array
    {
        foreach (self::REGRAS as $regra) {
            if (preg_match($regra['re'], $textoNormalizado) === 1) {
                return $regra;
            }
        }

        return null;
    }

    /**
     * Ajustes de contexto (dc L.5097-5101), na ordem do desenho.
     *
     * @param array<string, mixed>|null                                            $regra
     * @param array{mov: MovimentacaoParaPrevisao, n: string, data: string}       $ultima
     * @param list<array{mov: MovimentacaoParaPrevisao, n: string, data: string}> $historico
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function ajustarConfianca(?array $regra, array $ultima, array $historico, string $classe): array
    {
        if ($regra === null) {
            return ['Baixa', []];
        }

        $conf = $regra['conf'];
        $notas = [];
        if (isset($regra['exige']) && !self::algumCasa($historico, '/contesta/')) {
            $conf = 'Média';
            $notas[] = 'Não encontrei a contestação no histórico; confira se ela foi juntada.';
        }
        $replicaDepois = array_filter($historico, static fn (array $m): bool => $m['mov']->data > $ultima['mov']->data);
        if ($regra['id'] === 'replica' && self::algumCasa(array_values($replicaDepois), '/replica/')) {
            $conf = 'Baixa';
            $notas[] = 'Já existe réplica registrada depois desta intimação.';
        }
        if (preg_match('/cumprimento de senten/u', mb_strtolower($classe)) === 1 && in_array($regra['id'], self::REGRAS_DE_CONHECIMENTO, true)) {
            $conf = $conf === 'Alta' ? 'Média' : 'Baixa';
            $notas[] = 'A classe cadastrada é "Cumprimento de sentença", mas a sequência é de fase de conhecimento. Confira a classe do processo.';
        }

        return [$conf, $notas];
    }

    /** @param list<array{mov: MovimentacaoParaPrevisao, n: string, data: string}> $ms */
    private static function algumCasa(array $ms, string $padrao): bool
    {
        foreach ($ms as $m) {
            if (preg_match($padrao, $m['n']) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prazo em dias úteis (dc L.5102): o da regra, ou o do texto quando a regra manda ler o texto.
     * O "prazo de N dias" escrito na movimentação vence o da regra, com a nota.
     *
     * @param array<string, mixed>|null $regra
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function diasDoPrazo(?array $regra, string $textoNormalizado): array
    {
        if ($regra === null) {
            return [null, null];
        }

        $doTexto = preg_match('/prazo de (\d+) dias/u', $textoNormalizado, $m) === 1 ? (int) $m[1] : 0;
        if ($regra['dias'] === 'texto') {
            return [$doTexto, null];
        }
        if ($doTexto !== 0 && $doTexto !== $regra['dias']) {
            return [$doTexto, 'O texto fala em ' . $doTexto . ' dias; usei o prazo do texto.'];
        }

        return [$regra['dias'], null];
    }

    /**
     * @param array<string, mixed>|null                                      $regra
     * @param array{mov: MovimentacaoParaPrevisao, n: string, data: string} $ultima
     * @param list<string>                                                   $notas
     */
    private function montar(?array $regra, array $ultima, int $total, string $conf, array $notas, ?int $dias, ?\DateTimeImmutable $fim, ?int $falta): SituacaoAtualOutput
    {
        return new SituacaoAtualOutput(
            situacao: $regra['fase'] ?? 'Fase não identificada',
            status: self::status($fim, $falta),
            statusMod: self::statusMod($falta),
            ultimo: $ultima['data'] . ' · ' . $ultima['mov']->tipo,
            proximo: $regra['prox'] ?? 'Aguardar a próxima movimentação',
            quem: $regra['quem'] ?? '',
            prazo: $dias ? $dias . ' dias úteis' : 'Sem prazo legal definido',
            previsao: self::previsao($fim, $falta),
            dica: self::DICA,
            posterior: $regra['post'] ?? 'Depende de ato do juízo',
            conf: $conf,
            confMod: self::confMod($conf),
            base: ($regra !== null ? $regra['base'] . ' · ' : '') . 'histórico de ' . $total . ' movimentações',
            nota: $notas !== [] ? implode(' ', $notas) : null,
            chave: $ultima['data'] . ' ' . $ultima['mov']->tipo,
            previstoParaConferir: $regra['prox'] ?? '',
            textoDoUltimo: $ultima['n'],
        );
    }

    /** Cor do selo da confiança (dc L.5113 `confSt`). */
    private static function confMod(string $conf): string
    {
        return match ($conf) {
            'Alta' => 'alta',
            'Média' => 'media',
            default => 'baixa',
        };
    }

    /** Selo (dc L.5104). */
    private static function status(?\DateTimeImmutable $fim, ?int $falta): string
    {
        return match (true) {
            $fim === null => 'Sem prazo legal para a parte',
            $falta < 0 => 'Prazo encerrado',
            $falta <= 3 => 'Prazo terminando',
            default => 'Em andamento',
        };
    }

    /** Cor do selo (dc L.5110 `statusSt`): sem prazo, o verde do "em andamento", como no desenho. */
    private static function statusMod(?int $falta): string
    {
        return match (true) {
            $falta !== null && $falta < 0 => SituacaoAtualOutput::STATUS_ENCERRADO,
            $falta !== null && $falta <= 3 => SituacaoAtualOutput::STATUS_TERMINANDO,
            default => SituacaoAtualOutput::STATUS_ANDAMENTO,
        };
    }

    /** Data provável (dc L.5112). */
    private static function previsao(?\DateTimeImmutable $fim, ?int $falta): string
    {
        if ($fim === null) {
            return 'Sem data';
        }

        return $fim->format('d/m/Y') . ($falta < 0 ? ' (encerrado)' : ' · faltam ' . $falta . ' dias úteis');
    }
}
