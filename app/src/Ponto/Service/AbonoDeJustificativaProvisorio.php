<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Provisório: o abono em lote da aba Equipe passa pelo AnalisarJustificativaUseCase, que está sendo extraído do
 * TenantController em outra frente. Até ele chegar, abonar por aqui falha alto em vez de abonar por outra regra.
 */
#[AsAlias(id: AbonoDeJustificativa::class)]
final class AbonoDeJustificativaProvisorio implements AbonoDeJustificativa
{
    public function abonar(JustificativaPonto $justificativa, Tenant $escritorio, User $analista): ?string
    {
        throw new \LogicException('ligar ao AnalisarJustificativaUseCase');
    }
}
