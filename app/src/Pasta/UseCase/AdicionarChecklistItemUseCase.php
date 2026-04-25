<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaChecklistItem;
use App\Repository\Pasta\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AdicionarChecklistItemUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaChecklistItemRepository $checklistRepository,
    ) {
    }

    public function executar(Pasta $pasta, User $autor, string $titulo): PastaChecklistItem
    {
        $titulo = trim($titulo);

        if ($titulo === '') {
            throw new \InvalidArgumentException('O título do item não pode ser vazio.');
        }

        if (mb_strlen($titulo) > 255) {
            throw new \InvalidArgumentException('O título deve ter no máximo 255 caracteres.');
        }

        $tenant = $autor->getTenant();
        if ($tenant === null) {
            throw new \LogicException('Usuário sem tenant.');
        }

        $ordem = $this->checklistRepository->proximaOrdem($pasta, $tenant);

        $item = new PastaChecklistItem();
        $item->setPasta($pasta);
        $item->setTenant($tenant);
        $item->setTitulo($titulo);
        $item->setOrdem($ordem);
        $item->setConcluido(false);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
