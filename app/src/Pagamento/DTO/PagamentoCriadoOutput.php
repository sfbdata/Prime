<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

use App\Pagamento\Entity\Pagamento;

final class PagamentoCriadoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $referencia,
        public readonly string $idempotencyKey,
        /** `false` quando a referência já existia: a chamada repetida devolve a mesma cobrança. */
        public readonly bool $nova,
    ) {
    }

    public static function fromEntity(Pagamento $pagamento, bool $nova): self
    {
        return new self((int) $pagamento->getId(), $pagamento->getReferencia(), $pagamento->getIdempotencyKey(), $nova);
    }
}
