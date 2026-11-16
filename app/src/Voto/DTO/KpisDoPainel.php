<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Os KPIs clicáveis da Gestão eleitoral (A8 do inventário): só os que o sistema tem dado. "Votações abertas" e
 * "Cédulas depositadas por votação". Eleitores habilitados, cadastros em análise e incidentes dependem de cadastro forte
 * e de auditoria que o Voto ainda não tem.
 */
final readonly class KpisDoPainel
{
    /**
     * @param list<LinhaDoKpi> $abertas
     * @param list<LinhaDoKpi> $cedulas
     */
    public function __construct(
        public array $abertas,
        public array $cedulas,
        public int $totalDeCedulas,
    ) {
    }

    public function votacoesAbertas(): int
    {
        return \count($this->abertas);
    }
}
