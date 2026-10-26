<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\ServiceDesk\Pagamento\InstrucaoDePagamento;

final class RecargaSolicitadaOutput
{
    public function __construct(
        public readonly string $referencia,
        public readonly int $valorCentavos,
        public readonly InstrucaoDePagamento $instrucao,
    ) {
    }
}
