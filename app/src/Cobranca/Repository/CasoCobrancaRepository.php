<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CasoCobranca>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CasoCobrancaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CasoCobranca::class);
    }

    public function salvar(CasoCobranca $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CasoCobranca $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?CasoCobranca
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Casos ATIVOS de um objeto (modo B pode ter vários; SPEC §6). Escopo por tenant do objeto
     * (defesa em profundidade — o objeto já é tenant-bound).
     *
     * @return CasoCobranca[]
     */
    public function casosAtivosDoObjeto(ObjetoCobranca $objeto): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.status = :ativo')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->setParameter('ativo', StatusCaso::Ativo->value)
            ->getQuery()
            ->getResult();
    }

    /** Há caso ativo para o objeto? Usado pela guarda do modo A (uma cobrança ativa por objeto). */
    public function existeCasoAtivoParaObjeto(ObjetoCobranca $objeto): bool
    {
        $total = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.status = :ativo')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->setParameter('ativo', StatusCaso::Ativo->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $total > 0;
    }
}
