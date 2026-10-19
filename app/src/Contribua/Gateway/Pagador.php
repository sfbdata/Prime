<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/** Quem paga a assinatura de um escritório: dados que o gateway exige para criar o cliente e a nota. */
final readonly class Pagador
{
    public function __construct(
        public string $nome,
        /** Só dígitos: CPF (11) ou CNPJ (14). */
        public string $documento,
        public string $email,
    ) {
        if (preg_match('/^(\d{11}|\d{14})$/', $documento) !== 1) {
            throw new \DomainException('Informe o CPF ou CNPJ do titular.');
        }
    }
}
