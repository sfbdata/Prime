<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\Meta\MontarMetaDePastasDoMes;

/**
 * Ponto de entrada do painel BlueJus Intelligence: recebe o DashboardOutput que o UseCase
 * já calculou (com os mesmos filtros da tela) e devolve a leitura pronta para o template.
 * Chamado pelo controller depois do UseCase — o UseCase não sabe que o painel existe.
 */
final class MontarLeituraDoDashboard
{
    public function __construct(
        private readonly MotorDeRitmo $motorDeRitmo,
        private readonly MotorAvancado $motorAvancado,
        private readonly MontarMetaDePastasDoMes $montarMeta,
        private readonly MontarPlanosDeCrescimento $montarPlanos,
    ) {}

    /**
     * @param array<string, mixed> $filtros          filtros do Dashboard (data_de, data_ate, …)
     * @param bool                 $comPastasOcultas há pasta oculta (cadeado) para quem vê: a meta é do escritório
     *                                               inteiro e o realizado sairia sem elas, então o ritmo não é montado
     * @param string|null          $diaSimulado      "Simular dia" ('Y-m-d'): outro "hoje" só para esta leitura; nada se grava
     */
    public function montar(DashboardOutput $dashboard, array $filtros, \DateTimeImmutable $agora, bool $comPastasOcultas = false, ?string $diaSimulado = null): LeituraDoDashboard
    {
        $dataDe     = (string) ($filtros['data_de'] ?? '');
        $dataAte    = (string) ($filtros['data_ate'] ?? '');
        $simulacao  = SimulacaoDeDia::de($diaSimulado, TempoDoPeriodo::de($dataDe, $dataAte, $agora));
        $tempo      = TempoDoPeriodo::de($dataDe, $dataAte, $simulacao?->hoje($agora) ?? $agora);
        $ritmo    = $this->motorDeRitmo->analisar($dashboard, $tempo);
        $avancado = $this->motorAvancado->analisar($dashboard, $ritmo);

        $ritmoDaMeta = $this->ritmoDaMeta($dashboard, $comPastasOcultas, $simulacao);
        $planos      = $ritmoDaMeta === null ? [] : $this->montarPlanos->montar($ritmoDaMeta, $ritmo, array_values($dashboard->porAdvogado));

        return new LeituraDoDashboard($ritmo, $avancado, $agora, $tempo, $ritmoDaMeta, $simulacao, $planos);
    }

    private function ritmoDaMeta(DashboardOutput $dashboard, bool $comPastasOcultas, ?SimulacaoDeDia $simulacao): ?RitmoDaMeta
    {
        $meta = $dashboard->metaDePastas;
        if ($comPastasOcultas || $meta === null) {
            return null;
        }
        if ($simulacao?->dia !== null) {
            $meta = $this->montarMeta->comDiaSimulado($meta, $simulacao->dia);
        }

        return RitmoDaMeta::de($meta);
    }
}
