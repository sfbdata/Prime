<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\Invitation;

/**
 * O que criar e reenviar convite devolvem ao controller: o convite e o token PURO do link. O banco só tem o
 * hash (spec `docs/specs/seguranca-a-convites.md`, raiz B), então este objeto é o único lugar de onde o
 * `ConviteMailer` tira o token. Não vai para tela, DTO de lista nem JSON.
 */
final readonly class ConviteEmitido
{
    public function __construct(
        public Invitation $convite,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
