<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Em qual parte do arquivo a linha estava (SPEC espelho §5.4).
 *
 * Toda linha do .xlsx cai em EXATAMENTE um destes, e a soma dos seis tem que dar o número de linhas
 * do arquivo — é a reconciliação que prova que o leitor não perdeu nada. A precedência está na §5.4
 * da spec e é obrigatória: linha totalmente vazia é `Branca` antes de qualquer outra classificação,
 * senão a linha em branco do cabeçalho cabe em dois baldes.
 *
 * Medido no TL1 de 12/08 (4.145 linhas): cabecalho 5 · dados 4.123 · totalizador 8 ·
 * cabecalho_bloco2 1 · rodape 3 · branca 5. Os números variam por arquivo — a spec assere a
 * IDENTIDADE (soma == total), nunca as contagens.
 */
enum BlocoRelatorio: string
{
    case Cabecalho = 'cabecalho';
    case Dados = 'dados';
    case CabecalhoBloco2 = 'cabecalho_bloco2';
    case Totalizador = 'totalizador';
    case Rodape = 'rodape';
    case Branca = 'branca';
}
