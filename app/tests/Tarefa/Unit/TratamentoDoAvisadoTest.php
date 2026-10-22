<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Service\TratamentoDoAvisado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O `trat()` do desenho 04 de 22/10 (dc L155-160) para os avisados da caixa da esteira. */
#[CoversClass(TratamentoDoAvisado::class)]
#[Group('tarefa')]
final class TratamentoDoAvisadoTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool, string}> */
    public static function casos(): iterable
    {
        yield 'sem função de advogado: Primeiro Último' => ['RUI CARLOS PRADO', 'Secretário', false, 'Rui Prado'];
        yield 'nome de uma palavra' => ['bruno', null, false, 'Bruno'];
        yield 'advogada pela função' => ['ANA MARIA LIMA', 'Advogada', false, 'Dra. Ana Lima'];
        yield 'sócio pela função' => ['Carlos Mendes', 'Sócio', false, 'Dr. Carlos Mendes'];
        yield 'com OAB, sem função' => ['Thâmara Thays Souza', null, true, 'Dra. Thâmara Souza'];
        yield 'José termina em e e é masculino' => ['José Alves', 'Advogado', false, 'Dr. José Alves'];
        yield 'Felipe termina em e e é masculino' => ['FELIPE ROCHA', null, true, 'Dr. Felipe Rocha'];
        yield 'Kelly termina em ly' => ['Kelly Dias', 'Advogada', false, 'Dra. Kelly Dias'];
        yield 'nome vazio' => ['   ', 'Advogado', true, ''];
    }

    #[DataProvider('casos')]
    #[TestDox('$nome ($funcao) vira "$esperado"')]
    public function testTratamento(string $nome, ?string $funcao, bool $temOab, string $esperado): void
    {
        self::assertSame($esperado, TratamentoDoAvisado::de($nome, $funcao, $temOab));
    }
}
