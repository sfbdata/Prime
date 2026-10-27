<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * "Crie sua senha" do link de confirmação: o token da URL, a senha nova duas vezes e a caixa "Li e aceito", crus
 * como chegaram, mais o IP e o user agent do pedido, que vão para o aceite dos Termos e a ciência da Política.
 *
 * Sem #[Assert] de propósito: a ordem dos erros e os textos são os do cadastro e da troca de senha por código, e quem
 * valida é o CriarSenhaPeloLinkUseCase (o token primeiro, depois o aceite, a régua e a confirmação).
 */
final readonly class CriarSenhaPeloLinkInput
{
    public function __construct(
        public string $token,
        public string $senha,
        public string $confirmacao,
        public bool $aceite,
        public string $ip,
        public ?string $userAgent,
    ) {
    }
}
