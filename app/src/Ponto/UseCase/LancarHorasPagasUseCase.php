<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\LancamentoHorasPagasInput;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Exception\HorasPagasInvalidaException;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LancarHorasPagasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
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

        // Risco ALTO (isolamento entre escritórios): o controller já deveria ter resolvido o
        // colaborador dentro do tenant, mas o UseCase não confia nisso — User não carrega tenant
        // direto (o vínculo é a pivot user_tenant), então confere aqui antes de gravar.
        if ($this->userTenantRepository->existeVinculoAtivo($colaborador, $tenant) === false) {
            throw new HorasPagasInvalidaException('Colaborador não pertence a este escritório.');
        }

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
