<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\ServiceDesk\Enum\ResultadoDaConfirmacao;

final class RecargaConfirmadaOutput
{
    /** @param int|null $saldoCentavos saldo do escritório depois da operação (null quando a recarga não existe) */
    public function __construct(
        public readonly ResultadoDaConfirmacao $resultado,
        public readonly ?int $saldoCentavos,
        /** Quanto veio a mais que o pedido (só em `Confirmada`): o chamador avisa o Master da diferença. */
        public readonly int $diferencaCentavos = 0,
    ) {
    }

    /** Só neste caso o saldo subiu agora. */
    public function creditou(): bool
    {
        return ResultadoDaConfirmacao::Confirmada === $this->resultado;
    }
}
