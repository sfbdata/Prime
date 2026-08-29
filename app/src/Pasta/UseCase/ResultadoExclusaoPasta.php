<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O que a exclusão de fato fez. Quem chama precisa saber: a mensagem na tela e o destino do
 * redirect mudam — a lápide continua existindo e pode ser aberta, a removida não.
 */
enum ResultadoExclusaoPasta
{
    /** A pasta tinha posterior: virou lápide (riscada, arquivada, somente-leitura). */
    case Lapide;

    /** A pasta era a última da sequência: foi apagada de verdade e o número voltou a ser livre. */
    case Removida;
}
