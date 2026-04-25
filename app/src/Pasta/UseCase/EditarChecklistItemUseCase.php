<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\PastaChecklistItem;
use Doctrine\ORM\EntityManagerInterface;

final class EditarChecklistItemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PastaChecklistItem $item, string $titulo): void
    {
        $titulo = trim($titulo);

        if ($titulo === '') {
            throw new \InvalidArgumentException('O título do item não pode ser vazio.');
        }

        if (mb_strlen($titulo) > 255) {
            throw new \InvalidArgumentException('O título deve ter no máximo 255 caracteres.');
        }

        $item->setTitulo($titulo);
        $this->em->flush();
    }
}
