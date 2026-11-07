<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Quem paga a assinatura de um escritório, como o BlueJus conhece. O CPF/CNPJ do titular é validado aqui e
 * segue no `PedidoDeCheckout` para o gateway que precisar; o Asaas não recebe estes dados (o pagador os
 * preenche na página do checkout).
 */
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
