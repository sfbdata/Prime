<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * Uma pessoa que indicou, no card do Master (contrato com a 5d). `escritorio` é o da indicação mais recente (nulo se
 * foi sem escritório ou se o escritório foi purgado); `acimaDoAlerta` = `ultimas24h` >= alerta de 24h.
 */
final class IndicadorOutput
{
    public function __construct(
        public readonly int $userId,
        public readonly string $nome,
        public readonly string $email,
        public readonly ?string $escritorio,
        public readonly int $total,
        public readonly int $ultimas24h,
        public readonly int $ultimos30d,
        public readonly int $contasCriadas,
        public readonly ?\DateTimeImmutable $ultimaEm,
        public readonly bool $pausado,
        public readonly bool $acimaDoAlerta,
    ) {
    }
}
