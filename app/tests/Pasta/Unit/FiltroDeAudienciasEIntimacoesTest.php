<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\FiltroDeAudienciasEIntimacoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O filtro da chave "Audiências e intimações" (spec push-envio-automatico §3): intimação pelo tipo de
 * comunicação ou designação de audiência no texto.
 */
#[CoversClass(FiltroDeAudienciasEIntimacoes::class)]
final class FiltroDeAudienciasEIntimacoesTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function casos(): iterable
    {
        yield 'intimação pelo tipo' => ['Intimação', 'Vistos. Cite-se.', FiltroDeAudienciasEIntimacoes::INTIMACAO];
        yield 'intimacao sem acento, minúsculas' => ['intimacao', null, FiltroDeAudienciasEIntimacoes::INTIMACAO];
        yield 'designo audiência' => ['Edital', 'Designo audiência de conciliação para 10/11/2026.', FiltroDeAudienciasEIntimacoes::AUDIENCIA];
        yield 'audiência designada' => ['Lista de distribuição', 'Fica mantida a audiência designada para o dia 3.', FiltroDeAudienciasEIntimacoes::AUDIENCIA];
        yield 'designação de audiência' => [null, 'Ato ordinatório: designação de audiência de instrução.', FiltroDeAudienciasEIntimacoes::AUDIENCIA];
        yield 'só a palavra audiência' => ['Edital', 'A audiência foi realizada e o termo juntado.', null];
        yield 'despacho comum' => ['Edital', 'Junte-se. Após, conclusos.', null];
        yield 'nada' => [null, null, null];
    }

    #[DataProvider('casos')]
    #[TestDox('Natureza da publicação: $_dataName')]
    public function testNatureza(?string $tipo, ?string $texto, ?string $esperado): void
    {
        self::assertSame($esperado, FiltroDeAudienciasEIntimacoes::natureza($tipo, $texto));
        self::assertSame($esperado !== null, FiltroDeAudienciasEIntimacoes::casa($tipo, $texto));
    }
}
