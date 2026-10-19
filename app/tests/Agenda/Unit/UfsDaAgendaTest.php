<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Service\UfsDaAgenda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(UfsDaAgenda::class)]
final class UfsDaAgendaTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, list<string>}> */
    public static function casos(): iterable
    {
        yield 'lotação com barra e escritório' => ['Maceió/AL', 'DF', ['AL', 'DF']];
        yield 'lotação com hífen' => ['Sede - ba', null, ['BA']];
        yield 'mesma UF não repete' => ['Brasília/DF', 'DF', ['DF']];
        yield 'só o escritório' => [null, 'SP', ['SP']];
        yield 'lotação sem UF no final' => ['Matriz', 'RJ', ['RJ']];
        yield 'UF sem feriado na tabela fica de fora' => ['Belo Horizonte/MG', 'MG', []];
        yield 'nada cadastrado' => [null, null, []];
    }

    #[DataProvider('casos')]
    #[TestDox('Lotação "$lotacao" e escritório "$estado" dão as UFs esperadas')]
    public function testResolver(?string $lotacao, ?string $estado, array $esperado): void
    {
        self::assertSame($esperado, (new UfsDaAgenda())->resolver($lotacao, $estado));
    }
}
