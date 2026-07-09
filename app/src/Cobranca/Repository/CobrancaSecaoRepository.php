<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaSecao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CobrancaSecao>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CobrancaSecaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CobrancaSecao::class);
    }

    public function salvar(CobrancaSecao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CobrancaSecao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?CobrancaSecao
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Seções do caso, ordenadas. Escopada pelo tenant do caso (defesa em profundidade).
     *
     * @return CobrancaSecao[]
     */
    public function secoesDoCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.caso = :caso')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('s.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Próxima ordem (MAX+1) das seções do caso — escopada por caso+tenant. */
    public function proximaOrdem(CasoCobranca $caso): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.ordem)')
            ->andWhere('s.caso = :caso')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
