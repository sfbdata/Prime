<?php

namespace App\Repository;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
            $this->restringirAoVisivelPara($qb, $user);
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
            $this->restringirAoVisivelPara($qbRecorrentes, $user);
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
        return $this->restringirAoVisivelPara($this->createQueryBuilder('e'), $user)
            ->orderBy('e.dataInicio', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca próximos eventos do usuário (respeitando visibilidade)
     */
    public function findUpcomingByUser(User $user, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.dataInicio >= :now');

        return $this->restringirAoVisivelPara($qb, $user)
            ->andWhere('e.status = :status')
            ->setParameter('now', new \DateTimeImmutable())
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
            $this->restringirAoVisivelPara($qb, $user);
        } else {
            $qb->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Eventos na agenda de UMA pessoa — o destino do número "Eventos na agenda" do Dashboard
     * (DASH-009). A régua é a MESMA de MetricasExtrasDoDashboardRepository::contarEventosPorPessoa,
     * para o número da célula ser o total desta lista:
     *   - evento do escritório informado (explícito, além do TenantFilter);
     *   - a pessoa é criadora OU participante (cada evento uma vez, mesmo sendo as duas coisas);
     *   - só os "Para Todos" (o "Somente para mim" é privado de quem criou);
     *   - cancelados fora;
     *   - recorrente entra uma vez (a ocorrência-base), pela data de início;
     *   - período pela data de início, formato estrito 'Y-m-d' (data inválida é ignorada), fim do
     *     dia na borda final.
     * $status (agendado/concluido) só estreita; "cancelado" devolve vazio, como a régua manda.
     *
     * SEC-016: POR CIMA da régua, vale a visibilidade do módulo para quem OLHA ($visualizador,
     * o usuário logado) — a mesma de findForCalendar/findByDateRange. Um "Para Todos" COM
     * participantes só aparece para o criador e os participantes dele. Sem isso, trocar o id em
     * `?responsavel=` entregava título, local e participantes das reuniões de qualquer colega.
     * Consequência deliberada: para quem não participa de tudo, a lista pode ter MENOS itens que o
     * contador agregado do Dashboard (que não expõe título) — sigilo > paridade, decisão pendente
     * do Samuel.
     *
     * @param array<string, mixed> $periodo data_de, data_ate (Y-m-d)
     *
     * @return list<Evento>
     */
    public function findNaAgendaDaPessoa(
        Tenant $tenant,
        User $pessoa,
        User $visualizador,
        ?string $status = null,
        array $periodo = [],
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.visibilidade = :todos')
            ->andWhere('e.status != :cancelado')
            ->andWhere('e.criador = :pessoa OR :pessoa MEMBER OF e.participantes')
            ->setParameter('tenant', $tenant)
            ->setParameter('todos', Evento::VISIBILIDADE_TODOS)
            ->setParameter('cancelado', Evento::STATUS_CANCELADO)
            ->setParameter('pessoa', $pessoa)
            ->orderBy('e.dataInicio', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        $this->restringirAoVisivelPara($qb, $visualizador);

        if ($status !== null && $status !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        $de = $this->lerDataDoPeriodo((string) ($periodo['data_de'] ?? ''), false);
        if ($de !== null) {
            $qb->andWhere('e.dataInicio >= :pDe')->setParameter('pDe', $de);
        }
        $ate = $this->lerDataDoPeriodo((string) ($periodo['data_ate'] ?? ''), true);
        if ($ate !== null) {
            $qb->andWhere('e.dataInicio <= :pAte')->setParameter('pAte', $ate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A regra de visibilidade do módulo Agenda, num lugar só. O usuário vê um evento quando:
     *   - é "Somente para mim" e ele é o criador;
     *   - é "Para Todos" e ele é o criador;
     *   - é "Para Todos" e ele é participante;
     *   - é "Para Todos" SEM participantes (aberto ao escritório).
     * Ou seja: "Para Todos" COM participantes é restrito ao criador e aos participantes.
     *
     * Exige o alias `e`. Parâmetros com prefixo próprio para não colidir com os da consulta.
     */
    public function restringirAoVisivelPara(QueryBuilder $qb, User $visualizador): QueryBuilder
    {
        return $qb
            ->andWhere(
                '(e.visibilidade = :visSomenteEu AND e.criador = :visualizador) OR ' .
                '(e.visibilidade = :visTodos AND e.criador = :visualizador) OR ' .
                '(e.visibilidade = :visTodos AND :visualizador MEMBER OF e.participantes) OR ' .
                '(e.visibilidade = :visTodos AND SIZE(e.participantes) = 0)'
            )
            ->setParameter('visTodos', Evento::VISIBILIDADE_TODOS)
            ->setParameter('visSomenteEu', Evento::VISIBILIDADE_SOMENTE_EU)
            ->setParameter('visualizador', $visualizador);
    }

    /** Mesma leitura de data do Dashboard: 'Y-m-d' estrito; fora disso, null (ignorada). */
    private function lerDataDoPeriodo(string $valor, bool $fimDoDia): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fimDoDia ? $data->setTime(23, 59, 59) : $data;
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
            $this->restringirAoVisivelPara($qb, $user);
        } else {
            $qb->andWhere('e.visibilidade = :todos AND SIZE(e.participantes) = 0')
               ->setParameter('todos', Evento::VISIBILIDADE_TODOS);
        }

        return $qb->getQuery()->getResult();
    }
}
