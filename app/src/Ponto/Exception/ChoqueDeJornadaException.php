<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A gravação bate com o horário de trabalho da pessoa em outro escritório (spec seguranca-b-jornada, regra 3). A
 * mensagem é a do choque, escrita para o usuário final e sem o nome do outro escritório.
 */
final class ChoqueDeJornadaException extends \DomainException
{
}
