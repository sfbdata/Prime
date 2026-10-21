<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

use App\Indicacao\Entity\IndicacaoConfig;

/** Os limites vigentes (contrato com a 5d, spec `indicar-bluejus.md`). */
final class LimitesDeIndicacaoOutput
{
    public function __construct(
        public readonly bool $pausadoParaTodos,
        public readonly int $porDia,
        public readonly int $porMes,
        public readonly int $alertaEm24h,
    ) {
    }

    public static function fromEntity(IndicacaoConfig $config): self
    {
        return new self($config->isPausadoParaTodos(), $config->getLimiteDia(), $config->getLimiteMes(), $config->getAlerta24h());
    }
}
