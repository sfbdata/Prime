<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaVizinhasOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os campos do "N de M" no DTO das setas: entram quando a posição é informada e ficam
 * nulos quando não é — sem mudar nada do que as setas já recebiam.
 */
#[CoversClass(PastaVizinhasOutput::class)]
final class PastaVizinhasOutputPosicaoTest extends TestCase
{
    #[TestDox('com a posição informada, o DTO carrega posição e total')]
    public function testCarregaPosicaoETotal(): void
    {
        $out = PastaVizinhasOutput::montar(
            ['anterior' => ['id' => 7, 'nup' => '2003'], 'proxima' => null],
            ['posicao' => 3, 'total' => 7],
        );

        self::assertSame(3, $out->posicao);
        self::assertSame(7, $out->total);
        self::assertSame(7, $out->anteriorId);
        self::assertSame('Esta é a última pasta do acervo', $out->rotuloProxima);
    }

    #[TestDox('sem a posição (chamada antiga ou pasta sem acervo), os dois campos ficam nulos')]
    public function testSemPosicaoFicaNulo(): void
    {
        $antiga = PastaVizinhasOutput::montar(['anterior' => null, 'proxima' => null]);
        self::assertNull($antiga->posicao);
        self::assertNull($antiga->total);

        $nula = PastaVizinhasOutput::montar(['anterior' => null, 'proxima' => null], null);
        self::assertNull($nula->posicao);
        self::assertNull($nula->total);
    }
}
