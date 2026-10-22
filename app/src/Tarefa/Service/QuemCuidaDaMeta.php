<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;

/**
 * Quem "cuida" da meta: um responsável ou o criador. É quem dá ciência ao abrir a meta (C14) e quem certifica o
 * cumprimento de um despacho (C15), na spec `minhas-metas-esteira-2210.md`. Quem só olha (um administrador, por
 * exemplo) não entra.
 */
final class QuemCuidaDaMeta
{
    public function ehResponsavelOuCriador(Tarefa $meta, User $pessoa): bool
    {
        if (self::mesmaPessoa($meta->getCriadoPor(), $pessoa)) {
            return true;
        }

        foreach ($meta->getResponsaveis() as $responsavel) {
            if (self::mesmaPessoa($responsavel, $pessoa)) {
                return true;
            }
        }

        return false;
    }

    private static function mesmaPessoa(?User $a, User $b): bool
    {
        return $a !== null && ($a === $b || ($a->getId() !== null && $a->getId() === $b->getId()));
    }
}
