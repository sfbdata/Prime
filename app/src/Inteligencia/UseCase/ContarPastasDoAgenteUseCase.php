<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Inteligencia\Service\ConsultaDePastasDoAgente;

/**
 * `contar_pastas` do Agente de Pastas (Fase 1, só leitura): o total do mesmo filtro de
 * `buscar_pastas`, sem carregar as linhas — o que o agente pergunta antes de propor um lote.
 */
final class ContarPastasDoAgenteUseCase
{
    public function __construct(
        private readonly ConsultaDePastasDoAgente $consulta,
    ) {
    }

    /** @throws FiltroDoAgenteInvalidoException */
    public function executar(FiltroDoAgenteInput $filtro, Tenant $tenant): int
    {
        return $this->consulta->contar($filtro, $tenant);
    }
}
