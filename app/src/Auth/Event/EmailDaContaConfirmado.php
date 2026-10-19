<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * A pessoa clicou no link de confirmação do e-mail de uma conta já criada (desenho 10). Despachado DEPOIS do commit,
 * uma vez só por conta: o segundo clique não despacha de novo.
 */
final class EmailDaContaConfirmado
{
    public function __construct(
        public readonly int $userId,
    ) {
    }
}
