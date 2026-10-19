<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\DTO\DataDoSistemaOutput;

/**
 * Dias úteis forenses de âmbito NACIONAL, para contar prazo em dias úteis (CPC arts. 219 e 224).
 * Porte do `diaUtil`/`somaUteis`/`uteisAte` do desenho 1.2.3 (dc L.5089-5091):
 *   - sábado e domingo não contam;
 *   - o recesso de 20/12 a 20/01 não conta (CPC art. 220);
 *   - os feriados nacionais não contam.
 *
 * Os feriados NÃO são uma lista nova: saem do calendário nacional do `CalendarioDoSistema` (que
 * calcula os móveis pela Páscoa para qualquer ano). O desenho (`FERIADOS_NAC`, dc L.5079) conta
 * também a SEGUNDA de Carnaval (16/02/2026, 08/02/2027), que o calendário nacional não traz (lá ela
 * é data dos tribunais): ela entra aqui pela própria Páscoa, a 48 dias dela.
 *
 * Feriados locais e suspensões do tribunal ficam de fora, como diz a dica da previsão.
 */
final class DiasUteisNacionais
{
    /** @var array<int, array<string, true>> ano => datas 'Y-m-d' de feriado nacional */
    private array $feriadosPorAno = [];

    public function __construct(
        private readonly CalendarioDoSistema $calendario,
    ) {
    }

    public function ehDiaUtil(\DateTimeImmutable $dia): bool
    {
        if ((int) $dia->format('N') >= 6 || self::noRecesso($dia)) {
            return false;
        }

        return !isset($this->feriadosDoAno((int) $dia->format('Y'))[$dia->format('Y-m-d')]);
    }

    /**
     * Último dia de um prazo de `$dias` dias úteis publicado em `$publicacao` (dc L.5090,
     * `somaUteis`): o dia da publicação não conta; a contagem começa no 1º dia útil seguinte, que
     * já é o dia 1.
     */
    public function somarUteis(\DateTimeImmutable $publicacao, int $dias): \DateTimeImmutable
    {
        $dia = $this->primeiroUtilDepois($publicacao->setTime(0, 0));
        $contados = 1;
        while ($contados < $dias) {
            $dia = $dia->modify('+1 day');
            if ($this->ehDiaUtil($dia)) {
                ++$contados;
            }
        }

        return $dia;
    }

    /**
     * Quantos dias úteis faltam de `$hoje` até `$fim` (dc L.5091, `uteisAte`): -1 quando o fim já
     * passou; 0 quando o fim é hoje.
     */
    public function uteisAte(\DateTimeImmutable $fim, \DateTimeImmutable $hoje): int
    {
        $fim = $fim->setTime(0, 0);
        $dia = $hoje->setTime(0, 0);
        if ($fim < $dia) {
            return -1;
        }

        $uteis = 0;
        while ($dia < $fim) {
            $dia = $dia->modify('+1 day');
            if ($this->ehDiaUtil($dia)) {
                ++$uteis;
            }
        }

        return $uteis;
    }

    private function primeiroUtilDepois(\DateTimeImmutable $dia): \DateTimeImmutable
    {
        do {
            $dia = $dia->modify('+1 day');
        } while (!$this->ehDiaUtil($dia));

        return $dia;
    }

    private static function noRecesso(\DateTimeImmutable $dia): bool
    {
        $mes = (int) $dia->format('n');
        $diaDoMes = (int) $dia->format('j');

        return ($mes === 12 && $diaDoMes >= 20) || ($mes === 1 && $diaDoMes <= 20);
    }

    /** @return array<string, true> */
    private function feriadosDoAno(int $ano): array
    {
        if (isset($this->feriadosPorAno[$ano])) {
            return $this->feriadosPorAno[$ano];
        }

        $datas = $this->calendario->doPeriodo(
            new \DateTimeImmutable(sprintf('%04d-01-01', $ano)),
            new \DateTimeImmutable(sprintf('%04d-12-31', $ano)),
            [],
        );
        $feriados = [];
        foreach ($datas as $data) {
            if ($data->calendario === DataDoSistemaOutput::CAL_NACIONAL) {
                $feriados[$data->data] = true;
            }
        }
        $feriados[$this->calendario->pascoa($ano)->modify('-48 days')->format('Y-m-d')] = true;

        return $this->feriadosPorAno[$ano] = $feriados;
    }
}
