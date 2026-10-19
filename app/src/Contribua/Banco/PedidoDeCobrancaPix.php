<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** Uma cobrança PIX de apoio para uma conta (escritório). */
final readonly class PedidoDeCobrancaPix
{
    public function __construct(
        /** Nosso código, devolvido pelo banco em toda consulta: `BJ<conta>-<cobrança>`. */
        public string $codigo,
        public string $chaveDeIdempotencia,
        public string $nomeDoEscritorio,
        /** Só números. */
        public string $cnpj,
        public int $valorCentavos,
        public \DateTimeImmutable $vencimento,
    ) {
    }
}
