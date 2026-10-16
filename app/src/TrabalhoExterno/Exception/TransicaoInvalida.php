<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Exception;

/** A ação não cabe no estado atual da demanda (o controller responde 409 com a mensagem). */
final class TransicaoInvalida extends \DomainException
{
}
