<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/** Um campo da edição do cartão do processo não passou (P5b). Nada é gravado; o controller responde 422. */
final class DadosDoProcessoInvalidosException extends \DomainException
{
}
