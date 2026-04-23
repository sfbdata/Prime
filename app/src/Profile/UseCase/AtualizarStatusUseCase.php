<?php

namespace App\Profile\UseCase;

use App\Entity\Auth\UserProfile;
use Doctrine\ORM\EntityManagerInterface;

final class AtualizarStatusUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(UserProfile $perfil, ?string $status): void
    {
        $perfil->setStatus($status !== '' ? mb_substr($status ?? '', 0, 255) : null);
        $this->em->flush();
    }
}
