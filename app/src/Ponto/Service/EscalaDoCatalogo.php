<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\BlocoJornadaColaborador;

/**
 * Uma das 4 escalas do desenho 16 (`ESC` L409; inventário §6.4) como modelo que preenche os blocos da jornada pessoal
 * (spec `docs/specs/ponto-desenho-0811.md`, P56 a). Horários do PDF do desenho: entrada às 09:00, intervalo das 12:00
 * às 13:00 e a saída que fecha a carga do dia (13:00 mais a carga do dia menos as 3 horas da manhã).
 */
final readonly class EscalaDoCatalogo
{
    private const ENTRADA = 9 * 60;
    private const REPOUSO = 12 * 60;
    private const RETORNO = 13 * 60;

    /**
     * @param list<int> $dias          dias de trabalho, 1 (segunda) a 7 (domingo)
     * @param int       $cargaSemanal  em minutos
     * @param int       $minutosPorDia em minutos, igual em cada dia de trabalho
     */
    public function __construct(
        public string $id,
        public string $rotulo,
        public int $cargaSemanal,
        public array $dias,
        public int $minutosPorDia,
    ) {
    }

    /** @return array<int, int> minutos de cada dia da semana, de 1 (segunda) a 7 (domingo) */
    public function minutosDaSemana(): array
    {
        $minutos = [];
        for ($dia = 1; $dia <= 7; ++$dia) {
            $minutos[$dia] = in_array($dia, $this->dias, true) ? $this->minutosPorDia : 0;
        }

        return $minutos;
    }

    /** @return list<BlocoJornadaColaborador> um bloco só, com os dias de trabalho, ainda sem jornada */
    public function blocos(): array
    {
        $saida = self::RETORNO + $this->minutosPorDia - (self::REPOUSO - self::ENTRADA);

        return [(new BlocoJornadaColaborador())
            ->setDiasSemana($this->dias)
            ->setEntrada(self::hora(self::ENTRADA))
            ->setRepouso(self::hora(self::REPOUSO))
            ->setRetorno(self::hora(self::RETORNO))
            ->setSaida(self::hora($saida))
            ->setMinutosBloco($this->minutosPorDia)];
    }

    private static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
