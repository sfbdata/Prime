<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Marca publicações do Diário da OAB como lidas ou não lidas. Só mexe nas do próprio usuário. */
final class AtualizarLeituraDoDiarioUseCase
{
    public function __construct(private readonly PublicacaoDoDiarioDaOabRepository $repository)
    {
    }

    /** @param list<int> $ids */
    public function executar(Tenant $tenant, User $usuario, array $ids, bool $lida, \DateTimeImmutable $agora): int
    {
        return $this->repository->atualizarLeitura($tenant, $usuario, $ids, $lida ? $agora : null);
    }
}
