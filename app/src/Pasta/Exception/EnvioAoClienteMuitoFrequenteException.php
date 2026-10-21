<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** Passou do limite de envios da pasta por hora (429). */
final class EnvioAoClienteMuitoFrequenteException extends \DomainException
{
    public const MENSAGEM = 'Muitos envios em pouco tempo. Tente de novo mais tarde.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
