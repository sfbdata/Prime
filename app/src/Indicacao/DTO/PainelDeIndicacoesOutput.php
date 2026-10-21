<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * O card "Indicações do BlueJus" do Painel do Master (contrato com a 5d, spec `indicar-bluejus.md`). `viraramConta`
 * conta endereços distintos com conta criada depois da indicação; `alertas` são os 3 últimos.
 */
final class PainelDeIndicacoesOutput
{
    /**
     * @param list<AlertaDeIndicacaoOutput> $alertas
     * @param list<IndicadorOutput>         $indicadores ordem: ultimas24h desc, total desc
     */
    public function __construct(
        public readonly int $enviadas,
        public readonly int $ultimas24h,
        public readonly int $viraramConta,
        public readonly LimitesDeIndicacaoOutput $limites,
        public readonly array $alertas,
        public readonly array $indicadores,
        public readonly int $totalDeIndicadores,
        public readonly int $pagina,
        public readonly int $porPagina,
    ) {
    }
}
