<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\TextoDoPonto;

/**
 * A gravação bate com o horário de trabalho da pessoa em outro escritório (spec seguranca-b-jornada, regra 3). A
 * mensagem é a do choque, escrita para o usuário final e sem o nome do outro escritório. A mensagem da exceção é o português; quem a leva à tela
 * traduz o {@see self::texto()} (spec idioma-do-sistema §32).
 */
final class ChoqueDeJornadaException extends \DomainException
{
    public function __construct(private readonly TextoDoPonto $texto)
    {
        parent::__construct($texto->portugues);
    }

    public function texto(): TextoDoPonto
    {
        return $this->texto;
    }
}
