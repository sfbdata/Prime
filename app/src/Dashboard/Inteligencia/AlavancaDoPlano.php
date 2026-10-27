<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/** Uma alavanca (dc L1318-1321): a chave da tradução (`dashboard.ia.plano.alavanca.<chave>`) e os valores que entram no texto. */
final readonly class AlavancaDoPlano
{
    /** @param array<string, string|int> $parametros */
    public function __construct(
        public string $chave,
        public array $parametros = [],
    ) {
    }
}
