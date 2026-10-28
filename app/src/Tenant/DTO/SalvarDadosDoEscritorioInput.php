<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use Symfony\Component\HttpFoundation\Request;

/** Os campos do modo edição da tela Meu Escritório, como chegam (texto livre; o UseCase limpa e valida). */
final readonly class SalvarDadosDoEscritorioInput
{
    public function __construct(
        public string $nome = '',
        public string $doc = '',
        public string $oab = '',
        public string $email = '',
        public string $telefone = '',
        public string $cep = '',
        public string $endereco = '',
        public string $cidadeUf = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $corpo = $request->getPayload()->all();
        $texto = static fn (string $chave): string => is_string($corpo[$chave] ?? null) ? $corpo[$chave] : '';

        return new self($texto('nome'), $texto('doc'), $texto('oab'), $texto('email'), $texto('telefone'), $texto('cep'), $texto('endereco'), $texto('cidadeUf'));
    }
}
