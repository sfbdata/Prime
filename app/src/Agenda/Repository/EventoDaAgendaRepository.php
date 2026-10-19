<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\Evento;
use App\Agenda\Service\CompromissoNoGoogle;
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

    /**
     * Compromissos que a pessoa criou e que podem ir ao Google: os que ainda não acabaram (desde
     * `desde`), os que se repetem e os antigos que já estão lá e mudaram depois de `mudouDesde` (nulo:
     * todos). Filtra o escritório à mão (roda no worker).
     *
     * @return list<Evento>
     */
    public function criadosPorParaOGoogle(Tenant $tenant, User $criador, \DateTimeImmutable $desde, ?\DateTimeImmutable $mudouDesde): array
    {
        $qb = $this->eventos->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.criador = :criador')
            ->setParameter('tenant', $tenant)
            ->setParameter('criador', $criador)
            ->setParameter('desde', $desde)
            ->orderBy('e.id', 'ASC');
        if (null === $mudouDesde) {
            return $qb->andWhere('e.dataFim >= :desde OR e.recorrente = true OR e.googleId IS NOT NULL')->getQuery()->getResult();
        }

        return $qb->andWhere('e.dataFim >= :desde OR e.recorrente = true OR (e.googleId IS NOT NULL AND e.modificadoEm >= :mudou)')
            ->setParameter('mudou', $mudouDesde)
            ->getQuery()
            ->getResult();
    }

    /**
     * A pessoa ligou outra conta Google: os compromissos dela neste escritório perdem a ligação com a conta
     * antiga e vão para a nova como novos. Os "só do Google" (repetição que a Agenda não representa) ficam
     * como estão: iriam sem a repetição. Em SQL: colunas técnicas, sem efeito no que a pessoa vê.
     */
    public function esquecerGoogleDaPessoa(Tenant $tenant, User $criador): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE evento SET google_id = NULL, google_hash = NULL WHERE tenant_id = :t AND criador_id = :u AND google_hash IS DISTINCT FROM :so",
            ['t' => $tenant->getId(), 'u' => $criador->getId(), 'so' => CompromissoNoGoogle::SO_DO_GOOGLE],
        );
    }

    /** O compromisso da pessoa ligado a este evento do Google, se houver. */
    public function doGoogle(Tenant $tenant, User $criador, string $googleId): ?Evento
    {
        return $this->eventos->findOneBy(['tenant' => $tenant, 'criador' => $criador, 'googleId' => $googleId]);
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
