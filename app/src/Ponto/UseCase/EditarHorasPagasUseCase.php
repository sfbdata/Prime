<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class EditarHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(
        LancamentoHorasPagas $lancamento,
        LancamentoHorasPagasInput $input,
        User $autor,
        Tenant $tenant,
    ): void {
        GuardaHorasPagas::recusarOutroTenant($lancamento, $tenant);
        GuardaHorasPagas::validarInput($input);

        $lancamento->setAno($input->ano);
        $lancamento->setMes($input->mes);
        $lancamento->setMinutos($input->minutosComSinal());
        $lancamento->setMotivo(trim($input->motivo));
        $lancamento->setAtualizadoPor($autor);
        $lancamento->setAtualizadoEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
