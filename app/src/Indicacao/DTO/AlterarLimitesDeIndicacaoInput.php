<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** Os novos limites (contrato com a 5d): todos >= 1. O UseCase recusa também o que escapar da validação. */
final class AlterarLimitesDeIndicacaoInput
{
    public function __construct(
        #[Assert\GreaterThanOrEqual(1, message: 'O limite por dia precisa ser de pelo menos 1.')]
        public readonly int $porDia,
        #[Assert\GreaterThanOrEqual(1, message: 'O limite por mês precisa ser de pelo menos 1.')]
        public readonly int $porMes,
        #[Assert\GreaterThanOrEqual(1, message: 'O alerta precisa ser de pelo menos 1.')]
        public readonly int $alertaEm24h,
    ) {
    }
}
