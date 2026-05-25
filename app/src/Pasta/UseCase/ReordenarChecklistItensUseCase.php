<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ReordenarChecklistItensUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaChecklistItemRepository $checklistRepository,
    ) {
    }

    /** @param int[] $idsOrdenados IDs na nova ordem desejada */
    public function executar(Pasta $pasta, Tenant $tenant, array $idsOrdenados): void
    {
        if ($idsOrdenados === []) {
            return;
        }

        $itens = $this->checklistRepository->findByPasta($pasta, $tenant);
        $mapa = [];
        foreach ($itens as $item) {
            $mapa[$item->getId()] = $item;
        }

        $novaOrdem = 1;
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $mapa[$id]->setOrdem($novaOrdem);
            ++$novaOrdem;
        }

        $this->em->flush();
    }
}
