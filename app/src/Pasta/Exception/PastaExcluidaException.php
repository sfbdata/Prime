<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A pasta está excluída (lápide) e fica somente para leitura. Na tela o PastaSomenteLeituraListener recusa antes
 * do controller; quem chega sem passar por ele (o conector de I.A.) recebe esta exceção do UseCase.
 */
final class PastaExcluidaException extends \DomainException
{
}
