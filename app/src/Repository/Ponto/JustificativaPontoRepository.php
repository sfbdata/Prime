<?php

namespace App\Repository\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\JustificativaPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JustificativaPonto>
 */
class JustificativaPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JustificativaPonto::class);
    }

    /**
     * Retorna todas as justificativas do usuário no mês/ano, ordenadas por data desc.
     *
     * @return JustificativaPonto[]
     */
    public function findByUserAndCompetencia(User $user, int $ano, int $mes): array
    {
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $fim    = $inicio->modify('last day of this month')->setTime(23, 59, 59);

        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.data BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('j.data', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna as justificativas do mês indexadas por 'Y-m-d' (para uso no FolhaPontoBuilder).
     * Se houver mais de uma justificativa por dia, prevalece a aprovada; senão a mais recente.
     *
     * @return array<string, JustificativaPonto>
     */
    public function findByUserAndCompetenciaIndexed(User $user, int $ano, int $mes): array
    {
        $justificativas = $this->findByUserAndCompetencia($user, $ano, $mes);

        $indexed = [];
        foreach ($justificativas as $j) {
            $key = $j->getData()->format('Y-m-d');
            if (!isset($indexed[$key])) {
                $indexed[$key] = $j;
            } elseif ($j->getStatus() === 'aprovado') {
                // Aprovada tem prioridade sobre pendente/rejeitada
                $indexed[$key] = $j;
            }
        }

        return $indexed;
    }

    /**
     * Retorna todas as justificativas do usuário, ordenadas por data desc.
     *
     * @return JustificativaPonto[]
     */
    public function findByTenantUser(User $targetUser): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->setParameter('user', $targetUser)
            ->orderBy('j.data', 'DESC')
            ->addOrderBy('j.batchId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifica se já existe uma justificativa pendente ou aprovada para o usuário nessa data.
     */
    public function findOneByUserAndData(User $user, \DateTimeInterface $data): ?JustificativaPonto
    {
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.data = :data')
            ->andWhere("j.status IN ('pendente', 'aprovado')")
            ->setParameter('user', $user)
            ->setParameter('data', $data)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
