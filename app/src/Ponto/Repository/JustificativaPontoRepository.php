<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
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
     * Data da justificativa ABONADA mais antiga do colaborador no escritório. Junto com a primeira
     * batida, define quando a folha começa a contar — um abono deferido também é registro de ponto.
     *
     * `$aPartirDe` limita a busca por baixo: serve para procurar o abono mais antigo DENTRO de uma
     * janela, sem que um abono retroativo antigo puxe o início da contagem para trás.
     * Retorna null quando não há nenhuma no recorte.
     */
    public function findDataPrimeiraAbonada(User $user, Tenant $tenant, ?\DateTimeInterface $aPartirDe = null): ?\DateTimeImmutable
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
        $qb = $this->createQueryBuilder('j')
            ->select('MIN(j.data)')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.status = :status')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'abonado');

        if ($aPartirDe !== null) {
            $qb->andWhere('j.data >= :aPartirDe')->setParameter('aPartirDe', $aPartirDe);
        }

        $primeira = $qb->getQuery()->getSingleScalarResult();

        if ($primeira === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $primeira);
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
     * Se houver mais de uma justificativa por dia, prevalece a abonada; senão a mais recente.
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
            } elseif ($j->getStatus() === 'abonado') {
                // Abonada tem prioridade sobre pendente/rejeitada
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
     * Verifica se já existe uma justificativa pendente ou abonada para o usuário nessa data.
     */
    public function findOneByUserAndData(User $user, \DateTimeInterface $data): ?JustificativaPonto
    {
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.data = :data')
            ->andWhere("j.status IN ('pendente', 'abonado')")
            ->setParameter('user', $user)
            ->setParameter('data', $data)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
