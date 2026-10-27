<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/** Quem tentou criar, renovar ou encerrar o acompanhamento não é Master nem primário ativo. Vira 403. */
final class AcompanhamentoNaoPermitidoException extends \DomainException
{
}
