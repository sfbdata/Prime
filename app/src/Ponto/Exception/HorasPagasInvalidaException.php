<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Erro de regra de negócio do lançamento de horas pagas. A mensagem é escrita para o usuário final:
 * o controller a repassa direto para o flash.
 */
final class HorasPagasInvalidaException extends \DomainException
{
}
