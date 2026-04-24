<?php
declare(strict_types=1);
namespace App\Profile\UseCase;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;

final class ObterOuCriarPerfilUseCase
{
    public function __construct(
        private readonly UserProfileRepository $repository,
    ) {
    }

    public function executar(User $user): UserProfile
    {
        $perfil = $this->repository->buscarPorUsuario($user);

        if ($perfil === null) {
            $perfil = new UserProfile($user);
            $this->repository->salvar($perfil, flush: true);
        }

        return $perfil;
    }
}
