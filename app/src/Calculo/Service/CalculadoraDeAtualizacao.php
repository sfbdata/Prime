<?php

declare(strict_types=1);

namespace App\Calculo\Service;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\Calculo\DTO\AbatimentoCorrigido;
use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\LinhaDoCalculo;
use App\Calculo\DTO\ResultadoDoCalculo;
use App\Calculo\DTO\ValorDoCalculo;

/**
 * Atualização monetária pelas regras do desenho 14 (spec calculo-atualizacao-monetaria, §3).
 *
 * Puro: não lê banco nem relógio. A aritmética é de `float`, na mesma ordem de operações do `calc()`
 * do desenho, porque é isso que faz os números saírem idênticos ao PDF de exemplo; ninguém arredonda
 * aqui, a tela e o PDF arredondam na exibição. Datas são texto AAAA-MM-DD já validado pelo chamador.
 */
final class CalculadoraDeAtualizacao
{
    private const CORTE_TAXA_LEGAL = '2024-08-30';
    private const PRIMEIRO_MES_DO_IPCA = '2024-09';

    /** Meses (AAAA-MM) estimados pela média durante o cálculo de UM valor. */
    private array $mesesProjetados = [];

    public function calcular(CalculoInput $in, SeriesDoCalculo $series, string $hoje): ResultadoDoCalculo
    {
        $linhas = [];
        $semIndice = 0;

        foreach ($this->ordenadosPorData($in->valores) as $v) {
            $this->mesesProjetados = [];
            [$fator, $faltantes] = $this->fator($in, $series, $v->data, $in->ate);
            $semIndice += $faltantes;
            $corrigido = $v->valorNumerico() * $fator;
            $inicio = match ($in->incidencia) {
                'valores' => $v->data,
                'fixa' => $in->dataJuros,
                default => $v->data > $in->dataJuros ? $v->data : $in->dataJuros,
            };
            $jurosPct = $this->jurosPct($in, $series, $inicio, $in->ate);
            $juros = $corrigido * $jurosPct / 100;
            $linhas[] = new LinhaDoCalculo(
                $v->data,
                $v->descricao !== '' ? $v->descricao : 'Parcela',
                $v->valorNumerico(),
                $fator,
                $corrigido,
                $jurosPct,
                $juros,
                $corrigido + $juros,
                $v->data > $hoje,
                \count($this->mesesProjetados),
            );
        }

        $subtotal = array_reduce($linhas, static fn (float $a, LinhaDoCalculo $l): float => $a + $l->total, 0.0);
        $multa = $subtotal * $in->multaPct() / 100;
        $base = $subtotal + $multa;
        $honorarios = $base * $in->honorariosPct() / 100;

        $abatimentos = [];
        foreach ($in->abatimentos as $a) {
            if (!$a->completo()) {
                continue;
            }
            [$fator] = $this->fator($in, $series, $a->data, $in->ate);
            $abatimentos[] = new AbatimentoCorrigido($a->data, $a->descricao !== '' ? $a->descricao : 'Abatimento', $a->valorNumerico(), $fator, $a->valorNumerico() * $fator);
        }
        $abatido = array_reduce($abatimentos, static fn (float $s, AbatimentoCorrigido $x): float => $s + $x->corrigido, 0.0);

        $debito = $base + $honorarios - $abatido;
        $multa523 = $in->multa523 ? $debito * .1 : 0.0;
        $honorarios523 = $in->honorarios523 ? $debito * .1 : 0.0;
        $projetadas = \count(array_filter($linhas, static fn (LinhaDoCalculo $l): bool => $l->mesesProjetados > 0 || $l->aVencer));

        return new ResultadoDoCalculo(
            $linhas, $abatimentos, $subtotal, $multa, $honorarios, $abatido, $debito,
            $multa523, $honorarios523, $debito + $multa523 + $honorarios523, $in->ate, $semIndice, $projetadas,
        );
    }

