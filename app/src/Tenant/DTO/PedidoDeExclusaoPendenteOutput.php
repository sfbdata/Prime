<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/** Uma linha do bloco "Pedidos de exclusão de conta" do painel do Master (contrato com a b5). */
final readonly class PedidoDeExclusaoPendenteOutput
{
    public function __construct(
        public int $tenantId,
        public string $escritorio,
        public ?string $pedidoPorNome,
        public ?string $pedidoPorEmail,
        public \DateTimeImmutable $criadoEm,
        public string $motivo,
    ) {
    }
}
