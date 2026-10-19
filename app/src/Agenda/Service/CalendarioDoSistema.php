<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Calcula, em memória, as datas que a Agenda mostra para todo escritório: feriados nacionais (com os
 * móveis pela Páscoa), pontos facultativos, feriados estaduais por UF, datas dos tribunais, eleições,
 * recesso forense e datas comemorativas. Não lê nem grava banco: o Ponto continua só com a tabela
 * `feriado` (ver docs/specs/agenda-vs-18-10-26.md, seção 5). Classificação legal na seção 6.
 */
final class CalendarioDoSistema
{
    private const L662 = 'Lei nº 662/1949 (redação da Lei nº 10.607/2002)';
    private const PF   = 'Portaria anual do governo federal sobre feriados e pontos facultativos';
    private const L5   = 'Lei nº 5.010/1966, art. 62 (Justiça Federal e TJDFT)';

    /** UF => lista de [mês, dia, nome]. Tabela do desenho "09 - Agenda - VS 18.10.26". */
    public const ESTADUAIS = [
        'AC' => [[1, 23, 'Dia do Evangélico'], [6, 15, 'Aniversário do Acre'], [8, 6, 'Revolução Acreana'], [9, 5, 'Dia da Amazônia'], [11, 17, 'Tratado de Petrópolis']],
        'AL' => [[6, 24, 'São João'], [6, 29, 'São Pedro'], [9, 16, 'Emancipação de Alagoas'], [11, 30, 'Dia do Evangélico']],
        'AP' => [[3, 19, 'São José'], [9, 13, 'Criação do Amapá']],
        'AM' => [[9, 5, 'Elevação do Amazonas']],
        'BA' => [[7, 2, 'Independência da Bahia']],
        'CE' => [[3, 19, 'São José'], [3, 25, 'Data Magna do Ceará']],
        'DF' => [[11, 30, 'Dia do Evangélico']],
        'MA' => [[7, 28, 'Adesão do Maranhão']],
        'MS' => [[10, 11, 'Criação do Estado']],
        'PA' => [[8, 15, 'Adesão do Pará']],
        'PB' => [[8, 5, 'Fundação do Estado']],
        'PR' => [[12, 19, 'Emancipação do Paraná']],
        'PE' => [[3, 6, 'Revolução Pernambucana']],
        'PI' => [[10, 19, 'Dia do Piauí']],
        'RJ' => [[4, 23, 'São Jorge']],
        'RN' => [[10, 3, 'Mártires de Cunhaú e Uruaçu']],
        'RS' => [[9, 20, 'Revolução Farroupilha']],
        'RO' => [[1, 4, 'Criação do Estado'], [6, 18, 'Dia do Evangélico']],
        'RR' => [[10, 5, 'Criação de Roraima']],
        'SP' => [[7, 9, 'Revolução Constitucionalista']],
        'SE' => [[7, 8, 'Emancipação de Sergipe']],
        'TO' => [[10, 5, 'Criação do Tocantins']],
    ];

    private const OBS_RECESSO = 'Prazos processuais suspensos de 20/12 a 20/01 (CPC, art. 220). '
        . 'Recesso de 20/12 a 06/01: sem audiências e sessões, salvo urgências.';

    /**
     * @param list<string> $ufs
     *
     * @return list<D> ordenadas por data, só as que caem em [$de, $ate]
     */
    public function doPeriodo(\DateTimeImmutable $de, \DateTimeImmutable $ate, array $ufs): array
    {
        $ini = $de->format('Y-m-d');
        $fim = $ate->format('Y-m-d');
        $todas = [];
        for ($ano = (int) $de->format('Y') - 1; $ano <= (int) $ate->format('Y'); ++$ano) {
            array_push(
                $todas,
                ...$this->nacionais($ano),
                ...$this->estaduais($ano, $ufs),
                ...$this->tribunais($ano),
                ...$this->comemorativas($ano),
                ...$this->recesso($ano),
            );
        }
        $noPeriodo = array_values(array_filter($todas, static fn (D $d): bool => $d->data >= $ini && $d->data <= $fim));
        usort($noPeriodo, static fn (D $a, D $b): int => $a->data <=> $b->data);

        return $noPeriodo;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    public function pascoa(int $ano): \DateTimeImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = ($h + $l - 7 * $m + 114) % 31 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
    }

