<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\ResultadoFotografiaDashboard;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Fotografa o ESTOQUE do Dashboard de um escritório num dia: para cada colaborador ativo,
 * quantas metas ativas, demandas ativas, metas vencidas e prazos próximos ele tem agora.
 *
 * Usa EXATAMENTE as consultas do `ObterDadosDashboardUseCase` (mesmos `count*PorResponsavel`),
 * sem período — uma foto não sabe que período alguém vai escolher depois, então registra o
 * estoque inteiro. Vencidas e prazos próximos são relativos a `$momento`, como o painel faz
 * com "agora".
 *
 * Tenant explícito em todas as consultas: roda no console, sem TenantFilter. Colaborador sem
 * nada é fotografado com zeros (como aparece na tabela do painel) — zero fotografado é
 * informação; ausência de foto é que significa "não sei".
 */
final class FotografarDashboardUseCase
{
    public function __construct(
        private readonly TarefaRepository        $tarefaRepository,
        private readonly PastaRepository         $pastaRepository,
        private readonly UserRepository          $userRepository,
        private readonly DashboardFotoRepository $fotoRepository,
    ) {}

    public function executar(
        Tenant $tenant,
        \DateTimeImmutable $referencia,
        \DateTimeImmutable $momento,
        bool $dryRun = false,
    ): ResultadoFotografiaDashboard {
        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        if ($colaboradores === []) {
            return new ResultadoFotografiaDashboard([], 0);
        }

        $mAtivasTarefa = $this->tarefaRepository->countAtivasPorResponsavel($tenant, []);
        $mVencidas     = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $momento);
        $mPrazos       = $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $momento);
        $mAtivasPasta  = $this->pastaRepository->countAtivasPorResponsavel($tenant, []);

        $contagens = [];
        foreach ($colaboradores as $user) {
            $id             = (int) $user->getId();
            $contagens[$id] = [
                'metas_ativas'    => $mAtivasTarefa[$id] ?? 0,
                'demandas_ativas' => $mAtivasPasta[$id]  ?? 0,
                'metas_vencidas'  => $mVencidas[$id]     ?? 0,
                'prazos_proximos' => $mPrazos[$id]       ?? 0,
            ];
        }

        $gravadas = $dryRun ? 0 : $this->fotoRepository->gravar($tenant, $referencia, $contagens);

        return new ResultadoFotografiaDashboard($contagens, $gravadas);
    }
}
