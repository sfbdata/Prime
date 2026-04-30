<?php

namespace App\Repository\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\RegistroPonto;
use App\Entity\Tenant\Sede;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistroPonto>
 */
class RegistroPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroPonto::class);
    }

    /**
     * @return RegistroPonto[]
     */
    public function findByUserAndCompetencia(User $user, int $ano, int $mes): array
    {
        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $inicioMes)
            ->setParameter('fim', $fimMes)
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{valor: string, label: string, ano: int, mes: int}>
     */
    public function findCompetenciasComRegistroPorUsuario(User $user): array
    {
        $sql = <<<'SQL'
SELECT DISTINCT
    TO_CHAR(data_hora, 'YYYY-MM') AS valor,
    TO_CHAR(data_hora, 'MM/YYYY') AS label,
    EXTRACT(YEAR FROM data_hora)::int AS ano,
    EXTRACT(MONTH FROM data_hora)::int AS mes
FROM registro_ponto
WHERE user_id = :userId
ORDER BY valor DESC
SQL;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, ['userId' => $user->getId()])
            ->fetchAllAssociative();

        return array_map(static function (array $row): array {
            return [
                'valor' => (string) $row['valor'],
                'label' => (string) $row['label'],
                'ano' => (int) $row['ano'],
                'mes' => (int) $row['mes'],
            ];
        }, $rows);
    }

    /**
     * @return RegistroPonto[]
     */
    public function findBatidasDoDia(User $user, \DateTimeImmutable $dia): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $dia->setTime(0, 0, 0))
            ->setParameter('fim', $dia->setTime(23, 59, 59))
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRepousoDoDia(User $user, \DateTimeImmutable $dia): ?RegistroPonto
    {
        $inicio = $dia->setTime(0, 0, 0);
        $fim    = $dia->setTime(23, 59, 59);

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tipo = :tipo')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tipo', RegistroPonto::TIPO_REPOUSO)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('r.dataHora', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUltimaSaida(User $user): ?RegistroPonto
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.tipo = :tipo')
            ->setParameter('user', $user)
            ->setParameter('tipo', RegistroPonto::TIPO_SAIDA)
            ->orderBy('r.dataHora', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function desvincularSede(Sede $sede): int
    {
        $nomeSede = $sede->getNome();

        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.sede', 'NULL')
            ->set('r.sedeNomeSnapshot', 'COALESCE(r.sedeNomeSnapshot, :nomeSede)')
            ->where('r.sede = :sede')
            ->setParameter('nomeSede', $nomeSede)
            ->setParameter('sede', $sede)
            ->getQuery()
            ->execute();
    }
}
