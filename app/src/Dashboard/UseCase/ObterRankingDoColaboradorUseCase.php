<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\RankingDoColaboradorOutput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Periodo\PeriodoDoDashboard;
use App\Dashboard\Ranking\HistoricoDeCampeoes;
use App\Dashboard\Ranking\RankingDeMetasConcluidas;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\HistoricoDeMetasDoDashboardRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\Zeramento\ColunasZeraveis;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Dashboard\Zeramento\RecontagemComCorte;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Cartão do colaborador (spec dashboard-parte-1, item 4), na regra do troféu
 * (RankingDeMetasConcluidas: concluídas = total − ativas com a régua do painel, desempate menos
 * vencidas), entre todos os colaboradores ativos do escritório:
 *   - posição, Comentários e Rendimento no período do PAINEL (o do troféu); as janelas Mês,
 *     Trimestre, Semestre e Ano do seletor são a alternativa (decisão do dono);
 *   - "1º do mês": os meses fechados em que a pessoa foi o campeão (HistoricoDeCampeoes).
 * Posição, Comentários e Rendimento respeitam o "Zerar" (DASH-014) de Total metas, Metas ativas,
 * Metas vencidas e Relatório, como a tabela; o "1º do mês" não (é histórico de meses fechados).
 * A pessoa tem de ser colaboradora ativa do escritório corrente: fora disso,
 * ColaboradorForaDoEscritorioException (IDOR: o id chega pela URL).
 */
final class ObterRankingDoColaboradorUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly MetricasExtrasDoDashboardRepository $metricasExtras,
        private readonly HistoricoDeMetasDoDashboardRepository $historico,
        private readonly DashboardZeramentoRepository $zeramentos,
    ) {
    }

    /**
     * @param array<string, mixed> $filtrosDoPainel data_de e data_ate do painel (usados com a janela 'periodo')
     * @param list<int>            $pastasOcultas   pastas ocultas pelo cadeado para quem olha (não contam)
     */
    public function executar(Tenant $tenant, int $userId, string $janela, array $filtrosDoPainel, \DateTimeImmutable $hoje, array $pastasOcultas = []): RankingDoColaboradorOutput
    {
        $equipe   = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        $pessoa   = $this->colaborador($equipe, $userId);
        $janela   = PeriodoDoDashboard::janelaDoCartao($janela);
        $periodo  = $janela === PeriodoDoDashboard::PERIODO ? PeriodoDoDashboard::datasDoPainel($filtrosDoPainel) : PeriodoDoDashboard::daJanela($janela, $hoje);
        $todos    = array_map(static fn (User $u): int => (int) $u->getId(), $equipe);
        $cortes   = CortesDoDashboard::deZeramentos($this->zeramentos->listarAtivos($tenant));
        $contar   = fn (string $coluna, callable $consulta): array => self::comCorte($cortes, $coluna, $todos, $periodo, $consulta);
        $concl    = RankingDeMetasConcluidas::concluidas(
            $contar(Extras::METAS_CONCLUIDAS, fn (array $f): array => $this->tarefaRepository->countPorResponsavel($tenant, $f, $pastasOcultas)),
            $contar(Extras::METAS_CONCLUIDAS, fn (array $f): array => $this->tarefaRepository->countAtivasPorResponsavel($tenant, $f, $pastasOcultas)),
        );
        $vencidasHoje = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $hoje, $pastasOcultas);
        $vencidas = $contar(ColunasZeraveis::METAS_VENCIDAS, fn (array $f): array => isset($f['contar_desde'])
            ? $this->tarefaRepository->countVencidasPorResponsavel($tenant, $hoje, $pastasOcultas, $f['contar_desde'])
            : $vencidasHoje);
        $outros   = array_values(array_diff($todos, [$userId]));

        return new RankingDoColaboradorOutput(
            userId:      $userId,
            nome:        $pessoa->getFullName(),
            cargo:       $this->userRepository->findCargoPorColaboradores($tenant)[$userId] ?? null,
            janela:      $janela,
            dataDe:      $periodo['data_de'],
            dataAte:     $periodo['data_ate'],
            posicao:     RankingDeMetasConcluidas::posicoes($todos, $concl, $vencidas)[$userId] ?? count($todos),
            pessoas:     count($todos),
            registros:   $contar(Extras::RELATORIO, fn (array $f): array => $this->metricasExtras->contarRegistrosPorAutor($tenant, $f, $pastasOcultas))[$userId] ?? 0,
            campeao:     RankingDeMetasConcluidas::campeao($todos, $concl, $vencidas) === $userId,
            concluidas:  $concl[$userId] ?? 0,
            mediaOutros: $this->media($outros, $concl),
            outros:      count($outros),
            primeiros:   HistoricoDeCampeoes::doColaborador($userId, $this->historico->contarPorMesEResponsavel($tenant, $pastasOcultas), $todos, $vencidasHoje, $hoje),
        );
    }

    /**
     * Média de concluídas dos outros, com uma casa (o desenho mostra "2,4"); sem outros, 0.
     *
     * @param list<int>       $outros
     * @param array<int, int> $concluidas
     */
    private function media(array $outros, array $concluidas): float
    {
        if ($outros === []) {
            return 0.0;
        }
        $soma = array_sum(array_map(static fn (int $id): int => $concluidas[$id] ?? 0, $outros));

        return round($soma / count($outros), 1);
    }
    /**
     * A consulta no recorte do cartão e, para quem tem "Zerar" ativo na coluna, de novo a partir do corte.
     *
     * @param list<int>                                    $todos
     * @param array<string, mixed>                         $periodo
     * @param callable(array<string, mixed>): array<int, int> $consulta
     *
     * @return array<int, int>
     */
    private static function comCorte(CortesDoDashboard $cortes, string $coluna, array $todos, array $periodo, callable $consulta): array
    {
        return RecontagemComCorte::aplicar($consulta($periodo), $cortes, $coluna, $todos, static fn (\DateTimeImmutable $desde): array => $consulta(['contar_desde' => $desde] + $periodo));
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
