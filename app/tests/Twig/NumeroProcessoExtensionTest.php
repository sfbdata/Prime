<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\NumeroProcessoExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O banco guarda o número de processo com os 20 dígitos colados
 * ("10593167220224013400") e a tela imprimia exatamente isso — 20 algarismos
 * que ninguém confere de olho nem dita ao telefone. O padrão CNJ é
 * NNNNNNN-DD.AAAA.J.TR.OOOO.
 *
 * A regra é a CONTAGEM DE DÍGITOS, como no `documento_br`: o que não bate 20
 * volta EXATAMENTE como veio. Mascarar o que não bate exigiria inventar ou
 * descartar dígito, e número errado com máscara bonita deixa de PARECER errado.
 */
#[CoversClass(NumeroProcessoExtension::class)]
final class NumeroProcessoExtensionTest extends TestCase
{
    private NumeroProcessoExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new NumeroProcessoExtension();
    }

    #[TestDox('20 dígitos crus viram a máscara CNJ')]
    public function testMascaraOsVinteDigitos(): void
    {
        self::assertSame(
            '1059316-72.2022.4.01.3400',
            $this->ext->numeroCnj('10593167220224013400')
        );
    }

    #[TestDox('já mascarado volta idêntico — a conta é sobre os dígitos, não sobre a pontuação')]
    public function testJaMascaradoNaoMuda(): void
    {
        self::assertSame(
            '1059316-72.2022.4.01.3400',
            $this->ext->numeroCnj('1059316-72.2022.4.01.3400')
        );
    }

    #[TestDox('o que não bate 20 dígitos volta EXATAMENTE como veio')]
    #[DataProvider('forasDoPadrao')]
    public function testForaDoPadraoVoltaIntacto(?string $entrada, string $esperado): void
    {
        self::assertSame($esperado, $this->ext->numeroCnj($entrada));
    }

    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function forasDoPadrao(): iterable
    {
        yield 'nulo'                => [null, ''];
        yield 'vazio'               => ['', ''];
        yield 'base antiga'         => ['2005.01.1.012345-6', '2005.01.1.012345-6'];
        yield 'processo físico'     => ['ADM-4471/2019', 'ADM-4471/2019'];
        yield 'um dígito a menos'   => ['1059316722022401340', '1059316722022401340'];
        yield 'um dígito a mais'    => ['105931672202240134001', '105931672202240134001'];
    }

    #[TestDox('zeros à esquerda sobrevivem: a máscara é posicional, não numérica')]
    public function testZerosAEsquerda(): void
    {
        /* Converter para int em qualquer ponto comeria os zeros e produziria
           um número de processo DIFERENTE, com cara de certo. */
        self::assertSame(
            '0000123-45.2019.8.07.0001',
            $this->ext->numeroCnj('00001234520198070001')
        );
    }
}
