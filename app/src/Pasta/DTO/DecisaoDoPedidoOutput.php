<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado da decisão de um pedido de acesso: o estado do cadeado depois dela e o aviso para quem
 * decidiu ("Pasta N liberada para Ana", desenho `cadResolver`; "Pedido recusado").
 */
final class DecisaoDoPedidoOutput
{
    public function __construct(
        public readonly CadeadoDaPastaOutput $cadeado,
        public readonly string $aviso,
    ) {
    }
}
