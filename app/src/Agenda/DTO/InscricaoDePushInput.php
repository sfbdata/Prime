<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

/** O que o navegador manda ao se inscrever no push (`PushSubscription.toJSON()` mais o fuso). */
final class InscricaoDePushInput
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
        public readonly string $fuso,
    ) {
    }

    /** @param array<mixed> $json */
    public static function doJson(array $json): self
    {
        $chaves = is_array($json['keys'] ?? null) ? $json['keys'] : [];

        return new self(self::texto($json, 'endpoint'), self::texto($chaves, 'p256dh'), self::texto($chaves, 'auth'), self::texto($json, 'fuso'));
    }

    /** @param array<mixed> $j */
    private static function texto(array $j, string $chave): string
    {
        return is_scalar($j[$chave] ?? null) ? trim((string) $j[$chave]) : '';
    }
}
