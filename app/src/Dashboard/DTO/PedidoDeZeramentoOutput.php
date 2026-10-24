<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Resultado do "Pedir para zerar": quantas pessoas foram avisadas, se a Controladoria estava entre
 * elas (o texto da tela só cita a Controladoria quando ela existe) e se o pedido repetia um do mesmo
 * dia (o desenho: "Você já tinha pedido isso hoje").
 */
final class PedidoDeZeramentoOutput
{
    public function __construct(
        public readonly int $avisados,
        public readonly bool $comControladoria,
        public readonly bool $repetido,
    ) {
    }

    /** @return array{avisados: int, controladoria: bool, repetido: bool} */
    public function paraArray(): array
    {
        return ['avisados' => $this->avisados, 'controladoria' => $this->comControladoria, 'repetido' => $this->repetido];
    }
}
