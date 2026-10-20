<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Os dois modos do modal "Cadeado da pasta" (desenho L516-518, `cd.modos`): "Escolher por pessoa" e
 * "Ocultar para todos". Pasta sem cadeado não tem modo: é livre.
 */
enum ModoDoCadeado: string
{
    case PorPessoa = 'por_pessoa';
    case Todos     = 'todos';

    /** O selo do ⋮ (desenho L1237, `cd.menuTag`): Restrito ou Todos. */
    public function selo(): string
    {
        return match ($this) {
            self::PorPessoa => 'Restrito',
            self::Todos     => 'Todos',
        };
    }
}
