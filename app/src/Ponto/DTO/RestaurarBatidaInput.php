<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O gestor devolve à conta da folha a batida que ele desconsiderou (E10, plano-e10-ponto §6): pela linha
 * "Desconsideradas" da ficha. `ajusteId` é a desconsideração, que precisa ainda ser a cabeça da linhagem.
 */
final class RestaurarBatidaInput
{
    public function __construct(
        public readonly Tenant $escritorio,
        public readonly User $pessoa,
        public readonly int $ajusteId,
        public readonly string $motivo,
        public readonly User $autor,
    ) {
    }
}
