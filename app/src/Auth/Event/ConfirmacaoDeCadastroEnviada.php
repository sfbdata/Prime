<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * O CadastroMailer mandou a confirmação do cadastro público. Despachado depois do send, para o registro do Comercial (spec `comercial-e-emails.md` §3.5). Só ids e o necessário: quem ouve relê o resto. Um ouvinte nunca pode
 * derrubar o fluxo de Auth: os do Comercial capturam qualquer exceção.
 */
final class ConfirmacaoDeCadastroEnviada
{
    public function __construct(
        public readonly string $email, public readonly string $assunto,
    ) {
    }
}
