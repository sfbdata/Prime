<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de mostrar ou ocultar uma seccional no mapa (POST /master/seccionais/{uf}/visibilidade com `{visivel: bool}`),
 * vindo do botão direito no chip ({@see \App\Master\UseCase\DefinirVisibilidadeDaSeccionalUseCase}). O corpo leva o
 * estado desejado, não um "alternar": repetir o pedido não muda nada. `doJson()` só aceita booleano; o resto vira nulo
 * e o UseCase recusa.
 */
final readonly class DefinirVisibilidadeDaSeccionalInput
{
    public function __construct(
        /** Quem define: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public ?bool $visivel,
    ) {
    }

    /** @param array<string, mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        $visivel = $corpo['visivel'] ?? null;

        return new self($ator, $uf, is_bool($visivel) ? $visivel : null);
    }
}
