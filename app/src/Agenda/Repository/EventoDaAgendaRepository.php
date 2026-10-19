<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\EventoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consultas e gravação da Agenda nova sobre a entidade Evento. A regra de visibilidade continua num
 * lugar só (EventoRepository::restringirAoVisivelPara).
 */
class EventoDaAgendaRepository
{
    public function __construct(
        private readonly EventoRepository $eventos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Eventos do escritório que o visualizador vê e que podem ter ocorrência no período. Os
     * recorrentes vêm inteiros; quem expande é o OcorrenciasDoEvento. Com $soOsDele, só os que ele
     * criou ou de que participa ("Minha agenda").
     *
     * @return list<Evento>
     */
    public function doEscritorioNoPeriodo(
        Tenant $tenant,
        User $visualizador,
        \DateTimeImmutable $de,
        \DateTimeImmutable $ate,
        bool $soOsDele,
    ): array {
        $qb = $this->eventos->createQueryBuilder('e')
            ->addSelect('criador', 'participante', 'pasta')
            ->leftJoin('e.criador', 'criador')
            ->leftJoin('e.participantes', 'participante')
            ->leftJoin('e.pasta', 'pasta')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.dataInicio <= :ate')
            ->andWhere('(e.recorrente = false AND e.dataFim >= :de) OR (e.recorrente = true AND (e.fimRecorrencia IS NULL OR e.fimRecorrencia >= :de))')
            ->setParameter('tenant', $tenant)
            ->setParameter('de', $de)
            ->setParameter('ate', $ate)
            ->orderBy('e.dataInicio', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        $this->eventos->restringirAoVisivelPara($qb, $visualizador);
        if ($soOsDele) {
            $qb->andWhere('e.criador = :visualizador OR :visualizador MEMBER OF e.participantes');
        }

        return $qb->getQuery()->getResult();
    }

    /** O evento, se for do escritório e o visualizador puder vê-lo; senão nulo (vira 404). */
    public function visivelPara(int $id, Tenant $tenant, User $visualizador): ?Evento
    {
        $qb = $this->eventos->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->andWhere('e.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant);
        $this->eventos->restringirAoVisivelPara($qb, $visualizador);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function salvar(Evento $evento): void
    {
        $this->em->persist($evento);
        $this->em->flush();
    }

    public function remover(Evento $evento): void
    {
        $this->em->remove($evento);
        $this->em->flush();
    }
}
