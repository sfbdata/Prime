<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;

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
    ) {}

    /** @param array<string, mixed> $filtros filtros do Dashboard (data_de, data_ate, …) */
    public function montar(DashboardOutput $dashboard, array $filtros, \DateTimeImmutable $agora): LeituraDoDashboard
    {
        $tempo    = TempoDoPeriodo::de((string) ($filtros['data_de'] ?? ''), (string) ($filtros['data_ate'] ?? ''), $agora);
        $ritmo    = $this->motorDeRitmo->analisar($dashboard, $tempo);
        $avancado = $this->motorAvancado->analisar($dashboard, $ritmo);

        return new LeituraDoDashboard($ritmo, $avancado, $agora, $tempo);
    }
}
