<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

use App\Pagamento\Enum\ResultadoDaConfirmacao;

final class ConfirmarPagamentoOutput
{
    public function __construct(
        public readonly ResultadoDaConfirmacao $resultado,
        public readonly ?int $pagamentoId = null,
        /** Quanto pagou a mais, em centavos (só em `ConfirmadoComDiferenca`). */
        public readonly int $diferencaCentavos = 0,
    ) {
    }
}
