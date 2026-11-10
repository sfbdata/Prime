<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

/**
 * O que a pessoa respondeu no aceite mensal da folha (spec `ponto-desenho-0811.md`, E16, P22 e P33; desenho 16 L290 e
 * L809): concorda com os registros do mês ou assina com ressalva. O código é o que a coluna `situacao` guarda e o que o
 * selo do aceite cobre, então um código gravado nunca muda.
 */
enum SituacaoDoAceiteDaFolha: string
{
    case Concordo = 'concordo';
    case Ressalva = 'ressalva';
}
