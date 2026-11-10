<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * O que o validador de cadeia (etapa 6, E6-7) disse sobre as assinaturas novas de uma versão devolvida, gravado em
 * `cadeia_situacao`: `confirmada` (cadeia até a âncora do nível, revogação boa, cobertura e modificação aceitas),
 * `recusada` (o validador respondeu e alguma regra da E6-6 falhou) ou `indisponivel` (o validador não respondeu).
 */
enum SituacaoDaCadeia: string
{
    case Confirmada = 'confirmada';
    case Recusada = 'recusada';
    case Indisponivel = 'indisponivel';
}
