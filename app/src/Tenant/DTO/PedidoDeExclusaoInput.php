<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use Symfony\Component\HttpFoundation\Request;

/** As duas etapas do modal "Excluir conta" (PR 5): motivo e observação; na etapa 2, o código e a palavra. */
final readonly class PedidoDeExclusaoInput
{
    public function __construct(
        public string $motivo,
        public string $observacao = '',
        public string $codigo = '',
        public string $palavra = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $corpo = $request->request->all();
        $texto = static fn (string $chave): string => is_string($corpo[$chave] ?? null) ? $corpo[$chave] : '';

        return new self($texto('motivo'), $texto('observacao'), $texto('codigo'), $texto('palavra'));
    }
}
