<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\StatusRevisao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RevisaoPessoaCobrada>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class RevisaoPessoaCobradaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevisaoPessoaCobrada::class);
    }

    public function salvar(RevisaoPessoaCobrada $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(RevisaoPessoaCobrada $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?RevisaoPessoaCobrada
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Há revisão PENDENTE para o caso? Base do alerta de revisão (§8/§14) — que cessa quando a
     * revisão é resolvida. Escopado pelo tenant do caso (defesa em profundidade).
     */
    public function existePendenteDoCaso(CasoCobranca $caso): bool
    {
        $total = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.caso = :caso')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.status = :pendente')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('pendente', StatusRevisao::Pendente->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $total > 0;
    }

    /**
     * Revisões pendentes do caso (para listagem/telas). Escopado pelo tenant do caso.
     *
     * @return RevisaoPessoaCobrada[]
     */
    public function pendentesDoCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.caso = :caso')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.status = :pendente')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('pendente', StatusRevisao::Pendente->value)
            ->orderBy('r.criadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
