<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\OpcoesDaPauta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As opções de uma pauta como a tela as envia (desenho §2, etapa 4; validação L359/N110): número de 1 dígito (o 0 é da
 * abstenção, que entra sozinha), nome obrigatório, sem número repetido, ao menos uma opção.
 */
#[CoversClass(OpcoesDaPauta::class)]
final class OpcoesDaPautaTest extends TestCase
{
    #[TestDox('Ignora as linhas vazias e devolve as opções pelo número, com o nome sem espaços nas pontas')]
    public function testOpcoesValidas(): void
    {
        $opcoes = (new OpcoesDaPauta())->validar('Síndico(a)', [
            ['numero' => '2', 'rotulo' => ' Chapa Azul '],
            ['numero' => '', 'rotulo' => ''],
            ['numero' => '1', 'rotulo' => 'Chapa Verde'],
        ]);

        self::assertSame([['numero' => 1, 'rotulo' => 'Chapa Verde'], ['numero' => 2, 'rotulo' => 'Chapa Azul']], $opcoes);
    }

    /** @return iterable<string, array{list<array{numero: string, rotulo: string}>, string}> */
    public static function opcoesInvalidas(): iterable
    {
        yield 'número 0' => [[['numero' => '0', 'rotulo' => 'Branco']], 'O número 0 é da abstenção.'];
        yield 'dois dígitos' => [[['numero' => '12', 'rotulo' => 'Sim']], 'Número 12 deve ter 1 dígito(s).'];
        yield 'sem número' => [[['numero' => '', 'rotulo' => 'Sim']], 'Número deve ter 1 dígito(s).'];
        yield 'sem nome' => [[['numero' => '1', 'rotulo' => ' ']], 'Candidatura sem nome em Obra.'];
        yield 'número repetido' => [[['numero' => '1', 'rotulo' => 'Sim'], ['numero' => '1', 'rotulo' => 'Não']], 'Número repetido em Obra.'];
        yield 'nenhuma opção' => [[['numero' => '', 'rotulo' => '']], 'Inclua candidaturas em Obra.'];
    }

    /** @param list<array{numero: string, rotulo: string}> $linhas */
    #[DataProvider('opcoesInvalidas')]
    #[TestDox('Recusa com a mensagem do desenho: $_dataName')]
    public function testOpcoesInvalidas(array $linhas, string $mensagem): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage($mensagem);

        (new OpcoesDaPauta())->validar('Obra', $linhas);
    }
}
