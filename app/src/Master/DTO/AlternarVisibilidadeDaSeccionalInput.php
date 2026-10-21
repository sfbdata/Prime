<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de mostrar ou ocultar uma seccional no mapa (POST /master/seccionais/{uf}/visibilidade, sem corpo), vindo do
 * botão direito no chip ({@see \App\Master\UseCase\AlternarVisibilidadeDaSeccionalUseCase}).
 */
final readonly class AlternarVisibilidadeDaSeccionalInput
{
    public function __construct(
        /** Quem alterna: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
    ) {
    }
}
