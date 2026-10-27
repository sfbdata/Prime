<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Pedido de redistribuição das metas de uma pessoa: escritório e gestor vêm da sessão, nunca do corpo; a origem vem da
 * URL e os movimentos (meta e destino) do corpo. Tudo é conferido no UseCase (IDOR).
 */
final readonly class RedistribuirMetasInput
{
    /** @param list<array{meta_id: int, destino_id: int}> $movimentos */
    public function __construct(
        public Tenant $tenant,
        public User $ator,
        public int $origemId,
        public array $movimentos,
        public \DateTimeImmutable $agora,
    ) {
    }
}
