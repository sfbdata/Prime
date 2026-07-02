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

    /**
     * @param array<string, mixed> $filtros  filtro global do painel: data_de, data_ate,
     *                                        responsavel (userId), cargo (nome). Período e
     *                                        responsável recalculam cards + linhas; cargo e
     *                                        responsável estreitam quais colaboradores aparecem.
     *                                        Vencidas/prazos próximos seguem relativos a $referencia.
     */
    public function executar(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros = []): DashboardOutput
    {
        // CARDS
        $totalMetasAtivas  = $this->tarefaRepository->countMetasAtivas($tenant, $filtros);
        $demandasUrgentes  = $this->pastaRepository->countUrgentes($tenant, $filtros);
        $global            = $this->tarefaRepository->countMetasGlobal($tenant, $filtros);
        $metaGlobalPercent = $global['total'] > 0
            ? (int) round($global['concluidas'] / $global['total'] * 100)
            : 0;

        // 6 mapas userId => count (período aplicado; vencidas/prazos são relativos à referência)
        $mTotalTarefa  = $this->tarefaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasTarefa = $this->tarefaRepository->countAtivasPorResponsavel($tenant, $filtros);
        $mVencidas     = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $referencia);
        $mPrazos       = $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $referencia);
        $mTotalPasta   = $this->pastaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasPasta  = $this->pastaRepository->countAtivasPorResponsavel($tenant, $filtros);

        // Lista canônica: todos os colaboradores ativos do tenant
        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        $mCargo        = $colaboradores === [] ? [] : $this->userRepository->findCargoPorColaboradores($tenant);

        // Facetas de linha: responsável reduz a um colaborador; cargo reduz ao cargo escolhido.
        $respId = (int) ($filtros['responsavel'] ?? 0);
        if ($respId > 0) {
            $colaboradores = array_values(array_filter($colaboradores, static fn ($u) => $u->getId() === $respId));
        }

        $cargoFiltro = trim((string) ($filtros['cargo'] ?? ''));
        if ($cargoFiltro !== '') {
            $colaboradores = array_values(array_filter(
                $colaboradores,
                static fn ($u) => ($mCargo[$u->getId()] ?? null) === $cargoFiltro,
            ));
        }

        if ($colaboradores === []) {
            return new DashboardOutput($totalMetasAtivas, $demandasUrgentes, $metaGlobalPercent, []);
        }

        $mFoto = $this->userRepository->findFotoPorColaboradores($tenant);

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
