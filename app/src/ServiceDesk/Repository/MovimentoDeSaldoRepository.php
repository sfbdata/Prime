<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MovimentoDeSaldo>
 */
class MovimentoDeSaldoRepository extends ServiceEntityRepository
{
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MovimentoDeSaldo::class);
    }

    public function salvar(MovimentoDeSaldo $movimento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($movimento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(MovimentoDeSaldo $movimento, bool $flush = false): void
    {
        $this->getEntityManager()->remove($movimento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca pela referência da recarga, travando a linha (use dentro de `TransacaoDoRazao::emTransacao`).
     * Atravessa os escritórios de propósito: o webhook do gateway não tem tenant, e o Master pode ter um
     * em contexto sem que a recarga seja dele. Por isso desliga o TenantFilter só durante a consulta.
     */
    public function recargaPorReferenciaTravando(string $referencia): ?MovimentoDeSaldo
    {
        return $this->semFiltroDeTenant(fn (): ?MovimentoDeSaldo => $this->createQueryBuilder('m')
            ->andWhere('m.referencia = :referencia')
            ->setParameter('referencia', $referencia)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult());
    }

    public function existeComIdExterno(string $idExterno): bool
    {
        return $this->semFiltroDeTenant(fn (): bool => null !== $this->findOneBy(['idExterno' => $idExterno]));
    }

    public function quantasRecargasPendentesDoEscritorio(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.confirmadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<MovimentoDeSaldo> mais recentes primeiro */
    public function recentesDoEscritorio(Tenant $tenant, int $limite): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.criadoEm', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function somaPendentesDoEscritorio(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.valorCentavos), 0)')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.confirmadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
