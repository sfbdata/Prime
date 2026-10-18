<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ResultadoCalibracao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultadoCalibracao::class)]
final class ResultadoCalibracaoTest extends TestCase
{
    /** @return iterable<string, array{int, array<string, int>, string}> */
    public static function vereditos(): iterable
    {
        // Sem linha comparada, "todas exatas" seria verdade vazia: o veredito não pode dizer que bate.
        yield 'nenhuma comparada' => [0, [], 'sem dado'];
        yield 'nenhuma comparada, faixas zeradas' => [0, ['exato' => 0, 'ate 1 centavo' => 0], 'sem dado'];
        yield 'todas exatas' => [3, ['exato' => 3], 'bate'];
        yield 'exatas e até 1 centavo' => [3, ['exato' => 2, 'ate 1 centavo' => 1], 'bate quase'];
        // A faixa "até 1 real" não é arredondamento (§6.4): basta uma linha nela para não bater.
        yield 'uma até 1 real' => [3, ['exato' => 2, 'ate 1 real' => 1], 'nao bate'];
        yield 'uma acima de 1 real' => [3, ['exato' => 2, 'acima de 1 real' => 1], 'nao bate'];
    }

    /** @param array<string, int> $faixas */
    #[DataProvider('vereditos')]
    #[TestDox('veredito: $_dataName')]
    public function testVeredito(int $comparadas, array $faixas, string $esperado): void
    {
        $resultado = new ResultadoCalibracao('Carteira', null, $comparadas, 0, 0, 0, $faixas, []);

        self::assertSame($esperado, $resultado->veredito());
    }
}
