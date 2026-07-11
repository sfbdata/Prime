<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Carteira>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CarteiraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Carteira::class);
    }

    public function salvar(Carteira $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Carteira $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Carteira
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Lista paginada de carteiras do tenant, com busca (nome) e faceta de modo (Etapa 8).
     * Tenant SEMPRE explícito no WHERE (defesa em profundidade além do TenantFilter).
     *
     * @param array<string, string> $filtros busca, modo
     * @return Carteira[]
     */
    public function findByFilters(array $filtros, Tenant $tenant, int $pagina, int $porPagina, string $ordenar, string $direcao): array
    {
        $qb = $this->baseFiltro($filtros, $tenant);
        $dir = strtolower($direcao) === 'desc' ? 'DESC' : 'ASC';

        // Fetch-join (addSelect) do cliente (to-one, herança JOINED PF/PJ) que o `CarteiraResumoOutput`
        // hidrata via `getNomeExibicao()` — mata o N+1 lazy por carteira da página. Só no `findByFilters`,
        // NÃO no `baseFiltro` (compartilhado com `countByFilters`/`COUNT`). To-one → seguro com `setMaxResults`.
        $qb->leftJoin('c.cliente', 'cl')->addSelect('cl');

        // COALESCE não é aceito direto no ORDER BY do DQL → alias HIDDEN (não altera o hidratado).
        if ($ordenar === 'atualizacao') {
            $qb->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')->orderBy('ordCol', $dir);
        } else {
            $qb->orderBy('c.nome', $dir);
        }

        return $qb
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, string> $filtros */
    public function countByFilters(array $filtros, Tenant $tenant): int
    {
        return (int) $this->baseFiltro($filtros, $tenant)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Opções (id + nome) das carteiras do tenant para a faceta de filtro da lista de casos (Etapa 8).
     * Retorna escalares (não entidades) — leve e sem expor Doctrine ao Twig.
     *
     * @return list<array{id: int, nome: string}>
     */
    public function opcoesFacetaDoTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id AS id', 'c.nome AS nome')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('c.nome', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /** @param array<string, string> $filtros */
    private function baseFiltro(array $filtros, Tenant $tenant): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $qb->andWhere('LOWER(c.nome) LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower($busca) . '%');
        }

        $modo = $filtros['modo'] ?? '';
        if ($modo !== '') {
            $qb->andWhere('c.modo = :modo')->setParameter('modo', $modo);
        }

        return $qb;
    }
}
