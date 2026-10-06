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
 * Dois universos que NUNCA se misturam numa razão: `novas`/`concluidas`/`ativas` são as
 * metas CRIADAS no período (filtro por dataCriacao); `vencidas` e `prazos` são relativos a
 * HOJE, sem período (TarefaRepository::countVencidasPorResponsavel / countPrazosProximos…).
 * Vencidas e prazos entram só como contagem.
 *
 * O que NÃO foi portado, porque o dado não existe no sistema (e o painel declara em
 * `limites` em vez de fingir):
 *  - objetivo/alvo (L26-27: exige concluídas do período anterior e média dos 3 últimos),
 *    esperado, faltam, ritmo necessário e desvio (L28-31, L37);
 *  - classificação do ritmo adequado/atenção/abaixo/crítico (L45-56) — sem alvo o JS cai
 *    sempre em "atenção", o que seria um rótulo sem lastro;
 *  - projeção e "confiável" (L32-33, L109-115), comparação com o mesmo ponto do período
 *    anterior (L34, L80), "quanto mudar o ritmo" (L142), ações/riscos que dependem disso
 *    (L92, L94, L105) e o plano de crescimento (L152-181);
 *  - entrada × saída / "a fila cresce" (L39, L81, L93, L104): nas metas criadas no período,
 *    novas − concluídas é, por definição, o que segue aberto — não mede tendência nenhuma
 *    (as conclusões de metas mais antigas não entram). Fica a afirmação que os números
 *    sustentam: "N das M metas criadas no período seguem abertas";
 *  - "vencidas seguram a taxa de conclusão" (L82): vencidas são de hoje, a taxa é do
 *    período — ficou só a contagem.
 */
final class MotorDeRitmo
{
    public const TIPO_FASE               = 'fase';
    public const TIPO_ABERTAS_NO_PERIODO = 'abertas_no_periodo';
    public const TIPO_SEM_METAS          = 'sem_metas';
    public const TIPO_VENCIDAS           = 'vencidas';
    public const TIPO_PRAZOS             = 'prazos';
    public const TIPO_REDISTRIBUIR       = 'redistribuir';
    public const TIPO_SOBRECARGA         = 'sobrecarga';
    public const TIPO_REAVALIAR          = 'reavaliar';

    /** `top / média − 1` acima do qual quem tem mais metas ativas está em sobrecarga (L124). */
    public const SOBRECARGA_RAZAO = 0.45;

    /** Sugestão de redistribuição: um terço da diferença entre quem tem mais e quem tem menos (L126). */
    public const REDISTRIBUIR_DIVISOR = 3;

    // Pesos das ações (L89-95): a maior vira "o que fazer agora".
    public const PESO_VENCIDAS     = 100;
    public const PESO_PRAZOS       = 90;
    public const PESO_REDISTRIBUIR = 70;
    public const PESO_REAVALIAR    = 0;

    // Níveis dos riscos (L101-103).
    public const NIVEL_VENCIDAS   = 3;
    public const NIVEL_PRAZOS     = 2;
    public const NIVEL_SOBRECARGA = 2;

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

        $ritmoAtual  = null;
        $novasPorDia = null;
        if ($tempo !== null) {
            // L29: ritmoAtual = passados ? concluidas / passados : 0 (das metas criadas no período)
            $ritmoAtual  = $tempo->passados > 0 ? $concluidas / $tempo->passados : 0.0;
            $novasPorDia = $tempo->passados > 0 ? $novas / $tempo->passados : 0.0;
        }

        $equipe = $this->equipe($linhas);

        $numeros = [
            'novas'      => $novas,
            'concluidas' => $concluidas,
            'ativas'     => $ativas,
            'vencidas'   => $vencidas,
            'prazos'     => $prazos,
        ];

