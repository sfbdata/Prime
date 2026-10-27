<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Repository\ModeloDePecaRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Tira um modelo do banco do escritório. As estrelas dele caem junto (CASCADE no banco). Peça já
 * montada a partir do modelo (M2) é da pasta e não depende dele.
 */
final class ExcluirModeloDePecaUseCase
{
    public function __construct(
        private readonly ModeloDePecaRepository $modeloRepository,
    ) {
    }

    public function executar(ModeloDePeca $modelo, Tenant $tenant): void
    {
        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $this->modeloRepository->remover($modelo, flush: true);
    }
}
