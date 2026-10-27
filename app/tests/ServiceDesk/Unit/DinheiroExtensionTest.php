<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\ServiceDesk\Twig\DinheiroExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DinheiroExtension::class)]
final class DinheiroExtensionTest extends TestCase
{
    #[DataProvider('valores')]
    public function testFormataCentavosEmReais(int $centavos, string $esperado): void
    {
        self::assertSame($esperado, (new DinheiroExtension())->reais($centavos));
    }

    /** @return iterable<string, array{int, string}> */
    public static function valores(): iterable
    {
        yield 'zero' => [0, 'R$ 0,00'];
        yield 'vinte' => [2000, 'R$ 20,00'];
        yield 'milhar' => [123456, 'R$ 1.234,56'];
        yield 'centavos' => [5, 'R$ 0,05'];
    }
}
