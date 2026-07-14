<?php

declare(strict_types=1);

namespace App\Sync\Message;

/**
 * Mensagem da Fase 2: "sincronize a pasta X do tenant Y no Drive". Granularidade por PASTA — cobre
 * tanto "pasta criada" quanto "documento novo na pasta" com uma única mensagem, naturalmente
 * deduplicável/coalescível (a idempotência do {@see \App\Sync\Service\ReconciliadorDePasta} torna
 * duplicatas inofensivas). Só carrega ids escalares (serialização leve para o transport).
 */
final readonly class SincronizarPastaNoDrive
{
    public function __construct(
        public int $pastaId,
        public int $tenantId,
        public int $usuarioId,
    ) {
    }
}
