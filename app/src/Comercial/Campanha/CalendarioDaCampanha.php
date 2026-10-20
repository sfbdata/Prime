<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

/**
 * As datas da campanha recorrente (spec §8.4, A9): +7 dias, +14 dias, ou o dia original do mês seguinte limitado ao
 * último dia (31/01 → 28/02 → 31/03: o mensal ancora sempre no dia da primeira execução). Hora local, a da âncora.
 */
final class CalendarioDaCampanha
{
    private const MAXIMO_DE_PASSOS = 2000;

    /** A execução seguinte à `atual`; null na única. */
    public static function seguinte(string $frequencia, \DateTimeImmutable $ancora, \DateTimeImmutable $atual): ?\DateTimeImmutable
    {
        return match ($frequencia) {
            'semanal' => $atual->modify('+7 days'),
            'quinzenal' => $atual->modify('+14 days'),
            'mensal' => self::mesSeguinte($ancora, $atual),
            default => null,
        };
    }

    /** A primeira execução da série depois de `depoisDe` (nada é recuperado: as que passaram são puladas). */
    public static function primeiraDepois(string $frequencia, \DateTimeImmutable $ancora, \DateTimeImmutable $atual, \DateTimeImmutable $depoisDe): ?\DateTimeImmutable
    {
        $data = $atual;
        for ($passo = 0; $passo < self::MAXIMO_DE_PASSOS && $data <= $depoisDe; ++$passo) {
            $data = self::seguinte($frequencia, $ancora, $data);
            if ($data === null) {
                return null;
            }
        }

        return $data;
    }

    private static function mesSeguinte(\DateTimeImmutable $ancora, \DateTimeImmutable $atual): \DateTimeImmutable
    {
        $primeiroDoMes = $atual->setDate((int) $atual->format('Y'), (int) $atual->format('n'), 1)->modify('+1 month');
        $dia = min((int) $ancora->format('j'), (int) $primeiroDoMes->format('t'));

        return $primeiroDoMes
            ->setDate((int) $primeiroDoMes->format('Y'), (int) $primeiroDoMes->format('n'), $dia)
            ->setTime((int) $ancora->format('G'), (int) $ancora->format('i'));
    }
}
