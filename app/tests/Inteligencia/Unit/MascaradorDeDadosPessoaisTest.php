<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MascaradorDeDadosPessoais::class)]
final class MascaradorDeDadosPessoaisTest extends TestCase
{
    private MascaradorDeDadosPessoais $mascarador;

    protected function setUp(): void
    {
        $this->mascarador = new MascaradorDeDadosPessoais();
    }

    /** @return iterable<string, array{string, string}> */
    public static function casos(): iterable
    {
        yield 'CPF' => ['Autor: João, CPF 123.456.789-09, residente', 'Autor: João, CPF [CPF], residente'];
        yield 'CNPJ' => ['Ré: ACME LTDA, CNPJ 12.345.678/0001-90', 'Ré: ACME LTDA, CNPJ [CNPJ]'];
        yield 'telefone com DDD' => ['Contato (61) 99999-1234 ou 3333-4444', 'Contato [TEL] ou [TEL]'];
        yield 'e-mail' => ['Intimado por fulano.silva+adv@exemplo.com.br hoje', 'Intimado por [EMAIL] hoje'];
        yield 'vários de uma vez' => [
            'CPF 123.456.789-09 tel (61) 98888-7777 email a@b.com',
            'CPF [CPF] tel [TEL] email [EMAIL]',
        ];
    }

    #[DataProvider('casos')]
    #[TestDox('mascara: $_dataName')]
    public function testMascara(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, $this->mascarador->mascarar($entrada));
    }

    #[TestDox('o número CNJ NÃO é mascarado — nem quando há CPF ao lado')]
    public function testNumeroCnjIntacto(): void
    {
        $entrada = 'Processo 0701134-57.2025.8.07.0007, autor CPF 123.456.789-09.';

        $saida = $this->mascarador->mascarar($entrada);

        self::assertStringContainsString('0701134-57.2025.8.07.0007', $saida);
        self::assertStringContainsString('[CPF]', $saida);
        self::assertStringNotContainsString('123.456.789-09', $saida);
    }

    #[TestDox('texto sem dado pessoal volta byte a byte')]
    public function testTextoSemPiiIntacto(): void
    {
        $entrada = 'Intime-se a parte autora para réplica no prazo de 15 (quinze) dias. Art. 351 do CPC.';

        self::assertSame($entrada, $this->mascarador->mascarar($entrada));
    }

    #[TestDox('texto que é SÓ dado pessoal vira só a máscara — o filtro precisa funcionar quando remove tudo')]
    public function testRemoveTudo(): void
    {
        self::assertSame('[CPF]', $this->mascarador->mascarar('123.456.789-09'));
        self::assertSame('[EMAIL]', $this->mascarador->mascarar('x@y.org'));
    }

    #[TestDox('string vazia continua vazia')]
    public function testVazio(): void
    {
        self::assertSame('', $this->mascarador->mascarar(''));
    }
}
