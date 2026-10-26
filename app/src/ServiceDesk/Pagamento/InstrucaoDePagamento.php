<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

/**
 * O que a tela mostra depois de pedir a recarga. `copiaECola` é o PIX copia e cola (null quando não
 * há, como no cartão); `mensagem` é o aviso ao escritório ("aguardando confirmação do pagamento"); `expiraEm`, quando o PIX vence.
 */
final class InstrucaoDePagamento
{
    public function __construct(
        public readonly ?string $copiaECola,
        public readonly string $mensagem,
        public readonly ?\DateTimeImmutable $expiraEm = null,
    ) {
    }
}
