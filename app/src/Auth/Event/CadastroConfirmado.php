<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * Conta nova confirmada pelo link do cadastro público. Despachado DEPOIS de o wrapInTransaction do ConfirmarCadastroUseCase retornar (spec `comercial-e-emails.md` §3.5). Só ids e o necessário: quem ouve relê o resto. Um ouvinte nunca pode
 * derrubar o fluxo de Auth: os do Comercial capturam qualquer exceção.
 */
final class CadastroConfirmado
{
    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
    ) {
    }
}
