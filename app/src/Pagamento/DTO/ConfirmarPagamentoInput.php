<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

/** O que o banco confirmou, já lido pela reconsulta. O aviso do webhook sozinho nunca chega aqui. */
final class ConfirmarPagamentoInput
{
    public function __construct(
        public readonly string $referencia,
        public readonly int $valorPagoCentavos,
        public readonly ?string $endToEndId,
        public readonly \DateTimeImmutable $pagoEm,
        /** `banco` ou `manual:<id do usuário>`. */
        public readonly string $confirmadoPor = 'banco',
        public readonly ?string $pagadorNome = null,
        public readonly ?string $pagadorDocumento = null,
    ) {
    }
}
