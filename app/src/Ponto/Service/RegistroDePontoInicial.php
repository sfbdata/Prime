<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O estado com que a chave "Registra ponto eletrônico" nasce num vínculo novo (spec seguranca-b-jornada, regra 5 e
 * §3.1): ligada, salvo choque da jornada efetiva da pessoa neste escritório com outro escritório onde ela já registra
 * ponto. Havendo choque, nasce desligada, e o administrador vê o motivo ao tentar ligar. Ponto único de todos os
 * criadores de `UserTenant` (aceite de convite com e sem conta, criação de escritório).
 */
final class RegistroDePontoInicial
{
    public function __construct(
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
    ) {
    }

    public function registraPontoInicial(User $user, Tenant $tenant): bool
    {
        if ($user->getId() === null || $tenant->getId() === null) {
            return true;   // conta ou escritório que ainda nem foi gravado: não há jornada nem outro escritório a conferir
        }
        $trechos = $this->intervalos->daPessoaNoEscritorio($user, $tenant);

        return $this->choque->mensagem($user, $tenant, $trechos) === null;
    }
}
