<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de ligar ou desligar um tema comemorativo (POST /master/temas/{id}/ligado, JSON `{ligado: bool}`).
 */
final readonly class AlternarTemaInput
{
    public function __construct(
        /** Quem liga ou desliga: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $id,
        public bool $ligado,
    ) {
    }

    /**
     * Nulo quando o corpo não diz se o tema fica ligado ou desligado.
     *
     * @param array<mixed> $corpo
     */
    public static function doJson(?User $ator, string $id, array $corpo): ?self
    {
        if (!is_bool($corpo['ligado'] ?? null)) {
            return null;
        }

        return new self($ator, $id, $corpo['ligado']);
    }
}
