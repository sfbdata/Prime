<?php

declare(strict_types=1);

namespace App\Voto\Exception;

final class LimiteDeEnviosDoComprovanteException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Limite de 3 envios por hora por unidade atingido. Aguarde para tentar novamente.');
    }
}
