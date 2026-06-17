<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando uma meta (Tarefa) não satisfaz as regras de exclusão
 * (autor, janela de tempo). É uma falha de regra de negócio — distinta de
 * negação de acesso (AccessDeniedException) — para que o controller a trate
 * com mensagem ao usuário sem mascarar erros de autorização.
 */
final class MetaNaoExcluivelException extends \DomainException
{
}
