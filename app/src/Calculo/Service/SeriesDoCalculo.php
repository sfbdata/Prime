<?php

declare(strict_types=1);

namespace App\Calculo\Service;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Service\TabelaIndices;

/**
 * As séries mensais que o cálculo lê (INPC, IPCA, Selic), por competência 'AAAA-MM' e em float,
 * mais a média dos últimos 12 meses publicados de cada uma (usada para projetar meses sem índice).
 */
final class SeriesDoCalculo
{
    private const USADAS = [SerieIndice::INPC, SerieIndice::IPCA, SerieIndice::SELIC];

    /** @var array<string, array<string, float>> */
    private array $valores = [];

    /** @var array<string, float> */
    private array $medias = [];

    public function __construct(TabelaIndices $tabela)
    {
        foreach (self::USADAS as $serie) {
            $mapa = [];
            foreach ($tabela->competencias($serie) as $competencia) {
                $mapa[substr($competencia, 0, 7)] = (float) $tabela->variacao($serie, new \DateTimeImmutable($competencia));
            }
            $this->valores[$serie->value] = $mapa;
            $ultimos = \array_slice($mapa, -12, null, true);
            $this->medias[$serie->value] = $ultimos === [] ? 0.0 : array_sum($ultimos) / \count($ultimos);
        }
    }

    /** Variação do mês, ou null se o índice ainda não foi publicado. */
    public function publicada(SerieIndice $serie, string $competencia): ?float
    {
        return $this->valores[$serie->value][$competencia] ?? null;
    }

    public function media12(SerieIndice $serie): float
    {
        return $this->medias[$serie->value];
    }

    /** Última competência 'AAAA-MM' publicada da série, ou null se vazia. */
    public function ultima(SerieIndice $serie): ?string
    {
        $chaves = array_keys($this->valores[$serie->value]);

        return $chaves === [] ? null : (string) end($chaves);
    }

    public function temDados(): bool
    {
        foreach (self::USADAS as $serie) {
            if ($this->valores[$serie->value] === []) {
                return false;
            }
        }

        return true;
    }
}
