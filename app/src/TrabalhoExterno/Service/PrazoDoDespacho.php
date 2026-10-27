<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Agenda\DTO\DataDoSistemaOutput;
use App\Agenda\Service\CalendarioDoSistema;

/**
 * O prazo do despacho em dias úteis (desenho 06 de 24/10, `dpPrazo`/`dpUteis`, l.1393): o padrão é hoje mais 5
 * dias úteis, e o contador "N dias úteis" conta de amanhã até o prazo. O desenho pula só sábado e domingo; aqui
 * também os feriados do sistema (decisão P5): os do calendário nacional do `CalendarioDoSistema` (o mesmo da
 * Agenda, com os móveis pela Páscoa e os pontos facultativos nacionais). O recesso forense não entra: não é
 * feriado, e o despacho da Central não é prazo processual.
 */
final class PrazoDoDespacho
{
    public const DIAS_PADRAO = 5;

    /** @var array<int, array<string, true>> ano => 'Y-m-d' dos feriados */
    private array $feriadosPorAno = [];

    public function __construct(
        private readonly CalendarioDoSistema $calendario,
    ) {
    }

    public function ehDiaUtil(\DateTimeImmutable $dia): bool
    {
        return (int) $dia->format('N') < 6 && !isset($this->feriadosDoAno((int) $dia->format('Y'))[$dia->format('Y-m-d')]);
    }

    /** `while (n < 5) { d.setDate(d.getDate() + 1); if (útil) n++; }`: o quinto dia útil depois de hoje. */
    public function padrao(\DateTimeImmutable $hoje): \DateTimeImmutable
    {
        $dia = $hoje->setTime(0, 0);
        $contados = 0;
        while ($contados < self::DIAS_PADRAO) {
            $dia = $dia->modify('+1 day');
            if ($this->ehDiaUtil($dia)) {
                ++$contados;
            }
        }

        return $dia;
    }

    /** Os dias úteis de amanhã até `$prazo`, inclusive (`dpUteis`); prazo de hoje ou passado dá 0. */
    public function uteisAte(\DateTimeImmutable $prazo, \DateTimeImmutable $hoje): int
    {
        $fim = $prazo->setTime(0, 0);
        $uteis = 0;
        for ($dia = $hoje->setTime(0, 0)->modify('+1 day'); $dia <= $fim; $dia = $dia->modify('+1 day')) {
            if ($this->ehDiaUtil($dia)) {
                ++$uteis;
            }
        }

        return $uteis;
    }

    /**
     * Os feriados que caem de segunda a sexta entre amanhã e `$dias` dias depois: a tela conta "N dias úteis"
     * enquanto a data muda, sem perguntar ao servidor.
     *
     * @return list<string> 'Y-m-d'
     */
    public function feriadosEmDiaDeSemana(\DateTimeImmutable $hoje, int $dias = 400): array
    {
        $saida = [];
        $fim = $hoje->setTime(0, 0)->modify(sprintf('+%d days', $dias));
        for ($dia = $hoje->setTime(0, 0)->modify('+1 day'); $dia <= $fim; $dia = $dia->modify('+1 day')) {
            if ((int) $dia->format('N') < 6 && !$this->ehDiaUtil($dia)) {
                $saida[] = $dia->format('Y-m-d');
            }
        }

        return $saida;
    }

    /** @return array<string, true> */
    private function feriadosDoAno(int $ano): array
    {
        if (isset($this->feriadosPorAno[$ano])) {
            return $this->feriadosPorAno[$ano];
        }
        $feriados = [];
        $datas = $this->calendario->doPeriodo(new \DateTimeImmutable(sprintf('%04d-01-01', $ano)), new \DateTimeImmutable(sprintf('%04d-12-31', $ano)), []);
        foreach ($datas as $data) {
            if ($data->calendario === DataDoSistemaOutput::CAL_NACIONAL) {
                $feriados[$data->data] = true;
            }
        }

        return $this->feriadosPorAno[$ano] = $feriados;
    }
}
