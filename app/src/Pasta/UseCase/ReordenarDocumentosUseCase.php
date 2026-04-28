<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use Doctrine\ORM\EntityManagerInterface;

final class ReordenarDocumentosUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param int[] $idsOrdenados IDs na nova ordem desejada */
    public function executar(Pasta $pasta, array $idsOrdenados): void
    {
        if ($idsOrdenados === []) {
            return;
        }

        $docs = $this->em->getRepository(PastaDocumento::class)->findBy(['pasta' => $pasta]);

        $mapa = [];
        foreach ($docs as $doc) {
            $mapa[(int) $doc->getId()] = $doc;
        }

        $ordem = 1;
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $mapa[$id]->setOrdem($ordem);
            ++$ordem;
        }

        $this->em->flush();
    }
}
