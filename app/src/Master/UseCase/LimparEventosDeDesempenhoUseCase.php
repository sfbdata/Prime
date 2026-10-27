<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\EventoDeDesempenhoRepository;

/**
 * "Limpar registros" da seção Desempenho: apaga TODOS os eventos medidos (erros, carregamentos, pings, logins,
 * uploads). Incidentes e backups ficam: são decisão do Master, não telemetria (spec master-desempenho D6).
 */
final class LimparEventosDeDesempenhoUseCase
{
    public function __construct(private readonly EventoDeDesempenhoRepository $eventos)
    {
    }

    /** @return int quantos eventos foram apagados */
    public function executar(): int
    {
        return $this->eventos->apagarAntesDe();
    }
}
