<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Situação gravada do Termo de Voluntariado. O rascunho do desenho (`tm.status` vazio) não é gravado:
 * existe só na tela, até o "Enviar para aceite".
 */
enum StatusDoTermo: string
{
    case Enviado = 'enviado';
    case Aceito = 'aceito';
}
