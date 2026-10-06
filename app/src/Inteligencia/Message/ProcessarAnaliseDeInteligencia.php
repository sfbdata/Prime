<?php

declare(strict_types=1);

namespace App\Inteligencia\Message;

/**
 * "Processe a análise X do tenant Y" — roteada para o transport `async` (worker). Só ids escalares,
 * como `SincronizarPastaNoDrive`: serialização leve e nenhum dado sensível na fila. O `tenantId`
 * é revalidado pelo handler contra a linha (fronteira de confiança do worker).
 */
final readonly class ProcessarAnaliseDeInteligencia
{
    public function __construct(
        public int $analiseId,
        public int $tenantId,
    ) {
    }
}
