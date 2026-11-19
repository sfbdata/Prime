<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * A análise do prazo da meta nova ainda não pode ser gravada (prazo jurídico só estimado ou a conferir): a
 * pessoa confirma a análise ou informa o prazo jurídico antes. A tela responde com o código `analise_do_prazo`.
 */
final class AnaliseDoPrazoRecusadaException extends \DomainException
{
}
