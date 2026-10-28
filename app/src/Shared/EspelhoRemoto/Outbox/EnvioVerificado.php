<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Outbox;

/**
 * A prova de que uma chave está no espelho: o envio `verificado` que é a linha mais nova dela na
 * outbox (spec `leitura-pelo-r2-e-liberacao-de-disco.md`, §3.2).
 *
 * Tamanho, SHA-256 e MIME são os medidos no disco na gravação e conferidos no R2 pela réplica. É com
 * eles que a leitura de recuperação responde `existe()`/`metadados()` sem ir ao R2, e é com eles que o
 * download é conferido antes de ser entregue.
 */
final readonly class EnvioVerificado
{
    public function __construct(
        public string $chaveRemota,
        public string $sha256,
        public int $tamanhoBytes,
        public string $mimeType,
        /** Quando a gravação foi registrada (UTC): faz o papel do `mtime` do arquivo que saiu do disco. */
        public \DateTimeImmutable $gravadoEm,
    ) {
    }
}
