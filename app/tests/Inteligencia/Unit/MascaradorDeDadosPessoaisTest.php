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
        yield 'fixo com DDD entre parênteses' => ['Tel. (11) 3333-4444 e (11)33334444', 'Tel. [TEL] e [TEL]'];
        yield 'celular com DDD sem parênteses' => ['WhatsApp 61 99999-1234', 'WhatsApp [TEL]'];
        yield 'celular sem DDD' => ['ligar para 99999-1234', 'ligar para [TEL]'];
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

    /** @return iterable<string, array{string}> */
    public static function numerosQueNaoSaoTelefone(): iterable
    {
        yield 'intervalo de anos' => ['exercícios 2023-2024'];
        yield 'intervalo de anos antigo' => ['período 1999-2000'];
        yield 'número de lei com hífen' => ['Lei 13105-2015'];
        yield 'número de lei com ponto e barra' => ['Lei 13.105/2015, art. 1.010'];
        yield 'dois números seguidos' => ['art. 10 2023-2024'];
        yield 'número de processo antigo' => ['autos 2009.01.1.123456-7'];
        yield 'valor e data' => ['R$ 12.345,67 em 01/10/2026 às 14:30'];
        yield 'oito dígitos colados' => ['protocolo 20231234'];
    }

    #[DataProvider('numerosQueNaoSaoTelefone')]
    #[TestDox('não mascara o que só parece telefone: $_dataName')]
    public function testNaoMascaraNumeroQueNaoEhTelefone(string $entrada): void
    {
        self::assertSame($entrada, $this->mascarador->mascarar($entrada));
    }

    #[TestDox('telefone colado a outro número não é mascarado pela metade')]
    public function testTelefoneExigeBorda(): void
    {
        self::assertSame('ref 113105-2015', $this->mascarador->mascarar('ref 113105-2015'));
        self::assertSame('código 99999-12345', $this->mascarador->mascarar('código 99999-12345'));
    }

    #[TestDox('texto que é SÓ dado pessoal vira só a máscara — o filtro precisa funcionar quando remove tudo')]
    public function testRemoveTudo(): void
    {
        self::assertSame('[CPF]', $this->mascarador->mascarar('123.456.789-09'));
        self::assertSame('[EMAIL]', $this->mascarador->mascarar('x@y.org'));
        self::assertSame('[CPF]', $this->mascarador->mascarar('12345678909'));
        self::assertSame('[CNPJ]', $this->mascarador->mascarar('11222333000181'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function documentosSemPontuacao(): iterable
    {
        yield 'CPF válido' => ['Autor CPF 12345678909, residente', 'Autor CPF [CPF], residente'];
        yield 'outro CPF válido' => ['cpf:52998224725.', 'cpf:[CPF].'];
        yield 'CNPJ válido' => ['Ré ACME, CNPJ 11222333000181', 'Ré ACME, CNPJ [CNPJ]'];
        yield 'outro CNPJ válido' => ['(11444777000161)', '([CNPJ])'];
        yield 'formatado e sem pontuação juntos' => [
            'CPF 123.456.789-09 e 52998224725; CNPJ 11.222.333/0001-81 e 11444777000161',
            'CPF [CPF] e [CPF]; CNPJ [CNPJ] e [CNPJ]',
        ];
    }

    #[DataProvider('documentosSemPontuacao')]
    #[TestDox('mascara CPF/CNPJ sem pontuação com DV válido: $_dataName')]
    public function testMascaraDocumentoSemPontuacao(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, $this->mascarador->mascarar($entrada));
    }

    /** @return iterable<string, array{string}> */
    public static function digitosQueNaoSaoDocumento(): iterable
    {
        yield '11 dígitos com DV de CPF inválido' => ['protocolo 12345678900'];
        yield '14 dígitos com DV de CNPJ inválido' => ['protocolo 11222333000180'];
        yield '11 dígitos repetidos (passam no cálculo)' => ['código 00000000000'];
        yield '14 dígitos repetidos (passam no cálculo)' => ['código 00000000000000'];
        yield 'telefone sem pontuação que não passa no DV' => ['ligar 61999991234'];
        yield '12 dígitos que começam com CPF válido' => ['ref 123456789091'];
        yield '15 dígitos que começam com CNPJ válido' => ['ref 112223330001810'];
        // CNJ sem pontuação (20 dígitos): começa com um CPF válido e termina com um CNPJ válido.
        // Nenhum pedaço pode virar máscara.
        yield 'CNJ sem pontuação com CPF válido no começo' => ['autos 12345678909250807000'];
        yield 'CNJ sem pontuação com CNPJ válido no fim' => ['autos 00000011222333000181'];
    }

    #[DataProvider('digitosQueNaoSaoDocumento')]
    #[TestDox('não mascara sequência de dígitos que não é CPF/CNPJ: $_dataName')]
    public function testNaoMascaraDigitosQueNaoSaoDocumento(string $entrada): void
    {
        self::assertSame($entrada, $this->mascarador->mascarar($entrada));
    }

    #[TestDox('número CNJ formatado continua intacto ao lado de CPF e CNPJ sem pontuação')]
    public function testCnjFormatadoIntactoComDocumentosSemPontuacao(): void
    {
        $entrada = 'Processo 0701134-57.2025.8.07.0007, autor 12345678909, ré 11222333000181.';

        self::assertSame(
            'Processo 0701134-57.2025.8.07.0007, autor [CPF], ré [CNPJ].',
            $this->mascarador->mascarar($entrada),
        );
    }

    #[TestDox('string vazia continua vazia')]
    public function testVazio(): void
    {
        self::assertSame('', $this->mascarador->mascarar(''));
    }
}
