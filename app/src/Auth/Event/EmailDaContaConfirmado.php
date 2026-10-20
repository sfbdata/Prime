<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * A pessoa confirmou o e-mail de uma conta já criada (desenho 10): pelo clique no link de confirmação ou trocando a
 * senha com o código que chegou no e-mail. Despachado DEPOIS do commit, uma vez só por conta: o segundo clique, ou o
 * código depois do link, não despacha de novo.
 */
final class EmailDaContaConfirmado
{
    public function __construct(
        public readonly int $userId,
    ) {
    }
}
