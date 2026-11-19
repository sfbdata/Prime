<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Entity\Auth\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Segunda camada dos UseCases do conector de I.A. na aba "Configurações da I.A." do Master: o controller já exige
 * `ROLE_SUPER_ADMIN`, mas um UseCase de plataforma não confia que todo chamador lembrou disso (molde:
 * {@see \App\Inteligencia\Service\GuardaDoMasterDaIa}).
 */
final class GuardaDoMasterDoConector
{
    public static function exigir(User $user): void
    {
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw new AccessDeniedException('Só o Master vê o conector de I.A. dos escritórios.');
        }
    }
}
