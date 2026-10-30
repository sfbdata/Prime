<?php

declare(strict_types=1);

namespace App\Tests\Calculo\Unit;

use App\Calculo\DTO\CalculoInput;
use App\Calculo\Service\CalculadoraDeAtualizacao;
use App\Tests\Calculo\CalculoDeFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O PDF de exemplo do desenho 14 (30/10/2026) e os cenários de referência (porte independente do
 * `calc()` do desenho, sobre as mesmas séries do BCB). Qualquer diferença de centavo aqui é dinheiro.
 */
final class CalculadoraDeAtualizacaoTest extends TestCase
{
    private const DELTA = 1e-9;

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function casos(): iterable
    {
        foreach (CalculoDeFixture::casosDeReferencia()['casos'] as $caso) {
            yield $caso['nome'] => [$caso['entrada'], $caso['esperado']];
        }
    }

    /**
     * @param array<string, mixed> $entrada
     * @param array<string, mixed> $esperado
     */
    #[DataProvider('casos')]
    public function testReproduzOsCasosDeReferencia(array $entrada, array $esperado): void
    {
        $r = (new CalculadoraDeAtualizacao())->calcular(CalculoInput::fromArray($entrada), CalculoDeFixture::series(), CalculoDeFixture::HOJE);

        self::assertCount(\count($esperado['L']), $r->linhas);
        foreach ($esperado['L'] as $i => $e) {
            $l = $r->linhas[$i];
            self::assertSame($e['d'], $l->data);
            self::assertEqualsWithDelta($e['F'], $l->fator, self::DELTA, "fator da linha $i");
            self::assertEqualsWithDelta($e['c'], $l->corrigido, self::DELTA, "corrigido da linha $i");
            self::assertEqualsWithDelta($e['jp'], $l->jurosPct, self::DELTA, "juros % da linha $i");
            self::assertEqualsWithDelta($e['j'], $l->juros, self::DELTA, "juros da linha $i");
            self::assertEqualsWithDelta($e['tot'], $l->total, self::DELTA, "total da linha $i");
            self::assertSame($e['fut'], $l->aVencer, "a vencer da linha $i");
            self::assertSame($e['pm'], $l->mesesProjetados, "meses projetados da linha $i");
        }
        foreach (['sub' => $r->subtotal, 'multa' => $r->multa, 'hon' => $r->honorarios, 'abT' => $r->abatimentosCorrigidos, 'deb' => $r->debito, 'm5' => $r->multa523, 'h5' => $r->honorarios523, 'total' => $r->total] as $chave => $valor) {
            self::assertEqualsWithDelta($esperado[$chave], $valor, self::DELTA, $chave);
        }
        self::assertSame($esperado['falt'], $r->mesesSemIndice);
        self::assertSame($esperado['nP'], $r->parcelasProjetadas);
    }

    public function testExemploDoPdfBateNoCentavo(): void
    {
        $exemplo = CalculoDeFixture::casosDeReferencia()['casos'][0];
        self::assertSame('exemplo_pdf', $exemplo['nome']);

        $r = (new CalculadoraDeAtualizacao())->calcular(CalculoInput::fromArray($exemplo['entrada']), CalculoDeFixture::series(), CalculoDeFixture::HOJE);
        $m = static fn (float $v): string => number_format($v, 2, ',', '.');

        self::assertCount(13, $r->linhas);
        // as duas linhas de 25/08/2026 (R$ 100,00 e parcela 1/12, nessa ordem) e as parcelas 2/12 e 3/12
        self::assertSame(['1,004974', '1,004974', '1,008200', '1,000000'], array_map(static fn ($l): string => number_format($l->fator, 6, ',', ''), \array_slice($r->linhas, 0, 4)));
        self::assertSame(['1,182554', '1,182554', '0,656167', '0,104167', '0,000000'], array_map(static fn ($l): string => number_format($l->jurosPct, 6, ',', ''), \array_slice($r->linhas, 0, 5)));
        self::assertSame(['11,88', '1.016,86'], [$m($r->linhas[1]->juros), $m($r->linhas[1]->total)]);
        self::assertSame('12.134,40', $m($r->subtotal));
        self::assertSame('2.426,88', $m($r->multa));
        self::assertSame('2.184,19', $m($r->honorarios));
        self::assertSame('16.745,47', $m($r->debito));
        self::assertSame('1.674,55', $m($r->multa523));
        self::assertSame('1.674,55', $m($r->honorarios523));
        self::assertSame('20.094,57', $m($r->total));
        // as 4 primeiras têm outubro/2026 (IPCA) projetado pela média nos juros; as outras 9 ainda vão vencer
        self::assertSame(13, $r->parcelasProjetadas);
    }
}
