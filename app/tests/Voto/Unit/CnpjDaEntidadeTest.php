<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\CnpjDaEntidade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/** O CNPJ da entidade: opcional, grava só dígitos, recusa dígito verificador errado e exibe com a máscara. */
#[CoversClass(CnpjDaEntidade::class)]
final class CnpjDaEntidadeTest extends TestCase
{
    private function servico(): CnpjDaEntidade
    {
        return new CnpjDaEntidade(Validation::createValidator());
    }

    #[TestDox('Vazio vira null: o CNPJ é opcional')]
    public function testVazioViraNull(): void
    {
        self::assertNull($this->servico()->normalizar(null));
        self::assertNull($this->servico()->normalizar(''));
        self::assertNull($this->servico()->normalizar('   '));
    }

    #[TestDox('Com ou sem máscara, grava só os 14 dígitos')]
    public function testGravaSoDigitos(): void
    {
        self::assertSame('11222333000181', $this->servico()->normalizar('11.222.333/0001-81'));
        self::assertSame('11222333000181', $this->servico()->normalizar(' 11222333000181 '));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidos(): iterable
    {
        yield 'dígito verificador errado' => ['11.222.333/0001-82'];
        yield 'poucos dígitos' => ['11.222.333/0001'];
        yield 'dígitos repetidos' => ['11111111111111'];
        yield 'texto' => ['abc'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('Recusa o CNPJ inválido')]
    public function testRecusaInvalido(string $informado): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('CNPJ válido');
        $this->servico()->normalizar($informado);
    }

    #[TestDox('A máscara só vale para 14 dígitos; o resto volta como veio')]
    public function testFormatar(): void
    {
        self::assertSame('11.222.333/0001-81', CnpjDaEntidade::formatar('11222333000181'));
        self::assertSame('123', CnpjDaEntidade::formatar('123'));
        self::assertSame('', CnpjDaEntidade::formatar(null));
    }
}
