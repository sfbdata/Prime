<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Pedido de ligar ou desligar uma função do Ponto no escritório (E7, chave "Escritório"). O escritório é o da sessão
 * de quem pede, nunca um id que venha no pedido.
 */
final class AlterarFuncaoDoPontoInput
{
    public function __construct(
        public readonly User $quem,
        public readonly Tenant $tenant,
        public readonly string $chave,
        public readonly bool $ligada,
    ) {
    }

    /**
     * Corpo JSON `{ligada: bool}`; null quando `ligada` não é booleano.
     *
     * @param array<mixed> $corpo
     */
    public static function doJson(User $quem, Tenant $tenant, string $chave, array $corpo): ?self
    {
        if (!is_bool($corpo['ligada'] ?? null)) {
            return null;
        }

        return new self($quem, $tenant, $chave, $corpo['ligada']);
    }
}
