<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Enum\FormaDeRecarga;

final class SolicitarRecargaInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?User $solicitante,
        public readonly int $valorCentavos,
        public readonly FormaDeRecarga $forma,
        /** Referência já emitida e assinada na tela (PIX mostrado antes de gravar); nula = o caso gera uma. */
        public readonly ?string $referencia = null,
    ) {
    }
}
