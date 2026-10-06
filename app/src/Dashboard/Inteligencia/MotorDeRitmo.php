<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;

/**
 * Motor de ritmo do painel BlueJus Intelligence — porte, em PHP e sobre os dados REAIS do
 * Dashboard, das regras determinísticas de `bluejus-intelligence.js` (pacote do desenho,
 * `docs/design/claude-design-2026-10-05 (1)/`). Não há modelo de linguagem aqui: cada frase
 * sai de uma comparação com limiar fixo, e os números que a sustentam vão junto (Leitura).
 *
 * Entrada = o DashboardOutput já calculado pelo UseCase (linhas visíveis da tabela, com os
 * filtros aplicados) + o TempoDoPeriodo (null sem período). Os números-base são as SOMAS
 * das linhas, exatamente como o protótipo lê as `rows` (`dadosDe`, dc L2665-2669):
 * `concluidas = Σ(totalMetas − metasAtivas)`, `novas = Σ totalMetas`, `ativas`, `vencidas`,
 * `prazos`. É a linha de Total da tabela — e, como ela, conta a meta com dois responsáveis
 * para cada um (nota de rodapé do painel).
 *
 * O que NÃO foi portado, porque o dado não existe no sistema (e o painel declara em
 * `limites` em vez de fingir):
 *  - objetivo/alvo (L26-27: exige concluídas do período anterior e média dos 3 últimos),
 *    esperado, faltam, ritmo necessário e desvio (L28-31, L37);
 *  - classificação do ritmo adequado/atenção/abaixo/crítico (L45-56) — sem alvo o JS cai
 *    sempre em "atenção", o que seria um rótulo sem lastro;
 *  - projeção e "confiável" (L32-33, L109-115), comparação com o mesmo ponto do período
 *    anterior (L34, L80), "quanto mudar o ritmo" (L142), ações/riscos que dependem disso
 *    (L92, L94, L105) e o plano de crescimento (L152-181).
 */
final class MotorDeRitmo
{
    public const TIPO_FASE             = 'fase';
    public const TIPO_ENTRADA_VS_SAIDA = 'entrada_vs_saida';
    public const TIPO_VENCIDAS         = 'vencidas';
    public const TIPO_EQUILIBRIO       = 'equilibrio';
    public const TIPO_PRAZOS           = 'prazos';
    public const TIPO_REDISTRIBUIR     = 'redistribuir';
    public const TIPO_SOBRECARGA       = 'sobrecarga';
    public const TIPO_SEGURAR_ENTRADA  = 'segurar_entrada';
    public const TIPO_REAVALIAR        = 'reavaliar';

    /** Diferença entre novas/dia e concluídas/dia acima da qual "a fila cresce" (L81, L93, L104). */
    public const ENTRADA_VS_SAIDA_LIMIAR = 0.3;

    /** `top / média − 1` acima do qual quem tem mais metas ativas está em sobrecarga (L124). */
    public const SOBRECARGA_RAZAO = 0.45;

    /** Sugestão de redistribuição: um terço da diferença entre quem tem mais e quem tem menos (L126). */
    public const REDISTRIBUIR_DIVISOR = 3;

    // Pesos das ações (L89-95): a maior vira "o que fazer agora".
    public const PESO_VENCIDAS        = 100;
    public const PESO_PRAZOS          = 90;
    public const PESO_REDISTRIBUIR    = 70;
    public const PESO_SEGURAR_ENTRADA = 50;
    public const PESO_REAVALIAR       = 0;

    // Níveis dos riscos (L101-104).
    public const NIVEL_VENCIDAS   = 3;
    public const NIVEL_PRAZOS     = 2;
    public const NIVEL_SOBRECARGA = 2;
    public const NIVEL_ENTRADA    = 2;

    public const GRUPO_ATENCAO_IMEDIATA = 'Atenção imediata';
    public const GRUPO_ACAO_RECOMENDADA = 'Ação recomendada';
    public const GRUPO_ACOMPANHAMENTO   = 'Acompanhamento';