    /** @return list<D> */
    private function nacionais(int $ano): array
    {
        $p = fn (int $dias): string => $this->pascoa($ano)->modify(sprintf('%+d days', $dias))->format('Y-m-d');
        $f = static fn (int $m, int $d): string => sprintf('%04d-%02d-%02d', $ano, $m, $d);
        $lista = [
            [$f(1, 1), 'Confraternização Universal', D::TIPO_FERIADO, self::L662],
            [$p(-47), 'Carnaval · ponto facultativo', D::TIPO_PONTO_FACULTATIVO, self::PF],
            [$p(-2), 'Sexta-feira Santa', D::TIPO_FERIADO, 'Lei nº 9.093/1995, art. 2º, e ' . lcfirst(self::PF)],
            [$f(4, 21), 'Tiradentes', D::TIPO_FERIADO, self::L662],
            [$f(5, 1), 'Dia do Trabalho', D::TIPO_FERIADO, self::L662],
            [$p(60), 'Corpus Christi · ponto facultativo', D::TIPO_PONTO_FACULTATIVO, self::PF],
            [$f(9, 7), 'Independência', D::TIPO_FERIADO, self::L662],
            [$f(10, 12), 'N. Sra. Aparecida', D::TIPO_FERIADO, 'Lei nº 6.802/1980'],
            [$f(11, 2), 'Finados', D::TIPO_FERIADO, self::L662],
            [$f(11, 15), 'Proclamação da República', D::TIPO_FERIADO, self::L662],
            [$f(11, 20), 'Consciência Negra', D::TIPO_FERIADO, 'Lei nº 14.759/2023'],
            [$f(12, 25), 'Natal', D::TIPO_FERIADO, self::L662],
        ];

        return array_map(
            static fn (array $x): D => new D(self::id('fer', $x[0], $x[1]), $x[0], $x[1], D::CAL_NACIONAL, $x[2], $x[3]),
            $lista,
        );
    }

    /**
     * @param list<string> $ufs
     *
     * @return list<D>
     */
    private function estaduais(int $ano, array $ufs): array
    {
        $varios = count($ufs) > 1;
        $saida = [];
        foreach ($ufs as $uf) {
            foreach (self::ESTADUAIS[$uf] ?? [] as [$m, $d, $nome]) {
                $data = sprintf('%04d-%02d-%02d', $ano, $m, $d);
                $obs = 'Feriado estadual de ' . $uf . ($varios ? '. Vale só para quem atua em ' . $uf . '.' : '.')
                    . ' Confira também o calendário do tribunal.';
                $saida[] = new D(
                    'est-' . $uf . '-' . $data,
                    $data,
                    $nome . ($varios ? ' · ' . $uf : ''),
                    D::CAL_ESTADUAL,
                    D::TIPO_ESTADUAL,
                    'Lei estadual de ' . $uf,
                    $obs,
                    $uf,
                );
            }
        }

        return $saida;
    }

    /** @return list<D> */
    private function tribunais(int $ano): array
    {
        $p = fn (int $dias): string => $this->pascoa($ano)->modify(sprintf('%+d days', $dias))->format('Y-m-d');
        $f = static fn (int $m, int $d): string => sprintf('%04d-%02d-%02d', $ano, $m, $d);
        $lista = [
            [$p(-48), 'Segunda de Carnaval · tribunais', self::L5],
            [$p(-47), 'Terça de Carnaval · tribunais', self::L5],
            [$p(-46), 'Quarta de Cinzas · expediente a partir das 14h', 'Portarias dos tribunais'],
            [$p(-4), 'Quarta-feira Santa · tribunais', self::L5],
            [$p(-3), 'Quinta-feira Santa · tribunais', self::L5],
            [$f(8, 11), 'Dia do Advogado · feriado forense', self::L5],
            [$f(10, 28), 'Dia do Servidor Público · ponto facultativo', 'Lei nº 8.112/1990, art. 236 (data pode ser transferida)'],
            [$f(11, 1), 'Todos os Santos · tribunais', self::L5],
            [$f(12, 8), 'Dia da Justiça · feriado forense', self::L5],
        ];
        $datas = array_map(
            static fn (array $x): D => new D(
                self::id('trib', $x[0], $x[1]),
                $x[0],
                $x[1],
                D::CAL_TRIBUNAL,
                D::TIPO_TRIBUNAL,
                $x[2],
                'Fonte: ' . $x[2] . '. Confira o calendário oficial do seu tribunal.',
            ),
            $lista,
        );

        return [...$datas, ...$this->eleicoes($ano)];
    }

