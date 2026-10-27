<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/** Pedido de acompanhamento inválido (prazo fora de 1/2/7, o próprio gestor ou o Master como alvo). Vira 400. */
final class AcompanhamentoInvalidoException extends \DomainException
{
}
