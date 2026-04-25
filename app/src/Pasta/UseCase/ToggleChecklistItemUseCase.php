<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\PastaChecklistItem;
use Doctrine\ORM\EntityManagerInterface;

final class ToggleChecklistItemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PastaChecklistItem $item): void
    {
        $item->toggle();
        $this->em->flush();
    }
}