    /**
     * @param list<ValorDoCalculo> $valores
     *
     * @return list<ValorDoCalculo>
     */
    private function ordenadosPorData(array $valores): array
    {
        $completos = array_values(array_filter($valores, static fn (ValorDoCalculo $v): bool => $v->completo()));
        usort($completos, static fn (ValorDoCalculo $a, ValorDoCalculo $b): int => strcmp($a->data, $b->data));

        return $completos;
    }

    /**
     * Variação do mês `competencia` na série, estimada pela média de 12 meses se ainda não saiu
     * (`proj=media`) ou null (`proj=nao`). Registra o mês como projetado.
     */
    private function serie(CalculoInput $in, SeriesDoCalculo $series, SerieIndice $serie, string $competencia): ?float
    {
        $publicada = $series->publicada($serie, $competencia);
        if ($publicada !== null) {
            return $publicada;
        }
        if ($in->projecao !== 'media') {
            return null;
        }
        $this->mesesProjetados[$competencia] = true;

        return $series->media12($serie);
    }

    private function taxaDoMes(CalculoInput $in, SeriesDoCalculo $series, string $competencia): ?float
    {
        if ($in->indice === 'nenhum') {
            return 0.0;
        }

        return $this->serie($in, $series, $in->indice === 'inpc' || $competencia < self::PRIMEIRO_MES_DO_IPCA ? SerieIndice::INPC : SerieIndice::IPCA, $competencia);
    }

    /**
     * Fator de correção de `$d0` até `$d1`: meses de `$d0` até ANTES do mês de `$d1`.
     *
     * @return array{0: float, 1: int} fator e quantidade de meses sem índice (só com `proj=nao`)
     */
    private function fator(CalculoInput $in, SeriesDoCalculo $series, string $d0, string $d1): array
    {
        $fator = 1.0;
        $faltantes = 0;
        $fim = substr($d1, 0, 7);
        for ($k = substr($d0, 0, 7); $k < $fim; $k = self::proximoMes($k)) {
            $taxa = $this->taxaDoMes($in, $series, $k);
            if ($taxa === null) {
                ++$faltantes;
            } else {
                $fator *= 1 + $taxa / 100;
            }
        }

        return [$fator, $faltantes];
    }

    /** Juros acumulados em %, de `$d0` até `$d1`. */
    private function jurosPct(CalculoInput $in, SeriesDoCalculo $series, string $d0, string $d1): float
    {
        if ($in->juros === 'nenhum' || $d0 >= $d1) {
            return 0.0;
        }
        if ($in->juros === '1' || $in->juros === '05') {
            return self::dias($d0, $d1) / 30 * ($in->juros === '1' ? 1 : .5);
        }

        $pct = 0.0;
        if ($d0 < self::CORTE_TAXA_LEGAL) {
            $pct += self::dias($d0, $d1 < self::CORTE_TAXA_LEGAL ? $d1 : self::CORTE_TAXA_LEGAL) / 30;
        }
        if ($d1 > self::CORTE_TAXA_LEGAL) {
            $a = $d0 > self::CORTE_TAXA_LEGAL ? $d0 : self::CORTE_TAXA_LEGAL;
            while ($a < $d1) {
                $k = substr($a, 0, 7);
                $primeiroDoProximo = self::proximoMes($k) . '-01';
                $b = $primeiroDoProximo < $d1 ? $primeiroDoProximo : $d1;
                $diasDoMes = (int) (new \DateTimeImmutable($k . '-01'))->format('t');
                $selic = $this->serie($in, $series, SerieIndice::SELIC, $k) ?? 0.0;
                $ipca = $this->serie($in, $series, SerieIndice::IPCA, $k) ?? 0.0;
                $pct += max(0.0, $selic - $ipca) * self::dias($a, $b) / $diasDoMes;
                $a = $b;
            }
        }

        return $pct;
    }

    private static function proximoMes(string $competencia): string
    {
        return (new \DateTimeImmutable($competencia . '-01'))->modify('+1 month')->format('Y-m');
    }

    private static function dias(string $a, string $b): int
    {
        $dias = (new \DateTimeImmutable($a, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($b, new \DateTimeZone('UTC')));

        return $dias->invert === 1 ? 0 : (int) $dias->days;
    }
}
