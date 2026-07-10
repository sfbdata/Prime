<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CarteiraAlertasOutput;
use App\Cobranca\DTO\CasoComAlertasOutput;
use App\Cobranca\DTO\CentralAlertasOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: monta a Central de Alertas do escritório (SPEC §14/§20, Etapa 9). Visão consolidada do TENANT,
 * não de um caso — varre os casos do tenant, reutiliza `AlertasCobranca::alertasDoCaso` (invariável 28:
 * o sistema alerta, o humano decide) e agrupa os casos-com-alerta por carteira, com um resumo global por
 * tipo de alerta. Read-only: não persiste, não muda estado, nenhuma regra nova.
 *
 * História: o gestor abre a central para ver, num lugar só, tudo que exige atenção no escritório —
 * obrigações vencidas, parcelas de acordo vencidas, ações atrasadas, revisões pendentes e casos prontos
 * para encerrar — organizados por carteira. Tenant e carteira opcional já chegam resolvidos e tenant-safe
 * do controller. Casos encerrados retornam `[]` de alertas (derivação) e são naturalmente ignorados.
 */
final class MontarCentralAlertasUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly AlertasCobranca $alertasCobranca,
    ) {
    }

    public function executar(Tenant $tenant, ?Carteira $carteira = null, ?\DateTimeImmutable $hoje = null): CentralAlertasOutput
    {
        $hoje ??= new \DateTimeImmutable('today');

        $casos = $this->casoRepository->doTenant($tenant, $carteira);

        /** @var array<int, array{nome: string, casos: CasoComAlertasOutput[], total: int}> $grupos */
        $grupos = [];
        /** @var array<string, int> $contagemPorTipo */
        $contagemPorTipo = [];
        $totalCasosComAlerta = 0;
        $totalAlertas = 0;

        foreach ($casos as $caso) {
            $alertas = $this->alertasCobranca->alertasDoCaso($caso, $hoje);

            if ($alertas === []) {
                continue;
            }

            $casoOutput = CasoComAlertasOutput::fromEntity($caso, $alertas);
            $carteiraId = $casoOutput->carteiraId;

            if (!isset($grupos[$carteiraId])) {
                $grupos[$carteiraId] = ['nome' => $casoOutput->carteiraNome, 'casos' => [], 'total' => 0];
            }

            $grupos[$carteiraId]['casos'][] = $casoOutput;
            $grupos[$carteiraId]['total'] += count($alertas);

            ++$totalCasosComAlerta;
            $totalAlertas += count($alertas);

            foreach ($alertas as $alerta) {
                $chave = $alerta->tipo->value;
                $contagemPorTipo[$chave] = ($contagemPorTipo[$chave] ?? 0) + 1;
            }
        }

        $porCarteira = [];
        foreach ($grupos as $carteiraId => $grupo) {
            $porCarteira[] = new CarteiraAlertasOutput(
                carteiraId: $carteiraId,
                carteiraNome: $grupo['nome'],
                casos: $grupo['casos'],
                totalAlertas: $grupo['total'],
            );
        }

        // Resumo global na ordem natural do enum (exibição estável dos chips).
        $resumoPorTipo = [];
        foreach (TipoAlerta::cases() as $tipo) {
            if (isset($contagemPorTipo[$tipo->value])) {
                $resumoPorTipo[] = ['tipo' => $tipo, 'total' => $contagemPorTipo[$tipo->value]];
            }
        }

        return new CentralAlertasOutput(
            porCarteira: $porCarteira,
            resumoPorTipo: $resumoPorTipo,
            totalCasosComAlerta: $totalCasosComAlerta,
            totalAlertas: $totalAlertas,
            carteiraId: $carteira?->getId(),
        );
    }
}
