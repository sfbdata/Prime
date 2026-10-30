<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\Pasta\Entity\Pasta;

/** O que o clique em Confirmar devolve: o cartão no estado novo e, se criou pasta, ela (o controller dispara a sincronia do Drive). */
final readonly class ConfirmacaoDaProposta
{
    public function __construct(
        public PropostaOutput $proposta,
        public ?Pasta $pasta = null,
    ) {
    }
}
