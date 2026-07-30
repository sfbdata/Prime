<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use Doctrine\ORM\EntityManagerInterface;

final class LancarHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws HorasPagasInvalidaException
     */
    public function __invoke(
        LancamentoHorasPagasInput $input,
        User $colaborador,
        User $autor,
        Tenant $tenant,
    ): LancamentoHorasPagas {
        GuardaHorasPagas::recusarAutoLancamento($colaborador, $autor);
        GuardaHorasPagas::validarInput($input);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno($input->ano);
        $lancamento->setMes($input->mes);
        $lancamento->setMinutos($input->minutosComSinal());
        $lancamento->setMotivo(trim($input->motivo));
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());

        $this->em->persist($lancamento);
        $this->em->flush();

        return $lancamento;
    }
}
