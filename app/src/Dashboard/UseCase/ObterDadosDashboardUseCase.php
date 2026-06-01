<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

final class ObterDadosDashboardUseCase
{
    public function __construct(
        private readonly PastaRepository  $pastaRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly UserRepository   $userRepository,
    ) {}

    public function executar(Tenant $tenant, \DateTimeImmutable $referencia): DashboardOutput
    {
        // CARDS
        $totalMetasAtivas  = $this->tarefaRepository->countMetasAtivas($tenant);
        $demandasUrgentes  = $this->pastaRepository->countUrgentes($tenant);
        $global            = $this->tarefaRepository->countMetasGlobal($tenant);
        $metaGlobalPercent = $global['total'] > 0
            ? (int) round($global['concluidas'] / $global['total'] * 100)
            : 0;

        // 6 mapas userId => count
        $mTotalTarefa  = $this->tarefaRepository->countPorResponsavel($tenant);
        $mAtivasTarefa = $this->tarefaRepository->countAtivasPorResponsavel($tenant);
        $mVencidas     = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $referencia);
        $mPrazos       = $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $referencia);
        $mTotalPasta   = $this->pastaRepository->countPorResponsavel($tenant);
        $mAtivasPasta  = $this->pastaRepository->countAtivasPorResponsavel($tenant);

        // União dos userIds presentes em qualquer mapa
        $allIds = array_unique(array_merge(
            array_keys($mTotalTarefa),
            array_keys($mAtivasTarefa),
            array_keys($mVencidas),
            array_keys($mPrazos),
            array_keys($mTotalPasta),
            array_keys($mAtivasPasta),
        ));

        // Guard: findBy(['id' => []]) gera IN () no PostgreSQL
        if ($allIds === []) {
            return new DashboardOutput($totalMetasAtivas, $demandasUrgentes, $metaGlobalPercent, []);
        }

        // 1 query para todos os nomes (ids já são do tenant — filtrados nos 6 mapas)
        $users   = $this->userRepository->findBy(['id' => $allIds]);
        $userMap = [];
        foreach ($users as $user) {
            $userMap[$user->getId()] = $user;
        }

        // Montar linhas ($userMap[$id] pode ser null se user foi deletado)
        $linhas = [];
        foreach ($allIds as $id) {
            $user     = $userMap[$id] ?? null;
            $linhas[] = new LinhaAdvogadoDashboardOutput(
                userId:         $id,
                nomeAdvogado:   $user?->getFullName() ?? '',
                totalMetas:     $mTotalTarefa[$id]  ?? 0,
                metasAtivas:    $mAtivasTarefa[$id] ?? 0,
                metasVencidas:  $mVencidas[$id]     ?? 0,
                prazosProximos: $mPrazos[$id]       ?? 0,
                totalDemandas:  $mTotalPasta[$id]   ?? 0,
                demandasAtivas: $mAtivasPasta[$id]  ?? 0,
            );
        }

        usort($linhas, static fn($a, $b) => $b->totalMetas <=> $a->totalMetas);

        return new DashboardOutput($totalMetasAtivas, $demandasUrgentes, $metaGlobalPercent, $linhas);
    }
}
