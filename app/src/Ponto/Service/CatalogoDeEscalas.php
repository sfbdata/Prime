<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * As 4 escalas do desenho 16 (`ESC` L409; inventário §6.4), com os ids e os rótulos exatos, na ordem do desenho
 * (spec `docs/specs/ponto-desenho-0811.md`, P56 a: modelos que preenchem os blocos da jornada, sem tabela nova).
 *
 * A escala de uma pessoa é a que tem os mesmos minutos em cada dia da semana que a jornada que vale hoje; os
 * horários não contam. Nenhuma bate: a tela descreve a atual ("Jornada do escritório (40h)", "Personalizada (42h30)").
 */
final class CatalogoDeEscalas
{
    /** @return list<EscalaDoCatalogo> */
    public static function todas(): array
    {
        return [
            new EscalaDoCatalogo('44-6', '44h · seg a sáb (7h20)', 44 * 60, [1, 2, 3, 4, 5, 6], 440),
            new EscalaDoCatalogo('44-5', '44h · seg a sex (8h48)', 44 * 60, [1, 2, 3, 4, 5], 528),
            new EscalaDoCatalogo('40-5', '40h · seg a sex (8h)', 40 * 60, [1, 2, 3, 4, 5], 480),
            new EscalaDoCatalogo('30-5', '30h · seg a sex (6h)', 30 * 60, [1, 2, 3, 4, 5], 360),
        ];
    }

    public static function pelo(string $id): ?EscalaDoCatalogo
    {
        foreach (self::todas() as $escala) {
            if ($escala->id === $id) {
                return $escala;
            }
        }

        return null;
    }

    /** @param array<int, int> $minutosDaSemana minutos de cada dia, de 1 (segunda) a 7 (domingo) */
    public static function correspondente(array $minutosDaSemana): ?EscalaDoCatalogo
    {
        foreach (self::todas() as $escala) {
            if ($escala->minutosDaSemana() === $minutosDaSemana) {
                return $escala;
            }
        }

        return null;
    }

    /** A carga como o rótulo da escala mostra: "44h"; com minutos, "42h30". */
    public static function horas(int $minutos): string
    {
        $resto = $minutos % 60;

        return $resto === 0 ? intdiv($minutos, 60) . 'h' : sprintf('%dh%02d', intdiv($minutos, 60), $resto);
    }
}
