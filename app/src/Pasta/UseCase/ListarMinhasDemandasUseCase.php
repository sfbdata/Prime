<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Repository\PastaRepository;

final class ListarMinhasDemandasUseCase
{
    public function __construct(
        private readonly PastaRepository $repository,
    ) {}

    /** @return Pasta[] */
    public function executar(User $usuario, ?string $cliente, ?string $prioridade): array
    {
        return $this->repository->findAtivasPorResponsavel($usuario, $cliente, $prioridade);
    }
}
