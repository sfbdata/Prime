<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Pedido de permitir ou não o trabalho externo no escritório (E12, P74). O escritório é o da sessão de quem pede,
 * nunca um id que venha no pedido.
 */
final class AlterarTrabalhoExternoInput
{
    public function __construct(
        public readonly User $quem,
        public readonly Tenant $tenant,
        public readonly bool $permitir,
    ) {
    }

    /**
     * Corpo JSON `{permitir: bool}`; null quando `permitir` não é booleano.
     *
     * @param array<mixed> $corpo
     */
    public static function doJson(User $quem, Tenant $tenant, array $corpo): ?self
    {
        if (!is_bool($corpo['permitir'] ?? null)) {
            return null;
        }

        return new self($quem, $tenant, $corpo['permitir']);
    }
}
