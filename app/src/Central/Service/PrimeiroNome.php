<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Entity\Auth\User;

/** O primeiro nome de quem fez algo — nos avisos do sino e nas mensagens de sistema do grupo ("Ana adicionou Bruno"). */
final class PrimeiroNome
{
    public static function de(User $usuario): string
    {
        $nome = trim((string) $usuario->getFullName());

        return $nome === '' ? 'Um colega' : explode(' ', $nome)[0];
    }
}
