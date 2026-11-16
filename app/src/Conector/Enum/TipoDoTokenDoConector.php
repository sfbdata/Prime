<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * Os dois tokens opacos do conector, na mesma tabela (`docs/specs/mcp-remoto-oauth.md`, 13.2 e decisão 14.c): o de
 * acesso vale 1 hora; o de renovação vale até o fim da concessão (7 dias da autorização) e gira a cada uso.
 */
enum TipoDoTokenDoConector: string
{
    case Acesso = 'acesso';
    case Renovacao = 'renovacao';
}
