<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Entity\Auth\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Segunda camada dos UseCases do Master: a autorização é do controller da 5d (`ROLE_SUPER_ADMIN`), mas um UseCase de
 * plataforma não confia que todo chamador lembrou dela.
 */
final class GuardaDoMaster
{
    public static function exigir(User $user): void
    {
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw new AccessDeniedException('Só o Master altera as indicações.');
        }
    }
}
