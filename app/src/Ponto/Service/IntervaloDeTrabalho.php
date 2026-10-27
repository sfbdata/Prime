<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * Um trecho de trabalho num dia da semana (1 = segunda ... 7 = domingo), em minutos desde a meia-noite. O repouso fica
 * fora: entrada a repouso é um trecho, retorno a saída é outro (spec seguranca-b-jornada §3.2).
 */
final class IntervaloDeTrabalho
{
    public function __construct(
        public readonly int $diaSemana,
        public readonly int $inicio,
        public readonly int $fim,
    ) {
    }

    /** Os dois trechos têm algum minuto em comum no mesmo dia. Encostar (um acaba quando o outro começa) não conta. */
    public function sobrepoe(self $outro): bool
    {
        return $this->diaSemana === $outro->diaSemana && $this->inicio < $outro->fim && $outro->inicio < $this->fim;
    }

    /** O instante (dia da semana e segundos desde a meia-noite) cai dentro do trecho, sem as pontas. */
    public function contem(int $diaSemana, int $segundosDoDia): bool
    {
        return $this->diaSemana === $diaSemana && $this->inicio * 60 < $segundosDoDia && $segundosDoDia < $this->fim * 60;
    }

    /** @return array{0: string, 1: string} início e fim em HH:MM, para a mensagem */
    public function horario(): array
    {
        return [self::hhmm($this->inicio), self::hhmm($this->fim)];
    }

    public static function minutos(string $hhmm): int
    {
        [$horas, $minutos] = array_map('intval', explode(':', $hhmm) + [1 => '0']);

        return $horas * 60 + $minutos;
    }

    private static function hhmm(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
