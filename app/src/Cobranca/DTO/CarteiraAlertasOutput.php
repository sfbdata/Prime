<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Grupo de casos-com-alerta de uma mesma Carteira, para a Central de Alertas (Etapa 9). Agrupamento
 * puramente de apresentação (SPEC §20/§26): a central lista os alertas do escritório organizados por
 * carteira. `totalAlertas` = soma dos alertas dos casos do grupo.
 */
final class CarteiraAlertasOutput
{
    /**
     * @param CasoComAlertasOutput[] $casos
     */
    public function __construct(
        public readonly int $carteiraId,
        public readonly string $carteiraNome,
        public readonly array $casos,
        public readonly int $totalAlertas,
    ) {
    }
}
