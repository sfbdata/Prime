<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use Psr\Clock\ClockInterface;

/** Dinheiro, datas (sempre no fuso America/Sao_Paulo, D9) e o "dobro/triplo" da campanha, como no desenho. */
final class FormatoDaCampanha
{
    public const FUSO = 'America/Sao_Paulo';

    /** "R$ 1,00" (sem separador de milhar, como no desenho). */
    public static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '');
    }

    /** "dd/mm/aaaa" de uma data de calendário (início e fim da campanha). */
    public static function data(\DateTimeInterface $data): string
    {
        return $data->format('d/m/Y');
    }

    /** "dd/mm/aaaa" de um instante, no fuso de São Paulo. */
    public static function dataDoInstante(\DateTimeImmutable $instante): string
    {
        return self::diaDoInstante($instante)->format('d/m/Y');
    }

    /** A data (meia-noite de São Paulo) em que o instante cai. */
    public static function diaDoInstante(\DateTimeImmutable $instante): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instante->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d'), new \DateTimeZone(self::FUSO));
    }

    public static function hoje(ClockInterface $relogio): \DateTimeImmutable
    {
        return self::diaDoInstante($relogio->now());
    }

    public static function multiplicador(int $multiplicador): string
    {
        return $multiplicador === 3 ? 'o triplo' : 'o dobro';
    }
}
