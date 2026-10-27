<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/** A pessoa de origem ou uma meta do pedido não existe no escritório da sessão (404, IDOR: não confirma que o id existe em outro). */
final class RedistribuicaoForaDoEscritorioException extends \DomainException
{
}
