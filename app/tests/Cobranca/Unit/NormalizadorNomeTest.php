<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\NormalizadorNome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizadorNome::class)]
final class NormalizadorNomeTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function nomes(): array
    {
        return [
            'caixa e espaços' => ['  Maria  da  Silva ', 'MARIA DA SILVA'],
            'já normalizado' => ['MARIA DA SILVA', 'MARIA DA SILVA'],
            'acentos preservados' => ['joão césar', 'JOÃO CÉSAR'],
            'null' => [null, null],
            'vazio vira null' => ['   ', null],
        ];
    }

    #[Test]
    #[DataProvider('nomes')]
    public function canonizaNome(?string $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, NormalizadorNome::normalizar($entrada));
    }
}