    public function analisar(DashboardOutput $dashboard, ?TempoDoPeriodo $tempo): LeituraDoRitmo
    {
        $linhas     = $dashboard->porAdvogado;
        $novas      = $this->somar($linhas, 'totalMetas');
        $ativas     = $this->somar($linhas, 'metasAtivas');
        $concluidas = $novas - $ativas;
        $vencidas   = $this->somar($linhas, 'metasVencidas');
        $prazos     = $this->somar($linhas, 'prazosProximos');

        $ritmoAtual     = null;
        $novasPorDia    = null;
        $entradaVsSaida = null;
        if ($tempo !== null) {
            // L29: ritmoAtual = passados ? concluidas / passados : 0
            $ritmoAtual = $tempo->passados > 0 ? $concluidas / $tempo->passados : 0.0;
            // L39: entradaVsSaida = passados ? novas / passados − ritmoAtual : 0
            $novasPorDia    = $tempo->passados > 0 ? $novas / $tempo->passados : 0.0;
            $entradaVsSaida = $novasPorDia - $ritmoAtual;
        }

        $equipe = $this->equipe($linhas);

        $numeros = [
            'novas'      => $novas,
            'concluidas' => $concluidas,
            'ativas'     => $ativas,
            'vencidas'   => $vencidas,
            'prazos'     => $prazos,
        ];
        $filaCresce = $entradaVsSaida !== null && $entradaVsSaida > self::ENTRADA_VS_SAIDA_LIMIAR;

        return new LeituraDoRitmo(
            tempo:          $tempo,
            concluidas:     $concluidas,
            ativas:         $ativas,
            vencidas:       $vencidas,
            prazos:         $prazos,
            novas:          $novas,
            ritmoAtual:     $ritmoAtual,
            novasPorDia:    $novasPorDia,
            entradaVsSaida: $entradaVsSaida,
            equipe:         $equipe,
            oQue:           $this->oQue($tempo, $numeros),
            porQue:         $this->porQue($tempo, $numeros, $ritmoAtual, $novasPorDia, $filaCresce),
            acoes:          $this->acoes($numeros, $equipe, $filaCresce),
            riscos:         $this->riscos($numeros, $equipe, $filaCresce),
            // L146 sem a parte do estado (não classificado): vencidas ou sobrecarga
            alerta:         $vencidas > 0 || $equipe->sobrecarga !== null,
            limites:        $this->limites($tempo),
        );
    }

    /**
     * `analisarEquipe()` (L118-128): só quem tem meta no período entra na média; o de
     * maior fila está em sobrecarga quando passa da média além do limiar; a folga é o de
     * menor fila (outra pessoa); redistribuir = um terço da diferença, no mínimo 1.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     */
    private function equipe(array $linhas): EquipeDoRitmo
    {
        $ativos = array_values(array_filter(
            $linhas,
            static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->totalMetas > 0,
        ));
        $media = $this->somar($ativos, 'metasAtivas') / max(1, count($ativos));

        // usort é estável desde o PHP 8.0, como o sort do JS: empate mantém a ordem da tabela
        usort($ativos, static fn (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b): int => $b->metasAtivas <=> $a->metasAtivas);
        $top   = $ativos[0] ?? null;
        $baixo = $ativos === [] ? null : $ativos[count($ativos) - 1];

        $razao      = $top !== null && $media > 0 ? $top->metasAtivas / $media - 1 : 0.0;
        $sobrecarga = $top !== null && $razao > self::SOBRECARGA_RAZAO ? $top : null;
        $folga      = $sobrecarga !== null && $baixo !== null && $baixo->userId !== $top->userId ? $baixo : null;
        $redistribuir = $sobrecarga !== null && $folga !== null
            ? max(1, intdiv($top->metasAtivas - $folga->metasAtivas, self::REDISTRIBUIR_DIVISOR))
            : 0;

        return new EquipeDoRitmo($media, $sobrecarga, $razao, $folga, $redistribuir);
    }

    /**
     * "O que está acontecendo" (L75-79), sem as partes que dependem do estado/desvio: a fase
     * do período e, em seguida, os números reais do período. Sem período não há dia a contar.
     *
     * @param array<string, int> $n
     */
    private function oQue(?TempoDoPeriodo $tempo, array $n): ?Leitura
    {
        if ($tempo === null) {
            return null;
        }

        $fase    = $tempo->fase();
        $abertura = match ($fase) {
            // L75, sem "contra um objetivo de N"
            TempoDoPeriodo::FASE_ENCERRADO => sprintf(
                'A equipe encerrou o período com %d %s.',
                $n['concluidas'],
                $this->plural($n['concluidas'], 'meta concluída', 'metas concluídas'),
            ),
            // L76, sem a frase do estado
            TempoDoPeriodo::FASE_INICIO => sprintf('Início do período: dia %d de %d.', $tempo->passados, $tempo->total),
            // L77, sem a frase do estado
            TempoDoPeriodo::FASE_FINAL => sprintf('Restam %d %s.', $tempo->restantes, $this->plural($tempo->restantes, 'dia', 'dias')),
            // L78-79, sem o desvio
            default => sprintf('Dia %d de %d.', $tempo->passados, $tempo->total),
        };

        $texto = $abertura . ' ' . sprintf(
            '%d %s no período, %d %s e %d %s.',
            $n['novas'],
            $this->plural($n['novas'], 'meta aberta', 'metas abertas'),
            $n['concluidas'],
            $this->plural($n['concluidas'], 'concluída', 'concluídas'),
            $n['ativas'],
            $this->plural($n['ativas'], 'ativa', 'ativas'),
        );

        return new Leitura(self::TIPO_FASE, 0, $texto, $n + [
            'fase'      => $fase,
            'dia'       => $tempo->passados,
            'dias'      => $tempo->total,
            'restantes' => $tempo->restantes,
        ]);
    }

