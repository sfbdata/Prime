<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/** O pedido de redistribuição é inválido (400): vazio, acima de 50 metas, meta repetida, destino que não pode receber. Nada é gravado. */
final class RedistribuicaoRecusadaException extends \DomainException
{
}
