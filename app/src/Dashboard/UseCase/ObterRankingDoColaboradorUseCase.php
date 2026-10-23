<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\RankingDoColaboradorOutput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\Ranking\RankingDeMetasConcluidas;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Cartão do colaborador (spec dashboard-parte-1, item 4): posição no ranking de metas concluídas
 * (Total metas − Metas ativas, com a régua do painel: metas criadas na janela), entre todos os
 * colaboradores ativos do escritório, na janela escolhida. Mesma regra do troféu
 * (RankingDeMetasConcluidas). A pessoa tem de ser colaboradora ativa do escritório corrente:
 * fora disso, ColaboradorForaDoEscritorioException (IDOR: o id chega pela URL).
 */
final class ObterRankingDoColaboradorUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly MetricasExtrasDoDashboardRepository $metricasExtras,
    ) {
    }

    /** @param list<int> $pastasOcultas pastas ocultas pelo cadeado para quem olha (não contam) */
    public function executar(Tenant $tenant, int $userId, string $janela, \DateTimeImmutable $hoje, array $pastasOcultas = []): RankingDoColaboradorOutput
    {
        $equipe  = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        $pessoa  = $this->colaborador($equipe, $userId);
        $janela  = PeriodoDoDashboard::janelaValida($janela);
        $periodo = PeriodoDoDashboard::daJanela($janela, $hoje);
        $todos   = array_map(static fn (User $u): int => (int) $u->getId(), $equipe);
        $concluidas = RankingDeMetasConcluidas::concluidas(
            $this->tarefaRepository->countPorResponsavel($tenant, $periodo, $pastasOcultas),
            $this->tarefaRepository->countAtivasPorResponsavel($tenant, $periodo, $pastasOcultas),
        );
        $vencidas = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $hoje, $pastasOcultas);

        return new RankingDoColaboradorOutput(
            userId:             $userId,
            nome:               $pessoa->getFullName(),
            cargo:              $this->userRepository->findCargoPorColaboradores($tenant)[$userId] ?? null,
            janela:             $janela,
            dataDe:             $periodo['data_de'],
            dataAte:            $periodo['data_ate'],
            posicao:            RankingDeMetasConcluidas::posicoes($todos, $concluidas, $vencidas)[$userId] ?? count($todos),
            pessoas:            count($todos),
            metasConcluidas:    $concluidas[$userId] ?? 0,
            // Total do escritório (a meta com dois responsáveis conta uma vez), a base do card Meta global.
            metasConcluidasEquipe: $this->tarefaRepository->countMetasGlobal($tenant, $periodo, $pastasOcultas)['concluidas'],
            registros:          $this->metricasExtras->contarRegistrosPorAutor($tenant, $periodo, $pastasOcultas)[$userId] ?? 0,
            campeao:            RankingDeMetasConcluidas::campeao($todos, $concluidas, $vencidas) === $userId,
        );
    }

    /** @param array<int, User> $equipe colaboradores ativos do escritório corrente */
    private function colaborador(array $equipe, int $userId): User
    {
        foreach ($equipe as $u) {
            if ($u->getId() === $userId) {
                return $u;
            }
        }

        throw new ColaboradorForaDoEscritorioException('Colaborador não encontrado neste escritório.');
    }
}
