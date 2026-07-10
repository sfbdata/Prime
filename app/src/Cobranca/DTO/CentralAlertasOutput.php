<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoAlerta;

/**
 * Leitura agregada da Central de Alertas do escritório (Etapa 9, SPEC §14/§20). Visão global +
 * agrupamento por carteira dos alertas derivados por `AlertasCobranca` (invariável 28: o sistema
 * alerta, o humano decide). `resumoPorTipo` traz cada tipo ocorrido com sua contagem (para os chips do
 * resumo global; o `TipoAlerta` carrega label/badge/ícone para o Twig). Read-only: nada muda estado.
 */
final class CentralAlertasOutput
{
    /**
     * @param CarteiraAlertasOutput[]                     $porCarteira
     * @param array<int, array{tipo: TipoAlerta, total: int}> $resumoPorTipo
     */
    public function __construct(
        public readonly array $porCarteira,
        public readonly array $resumoPorTipo,
        public readonly int $totalCasosComAlerta,
        public readonly int $totalAlertas,
        public readonly ?int $carteiraId,
    ) {
    }
}
