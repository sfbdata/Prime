<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AcordoDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcordoDocumento>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class AcordoDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcordoDocumento::class);
    }

    public function salvar(AcordoDocumento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AcordoDocumento $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?AcordoDocumento
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Documentos do acordo, em ordem cronológica (mais antigos primeiro — os novos aparecem
     * abaixo). Escopada pelo tenant do acordo (defesa em profundidade).
     *
     * @return AcordoDocumento[]
     */
    public function listarPorAcordo(Acordo $acordo): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.acordo = :acordo')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('acordo', $acordo)
            ->setParameter('tenant', $acordo->getTenant())
            ->orderBy('d.carregadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
