<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/** Situação de cada signatário (contrato da etapa 1, seção 3.1). */
enum SituacaoDoSignatario: string
{
    case Pendente = 'pendente';
    case Assinado = 'assinado';
    case Recusado = 'recusado';
}
