<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Enum\NaturezaDoDespacho;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(NaturezaDoDespacho::class)]
final class NaturezaDoDespachoTest extends TestCase
{
    #[TestDox('As cinco naturezas do desenho 04B L159, na ordem dos chips')]
    public function testValores(): void
    {
        self::assertSame(
            ['ciencia', 'providencia', 'manifestacao', 'conferencia', 'cumpra_se'],
            array_map(static fn (NaturezaDoDespacho $n): string => $n->value, NaturezaDoDespacho::cases()),
        );
    }

    #[TestDox('Rótulo e começo do texto são chaves do catálogo, uma por valor')]
    public function testChavesDoCatalogo(): void
    {
        self::assertSame('metas.despacho.natureza.cumpra_se', NaturezaDoDespacho::CumpraSe->rotulo());
        self::assertSame('metas.despacho.inicio.providencia', NaturezaDoDespacho::Providencia->inicioDoTexto());
    }
}
