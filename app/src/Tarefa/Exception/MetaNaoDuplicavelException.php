<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando a meta não pode ser duplicada (a pasta dela foi excluída e é lápide, somente leitura).
 * Falha de regra de negócio: o controller devolve a mensagem ao usuário.
 */
final class MetaNaoDuplicavelException extends \DomainException
{
}
