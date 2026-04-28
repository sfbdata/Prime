<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Pasta\PastaSecao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ExcluirPastaSecaoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(PastaSecao $secao, User $autor): void
    {
        $tenant = $autor->getTenant();
        if ($tenant === null) {
            throw new \LogicException('Usuário sem tenant.');
        }

        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $this->em->remove($secao);
        $this->em->flush();
    }
}
