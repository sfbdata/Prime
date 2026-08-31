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
    /**
     * Colunas que o cabeçalho da tabela deixa ordenar: chave usada no `data-ordenar` do <th>
     * => propriedade da linha. A chave chega pela URL, então tudo que não estiver aqui é
     * ignorado (ver ordenar()).
     */
    private const ORDENAVEIS = [
        'advogado'        => 'nomeAdvogado',
        'cargo'           => 'cargoNome',
        'metas'           => 'totalMetas',
        'metas_ativas'    => 'metasAtivas',
        'metas_vencidas'  => 'metasVencidas',
        'prazos'          => 'prazosProximos',
        'demandas'        => 'totalDemandas',
        'demandas_ativas' => 'demandasAtivas',
        'pastas_criadas'  => 'pastasCriadas',
    ];

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
     *                                        `ordenar`/`direcao` ordenam a tabela pela coluna
     *                                        clicada no cabeçalho.
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

        // 7 mapas userId => count (período aplicado; vencidas/prazos são relativos à referência)
        $mTotalTarefa  = $this->tarefaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasTarefa = $this->tarefaRepository->countAtivasPorResponsavel($tenant, $filtros);
        $mVencidas     = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $referencia);
        $mPrazos       = $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $referencia);
        $mTotalPasta   = $this->pastaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasPasta  = $this->pastaRepository->countAtivasPorResponsavel($tenant, $filtros);
        // Por CRIADOR, não por responsável: mede quem abriu a pasta (uso do sistema e
        // produtividade), enquanto os dois mapas acima medem quem responde por ela.
        $mCriadasPasta = $this->pastaRepository->countCriadasPorCriador($tenant, $filtros);

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
                pastasCriadas:  $mCriadasPasta[$id] ?? 0,
            );
        }

        return new DashboardOutput(
            $totalMetasAtivas,
            $demandasUrgentes,
            $metaGlobalPercent,
            $this->ordenar($linhas, $filtros),
        );
    }

    /**
     * Ordena a tabela pela coluna clicada no cabeçalho. Sem coluna escolhida — ou com uma que não
     * existe, já que a chave chega pela URL — mantém o padrão histórico do painel: mais metas
     * primeiro. Qualquer direção diferente de `asc` é decrescente.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     * @param array<string, mixed>           $filtros
     *
     * @return LinhaAdvogadoDashboardOutput[]
     */
    private function ordenar(array $linhas, array $filtros): array
    {
        $coluna = (string) ($filtros['ordenar'] ?? '');

        if (!isset(self::ORDENAVEIS[$coluna])) {
            usort($linhas, static fn (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b): int => $b->totalMetas <=> $a->totalMetas);

            return $linhas;
        }

        $campo = self::ORDENAVEIS[$coluna];
        $asc   = ($filtros['direcao'] ?? '') === 'asc';

        // Nome e cargo ordenam como gente lê, não como bytes: o container roda com LC_COLLATE=C,
        // onde "Élida" cairia depois de "Zulmira". Mesmo caminho do MontarAtividadeEquipeUseCase.
        $collator = in_array($campo, ['nomeAdvogado', 'cargoNome'], true) ? new \Collator('pt_BR') : null;

        usort($linhas, static function (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b) use ($campo, $asc, $collator): int {
            $cmp = $collator !== null
                ? (int) $collator->compare((string) $a->$campo, (string) $b->$campo)
                : $a->$campo <=> $b->$campo;

            return $asc ? $cmp : -$cmp;
        });

        return $linhas;
    }
}
