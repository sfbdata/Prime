<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando o Lembrar da meta (desenho 04B L50, L133) não pode avisar ninguém: meta concluída, ninguém além de
 * quem pede, ou lembrete repetido dentro de uma hora. A mensagem é a que o usuário lê.
 */
final class LembreteRecusadoException extends \DomainException
{
}
