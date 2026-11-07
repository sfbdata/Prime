<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Quem paga a assinatura de um escritório, como o BlueJus conhece. O CPF/CNPJ do titular é validado aqui e
 * segue no `PedidoDeCheckout` para o gateway de formulário (cartão tokenizado na tela do BlueJus), que o
 * exige. Na página hospedada (Asaas) o documento é null: o pagador o digita na página do gateway e a
 * janela do BlueJus nem pede (decisão de 07/11/2026, para não pedir o CPF duas vezes).
 */
final readonly class Pagador
{
    public function __construct(
        public string $nome,
        /** Só dígitos: CPF (11) ou CNPJ (14). Null na página hospedada, que pede o documento ela mesma. */
        public ?string $documento,
        public string $email,
    ) {
        if ($documento !== null && preg_match('/^(\d{11}|\d{14})$/', $documento) !== 1) {
            throw new \DomainException('Informe o CPF ou CNPJ do titular.');
        }
    }
}
