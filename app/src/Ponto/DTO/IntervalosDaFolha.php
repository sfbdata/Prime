<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O quadro "CONTROLE DE INTERVALOS" da folha em PDF (desenho 16 L689 a L693): intrajornada e interjornada com os dias
 * em que ficaram abaixo do mínimo, o descanso semanal (mais de 6 dias seguidos com batida é "Não conforme") e a
 * contagem de feriados e fins de semana trabalhados. Os mínimos são os da jornada do escritório, em minutos.
 */
final readonly class IntervalosDaFolha
{
    /** Mais de 6 dias seguidos com batida: descanso semanal "Não conforme" (desenho L691). */
    public const DIAS_SEGUIDOS_PERMITIDOS = 6;

    /**
     * @param list<string> $diasIntrajornada dias do mês ('03') com intervalo menor que o mínimo
     * @param list<string> $diasInterjornada dias do mês com descanso entre jornadas menor que o mínimo
     */
    public function __construct(
        public int $minimoIntrajornada,
        public array $diasIntrajornada,
        public int $minimoInterjornada,
        public array $diasInterjornada,
        public int $maiorSequencia,
        public int $feriadosTrabalhados,
        public int $finaisDeSemana,
    ) {
    }

    public function intrajornadaConforme(): bool
    {
        return $this->diasIntrajornada === [];
    }

    public function interjornadaConforme(): bool
    {
        return $this->diasInterjornada === [];
    }

    public function descansoSemanalConforme(): bool
    {
        return $this->maiorSequencia <= self::DIAS_SEGUIDOS_PERMITIDOS;
    }

    /** "1h00", como o desenho escreve os mínimos (L663 e L690). */
    public static function duracao(int $minutos): string
    {
        return sprintf('%dh%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /**
     * "Dias 03, 05" embaixo do "Não conforme" (L690); vazio quando conforme.
     *
     * @param list<string> $dias
     */
    public static function textoDosDias(array $dias): string
    {
        return $dias === [] ? '' : 'Dias ' . implode(', ', $dias);
    }

    /** "Feriados trabalhados: N · Finais de semana: N", e " · N dias seguidos" quando passa de 6 (L692). */
    public function textoDaContagem(): string
    {
        $texto = sprintf('Feriados trabalhados: %d · Finais de semana: %d', $this->feriadosTrabalhados, $this->finaisDeSemana);

        return $this->descansoSemanalConforme() ? $texto : $texto . sprintf(' · %d dias seguidos', $this->maiorSequencia);
    }
}
