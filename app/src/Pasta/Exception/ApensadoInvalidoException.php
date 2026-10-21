<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O processo não pode ser vinculado como apensado desta pasta: já é o principal ou já está vinculado.
 * A mensagem é o texto do desenho (`procSalvarVinc`, N:4552-4553) e vai direto para a tela (422).
 */
final class ApensadoInvalidoException extends \DomainException
{
}
