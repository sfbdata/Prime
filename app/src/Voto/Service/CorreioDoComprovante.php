<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\ComprovanteDeParticipacao;

interface CorreioDoComprovante
{
    public function enviar(string $destinatario, ComprovanteDeParticipacao $comprovante): void;
}
