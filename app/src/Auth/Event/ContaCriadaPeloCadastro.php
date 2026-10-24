<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * Conta e escritório criados pelo "Criar minha conta" da tela de entrada (desenho 10). Despachado DEPOIS do commit
 * (spec `login-desenho-10.md`). Pelo e-mail, a conta ainda não está confirmada neste momento: quem quer o e-mail
 * confirmado ouve o EmailDaContaConfirmado. Pelo Google (spec entrar-com-google, entrega 2), a conta nasce confirmada
 * e o EmailDaContaConfirmado sai logo depois deste. Um ouvinte nunca pode derrubar o cadastro.
 */
final class ContaCriadaPeloCadastro
{
    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
    ) {
    }
}
