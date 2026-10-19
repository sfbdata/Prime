<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** O que o banco diz da cobrança na reconsulta. Só isto confirma um apoio (o aviso não confirma). */
final readonly class SituacaoDaCobranca
{
    public function __construct(
        public bool $paga,
        public int $valorPagoCentavos,
        public ?\DateTimeImmutable $pagaEm,
        /** Nosso código, como o banco devolve. */
        public ?string $codigo,
        public bool $cancelada = false,
    ) {
    }
}
