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
    /**
     * @param bool $renomear Propaga o nome do sistema para a pasta no Drive (R3). Só vem `true`
     *                       quando a origem JÁ constatou que o nome mudou — a comparação vive lá,
     *                       não aqui: renomear é write e não pode virar rotina de varredura
     *                       (D12.3). Com `false`, o handler faz apenas o envio de sempre.
     */
    public function __construct(
        public int $pastaId,
        public int $tenantId,
        public int $usuarioId,
        public bool $renomear = false,
    ) {
    }
}
