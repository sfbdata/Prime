<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\ModeloDoEscritorio;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os modelos do escritório. Toda leitura leva o escritório à mão, além do `TenantFilter`: id de outro escritório volta
 * nulo (404).
 *
 * @extends ServiceEntityRepository<ModeloDoEscritorio>
 */
class ModeloDoEscritorioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeloDoEscritorio::class);
    }

    public function doEscritorio(int $id, Tenant $tenant): ?ModeloDoEscritorio
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A aba Modelos: o alterado por último primeiro (o salvo sobe, como o desenho). Um escritório tem poucos modelos,
     * e a tela mostra todos.
     *
     * @return list<ModeloDoEscritorio>
     */
    public function doEscritorioEmOrdem(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.alteradoEm', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function salvar(ModeloDoEscritorio $modelo): void
    {
        $this->getEntityManager()->persist($modelo);
        $this->getEntityManager()->flush();
    }

    public function remover(ModeloDoEscritorio $modelo): void
    {
        $this->getEntityManager()->remove($modelo);
        $this->getEntityManager()->flush();
    }
}
