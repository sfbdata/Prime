<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * O gateway não respondeu como deveria (fora do ar, tempo esgotado, resposta inesperada). Temporária: o
 * pedido fica como estava e a conciliação tenta de novo. A mensagem é para log, não para a tela.
 */
final class FalhaNoGateway extends \RuntimeException
{
}
