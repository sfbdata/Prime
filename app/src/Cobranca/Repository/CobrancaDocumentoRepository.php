<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CobrancaDocumento>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CobrancaDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CobrancaDocumento::class);
    }

    public function salvar(CobrancaDocumento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CobrancaDocumento $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?CobrancaDocumento
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Documentos do caso, ordenados. Escopada pelo tenant do caso (defesa em profundidade).
     *
     * @return CobrancaDocumento[]
     */
    public function documentosDoCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.caso = :caso')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('d.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Próxima ordem (MAX+1) dos documentos do caso — escopada por caso+tenant. */
    public function proximaOrdem(CasoCobranca $caso): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.ordem)')
            ->andWhere('d.caso = :caso')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
