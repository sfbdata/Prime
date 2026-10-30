<?php

declare(strict_types=1);

namespace App\Tests\Calculo;

use App\AtualizacaoMonetaria\Service\TabelaIndices;
use App\Calculo\Service\SeriesDoCalculo;

/** Séries reais do BCB (consultadas em 30/10/2026), congeladas, para os testes do cálculo. */
final class CalculoDeFixture
{
    public const HOJE = '2026-10-30';

    public static function series(): SeriesDoCalculo
    {
        return new SeriesDoCalculo(self::tabela());
    }

    public static function tabela(): TabelaIndices
    {
        $bruto = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/series-bcb-30-10-2026.json'), true, 512, \JSON_THROW_ON_ERROR);
        $variacoes = [];
        foreach (['INPC', 'IPCA', 'SELIC'] as $serie) {
            foreach ($bruto[$serie] as $competencia => $valor) {
                $variacoes[$serie][$competencia . '-01'] = (string) $valor;
            }
        }

        return new TabelaIndices($variacoes);
    }

    /** @return array{hoje: string, casos: list<array<string, mixed>>} */
    public static function casosDeReferencia(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/casos-referencia.json'), true, 512, \JSON_THROW_ON_ERROR);
    }
}
