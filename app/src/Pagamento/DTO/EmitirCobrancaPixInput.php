<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

use App\Pagamento\Enum\OrigemDoPagamento;

/**
 * Pedido de uma cobrança PIX dinâmica. Valor, referência e escritório vêm do servidor do consumidor, nunca do
 * navegador. `recurso` é a chave da integração que liga a função (`te` no Trabalho Externo).
 */
final class EmitirCobrancaPixInput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly OrigemDoPagamento $origem,
        public readonly int $origemId,
        public readonly string $referencia,
        public readonly int $valorCentavos,
        public readonly \DateTimeImmutable $expiraEm,
        public readonly string $recurso,
        /** Quem paga, como o banco registra a cobrança. */
        public readonly string $nomeDoCliente,
        /** Só números, 14 dígitos. */
        public readonly string $cnpj,
    ) {
    }
}