    /**
     * "Por que" (L80-83), sem a comparação com o mesmo ponto do período anterior (L80).
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function porQue(?TempoDoPeriodo $tempo, array $n, ?float $ritmoAtual, ?float $novasPorDia, bool $filaCresce): array
    {
        $p = [];

        if ($filaCresce) {
            // L81 (o JS mostra novas / max(1, passados) e o ritmo atual)
            $p[] = new Leitura(self::TIPO_ENTRADA_VS_SAIDA, 2, sprintf(
                'Entram mais metas por dia (%s) do que são concluídas (%s), o que faz a fila crescer.',
                $this->fmt((float) $novasPorDia),
                $this->fmt((float) $ritmoAtual),
            ), ['novas_por_dia' => round((float) $novasPorDia, 1), 'concluidas_por_dia' => round((float) $ritmoAtual, 1)]);
        }

        if ($n['vencidas'] > 0) {
            // L82
            $p[] = new Leitura(self::TIPO_VENCIDAS, 3, sprintf(
                '%d %s a taxa de conclusão.',
                $n['vencidas'],
                $this->plural($n['vencidas'], 'meta vencida segura', 'metas vencidas seguram'),
            ), ['vencidas' => $n['vencidas']]);
        }

        // L83 — só com período: sem dia contado não dá para afirmar equilíbrio entre entrada e saída
        if ($p === [] && $tempo !== null) {
            $p[] = new Leitura(self::TIPO_EQUILIBRIO, 0, 'Entrada e saída de metas estão equilibradas no período.', [
                'novas_por_dia'      => round((float) $novasPorDia, 1),
                'concluidas_por_dia' => round((float) $ritmoAtual, 1),
            ]);
        }

        return $p;
    }

    /**
     * Ações (L87-97), ordenadas por peso; a primeira é "o que fazer agora" (L141). Ficaram de
     * fora as que dependem do ritmo necessário/estado (L92, L94).
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function acoes(array $n, EquipeDoRitmo $equipe, bool $filaCresce): array
    {
        $a = [];

        if ($n['vencidas'] > 0) {
            // L89
            $a[] = new Leitura(self::TIPO_VENCIDAS, self::PESO_VENCIDAS, sprintf(
                'Priorizar %d %s.',
                $n['vencidas'],
                $this->plural($n['vencidas'], 'meta vencida', 'metas vencidas'),
            ), ['vencidas' => $n['vencidas']], self::GRUPO_ATENCAO_IMEDIATA);
        }

        if ($n['prazos'] > 0) {
            // L90
            $a[] = new Leitura(self::TIPO_PRAZOS, self::PESO_PRAZOS, sprintf(
                'Concluir primeiro %s com prazo próximo.',
                $this->plural($n['prazos'], 'a meta', 'as ' . $n['prazos'] . ' metas'),
            ), ['prazos' => $n['prazos']], self::GRUPO_ATENCAO_IMEDIATA);
        }

        if ($equipe->sobrecarga !== null && $equipe->folga !== null && $equipe->redistribuir > 0) {
            // L91
            $a[] = new Leitura(self::TIPO_REDISTRIBUIR, self::PESO_REDISTRIBUIR, sprintf(
                'Redistribuir %d %s de %s para %s, que tem menor carga.',
                $equipe->redistribuir,
                $this->plural($equipe->redistribuir, 'meta', 'metas'),
                $this->primeiroNome($equipe->sobrecarga->nomeAdvogado),
                $this->primeiroNome($equipe->folga->nomeAdvogado),
            ), [
                'redistribuir'      => $equipe->redistribuir,
                'ativas_sobrecarga' => $equipe->sobrecarga->metasAtivas,
                'ativas_folga'      => $equipe->folga->metasAtivas,
                'media'             => round($equipe->media, 1),
            ], self::GRUPO_ACAO_RECOMENDADA);
        }

        if ($filaCresce) {
            // L93
            $a[] = new Leitura(
                self::TIPO_SEGURAR_ENTRADA,
                self::PESO_SEGURAR_ENTRADA,
                'Segurar a abertura de novas metas de baixo impacto até a fila voltar a cair.',
                ['novas' => $n['novas'], 'concluidas' => $n['concluidas']],
                self::GRUPO_ACAO_RECOMENDADA,
            );
        }

        // L95: sempre a última
        $a[] = new Leitura(self::TIPO_REAVALIAR, self::PESO_REAVALIAR, 'Reavaliar o resultado em 24 horas.', [], self::GRUPO_ACOMPANHAMENTO);

        usort($a, static fn (Leitura $x, Leitura $y): int => $y->severidade <=> $x->severidade);

        return $a;
    }

    /**
     * Riscos (L99-107), por nível; sem o risco de projeção abaixo do objetivo (L105).
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function riscos(array $n, EquipeDoRitmo $equipe, bool $filaCresce): array
    {
        $r = [];

        if ($n['vencidas'] > 0) {
            // L101
            $r[] = new Leitura(self::TIPO_VENCIDAS, self::NIVEL_VENCIDAS, sprintf(
                '%d %s',
                $n['vencidas'],
                $this->plural($n['vencidas'], 'meta vencida', 'metas vencidas'),
            ), ['vencidas' => $n['vencidas']]);
        }

        if ($n['prazos'] > 0) {
            // L102
            $r[] = new Leitura(self::TIPO_PRAZOS, self::NIVEL_PRAZOS, sprintf(
                '%d %s nos próximos dias',
                $n['prazos'],
                $this->plural($n['prazos'], 'meta vence', 'metas vencem'),
            ), ['prazos' => $n['prazos']]);
        }

        if ($equipe->sobrecarga !== null) {
            // L103
            $acima = (int) round($equipe->razao * 100);
            $r[] = new Leitura(self::TIPO_SOBRECARGA, self::NIVEL_SOBRECARGA, sprintf(
                '%s concentra %d metas ativas (%d%% acima da média)',
                $this->primeiroNome($equipe->sobrecarga->nomeAdvogado),
                $equipe->sobrecarga->metasAtivas,
                $acima,
            ), [
                'ativas'          => $equipe->sobrecarga->metasAtivas,
                'media'           => round($equipe->media, 1),
                'acima_da_media'  => $acima,
            ]);
        }

        if ($filaCresce) {
            // L104
            $r[] = new Leitura(self::TIPO_ENTRADA_VS_SAIDA, self::NIVEL_ENTRADA, 'Fila de metas crescendo mais rápido do que a equipe conclui', [
                'novas'      => $n['novas'],
                'concluidas' => $n['concluidas'],
            ]);
        }

        usort($r, static fn (Leitura $x, Leitura $y): int => $y->severidade <=> $x->severidade);

        return $r;
    }

    /**
     * O que o painel não consegue dizer com os dados do sistema — declarado, nunca simulado.
     *
     * @return string[]
     */
    private function limites(?TempoDoPeriodo $tempo): array
    {
        $l = [
            'Sem o histórico de conclusões do período anterior (e a média dos três últimos), o painel não define objetivo, '
            . 'não classifica o ritmo como adequado, de atenção, abaixo ou crítico, e não projeta o fechamento do período.',
        ];

        if ($tempo === null) {
            $l[] = 'Sem período (De e Até) não há dia a contar: ritmo por dia, fase do período e entrada × saída ficam de fora.';
        }

        return $l;
    }

    /** @param LinhaAdvogadoDashboardOutput[] $linhas */
    private function somar(array $linhas, string $campo): int
    {
        return array_sum(array_map(
            static fn (LinhaAdvogadoDashboardOutput $l): int => (int) $l->$campo,
            $linhas,
        ));
    }

    /** `fmt` do JS (L12-13): uma casa decimal, vírgula, sem zero à direita ("1", "1,5"). */
    private function fmt(float $v): string
    {
        return str_replace('.', ',', (string) round($v, 1));
    }

    private function plural(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }

    private function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0];
    }
}
