<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Tira publicações do Diário da OAB da lista (exclusão lógica). Só as do próprio usuário. */
final class ApagarPublicacoesDoDiarioUseCase
{
    public function __construct(private readonly PublicacaoDoDiarioDaOabRepository $repository)
    {
    }

    /** @param list<int> $ids */
    public function executar(Tenant $tenant, User $usuario, array $ids, \DateTimeImmutable $agora): int
    {
        return $this->repository->marcarExcluidas($tenant, $usuario, $ids, $agora);
    }

    public function limparLidas(Tenant $tenant, User $usuario, \DateTimeImmutable $agora): int
    {
        return $this->repository->marcarLidasComoExcluidas($tenant, $usuario, $agora);
    }
}
