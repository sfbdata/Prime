<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Liquidacao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Liquidacao>
 */
// Não-final: permite substituição por mock nos testes de UseCase e da CalculadoraSaldo.
class LiquidacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Liquidacao::class);
    }

    public function salvar(Liquidacao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Liquidacao $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Liquidacao
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Σ, em centavos, do valor RECONHECIDO das liquidações do caso (o que reduz o saldo — SPEC §11;
     * distinto do valor atribuído ao bem). Escopo por tenant do caso (defesa em profundidade).
     */
    public function totalReconhecidoNoCaso(CasoCobranca $caso): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.valorReconhecido), 0)')
            ->andWhere('l.caso = :caso')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liquidações do caso (mais recentes primeiro) para a aba do detalhe (Etapa 8). Escopo por
     * tenant do caso (defesa em profundidade).
     *
     * @return Liquidacao[]
     */
    public function doCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.caso = :caso')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('l.data', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Liquidações de VÁRIOS casos numa única query — versão em LOTE para a agregação tenant-wide do
     * Dashboard (Etapa 9), evitando um `doCaso` por caso. Escopo por tenant explícito. `$casoIds` vazio → `[]`.
     *
     * @param int[] $casoIds
     *
     * @return Liquidacao[]
     */
    public function dosCasos(array $casoIds, Tenant $tenant): array
    {
        if ($casoIds === []) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->andWhere('l.caso IN (:casos)')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('casos', $casoIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }
}
