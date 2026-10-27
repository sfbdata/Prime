<?php

declare(strict_types=1);

namespace App\ServiceDesk\Exception;

final class SaldoInsuficiente extends \DomainException
{
    public static function paraAbrirChamado(): self
    {
        return new self('Saldo insuficiente para abrir o chamado.');
    }

    public static function para(int $saldoCentavos, int $pedidoCentavos): self
    {
        return new self(sprintf('Saldo insuficiente: há %d centavos e o valor é %d.', $saldoCentavos, $pedidoCentavos));
    }
}
