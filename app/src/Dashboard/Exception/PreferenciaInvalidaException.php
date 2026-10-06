<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/**
 * Chave fora da lista fechada do menu ⋮, ou valor que ela não aceita. Vira 400 no controller.
 */
final class PreferenciaInvalidaException extends \DomainException
{
}
