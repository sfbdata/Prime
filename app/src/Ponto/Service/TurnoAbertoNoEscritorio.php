<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * A pessoa tem um turno aberto NESTE escritório: a última batida dela aqui, hoje até `$agora`, é entrada ou retorno
 * (spec seguranca-b-jornada §3.3). Então a saída e a ida ao repouso fecham esse turno e passam livres da chave e do
 * choque: nunca prender o ponto aberto. O "hoje" é o do relógio do servidor, o mesmo da folha e da trava da entrada.
 */
final class TurnoAbertoNoEscritorio
{
    public function __construct(
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    public function estaAberto(User $user, Tenant $tenant, \DateTimeImmutable $agora): bool
    {
        $ultima = $this->registros->ultimaBatidaNoTenant($user, $tenant, $agora->setTime(0, 0, 0), $agora);

        return in_array($ultima?->getTipo(), [RegistroPonto::TIPO_ENTRADA, RegistroPonto::TIPO_RETORNO], true);
    }
}
