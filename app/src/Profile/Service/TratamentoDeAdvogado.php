<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Entity\Auth\User;
use App\Profile\Enum\Genero;

/**
 * "Dr." ou "Dra." antes do nome de quem é advogado (desenho 04 de 22/10, `trat()`, L155-162; spec
 * genero-no-cadastro, regra 4). Advogado = OAB no cadastro (`User::oabNumero` preenchido). O gênero é o que a
 * pessoa declarou: aqui não se adivinha pelo nome (decisão do dono). Sem OAB, sem gênero ou com o legado
 * `nao_informado`: sem título.
 *
 * Devolve só o código (`dra` / `dr`); a tela escreve o título pelo catálogo de idioma.
 */
final class TratamentoDeAdvogado
{
    public const DRA = 'dra';
    public const DR = 'dr';

    public static function de(User $user): ?string
    {
        if (trim((string) $user->getOabNumero()) === '') {
            return null;
        }

        return match ($user->getProfile()?->getGenero()) {
            Genero::Feminino  => self::DRA,
            Genero::Masculino => self::DR,
            default           => null,
        };
    }
}
