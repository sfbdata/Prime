<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\OrigemDoPagamento;

/** Entrada de quem cria a cobrança. O valor e a referência vêm do servidor do consumidor, nunca do navegador. */
final class CriarPagamentoInput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly OrigemDoPagamento $origem,
        public readonly int $origemId,
        public readonly string $referencia,
        public readonly int $valorCentavos,
        public readonly MetodoDePagamento $metodo,
        public readonly string $provedor,
        public readonly ?\DateTimeImmutable $expiraEm = null,
    ) {
    }
}
