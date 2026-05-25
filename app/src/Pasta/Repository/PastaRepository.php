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
    public function findByFilters(array $filters, Tenant $tenant): array
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
                       $qb->expr()->like('cpf.nomeCompleto', ':cliente'),
                       $qb->expr()->like('cpj.razaoSocial', ':cliente'),
                       $qb->expr()->like('p.nomeCliente', ':cliente'),
                   )
               )
               ->setParameter('cliente', '%' . $filters['cliente'] . '%')
               ->addGroupBy('p.id');
        }

        if (!empty($filters['acao'])) {
            $qb->leftJoin('p.processo', 'proc')
               ->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
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
    public function findPorMarcador(Marcador $marcador, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador')
            ->setParameter('marcador', $marcador)
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFiltrosEMarcador(array $filters, Marcador $marcador, Tenant $tenant): array
    {
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
                       $qb->expr()->like('cpf.nomeCompleto', ':cliente'),
                       $qb->expr()->like('cpj.razaoSocial', ':cliente'),
                       $qb->expr()->like('p.nomeCliente', ':cliente'),
                   )
               )
               ->setParameter('cliente', '%' . $filters['cliente'] . '%')
               ->addGroupBy('p.id');
        }

        if (!empty($filters['acao'])) {
            $qb->leftJoin('p.processo', 'proc')
               ->andWhere('p.nomeAcao LIKE :acao OR proc.classeProcessual LIKE :acao')
               ->setParameter('acao', '%' . $filters['acao'] . '%');
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
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
}
