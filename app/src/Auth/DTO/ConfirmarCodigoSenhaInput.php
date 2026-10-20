<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * Passo 2 da senha por código: e-mail, o código de 6 números recebido e a senha nova duas vezes.
 *
 * Sem #[Assert] de propósito: a ordem dos erros e os textos são do desenho 10, e o código
 * errado tem que sair com o MESMO texto que a conta inexistente (RN02). Quem valida é o
 * ConfirmarCodigoSenhaUseCase.
 */
final class ConfirmarCodigoSenhaInput
{
    public function __construct(
        public readonly string $email,
        public readonly string $codigo,
        public readonly string $senha,
        public readonly string $confirmacao,
    ) {
    }
}
