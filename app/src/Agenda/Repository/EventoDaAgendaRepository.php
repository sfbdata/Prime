<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\EventoRepository;

/**
 * Consultas da Agenda nova sobre a entidade legada Evento. A regra de visibilidade continua num
 * lugar só (EventoRepository::restringirAoVisivelPara).
 */
class EventoDaAgendaRepository
{
    public function __construct(
        private readonly EventoRepository $eventos,
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
            ->addSelect('criador')
            ->leftJoin('e.criador', 'criador')
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
}
