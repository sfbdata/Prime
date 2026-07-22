<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CarteiraDocumento>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CarteiraDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CarteiraDocumento::class);
    }

    public function salvar(CarteiraDocumento $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CarteiraDocumento $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?CarteiraDocumento
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Documentos da carteira, em ordem cronológica (mais antigos primeiro — os novos aparecem
     * abaixo). Escopada pelo tenant da carteira (defesa em profundidade).
     *
     * @return CarteiraDocumento[]
     */
    public function listarPorCarteira(Carteira $carteira): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.carteira = :carteira')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->orderBy('d.carregadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
