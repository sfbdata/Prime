<?php

namespace App\Pasta\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pasta>
 */
class PastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pasta::class);
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFilters(
        array $filters,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25
    ): array {
        $qb = $this->buildQbByFilters($filters, $tenant);

        if (!empty($filters['cliente'])) {
            $qb->addGroupBy('p.id');
        }

        return $qb
            ->orderBy('CASE WHEN CAST_INT(p.nup) IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('CAST_INT(p.nup)', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countByFilters(array $filters, Tenant $tenant): int
    {
        return (int) $this->buildQbByFilters($filters, $tenant)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return string[]
     */
    public function findAllNups(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.nup')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.nup', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'nup');
    }

    /**
     * @return Pasta[]
     */
    public function findByCliente(Cliente $cliente, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.clientes', 'c')
            ->where('c = :cliente')
            ->setParameter('cliente', $cliente)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.dataAbertura', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Pasta[]
     */
    public function findPorMarcador(
        Marcador $marcador,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25
    ): array {
        return $this->buildQbPorMarcador([], $marcador, $tenant)
            ->orderBy('CASE WHEN CAST_INT(p.nup) IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('CAST_INT(p.nup)', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPorMarcador(Marcador $marcador, Tenant $tenant): int
    {
        return (int) $this->buildQbPorMarcador([], $marcador, $tenant)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFiltrosEMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant,
        int $page = 1,
        int $perPage = 25
    ): array {
        $qb = $this->buildQbPorMarcador($filters, $marcador, $tenant);

        if (!empty($filters['cliente'])) {
            $qb->addGroupBy('p.id');
        }

        return $qb
            ->orderBy('CASE WHEN CAST_INT(p.nup) IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('CAST_INT(p.nup)', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countByFiltrosEMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant
    ): int {
        return (int) $this->buildQbPorMarcador($filters, $marcador, $tenant)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Pasta[]
     */
    public function findAtivasPorResponsavel(
        User $responsavel,
        Tenant $tenant,
        ?string $cliente = null,
        ?string $prioridade = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->where('p.responsavel = :responsavel')
            ->andWhere('p.situacao = :situacao')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('responsavel', $responsavel)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.prioridade', 'DESC')
            ->addOrderBy('p.dataAbertura', 'DESC');

        if ($cliente !== null && $cliente !== '') {
            $qb->andWhere('p.nomeCliente LIKE :cliente')
               ->setParameter('cliente', '%' . $cliente . '%');
        }

        if ($prioridade !== null && $prioridade !== '') {
            $qb->andWhere('p.prioridade = :prioridade')
               ->setParameter('prioridade', PrioridadePasta::from($prioridade));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, int>  marcadorId => total
     */
    public function countPorMarcadores(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('m.id AS marcador_id, COUNT(p.id) AS total')
            ->join('p.marcadores', 'm')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('m.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['marcador_id']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countAtivas(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.situacao = :situacao')
            ->setParameter('tenant', $tenant)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUrgentes(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.prioridade = :prioridade')
            ->setParameter('tenant', $tenant)
            ->setParameter('prioridade', PrioridadePasta::Urgente)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, int>  userId => total (todas as situações)
     */
    public function countPorResponsavel(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('r.id AS responsavel_id, COUNT(p.id) AS total')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('r.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, int>  userId => total (só ativas)
     */
    public function countAtivasPorResponsavel(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('r.id AS responsavel_id, COUNT(p.id) AS total')
            ->join('p.responsavel', 'r')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.situacao = :situacao')
            ->setParameter('tenant', $tenant)
            ->setParameter('situacao', Pasta::SITUACAO_ATIVA)
            ->groupBy('r.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['responsavel_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildQbByFilters(array $filters, Tenant $tenant): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.situacao = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
        }

        if (!empty($filters['cliente'])) {
            $qb->leftJoin('p.clientes', 'c_cli')
               ->leftJoin(ClientePF::class, 'cpf', 'WITH', 'cpf.id = c_cli.id')
               ->leftJoin(ClientePJ::class, 'cpj', 'WITH', 'cpj.id = c_cli.id')
               ->andWhere(
                   $qb->expr()->orX(
                       'LOWER(cpf.nomeCompleto) LIKE :cliente',
                       'LOWER(cpj.razaoSocial) LIKE :cliente',
                       'LOWER(p.nomeCliente) LIKE :cliente',
                   )
               )
               ->setParameter('cliente', '%' . mb_strtolower($filters['cliente']) . '%');
        }

        if (!empty($filters['acao'])) {
            $qb->leftJoin('p.processo', 'proc')
               ->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        return $qb;
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildQbPorMarcador(
        array $filters,
        Marcador $marcador,
        Tenant $tenant
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador')
            ->setParameter('marcador', $marcador)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.situacao = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
        }

        if (!empty($filters['cliente'])) {
            $qb->leftJoin('p.clientes', 'c_cli')
               ->leftJoin(ClientePF::class, 'cpf', 'WITH', 'cpf.id = c_cli.id')
               ->leftJoin(ClientePJ::class, 'cpj', 'WITH', 'cpj.id = c_cli.id')
               ->andWhere(
                   $qb->expr()->orX(
                       'LOWER(cpf.nomeCompleto) LIKE :cliente',
                       'LOWER(cpj.razaoSocial) LIKE :cliente',
                       'LOWER(p.nomeCliente) LIKE :cliente',
                   )
               )
               ->setParameter('cliente', '%' . mb_strtolower($filters['cliente']) . '%');
        }

        if (!empty($filters['acao'])) {
            $qb->leftJoin('p.processo', 'proc')
               ->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        return $qb;
    }
}
