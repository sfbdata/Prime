<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\ValorEmReais;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conversão de dinheiro saiu do `AtualizarValorCausaUseCase` quando o
 * pagamento da pasta passou a precisar da mesma regra. Estes testes cobrem o
 * que a extração acrescentou — a ida e volta em CENTAVOS INTEIROS, que é como
 * os totais do card Pagamentos são somados.
 *
 * A tabela de entradas em pt-BR continua provada em `AtualizarValorCausaUseCaseTest`,
 * que exercita o mesmo código pelo caminho de quem o usa.
 */
#[CoversClass(ValorEmReais::class)]
final class ValorEmReaisTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function decimaisECentavos(): array
    {
        return [
            'valor com centavos'      => ['1300.50', 130050],
            'valor redondo'           => ['1300.00', 130000],
            'só centavos'             => ['0.99', 99],
            'zero'                    => ['0.00', 0],
            'milhão'                  => ['1234567.89', 123456789],
            'teto da coluna'          => ['9999999999999.99', 999999999999999],
        ];
    }

    #[DataProvider('decimaisECentavos')]
    #[TestDox('converte o decimal do banco em centavos inteiros')]
    public function testParaCentavos(string $decimal, int $esperado): void
    {
        self::assertSame($esperado, ValorEmReais::paraCentavos($decimal));
    }

    #[DataProvider('decimaisECentavos')]
    #[TestDox('e volta dos centavos ao decimal sem perder um centavo no caminho')]
    public function testDeCentavos(string $decimal, int $centavos): void
    {
        self::assertSame($decimal, ValorEmReais::deCentavos($centavos));
    }

    #[TestDox('ausência de valor conta como zero, não como erro')]
    public function testAusenciaValeZero(): void
    {
        self::assertSame(0, ValorEmReais::paraCentavos(null));
        self::assertSame(0, ValorEmReais::paraCentavos(''));
    }

    /**
     * A PROVA de que a soma não passa por float: 0,1 + 0,2 em float dá
     * 0.30000000000000004, e três parcelas de 1.300,10 somadas assim erram o
     * centavo. Em inteiro, não erram nunca.
     */
    #[TestDox('somar em centavos não acumula o erro que o float acumularia')]
    public function testSomaEmCentavosNaoPerdeCentavo(): void
    {
        $parcelas = ['1300.10', '1300.10', '1300.10', '0.10', '0.20'];

        $centavos = 0;
        foreach ($parcelas as $parcela) {
            $centavos += ValorEmReais::paraCentavos($parcela);
        }

        self::assertSame('3900.60', ValorEmReais::deCentavos($centavos));
    }

    #[TestDox('valor em branco vira nulo: "não sei" é diferente de R$ 0,00')]
    public function testBrancoViraNulo(): void
    {
        self::assertNull(ValorEmReais::normalizar(''));
        self::assertNull(ValorEmReais::normalizar('   '));
        self::assertNull(ValorEmReais::normalizar(null));
        self::assertSame('0.00', ValorEmReais::normalizar('0'), 'zero digitado é um valor, não uma ausência');
    }

    #[TestDox('o rótulo entra na mensagem: o erro nomeia o campo que o humano estava preenchendo')]
    public function testRotuloAparecemNaMensagem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valor do pagamento');

        ValorEmReais::normalizar('-50,00', 'valor do pagamento');
    }
}
