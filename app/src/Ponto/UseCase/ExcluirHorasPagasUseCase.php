<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class ExcluirHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(LancamentoHorasPagas $lancamento, User $autor, Tenant $tenant): void
    {
        GuardaHorasPagas::recusarOutroTenant($lancamento, $tenant);

        $this->em->remove($lancamento);
        $this->em->flush();
    }
}
