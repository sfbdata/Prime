<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando o novo nome de uma meta não pode ser gravado (vazio ou maior que a
 * coluna). Falha de regra de negócio — o controller devolve a mensagem ao usuário.
 */
final class TituloDeMetaInvalidoException extends \DomainException
{
}
