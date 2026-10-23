<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * Os campos do "Criar conta e escritório" da tela de entrada (desenho 10), crus como chegaram no JSON.
 * A validação (ordem e textos do desenho) é do CriarContaEEscritorioUseCase.
 *
 * `senha` não vem mais da tela (D1 da spec `cadastro-senha-no-link.md`); se um cliente antigo a mandar, o servidor a
 * ignora: a senha é criada no link de confirmação.
 */
final readonly class CriarContaEEscritorioInput
{
    public function __construct(
        public string $nome,
        public string $escritorio,
        public string $email,
        public string $senha,
        public bool $aceite,
        /** Valor cru do campo Gênero (spec genero-no-cadastro); só `feminino` ou `masculino` passam. */
        public string $genero,
    ) {
    }
}
