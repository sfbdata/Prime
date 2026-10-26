<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\DTO\AlterarRegrasDoProgramaInput;
use App\Recompensa\DTO\DefinirCondicaoIndividualInput;
use App\Recompensa\DTO\LeitorDeValoresDeRecompensa;
use App\Recompensa\Exception\RecompensaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(LeitorDeValoresDeRecompensa::class)]
#[CoversClass(AlterarRegrasDoProgramaInput::class)]
#[CoversClass(DefinirCondicaoIndividualInput::class)]
final class LeitorDeValoresDeRecompensaTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?int}> */
    public static function dinheiro(): iterable
    {
        yield '10,00' => ['10,00', 1000];
        yield '10' => ['10', 1000];
        yield '10,5' => ['10,5', 1050];
        yield '1.000,50' => ['1.000,50', 100050];
        yield '10.5 (ponto decimal)' => ['10.5', 1050];
        yield '1.000 (milhar)' => ['1.000', 100000];
        yield 'zero' => ['0', 0];
        yield 'número JSON' => [25, 2500];
        yield 'decimal JSON' => [2.5, 250];
        yield 'espaços' => ['  7,99 ', 799];
        yield 'vazio' => ['', null];
        yield 'só espaços' => ['   ', null];
        yield 'nulo' => [null, null];
    }

    #[DataProvider('dinheiro')]
    #[TestDox('centavos lê reais em texto ou número e devolve centavos inteiros')]
    public function testCentavos(mixed $entrada, ?int $esperado): void
    {
        self::assertSame($esperado, LeitorDeValoresDeRecompensa::centavos($entrada));
    }

    /** @return iterable<string, array{mixed}> */
    public static function dinheiroInvalido(): iterable
    {
        yield 'negativo' => ['-5'];
        yield 'texto' => ['dez'];
        yield 'três casas' => ['10,999'];
        yield 'enorme' => ['99999999,00'];
        yield 'lista' => [[1]];
        yield 'booleano' => [true];
    }

    #[DataProvider('dinheiroInvalido')]
    #[TestDox('centavos recusa valor negativo ou que não é número')]
    public function testCentavosInvalido(mixed $entrada): void
    {
        $this->expectException(RecompensaRecusada::class);
        LeitorDeValoresDeRecompensa::centavos($entrada);
    }

    #[TestDox('inteiro lê dígitos e recusa negativo, decimal e texto; vazio é nulo')]
    public function testInteiro(): void
    {
        self::assertSame(12, LeitorDeValoresDeRecompensa::inteiro('12'));
        self::assertSame(0, LeitorDeValoresDeRecompensa::inteiro(0));
        self::assertNull(LeitorDeValoresDeRecompensa::inteiro(''));
        self::assertNull(LeitorDeValoresDeRecompensa::inteiro(null));

        foreach (['-1', '1,5', '1.5', 'x', '1234567890'] as $ruim) {
            try {
                LeitorDeValoresDeRecompensa::inteiro($ruim);
                self::fail('devia recusar ' . $ruim);
            } catch (RecompensaRecusada) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Input das regras: doJson converte a recompensa em centavos e o #[Assert] recusa regra faltando ou fora do intervalo')]
    public function testInputDasRegras(): void
    {
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $corpo = ['ativo' => true, 'valor' => '10,00', 'minEscritorios' => '10', 'carenciaDias' => 30, 'alertaQtd' => '5', 'exigePasta' => false];

        $ok = AlterarRegrasDoProgramaInput::doJson(null, $corpo);
        self::assertSame(1000, $ok->valorCentavos);
        self::assertFalse($ok->exigePasta);
        self::assertCount(0, $validador->validate($ok));

        $faltando = AlterarRegrasDoProgramaInput::doJson(null, array_diff_key($corpo, ['ativo' => 1]));
        self::assertCount(1, $validador->validate($faltando));

        $zero = AlterarRegrasDoProgramaInput::doJson(null, ['minEscritorios' => '0'] + $corpo);
        self::assertSame('O número de escritórios ativos para liberar deve ser de 1 a 100000.', (string) $validador->validate($zero)->get(0)->getMessage());
    }

    #[TestDox('Input da condição: campos vazios viram nulo (regra geral), reais viram centavos, recorrente só com true')]
    public function testInputDaCondicao(): void
    {
        $i = DefinirCondicaoIndividualInput::doJson(null, 9, ['valor' => '25,50', 'minEscritorios' => '', 'carenciaDias' => '15', 'recorrente' => 'sim', 'mensal' => '2,00', 'motivo' => ' parceria ']);

        self::assertSame(9, $i->userId);
        self::assertSame(2550, $i->valorCentavos);
        self::assertNull($i->minEscritorios);
        self::assertSame(15, $i->carenciaDias);
        self::assertFalse($i->recorrente, '"sim" não é true');
        self::assertSame(200, $i->mensalCentavos);
        self::assertSame('parceria', $i->motivo);
    }
}
