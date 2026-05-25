<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

final class ListarMinhasDemandasUseCase
{
    public function __construct(
        private readonly PastaRepository $repository,
    ) {}

    /** @return Pasta[] */
    public function executar(User $usuario, Tenant $tenant, ?string $cliente, ?string $prioridade): array
    {
        return $this->repository->findAtivasPorResponsavel($usuario, $tenant, $cliente, $prioridade);
    }
}
