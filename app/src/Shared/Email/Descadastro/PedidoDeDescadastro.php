<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

/** O que um link de descadastro assinado carrega, já conferido: escritório, endereço e assunto. */
final readonly class PedidoDeDescadastro
{
    public function __construct(
        public int $tenantId,
        public string $email,
        public string $assunto,
    ) {
    }
}
