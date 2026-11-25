<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\IntervaloDosConvites;

/**
 * O intervalo entre convites ao mesmo contato salvo, como a Central de envios mostra: os dias em vigor, de onde vêm
 * (`painel` ou `padrao`) e a faixa que vale (mínimo, máximo e padrão).
 */
final readonly class IntervaloDeConviteOutput
{
    public function __construct(
        public int $dias,
        public string $origem,
    ) {
    }

    /** @return array{dias: int, origem: string, padrao: int, minimo: int, maximo: int} */
    public function paraJson(): array
    {
        return [
            'dias' => $this->dias, 'origem' => $this->origem,
            'padrao' => IntervaloDosConvites::PADRAO, 'minimo' => IntervaloDosConvites::MINIMO, 'maximo' => IntervaloDosConvites::MAXIMO,
        ];
    }
}
