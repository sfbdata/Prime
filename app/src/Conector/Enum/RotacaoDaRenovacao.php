<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * O que a troca de uma renovação foi (`docs/specs/mcp-remoto-oauth.md`, 9.1): a primeira (emite o par novo) ou a repetição
 * do mesmo app dentro da janela de tolerância (revoga o par emitido antes a partir dela e emite outro).
 */
enum RotacaoDaRenovacao
{
    case Primeira;
    case RepeticaoNaJanela;
}
