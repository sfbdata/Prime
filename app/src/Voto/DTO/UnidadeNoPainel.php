<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Um quadradinho do painel de unidades: só a identificação e o estado. Nunca nome, voto nem hora. */
final readonly class UnidadeNoPainel
{
    public const VOTOU = 'votou';
    public const NAO_VOTOU = 'nao_votou';
    public const INAPTA = 'inapta';

    public function __construct(
        public string $identificacao,
        public string $situacao,
    ) {
    }
}
