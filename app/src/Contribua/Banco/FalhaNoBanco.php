<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** O banco não respondeu ou recusou o pedido. Quem chama cai no PIX estático ou tenta de novo depois. */
final class FalhaNoBanco extends \RuntimeException
{
}
