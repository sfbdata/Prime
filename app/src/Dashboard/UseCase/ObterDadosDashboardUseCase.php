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

        // Lista canônica: todos os colaboradores ativos do tenant
        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);

        if ($colaboradores === []) {
            return new DashboardOutput($totalMetasAtivas, $demandasUrgentes, $metaGlobalPercent, []);
        }

        $mCargo = $this->userRepository->findCargoPorColaboradores($tenant);
        $mFoto  = $this->userRepository->findFotoPorColaboradores($tenant);

        // Montar linhas — colaborador sem tarefa/pasta aparece com zeros
        $linhas = [];
        foreach ($colaboradores as $user) {
            $id       = $user->getId();
            $linhas[] = new LinhaAdvogadoDashboardOutput(
                userId:         $id,
                nomeAdvogado:   $user->getFullName(),
                cargoNome:      $mCargo[$id] ?? null,
                fotoUrl:        $mFoto[$id]  ?? null,
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
