<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O limite e o prazo do banco de horas que ficaram gravados no escritório depois da mudança (E17, P73). A tela põe nos
 * campos o que o servidor devolveu.
 */
final class LimitesDoBancoOutput
{
    public function __construct(
        public readonly int $limiteHoras,
        public readonly int $prazoMeses,
    ) {
    }

    /** @return array{limiteHoras: int, prazoMeses: int} */
    public function paraJson(): array
    {
        return [AlterarLimitesDoBancoInput::LIMITE => $this->limiteHoras, AlterarLimitesDoBancoInput::PRAZO => $this->prazoMeses];
    }
}
