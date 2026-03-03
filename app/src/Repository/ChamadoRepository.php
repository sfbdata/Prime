<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Chamado>
 */
class ChamadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chamado::class);
    }

    /**
     * Busca chamados do solicitante
     */
    public function findBySolicitante(User $user, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.solicitante = :user')
            ->setParameter('user', $user)
            ->orderBy('c.criadoEm', 'DESC');

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Busca chamados atribuídos ao técnico
     */
    public function findByResponsavel(User $user, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.responsavel = :user')
            ->setParameter('user', $user)
            ->orderBy('c.criadoEm', 'DESC');

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Busca todos os chamados (para equipe TI)
     */
    public function findAllFiltered(
        ?string $status = null,
        ?string $categoria = null,
        ?string $prioridade = null,
        ?User $responsavel = null,
        ?string $busca = null,
        string $ordenar = 'criadoEm',
        string $direcao = 'DESC'
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.solicitante', 's')
            ->leftJoin('c.responsavel', 'r');

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
               ->setParameter('status', $status);
        }

        if ($categoria !== null) {
            $qb->andWhere('c.categoria = :categoria')
               ->setParameter('categoria', $categoria);
        }

        if ($prioridade !== null) {
            $qb->andWhere('c.prioridade = :prioridade')
               ->setParameter('prioridade', $prioridade);
        }

        if ($responsavel !== null) {
            $qb->andWhere('c.responsavel = :responsavel')
               ->setParameter('responsavel', $responsavel);
        }

        if ($busca !== null && $busca !== '') {
            $qb->andWhere('c.titulo LIKE :busca OR c.descricao LIKE :busca OR s.fullName LIKE :busca')
               ->setParameter('busca', '%' . $busca . '%');
        }

        // Ordenação
        $campoOrdenacao = match ($ordenar) {
            'titulo' => 'c.titulo',
            'status' => 'c.status',
            'prioridade' => 'c.prioridade',
            'categoria' => 'c.categoria',
            'solicitante' => 's.fullName',
            default => 'c.criadoEm',
        };

        $qb->orderBy($campoOrdenacao, strtoupper($direcao) === 'ASC' ? 'ASC' : 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Busca chamados abertos não atribuídos
     */
    public function findAbertosNaoAtribuidos(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.responsavel IS NULL')
            ->setParameter('status', Chamado::STATUS_ABERTO)
            ->orderBy('c.prioridade', 'DESC')
            ->addOrderBy('c.criadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta chamados por status
     */
    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.status, COUNT(c.id) as total')
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();

        $counts = [
            Chamado::STATUS_ABERTO => 0,
            Chamado::STATUS_EM_ANDAMENTO => 0,
            Chamado::STATUS_RESOLVIDO => 0,
            Chamado::STATUS_FECHADO => 0,
        ];

        foreach ($result as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Conta chamados por categoria
     */
    public function countByCategoria(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.categoria, COUNT(c.id) as total')
            ->groupBy('c.categoria')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcula tempo médio de resolução em horas
     */
    public function getTempoMedioResolucao(): ?float
    {
        // Usar SQL nativo para PostgreSQL
        $conn = $this->getEntityManager()->getConnection();
        $sql = "SELECT AVG(EXTRACT(EPOCH FROM (resolvido_em - criado_em)) / 3600) as media 
                FROM chamado 
                WHERE resolvido_em IS NOT NULL";
        
        $result = $conn->executeQuery($sql)->fetchOne();

        return $result ? (float) $result : null;
    }

    /**
     * Busca chamados recentes (últimos 7 dias)
     */
    public function findRecentes(int $limite = 10): array
    {
        $dataLimite = new \DateTimeImmutable('-7 days');

        return $this->createQueryBuilder('c')
            ->where('c.criadoEm >= :data')
            ->setParameter('data', $dataLimite)
            ->orderBy('c.criadoEm', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca chamados urgentes (alta/crítica prioridade e abertos)
     */
    public function findUrgentes(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.prioridade IN (:prioridades)')
            ->andWhere('c.status IN (:status)')
            ->setParameter('prioridades', [Chamado::PRIORIDADE_ALTA, Chamado::PRIORIDADE_CRITICA])
            ->setParameter('status', [Chamado::STATUS_ABERTO, Chamado::STATUS_EM_ANDAMENTO])
            ->orderBy('c.prioridade', 'DESC')
            ->addOrderBy('c.criadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
