<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\ComparacaoDaPessoaOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MetricaDaPessoaOutput;

/**
 * Leitura individual por regras fixas (dc 01 "Diagnóstico", L1136-1142): só FATOS lidos dos números
 * do Painel, todos com a tag "Observado". "Correlação" e "Hipótese" do desenho exigem causa e
 * histórico próprio que o sistema não tem, então nunca são emitidas. Sem limiar inventado: a frase
 * descreve o número, não o classifica.
 */
final class DiagnosticoDaPessoa
{
    /** @return list<string> */
    public static function motivos(LinhaAdvogadoDashboardOutput $linha, int $concluidas, string $primeiroNome): array
    {
        $motivos = [];
        $resumo  = self::resumoDoPeriodo($linha, $concluidas, $primeiroNome);
        if ($resumo !== null) {
            $motivos[] = $resumo;
        }
        if ($linha->metasVencidas > 0) {
            $motivos[] = sprintf('%d %s com prazo já vencido %s.', $linha->metasVencidas, self::plural($linha->metasVencidas, 'meta', 'metas'), self::plural($linha->metasVencidas, 'continua aberta', 'continuam abertas'));
        }
        if ($linha->prazosProximos > 0) {
            $motivos[] = sprintf('%d %s nos próximos dias.', $linha->prazosProximos, self::plural($linha->prazosProximos, 'meta vence', 'metas vencem'));
        }

        return [...$motivos, ...self::frasesDaCarga($linha)];
    }

    /**
     * A frase-resumo mistura Total, Ativas, Concluídas e Taxa, cada uma com o corte do próprio "Zerar":
     * com qualquer uma zerada, as bases divergem e a frase seria um número que a tabela não confirma.
     */
    private static function resumoDoPeriodo(LinhaAdvogadoDashboardOutput $linha, int $concluidas, string $primeiroNome): ?string
    {
        if (array_intersect(['metas', 'metas_ativas', 'metas_concluidas', 'taxa_conclusao'], $linha->colunasZeradas) !== []) {
            return null;
        }
        if ($linha->totalMetas === 0) {
            return sprintf('Nenhuma meta foi criada para %s no período.', $primeiroNome);
        }

        return sprintf(
            '%d %s no período: %d %s e %d %s (%d%% concluído).',
            $linha->totalMetas,
            self::plural($linha->totalMetas, 'meta criada', 'metas criadas'),
            $concluidas,
            self::plural($concluidas, 'concluída', 'concluídas'),
            $linha->metasAtivas,
            self::plural($linha->metasAtivas, 'ativa', 'ativas'),
            (int) round($concluidas / $linha->totalMetas * 100),
        );
    }

    /**
     * Comparação com o período anterior de mesma duração. Só entra a linha que tem o número do passado
     * de verdade: sem período completo, ou sem foto do dia para as vencidas, a linha não existe.
     *
     * @return list<ComparacaoDaPessoaOutput>
     */
    public static function comparacoes(LinhaAdvogadoDashboardOutput $linha): array
    {
        $c = [];
        if ($linha->totalMetasAnterior !== null && !in_array('metas', $linha->colunasZeradas, true)) {
            $c[] = new ComparacaoDaPessoaOutput('recebidas', $linha->totalMetas, $linha->totalMetasAnterior);
        }
        if ($linha->totalDemandasAnterior !== null && !in_array('demandas', $linha->colunasZeradas, true)) {
            $c[] = new ComparacaoDaPessoaOutput('demandas', $linha->totalDemandas, $linha->totalDemandasAnterior);
        }
        if ($linha->metasVencidasAnterior !== null && !in_array('metas_vencidas', $linha->colunasZeradas, true)) {
            $c[] = new ComparacaoDaPessoaOutput('atrasadas', $linha->metasVencidas, $linha->metasVencidasAnterior, ComparacaoDaPessoaOutput::SOBE_RUIM);
        }

        return $c;
    }

    /**
     * "Quais dados foram considerados?" (dc L1168-1170): cada número com a regra que o produziu.
     *
     * @param list<MetricaDaPessoaOutput> $metricas
     *
     * @return list<string>
     */
    public static function dados(array $metricas): array
    {
        $dados = [];
        foreach ($metricas as $m) {
            $dados[] = sprintf('%s: %s', self::ROTULOS[$m->chave], $m->valor === null ? 'sem dado' : $m->valor . ($m->porcentagem ? '%' : ''));
        }

        return $dados;
    }

    /** @return list<string> */
    public static function limites(bool $periodoCompleto, bool $conclusoesPorData = false, bool $anteriorSemRegistro = false): array
    {
        $l = [
            'Prazos cumpridos, complexidade e retrabalho não são registrados no sistema: a análise não os estima.',
            'Sem objetivo nem histórico próprio, a análise não classifica o desempenho (adequado, atenção, crítico) e não aponta causa.',
            'Vencidas e prazos próximos são contados em relação a hoje, não ao período; as demais contagens são das metas criadas no período.',
        ];
        if (!$periodoCompleto) {
            $l[] = 'Sem período (De e Até) não há período anterior para comparar.';
        }
        if ($conclusoesPorData) {
            $l[] = 'Conclusões e tempo médio da comparação são contados pela data de conclusão, nos dois períodos; a meta reaberta perde a data e não entra em nenhum.';
        }
        if ($anteriorSemRegistro) {
            $l[] = 'O período anterior começa antes de o sistema registrar a data de conclusão das metas: a comparação de conclusões não é feita.';
        }

        return $l;
    }

    private const ROTULOS = [
        'recebidas'  => 'Recebidas (metas criadas no período)',
        'concluidas' => 'Concluídas (das criadas no período)',
        'pendentes'  => 'Pendentes (ativas das criadas no período)',
        'atrasadas'  => 'Atrasadas (vencidas até hoje)',
        'prazos'     => 'Prazos próximos (de hoje)',
        'taxa'       => 'Taxa de conclusão (concluídas ÷ recebidas)',
    ];

    /** @return list<string> */
    private static function frasesDaCarga(LinhaAdvogadoDashboardOutput $linha): array
    {
        $c = $linha->totalMetasAnterior;
        if ($c === null || $c === 0 || $c === $linha->totalMetas || in_array('metas', $linha->colunasZeradas, true)) {
            return [];
        }
        $pct = (int) round(abs($linha->totalMetas - $c) / $c * 100);

        return [sprintf('A carga recebida %s %d%% frente ao período anterior (%d para %d).', $linha->totalMetas > $c ? 'subiu' : 'caiu', $pct, $c, $linha->totalMetas)];
    }

    private static function plural(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }
}
