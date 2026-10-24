<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * A pasta de onde veio o pedido está excluída (lápide, somente leitura): a foto não muda por ela. O
 * `PastaSomenteLeituraListener` já recusa a rota antes; o UseCase repete a regra para não depender da rota. 403.
 */
final class FotoDoClienteRecusadaException extends \DomainException
{
}
