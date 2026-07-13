<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PrioridadePasta;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tarefa>
 */
class TarefaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tarefa::class);
    }

    /**
     * @return Tarefa[]
     */
    public function findByResponsavel(User $usuario): array
    {
        return $this->createQueryBuilder('t')
            ->where(':usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lista as tarefas do usuário (responsável ou criador) com busca + facetas, para a
     * tela "Minhas Metas". Mantém a ordenação por dataCriacao DESC; o agrupamento por
     * status continua na view. Tarefa é TenantAware — o escopo de tenant vem do
     * TenantFilter no fluxo web.
     *
     * A condição base é parentetizada de propósito: sem os parênteses, o `andWhere` das
     * facetas produziria `A OR B AND C` (precedência errada), vazando tarefas de terceiros.
     *
     * @param array<string, string> $filtros  busca, status, prioridade, prazo
     * @return Tarefa[]
     */
    public function findByResponsavelComFiltros(User $usuario, array $filtros): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('(:usuario MEMBER OF t.responsaveis OR t.criadoPor = :usuario)')
            ->setParameter('usuario', $usuario);

        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $qb->andWhere('UNACCENT(LOWER(t.titulo)) LIKE UNACCENT(:busca) OR UNACCENT(LOWER(t.descricao)) LIKE UNACCENT(:busca)')
               ->setParameter('busca', '%' . mb_strtolower($busca) . '%');
        }

        if (!empty($filtros['status'])) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $filtros['status']);
        }

        if (!empty($filtros['prioridade'])) {
            $prioridade = PrioridadePasta::tryFrom((string) $filtros['prioridade']);
            if ($prioridade !== null) {
                $qb->join('t.pasta', 'p_prio')
                   ->andWhere('p_prio.prioridade = :prioridade')
                   ->setParameter('prioridade', $prioridade);
            }
        }

        $prazo = (string) ($filtros['prazo'] ?? '');
        if ($prazo !== '') {
            $agora = new \DateTimeImmutable();

            if ($prazo === 'vencidas') {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo < :agora AND t.status != :concluida')
                   ->setParameter('agora', $agora)
                   ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);
            }

            if ($prazo === 'proximas') {
                $qb->andWhere('t.prazo IS NOT NULL AND t.prazo >= :agora AND t.prazo <= :limite AND t.status != :concluida')
                   ->setParameter('agora', $agora)
                   ->setParameter('limite', $agora->modify('+7 days'))
                   ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);
            }

            if ($prazo === 'sem') {
                $qb->andWhere('t.prazo IS NULL');
            }
        }

        return $qb->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Tarefa[]
     */
    public function findByProcesso(Processo $processo): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->join('p.pastaProcessos', 'pp')
            ->where('pp.processo = :processo')
            ->setParameter('processo', $processo)
            ->orderBy('t.dataCriacao', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Tarefa $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     */
    public function countMetasAtivas(Tenant $tenant, array $filtros = []): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate, responsavel)
     * @return array{concluidas: int, total: int}
     */
    public function countMetasGlobal(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $this->aplicarFiltrosDashboard($qb, $filtros, true);

        $total = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $concluidas = (int) (clone $qb)
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :concluida')
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->getQuery()
            ->getSingleScalarResult();

        return ['concluidas' => $concluidas, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @return array<int, int>  userId => total (todos os status)
     */
    public function countPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('u.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filtros  filtros do Dashboard (data_de, data_ate)
     * @return array<int, int>  userId => total (status != concluida)
     */
    public function countAtivasPorResponsavel(Tenant $tenant, array $filtros = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->groupBy('u.id');

        $this->aplicarFiltrosDashboard($qb, $filtros, false);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo < $referencia)
     */
    public function countVencidasPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo < :referencia')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countVencidas(Tenant $tenant, \DateTimeImmutable $referencia): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo < :referencia')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, int>  userId => total (ativas com prazo entre $referencia e $referencia+7 dias)
     */
    public function countPrazosProximosPorResponsavel(Tenant $tenant, \DateTimeImmutable $referencia): array
    {
        $limite = $referencia->modify('+7 days');

        $rows = $this->createQueryBuilder('t')
            ->select('u.id AS responsavel_id, COUNT(t.id) AS total')
            ->join('t.pasta', 'p')
            ->join('t.responsaveis', 'u')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('t.status != :concluida')
            ->andWhere('t.prazo IS NOT NULL')
            ->andWhere('t.prazo >= :referencia')
            ->andWhere('t.prazo <= :limite')
            ->setParameter('tenant', $tenant)
            ->setParameter('concluida', Tarefa::STATUS_CONCLUIDA)
            ->setParameter('referencia', $referencia)
            ->setParameter('limite', $limite)
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Aplica os filtros globais do Dashboard a uma query cujo alias raiz é 't':
     * período por `t.dataCriacao` e, quando $filtrarResponsavel, o responsável (via
     * junção em t.responsaveis). Não é usado nas contagens de vencidas/prazos, que são
     * relativas à referência (agora), não ao período escolhido.
     *
     * @param array<string, mixed> $filtros  data_de, data_ate, responsavel
     */
    private function aplicarFiltrosDashboard(QueryBuilder $qb, array $filtros, bool $filtrarResponsavel): void
    {
        $dataDe = $this->parseDataDashboard((string) ($filtros['data_de'] ?? ''), false);
        if ($dataDe !== null) {
            $qb->andWhere('t.dataCriacao >= :fDataDe')->setParameter('fDataDe', $dataDe);
        }

        $dataAte = $this->parseDataDashboard((string) ($filtros['data_ate'] ?? ''), true);
        if ($dataAte !== null) {
            $qb->andWhere('t.dataCriacao <= :fDataAte')->setParameter('fDataAte', $dataAte);
        }

        if ($filtrarResponsavel) {
            $resp = (int) ($filtros['responsavel'] ?? 0);
            if ($resp > 0) {
                $qb->join('t.responsaveis', 'u_dash')
                   ->andWhere('u_dash.id = :fResp')
                   ->setParameter('fResp', $resp);
            }
        }
    }

    private function parseDataDashboard(string $valor, bool $fimDoDia): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fimDoDia ? $data->setTime(23, 59, 59) : $data;
    }
}
