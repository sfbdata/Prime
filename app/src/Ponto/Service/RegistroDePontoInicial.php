<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;

/**
 * O estado com que a chave "Registra ponto eletrônico" nasce num vínculo novo (spec seguranca-b-jornada, regra 5 e
 * §3.1): ligada, salvo choque da jornada efetiva da pessoa neste escritório com outro escritório onde ela já registra
 * ponto. Havendo choque, nasce desligada, e o administrador vê o motivo ao tentar ligar. Ponto único de todos os
 * criadores de `UserTenant` (aceite de convite com e sem conta, criação de escritório) e da religação de um vínculo
 * (situação de volta a aprovado, convite que reativa, escritório reativado pelo Master): enquanto o vínculo ou o
 * escritório estavam fora do ar, o choque não os via, e outro escritório pode ter marcado o mesmo horário.
 */
final class RegistroDePontoInicial
{
    public function __construct(
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
        private readonly UserTenantRepository $vinculos,
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

    /** O vínculo voltou a ativo com a chave ligada: havendo choque, a chave fica desligada (quem marcou antes fica). */
    public function conferirAoReligar(UserTenant $vinculo): void
    {
        if ($vinculo->isActive() && $vinculo->isRegistraPonto() && !$this->registraPontoInicial($vinculo->getUser(), $vinculo->getTenant())) {
            $vinculo->alterarRegistroDePonto(false);
        }
    }

    /** O escritório voltou ao ar: cada vínculo ativo dele com a chave ligada passa pela mesma conferência. */
    public function conferirAoReligarOEscritorio(Tenant $tenant): void
    {
        foreach ($this->vinculos->findAtivosQueRegistramPonto($tenant) as $vinculo) {
            $this->conferirAoReligar($vinculo);
        }
    }
}
