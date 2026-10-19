<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * Os campos do "Criar conta e escritório" da tela de entrada (desenho 10), crus como chegaram no JSON.
 * A validação (ordem e textos do desenho) é do CriarContaEEscritorioUseCase.
 */
final readonly class CriarContaEEscritorioInput
{
    public function __construct(
        public string $nome,
        public string $escritorio,
        public string $email,
        public string $senha,
        public bool $aceite,
    ) {
    }
}
