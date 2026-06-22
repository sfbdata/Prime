<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando o prazo de uma meta (Tarefa) não pode ser alterado pelo usuário
 * (não é o criador, ou tenant divergente). É uma falha de regra de negócio —
 * distinta de negação de acesso (AccessDeniedException) — para que o controller a
 * trate com mensagem ao usuário sem mascarar erros de autorização.
 */
final class PrazoNaoEditavelException extends \DomainException
{
}
