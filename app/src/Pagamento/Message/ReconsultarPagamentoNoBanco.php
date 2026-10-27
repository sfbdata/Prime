<?php

declare(strict_types=1);

namespace App\Pagamento\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** O banco avisou um evento de uma cobrança do registro de pagamentos: reconsultar por mTLS (só a resposta do banco confirma). */
#[AsMessage('async')]
final readonly class ReconsultarPagamentoNoBanco
{
    public function __construct(
        public string $banco,
        public string $idNoBanco,
        public ?string $idEvento = null,
    ) {
    }
}
