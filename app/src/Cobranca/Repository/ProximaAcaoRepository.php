<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusProximaAcao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProximaAcao>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ProximaAcaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProximaAcao::class);
    }

    public function salvar(ProximaAcao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ProximaAcao $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?ProximaAcao
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * A ação ATIVA (pendente) do caso, se houver. Base da guarda "máx. 1 ativa por caso" (§14) e
     * do alerta de ação atrasada. Escopada pelo tenant do caso (defesa em profundidade).
     */
    public function findAtivaDoCaso(CasoCobranca $caso): ?ProximaAcao
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.caso = :caso')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.status = :pendente')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('pendente', StatusProximaAcao::Pendente->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
