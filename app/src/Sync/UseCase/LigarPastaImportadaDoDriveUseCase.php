<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Sync\Exception\ImportacaoDoDriveRecusadaException;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\LoteDoDrive;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Importar do Google Drive" (spec importar-do-drive.md, decisão do Samuel de 04/11/2026): a pasta criada pelo
 * lote fica ligada à pasta de origem no Drive. O envio automático passa a usar essa pasta (não cria outra) e
 * não a renomeia.
 *
 * Duas etapas, porque a pasta só nasce no meio: {@see conferir()} antes de criar (pasta fora da pasta do
 * escritório recusa o pedido inteiro) e {@see ligar()} depois, antes do envio automático ser despachado.
 */
final class LigarPastaImportadaDoDriveUseCase
{
    public function __construct(
        private readonly GoogleDriveClientFactoryInterface $drives,
        private readonly LoteDoDrive $loteDoDrive,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws ImportacaoDoDriveRecusadaException
     * @throws \App\Sync\Exception\TenantSemDriveException
     */
    public function conferir(Tenant $tenant, string $driveFolderId): void
    {
        $this->loteDoDrive->conferirPasta($this->drives->paraTenant($tenant), $driveFolderId);
    }

    /**
     * Grava o vínculo. A pasta do Drive só pode estar ligada a uma pasta do sistema: se outra já a usa (o mesmo
     * lote importado duas vezes), esta fica sem vínculo e o envio automático cria uma pasta nova, como antes.
     *
     * @return bool se ligou
     */
    public function ligar(Pasta $pasta, string $driveFolderId): bool
    {
        if ($this->em->getRepository(Pasta::class)->count(['driveFolderId' => $driveFolderId]) > 0) {
            return false;
        }
        $pasta->ligarAPastaDoDrive($driveFolderId);
        $this->em->flush();

        return true;
    }
}
