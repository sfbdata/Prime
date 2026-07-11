<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * Lista paginada de casos do tenant, com busca (objeto/pessoa cobrada), faceta de status
     * e faceta de carteira (Etapa 8). Tenant SEMPRE explícito no WHERE. Facetas derivadas do
     * saldo (vencido, pronto p/ encerrar) NÃO entram aqui: o saldo é derivado por
     * `CalculadoraSaldo`, não é coluna — filtro/ordenação por saldo fica p/ a Etapa 9 (dashboard).
     *
     * @param array<string, string> $filtros busca, status, carteira
     * @return CasoCobranca[]
     */
    public function findByFilters(array $filtros, Tenant $tenant, int $pagina, int $porPagina, string $ordenar, string $direcao): array
    {
        $qb = $this->baseFiltro($filtros, $tenant);
        $dir = strtolower($direcao) === 'asc' ? 'ASC' : 'DESC';

        // Fetch-join (addSelect) das associações to-one que o `CasoResumoOutput` hidrata (objeto/carteira/
        // pessoa) — mata o N+1 de hidratação lazy por caso da página. `o`/`p` já vêm de `baseFiltro`. Todas
        // ManyToOne → seguras com `setMaxResults` (não multiplicam linhas, sem paginação em memória).
        $qb->addSelect('o', 'p')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart');

        // COALESCE não é aceito direto no ORDER BY do DQL → alias HIDDEN (não altera o hidratado).
        if ($ordenar === 'criacao') {
            $qb->orderBy('c.criadoEm', $dir);
        } else {
            $qb->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')->orderBy('ordCol', $dir);
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
     * Casos de uma carteira (mais recentes primeiro) para a visão da carteira (Etapa 8). Escopo por
     * tenant da carteira. Sem paginação — a carteira do MVP tem volume moderado; se crescer, migrar
     * para paginação (follow-up de perf).
     *
     * @return CasoCobranca[]
     */
    public function daCarteira(Carteira $carteira): array
    {
        // Fetch-join (addSelect) de objeto/carteira/pessoa: o `CasoResumoOutput` monta a linha a partir
        // dessas associações to-one; sem o fetch-join cada caso dispararia hidratação lazy (a pessoa é
        // ~distinta por caso → N+1). ManyToOne → não multiplicam linhas; sem paginação aqui → seguro.
        return $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->addSelect('o')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart')
            ->leftJoin('c.pessoaCobradaAtual', 'p')
            ->addSelect('p')
            ->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->orderBy('ordCol', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * TODOS os casos do tenant (opcionalmente de uma carteira), mais recentes primeiro — base da
     * agregação tenant-wide do Dashboard e da Central de Alertas (Etapa 9). Tenant SEMPRE explícito no
     * WHERE. Inclui casos encerrados de propósito: o "valor total recuperado" (all-time) precisa deles;
     * a derivação por-caso naturalmente zera saldo/alertas do encerrado. Sem paginação — o Dashboard
     * consome tudo; se o volume crescer, migrar para agregação materializada (follow-up de perf, coerente
     * com a nota do `findByFilters`). Saldo/alertas continuam derivados por serviço, nunca por SQL aqui.
     *
     * @return CasoCobranca[]
     */
    public function doTenant(Tenant $tenant, ?Carteira $carteira = null): array
    {
        // Fetch-join (addSelect) de objeto/carteira/pessoa: os consumidores tenant-wide (Central de Alertas,
        // Dashboard) montam Output DTOs a partir dessas associações to-one; sem o fetch-join cada caso
        // dispararia a hidratação lazy (N+1). São ManyToOne → não multiplicam linhas nem afetam a ordenação.
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->addSelect('o')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart')
            ->leftJoin('c.pessoaCobradaAtual', 'p')
            ->addSelect('p')
            ->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ordCol', 'DESC');

        if ($carteira !== null) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', $carteira);
        }

        return $qb->getQuery()->getResult();
    }

    /** @param array<string, string> $filtros */
    private function baseFiltro(array $filtros, Tenant $tenant): QueryBuilder
    {
        // INNER JOIN é seguro: objeto e pessoaCobradaAtual são JoinColumn(nullable: false) —
        // um caso persistido sempre tem ambos (o `?` no type-hint é só o estado pré-persist).
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->innerJoin('c.pessoaCobradaAtual', 'p')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $qb->andWhere('LOWER(o.identificacao) LIKE :busca OR LOWER(o.descricao) LIKE :busca OR LOWER(p.nome) LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower($busca) . '%');
        }

        $status = $filtros['status'] ?? '';
        if ($status !== '') {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $carteira = $filtros['carteira'] ?? '';
        if ($carteira !== '' && ctype_digit($carteira)) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', (int) $carteira);
        }

        return $qb;
    }
}
