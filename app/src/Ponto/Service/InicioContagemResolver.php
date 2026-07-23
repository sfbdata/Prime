<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * Resolve a partir de quando a folha de ponto conta para um colaborador num escritório.
 *
 * A contagem abre no registro de ponto MAIS ANTIGO — seja uma batida real, seja uma justificativa
 * já abonada (um abono deferido também é registro de ponto). Antes disso não há controle de ponto
 * para a pessoa, então não há meta a cobrar.
 *
 * Data de admissão e data de cadastro NÃO entram nesta conta: a primeira diz desde quando a pessoa
 * é funcionária, a segunda desde quando o registro existe — nenhuma das duas responde "desde quando
 * há controle de ponto para ela". Usá-las já causou incidente em produção (colaboradores admitidos
 * antes de o sistema existir acumulando centenas de horas negativas).
 */
final class InicioContagemResolver
{
    public function __construct(
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
    ) {}

    /** null = colaborador sem nenhum registro de ponto: não há o que contar. */
    public function resolver(User $user, Tenant $tenant): ?\DateTimeImmutable
    {
        $primeiraBatida = $this->registroPontoRepository->findDataPrimeiraBatida($user, $tenant);
        $primeiroAbono  = $this->justificativaPontoRepository->findDataPrimeiraAbonada($user, $tenant);

        if ($primeiraBatida === null) {
            return $primeiroAbono;
        }

        if ($primeiroAbono === null) {
            return $primeiraBatida;
        }

        return $primeiroAbono < $primeiraBatida ? $primeiroAbono : $primeiraBatida;
    }
}
