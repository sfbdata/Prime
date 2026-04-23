<?php

namespace App\Profile\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ObterOuCriarPerfilUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(User $user): UserProfile
    {
        $perfil = $this->repository->findOneBy(['user' => $user]);

        if ($perfil === null) {
            $perfil = new UserProfile($user);
            $this->em->persist($perfil);
            $this->em->flush();
        }

        return $perfil;
    }
}
