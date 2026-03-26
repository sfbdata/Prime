<?php

namespace App\Repository;

use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evento>
 */
class EventoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evento::class);
    }

    /**
     * Busca eventos em um intervalo de datas (incluindo ocorrências de eventos recorrentes)
     */
    public function findByDateRange(\DateTimeImmutable $start, \DateTimeImmutable $end, ?User $user = null): array
    {
        // Buscar eventos não-recorrentes no período
        $qb = $this->createQueryBuilder('e')
            ->where('e.recorrente = false')
            ->andWhere('(e.dataInicio >= :start AND e.dataInicio <= :end) OR (e.dataFim >= :start AND e.dataFim <= :end) OR (e.dataInicio <= :start AND e.dataFim >= :end)')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.dataInicio', 'ASC');

        if ($user !== null) {
            $qb->andWhere(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
               ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
               ->setParameter('user', $user);
        } else {
            $qb->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        $eventosNormal = $qb->getQuery()->getResult();

        // Buscar eventos recorrentes que podem ter ocorrências no período
        $qbRecorrentes = $this->createQueryBuilder('e')
            ->where('e.recorrente = true')
            ->andWhere('e.dataInicio <= :end')
            ->andWhere('e.fimRecorrencia IS NULL OR e.fimRecorrencia >= :start')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($user !== null) {
            $qbRecorrentes->andWhere(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
                          ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
                          ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
                          ->setParameter('user', $user);
        } else {
            $qbRecorrentes->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
                          ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        $eventosRecorrentes = $qbRecorrentes->getQuery()->getResult();

        // Expandir eventos recorrentes em ocorrências
        $ocorrencias = $this->expandirEventosRecorrentes($eventosRecorrentes, $start, $end);

        return array_merge($eventosNormal, $ocorrencias);
    }

    /**
     * Expande eventos recorrentes em ocorrências dentro do período
     * @return array Array de arrays para FullCalendar (não entidades)
     */
    private function expandirEventosRecorrentes(array $eventos, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $ocorrencias = [];

        foreach ($eventos as $evento) {
            $tipoRecorrencia = $evento->getTipoRecorrencia();
            $dataInicio = $evento->getDataInicio();
            $dataFim = $evento->getDataFim();
            $fimRecorrencia = $evento->getFimRecorrencia() ?? $end;
            
            // Limitar fim da recorrência ao período de busca
            if ($fimRecorrencia > $end) {
                $fimRecorrencia = $end;
            }

            // Calcular intervalo entre dataInicio e dataFim (duração do evento)
            $duracao = $dataInicio->diff($dataFim);

            // Primeira ocorrência que pode estar no período
            $dataAtual = $dataInicio;

            // Avançar até o início do período se necessário
            while ($dataAtual < $start) {
                $dataAtual = $this->proximaOcorrencia($dataAtual, $tipoRecorrencia);
                if ($dataAtual === null || $dataAtual > $fimRecorrencia) {
                    break;
                }
            }

            // Gerar ocorrências no período
            $contador = 0;
            $maxOcorrencias = 366; // Limite de segurança
            
            while ($dataAtual !== null && $dataAtual <= $fimRecorrencia && $contador < $maxOcorrencias) {
                if ($dataAtual >= $start && $dataAtual <= $end) {
                    // Criar ocorrência virtual (não é uma entidade, apenas dados para calendário)
                    $ocorrencias[] = [
                        'id' => $evento->getId() . '_' . $dataAtual->format('Y-m-d'),
                        'originalId' => $evento->getId(),
                        'title' => $evento->getTitulo(),
                        'start' => $dataAtual->format('Y-m-d\TH:i:s'),
                        'end' => $dataAtual->add($duracao)->format('Y-m-d\TH:i:s'),
                        'allDay' => $evento->isDiaInteiro(),
                        'backgroundColor' => $evento->getCor(),
                        'borderColor' => $evento->getCor(),
                        'classNames' => $evento->getStatus() === Evento::STATUS_CANCELADO ? ['evento-cancelado'] : [],
                        'extendedProps' => [
                            'descricao' => $evento->getDescricao(),
                            'local' => $evento->getLocal(),
                            'status' => $evento->getStatus(),
                            'criador' => $evento->getCriador()?->getFullName(),
                            'recorrente' => true,
                        ],
                    ];
                }

                $dataAtual = $this->proximaOcorrencia($dataAtual, $tipoRecorrencia);
                $contador++;
            }
        }

        return $ocorrencias;
    }

    /**
     * Calcula a próxima ocorrência baseada no tipo de recorrência
     */
    private function proximaOcorrencia(\DateTimeImmutable $data, ?string $tipo): ?\DateTimeImmutable
    {
        return match ($tipo) {
            'diario' => $data->modify('+1 day'),
            'semanal' => $data->modify('+1 week'),
            'mensal' => $data->modify('+1 month'),
            'anual' => $data->modify('+1 year'),
            default => null,
        };
    }

    /**
     * Busca eventos do usuário (criador ou participante, respeitando visibilidade)
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->where(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
            ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
            ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
            ->setParameter('user', $user)
            ->orderBy('e.dataInicio', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca próximos eventos do usuário (respeitando visibilidade)
     */
    public function findUpcomingByUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.dataInicio >= :now')
            ->andWhere(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
            ->andWhere('e.status = :status')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
            ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
            ->setParameter('user', $user)
            ->setParameter('status', Evento::STATUS_AGENDADO)
            ->orderBy('e.dataInicio', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca eventos do dia
     */
    public function findTodayEvents(?User $user = null): array 
    {
        $hoje = new \DateTimeImmutable('today');
        $amanha = $hoje->modify('+1 day');

        $qb = $this->createQueryBuilder('e')
            ->where('e.dataInicio >= :hoje AND e.dataInicio < :amanha')
            ->orWhere('e.dataFim >= :hoje AND e.dataFim < :amanha')
            ->orWhere('e.dataInicio < :hoje AND e.dataFim >= :amanha')
            ->setParameter('hoje', $hoje)
            ->setParameter('amanha', $amanha)
            ->orderBy('e.dataInicio', 'ASC');

        if ($user !== null) {
            $qb->andWhere(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
               ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
               ->setParameter('user', $user);
        } else {
            $qb->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Atualiza status de eventos passados para concluído
     */
    public function updatePastEventsStatus(): int
    {
        return $this->createQueryBuilder('e')
            ->update()
            ->set('e.status', ':statusConcluido')
            ->where('e.dataFim < :now')
            ->andWhere('e.status = :statusAgendado')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('statusConcluido', Evento::STATUS_CONCLUIDO)
            ->setParameter('statusAgendado', Evento::STATUS_AGENDADO)
            ->getQuery()
            ->execute();
    }

    /**
     * Busca todos os eventos para o calendário (com filtros opcionais, respeitando visibilidade)
     */
    public function findForCalendar(?string $status = null, ?User $criador = null, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.dataInicio', 'ASC');

        if ($status !== null) {
            $qb->andWhere('e.status = :status')
               ->setParameter('status', $status);
        }

        if ($criador !== null) {
            $qb->andWhere('e.criador = :criador')
               ->setParameter('criador', $criador);
        }

        if ($user !== null) {
            $qb->andWhere(
                '(e.visibilidade = :somente_eu AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND e.criador = :user) OR ' .
                '(e.visibilidade = :todos AND :user MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :todos AND SIZE(e.participantes) = 0)'
            )
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
               ->setParameter('somente_eu', Evento::VISIBILIDADE_SOMENTE_EU)
               ->setParameter('user', $user);
        } else {
            $qb->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        return $qb->getQuery()->getResult();
    }
}
