<?php

declare(strict_types=1);

namespace App\Sync\MessageHandler;

use App\Entity\Tenant\Tenant;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Message\ReconhecerPastasDoDrive;
use App\Sync\UseCase\ReconhecerPastasDoDriveUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Roda o reconhecimento do Drive no worker. No-op se o escritório sumiu ou desconectou o Drive. */
#[AsMessageHandler]
final class ReconhecerPastasDoDriveHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReconhecerPastasDoDriveUseCase $reconhecer,
    ) {
    }

    public function __invoke(ReconhecerPastasDoDrive $mensagem): void
    {
        $tenant = $this->em->find(Tenant::class, $mensagem->tenantId);
        if ($tenant === null) {
            return;
        }

        try {
            $this->reconhecer->executar($tenant);
        } catch (TenantSemDriveException) {
            // desconectado entre o pedido e o worker: nada a reconhecer
        }
    }
}
