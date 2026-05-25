<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaChecklistItem;
use Doctrine\ORM\EntityManagerInterface;

final class ExcluirChecklistItemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PastaChecklistItem $item): void
    {
        $this->em->remove($item);
        $this->em->flush();
    }
}
