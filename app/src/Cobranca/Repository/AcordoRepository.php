<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Acordo>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class AcordoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Acordo::class);
    }

    public function salvar(Acordo $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Acordo $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Acordo
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Dedup por (carteira + número externo) na (re)importação do relatório TOPLIFE (spec
     * `cobranca-importar-linhas-acordo.md` §3.2/§4, tarefa #7-B). A carteira do acordo é derivada
     * via `caso → objeto → carteira` (não há coluna direta). Sempre filtrado por tenant — nunca
     * cruza escritórios.
     */
    public function findOnePorNumeroExternoNaCarteira(int $numeroExterno, Carteira $carteira, Tenant $tenant): ?Acordo
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.caso', 'c')
            ->innerJoin('c.objeto', 'o')
            ->andWhere('a.numeroExterno = :numeroExterno')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('numeroExterno', $numeroExterno)
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Acordos do caso (mais recentes primeiro) para a aba do detalhe (Etapa 8). Escopo por tenant
     * do caso (defesa em profundidade).
     *
     * @return Acordo[]
     */
    public function doCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.caso = :caso')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('a.dataAcordo', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
