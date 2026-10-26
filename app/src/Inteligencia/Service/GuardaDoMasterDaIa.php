<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Segunda camada dos UseCases da aba "Configurações da I.A." do Master: o controller já exige `ROLE_SUPER_ADMIN`, mas
 * um UseCase de plataforma não confia que todo chamador lembrou disso (o mesmo padrão das Indicações).
 */
final class GuardaDoMasterDaIa
{
    public static function exigir(User $user): void
    {
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw new AccessDeniedException('Só o Master altera a BlueJus IA da plataforma.');
        }
    }
}
