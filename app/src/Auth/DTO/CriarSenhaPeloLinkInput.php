<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * "Crie sua senha" do link de confirmação: o token da URL e a senha nova duas vezes, crus como chegaram.
 *
 * Sem #[Assert] de propósito: a ordem dos erros e os textos são os da troca de senha por código, e quem valida é o
 * CriarSenhaPeloLinkUseCase (o token primeiro, depois a régua, depois a confirmação).
 */
final readonly class CriarSenhaPeloLinkInput
{
    public function __construct(
        public string $token,
        public string $senha,
        public string $confirmacao,
    ) {
    }
}