    /**
     * Eleições gerais e municipais acontecem em ano par: 1º turno no primeiro domingo de outubro e
     * 2º turno no último domingo de outubro (CF, arts. 28, 29 e 77).
     *
     * @return list<D>
     */
    private function eleicoes(int $ano): array
    {
        if (0 !== $ano % 2) {
            return [];
        }
        $primeiro = (new \DateTimeImmutable(sprintf('%04d-10-01', $ano)))->modify('first sunday of this month')->format('Y-m-d');
        $ultimo = (new \DateTimeImmutable(sprintf('%04d-10-01', $ano)))->modify('last sunday of this month')->format('Y-m-d');

        $turnos = [
            [$primeiro, 'Eleições · 1º turno', 'Lei nº 9.504/1997, art. 1º e Código Eleitoral'],
            [$ultimo, 'Eleições · 2º turno (onde houver)', 'Constituição, art. 77'],
        ];

        return array_map(
            static fn (array $x): D => new D(
                self::id('trib', $x[0], $x[1]),
                $x[0],
                $x[1],
                D::CAL_TRIBUNAL,
                D::TIPO_ELEICAO,
                $x[2],
                'Fonte: ' . $x[2] . '. Confira o calendário oficial do seu tribunal.',
            ),
            $turnos,
        );
    }

    /**
     * Id estável "<calendário>-<data>-<nome>", com o nome sem o complemento depois de " · ". Os
     * ajustes do escritório (entrega 4) guardam esse id: não mude o formato.
     */
    private static function id(string $calendario, string $data, string $titulo): string
    {
        $nome = explode(' · ', $titulo)[0];

        return $calendario . '-' . $data . '-' . (new AsciiSlugger('pt'))->slug($nome)->lower()->toString();
    }

    /** @return list<D> */
    private function comemorativas(int $ano): array
    {
        $dom = static fn (int $mes, string $ordem): string => (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))
            ->modify($ordem . ' sunday of this month')->format('Y-m-d');
        $f = static fn (int $m, int $d): string => sprintf('%04d-%02d-%02d', $ano, $m, $d);
        $lista = [
            [$f(3, 8), 'Dia Internacional da Mulher'], [$f(3, 15), 'Dia do Consumidor'],
            [$dom(5, 'second'), 'Dia das Mães'], [$f(6, 12), 'Dia dos Namorados'],
            [$dom(8, 'second'), 'Dia dos Pais'], [$f(8, 11), 'Dia do Advogado'],
            [$f(8, 18), 'Dia do Estagiário'], [$f(9, 15), 'Dia do Cliente'],
            [$f(12, 8), 'Dia da Justiça'], [$f(12, 31), 'Réveillon'],
        ];

        return array_map(
            static fn (array $x): D => new D(self::id('com', $x[0], $x[1]), $x[0], $x[1], D::CAL_COMEMORATIVA, D::TIPO_COMEMORATIVA),
            $lista,
        );
    }

    /**
     * Recesso que começa em 20/12 de $ano e termina em 20/01 do ano seguinte: um item por dia, como
     * no desenho ("Recesso forense" até 06/01, "Prazos suspensos" de 07/01 a 20/01).
     *
     * @return list<D>
     */
    private function recesso(int $ano): array
    {
        $saida = [];
        $fimRecesso = sprintf('%04d-01-06', $ano + 1);
        $periodo = new \DatePeriod(
            new \DateTimeImmutable(sprintf('%04d-12-20', $ano)),
            new \DateInterval('P1D'),
            new \DateTimeImmutable(sprintf('%04d-01-21', $ano + 1)),
        );
        foreach ($periodo as $dia) {
            $data = $dia->format('Y-m-d');
            $titulo = $data <= $fimRecesso ? 'Recesso forense' : 'Prazos suspensos';
            $saida[] = new D('rec-' . $data, $data, $titulo, D::CAL_FORENSE, D::TIPO_FORENSE, 'CPC, art. 220', self::OBS_RECESSO);
        }

        return $saida;
    }
}
