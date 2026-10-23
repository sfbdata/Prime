<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\HorarioFuturoDaBatida;

/**
 * A batida pedida fica acima de agora no fuso do escritório. A mensagem é a da tela; o controller
 * confere antes (recusa rápida) e esta exceção é a última guarda de quem grava a batida.
 */
final class BatidaEmHorarioFuturoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(HorarioFuturoDaBatida::MENSAGEM);
    }
}
