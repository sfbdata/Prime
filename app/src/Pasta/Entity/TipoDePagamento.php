<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * O tipo de um lançamento do card Pagamentos da pasta (desenho 02, `FIN_TIPOS`).
 *
 * Contrato e custas são valores combinados, com vencimento. Êxito e sucumbência são
 * CONDICIONADOS ao resultado: nascem como percentual, sem vencimento, e ficam fora do
 * contratado até serem recebidos (spec pasta-exito-sucumbencia).
 */
enum TipoDePagamento: string
{
    case Contrato    = 'contrato';
    case Exito       = 'exito';
    case Sucumbencia = 'sucumbencia';
    case Custas      = 'custas';
    /** Honorários mensais fixos: o mesmo valor todo mês, pelo número de meses (dc 27.10). */
    case Recorrente  = 'recorrente';

    /** Êxito e sucumbência: só existem se houver resultado. */
    public function ehCondicionado(): bool
    {
        return $this === self::Exito || $this === self::Sucumbencia;
    }
}
