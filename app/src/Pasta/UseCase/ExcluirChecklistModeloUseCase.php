<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Tira um modelo da lista do escritório.
 *
 * Apaga só o modelo e as linhas dele. Os itens que esse modelo já criou em pastas
 * continuam onde estão: uma vez aplicado, o checklist é da PASTA, e apagar o molde
 * não pode desfazer conferência de documento que alguém já marcou.
 */
final class ExcluirChecklistModeloUseCase
{
    public function __construct(
        private readonly PastaChecklistModeloRepository $modeloRepository,
    ) {
    }

    public function executar(PastaChecklistModelo $modelo, Tenant $tenant): void
    {
        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $this->modeloRepository->remover($modelo, flush: true);
    }
}
