<?php

declare(strict_types=1);

namespace App\Master\DTO;

/** As abas da Central do Master, com as chaves do desenho (nfAbas): rec, nl, est, resp, arq. */
final class AbaDaCentral
{
    public const RECEBIDAS = 'rec';
    public const NAO_LIDAS = 'nl';
    public const COM_ESTRELA = 'est';
    public const RESPONDIDAS = 'resp';
    public const ARQUIVADAS = 'arq';

    public const TODAS = [self::RECEBIDAS, self::NAO_LIDAS, self::COM_ESTRELA, self::RESPONDIDAS, self::ARQUIVADAS];

    public static function valida(string $aba): string
    {
        return in_array($aba, self::TODAS, true) ? $aba : self::RECEBIDAS;
    }
}
