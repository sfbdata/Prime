<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Entity\Auth\User;
use App\Master\Service\RegraDosMasters;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Segunda camada dos UseCases da aba "Voto" do painel do Master: o controller já exige `ROLE_SUPER_ADMIN`, mas um
 * UseCase de plataforma não confia que todo chamador lembrou disso (o mesmo padrão da I.A. e das Indicações).
 */
final class GuardaDoMasterDoVoto
{
    public static function exigir(User $user): void
    {
        if (!in_array(RegraDosMasters::PAPEL_MASTER, $user->getRoles(), true)) {
            throw new AccessDeniedException('Só o Master libera o BlueJus Voto para um escritório.');
        }
    }
}
