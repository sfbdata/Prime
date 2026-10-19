<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Service\RotulosDoConsentimento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os nomes legíveis da origem e da lista (spec `comercial-e-emails.md` §3.3, M-g). */
#[CoversClass(RotulosDoConsentimento::class)]
final class RotulosDoConsentimentoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function origens(): iterable
    {
        yield 'página de vagas' => ['pagina-publica-vagas', 'na página de vagas do BlueJus'];
        yield 'desconhecida' => ['consumidor:novo', 'em um formulário do BlueJus'];
    }

    #[DataProvider('origens')]
    #[TestDox('Rótulo da origem: $_dataName')]
    public function testOrigem(string $origem, string $esperado): void
    {
        self::assertSame($esperado, RotulosDoConsentimento::daOrigem($origem));
    }

    /** @return iterable<string, array{string, string}> */
    public static function listas(): iterable
    {
        yield 'alertas de vagas' => ['vagas_alerta', 'os alertas de vagas do BlueJus'];
        yield 'desconhecida' => ['campanha_nova', 'as comunicações do BlueJus'];
    }

    #[DataProvider('listas')]
    #[TestDox('Nome da lista: $_dataName')]
    public function testLista(string $lista, string $esperado): void
    {
        self::assertSame($esperado, RotulosDoConsentimento::daLista($lista));
    }
}
