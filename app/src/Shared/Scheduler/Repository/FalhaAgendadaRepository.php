<?php

declare(strict_types=1);

namespace App\Shared\Scheduler\Repository;

use App\Entity\Auth\User;
use App\Shared\Scheduler\Entity\FalhaAgendada;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Falhas de tarefa agendada (spec scheduler-tarefas-agendadas, "Aviso de falha"). Dado do sistema, sem
 * escritório: nenhuma consulta filtra tenant, por desenho (FalhaAgendada está fora do TenantAware).
 *
 * @extends ServiceEntityRepository<FalhaAgendada>
 */
class FalhaAgendadaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FalhaAgendada::class);
    }

    /**
     * Grava a falha por SQL direto, sem passar pela unidade de trabalho: a tarefa que falhou pode ter
     * deixado o EntityManager do consumidor fechado ou com entidades pela metade.
     */
    public function registrar(string $comando, \DateTimeImmutable $ocorridaEm, string $classeErro, ?int $codigoSaida): void
    {
        $this->getEntityManager()->getConnection()->insert('falha_agendada', [
            'comando' => mb_substr($comando, 0, 255),
            'ocorrida_em' => $ocorridaEm,
            'classe_erro' => mb_substr($classeErro, 0, 255),
            'codigo_saida' => $codigoSaida,
        ], [
            'ocorrida_em' => Types::DATETIME_IMMUTABLE,
            'codigo_saida' => Types::INTEGER,
        ]);
    }

    /**
     * As pendentes mais recentes, para o aviso da moldura. Roda a cada página para quem vê o aviso:
     * `dispensada_em IS NULL` com LIMIT, apoiado no índice (dispensada_em, ocorrida_em).
     *
     * @return list<FalhaAgendada>
     */
    public function pendentesRecentes(int $limite): array
    {
        /** @var list<FalhaAgendada> */
        return $this->createQueryBuilder('f')
            ->andWhere('f.dispensadaEm IS NULL')
            ->orderBy('f.ocorridaEm', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * As mais recentes, dispensadas ou não, para a Central do Master.
     *
     * @return list<FalhaAgendada>
     */
    public function recentes(int $limite): array
    {
        /** @var list<FalhaAgendada> */
        return $this->createQueryBuilder('f')
            ->orderBy('f.ocorridaEm', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function uma(int $id): ?FalhaAgendada
    {
        return $this->find($id);
    }

    /** Dispensa todas as pendentes de uma vez. Devolve quantas foram dispensadas. */
    public function dispensarPendentes(User $quem, \DateTimeImmutable $quando): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(FalhaAgendada::class, 'f')
            ->set('f.dispensadaEm', ':quando')
            ->set('f.dispensadaPor', ':quem')
            ->andWhere('f.dispensadaEm IS NULL')
            ->setParameter('quando', $quando, Types::DATETIME_IMMUTABLE)
            ->setParameter('quem', $quem)
            ->getQuery()
            ->execute();
    }
}
