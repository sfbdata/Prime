<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\IntervaloDosConvites;
use App\Comercial\DTO\IntervaloDeConviteOutput;

/** O intervalo entre convites aos contatos salvos para a Central de envios (só leitura, só o Master). */
final class ConsultarIntervaloDeConviteUseCase
{
    public function __construct(private readonly IntervaloDosConvites $intervalo)
    {
    }

    public function executar(): IntervaloDeConviteOutput
    {
        return new IntervaloDeConviteOutput($this->intervalo->dias(), $this->intervalo->origem());
    }
}
