<?php

declare(strict_types=1);

namespace App\Contribua\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** O banco avisou um evento da cobrança: reconsultar por mTLS (só a resposta do banco confirma). */
#[AsMessage('async')]
final readonly class ReconsultarCobrancaNoBanco
{
    public function __construct(
        public string $banco,
        public string $idNoBanco,
        public ?string $idEvento = null,
    ) {
    }
}
