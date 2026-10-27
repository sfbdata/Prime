<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Ligar ou desligar a chave "Registra ponto eletrônico" de uma pessoa num escritório (spec seguranca-b-jornada). */
final class AlterarRegistroDePontoInput
{
    public function __construct(
        public readonly User $pessoa,
        public readonly Tenant $escritorio,
        public readonly bool $registraPonto,
    ) {
    }
}
