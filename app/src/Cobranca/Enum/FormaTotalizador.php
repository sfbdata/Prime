<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * O bloco totalizador do relatório tem DUAS formas de layout, e confundi-las grava valor nulo
 * (SPEC espelho §4.1, achado 4 da re-revisão).
 *
 * - `Larga`: layout de 15 colunas, igual ao bloco de dados — rótulo em A, valores em H..M.
 *   É a linha "Total inadimplência das unidades".
 * - `Estreita`: layout de 7 colunas — rótulo em A, valores em B..G. São as linhas por classe de
 *   conta e o "Total de inadimplência".
 *
 * O leitor normaliza as duas para as mesmas colunas, mas REGISTRA de qual veio: sem isso não há como
 * provar que a normalização acertou.
 */
enum FormaTotalizador: string
{
    case Larga = 'larga';
    case Estreita = 'estreita';
}
