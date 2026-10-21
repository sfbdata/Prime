<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contract\TravaDaConversa;

/**
 * Dublê da trava da aba Perguntar para o teste do UseCase: registra cada pedido (ids de escritório, pasta e
 * pessoa), roda o trabalho e diz, a qualquer momento, se o código está DENTRO da trava. A trava de verdade
 * (`pg_advisory_xact_lock`) se prova no `TravaPostgresDaConversaTest`.
 */
final class TravaDaConversaEspia implements TravaDaConversa
{
    /** @var list<array{int, int, int}> */
    public array $pedidos = [];

    public bool $dentro = false;

    public function emTransacaoTravada(Tenant $tenant, int $pastaId, User $autor, callable $trabalho): mixed
    {
        $this->pedidos[] = [(int) $tenant->getId(), $pastaId, (int) $autor->getId()];
        $this->dentro = true;
        try {
            return $trabalho();
        } finally {
            $this->dentro = false;
        }
    }
}
