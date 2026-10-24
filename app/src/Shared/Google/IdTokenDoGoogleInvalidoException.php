<?php

declare(strict_types=1);

namespace App\Shared\Google;

/** O `id_token` do Google não serve: de outro cliente, de outro emissor, vencido, de outro pedido ou sem e-mail confirmado. */
final class IdTokenDoGoogleInvalidoException extends \RuntimeException
{
}