        return new LeituraDoRitmo(
            tempo:       $tempo,
            concluidas:  $concluidas,
            ativas:      $ativas,
            vencidas:    $vencidas,
            prazos:      $prazos,
            novas:       $novas,
            ritmoAtual:  $ritmoAtual,
            novasPorDia: $novasPorDia,
            equipe:      $equipe,
            oQue:        $this->oQue($tempo, $numeros),
            porQue:      $this->porQue($tempo, $numeros),
            acoes:       $this->acoes($numeros, $equipe),
            riscos:      $this->riscos($numeros, $equipe),
            // L146 sem a parte do estado (não classificado): vencidas ou sobrecarga
            alerta:      $vencidas > 0 || $equipe->sobrecarga !== null,
            limites:     $this->limites($tempo),
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
     * "Por que" (L80-83) reduzido ao que os números sustentam: quantas das metas criadas no
     * período seguem abertas (ou que não houve meta no período) e quantas vencidas — de
     * hoje — continuam abertas. Sem "a fila cresce" (não é medida) nem "equilibradas".
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function porQue(?TempoDoPeriodo $tempo, array $n): array
    {
        $p = [];

        if ($tempo !== null) {
            if ($n['novas'] === 0) {
                $p[] = new Leitura(self::TIPO_SEM_METAS, 0, 'Nenhuma meta foi criada no período.', ['novas' => 0]);
            } else {
                $pct = (int) round($n['ativas'] / $n['novas'] * 100);
                $p[] = new Leitura(self::TIPO_ABERTAS_NO_PERIODO, $n['ativas'] > 0 ? 1 : 0, sprintf(
                    '%d das %d %s no período %s %s (%d%%).',
                    $n['ativas'],
                    $n['novas'],
                    $this->plural($n['novas'], 'meta criada', 'metas criadas'),
                    $this->plural($n['ativas'], 'segue', 'seguem'),
                    $this->plural($n['ativas'], 'aberta', 'abertas'),
                    $pct,
                ), ['ativas' => $n['ativas'], 'novas' => $n['novas'], 'abertas_pct' => $pct]);
            }
        }

        if ($n['vencidas'] > 0) {
            // L82 sem "seguram a taxa de conclusão": vencidas são de hoje, a taxa é do período
            $p[] = new Leitura(self::TIPO_VENCIDAS, 3, sprintf(
                '%d %s com prazo já vencido %s %s.',
                $n['vencidas'],
                $this->plural($n['vencidas'], 'meta', 'metas'),
                $this->plural($n['vencidas'], 'continua', 'continuam'),
                $this->plural($n['vencidas'], 'aberta', 'abertas'),
            ), ['vencidas' => $n['vencidas']]);
        }

        return $p;
    }

    /**
     * Ações (L87-97), ordenadas por peso; a primeira é "o que fazer agora" (L141). Ficaram de
     * fora as que dependem do ritmo necessário/estado (L92, L94) e "segurar a entrada" (L93,
     * que pressupunha a fila crescendo).
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function acoes(array $n, EquipeDoRitmo $equipe): array
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

        // L95: sempre a última
        $a[] = new Leitura(self::TIPO_REAVALIAR, self::PESO_REAVALIAR, 'Reavaliar o resultado em 24 horas.', [], self::GRUPO_ACOMPANHAMENTO);

        usort($a, static fn (Leitura $x, Leitura $y): int => $y->severidade <=> $x->severidade);

        return $a;
    }

    /**
     * Riscos (L99-107), por nível; sem a "fila crescendo" (L104) e sem a projeção abaixo do
     * objetivo (L105).
     *
     * @param array<string, int> $n
     *
     * @return Leitura[]
     */
    private function riscos(array $n, EquipeDoRitmo $equipe): array
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
            'O painel não mede tendência da fila (entrada × saída): das metas criadas no período ele só sabe quais seguem '
            . 'abertas, e não vê conclusões de metas mais antigas. Vencidas e prazos próximos são contados em relação a hoje, '
            . 'não ao período, por isso aparecem só como contagem.',
        ];

        if ($tempo === null) {
            $l[] = 'Sem período (De e Até) não há dia a contar: ritmo por dia e fase do período ficam de fora.';
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

    private function plural(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }

    private function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0];
    }
}
