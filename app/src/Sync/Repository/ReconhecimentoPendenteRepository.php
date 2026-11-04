<?php

declare(strict_types=1);

namespace App\Sync\Repository;

use App\Entity\Tenant\Tenant;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Enum\EstadoReconhecimento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dúvidas do reconhecimento do Drive. Tenant sempre explícito nas consultas: o reconhecimento roda no worker e no
 * terminal, onde o TenantFilter pode estar desligado.
 *
 * @extends ServiceEntityRepository<ReconhecimentoPendente>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ReconhecimentoPendenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconhecimentoPendente::class);
    }

    public function salvar(ReconhecimentoPendente $pendencia, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pendencia);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarDoTenant(Tenant $tenant, int $id): ?ReconhecimentoPendente
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    /** @return list<ReconhecimentoPendente> */
    public function listarPendentesDoTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant, 'estado' => EstadoReconhecimento::Pendente], ['nomeNoDrive' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Ids de pasta do Drive que já têm registro neste escritório, em qualquer estado.
     *
     * @return array<string, true>
     */
    public function driveFolderIdsRegistrados(Tenant $tenant): array
    {
        return $this->driveFolderIds($tenant, null);
    }

    /**
     * Ids de pasta do Drive com dúvida ainda pendente neste escritório.
     *
     * @return array<string, true>
     */
    public function driveFolderIdsPendentes(Tenant $tenant): array
    {
        return $this->driveFolderIds($tenant, EstadoReconhecimento::Pendente);
    }

    /** @return array<string, true> */
    private function driveFolderIds(Tenant $tenant, ?EstadoReconhecimento $estado): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.driveFolderId')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);
        if ($estado !== null) {
            $qb->andWhere('r.estado = :estado')->setParameter('estado', $estado);
        }

        $ids = [];
        foreach ($qb->getQuery()->getSingleColumnResult() as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }
}
