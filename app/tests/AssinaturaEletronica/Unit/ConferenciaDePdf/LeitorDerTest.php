<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\FormatoNaoReconhecido;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LeitorDer;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimiteExcedido;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\NoDer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDer::class)]
#[CoversClass(NoDer::class)]
final class LeitorDerTest extends TestCase
{
    #[TestDox('abre a estrutura do byte 0, ignora o preenchimento depois e navega pelos filhos')]
    public function testAbreENavega(): void
    {
        $der = MontadorDeDer::sequencia(MontadorDeDer::oid('1.2.840.113549.1.7.2'), MontadorDeDer::octetos('abc')) . "\0\0\0";

        $raiz = LeitorDer::abrir($der);
        $filhos = LeitorDer::filhos($der, $raiz);

        self::assertSame(0x30, $raiz->etiqueta);
        self::assertCount(2, $filhos);
        self::assertSame('2a864886f70d010702', LeitorDer::hex($der, $filhos[0]));
        self::assertSame('abc', LeitorDer::valor($der, $filhos[1]));
        self::assertSame("\x04\x03abc", LeitorDer::bruto($der, $filhos[1]));
    }

    #[TestDox('tamanho no formato longo (até 4 bytes)')]
    public function testTamanhoLongo(): void
    {
        $der = MontadorDeDer::octetos(str_repeat('x', 70_000));

        self::assertSame(70_000, \strlen(LeitorDer::valor($der, LeitorDer::abrir($der))));
    }

    #[TestDox('limite: 32 níveis passam, 33 não')]
    public function testProfundidade(): void
    {
        $aninhado = static function (int $niveis): string {
            $der = MontadorDeDer::nulo();
            for ($i = 1; $i < $niveis; ++$i) {
                $der = MontadorDeDer::sequencia($der);
            }

            return $der;
        };

        LeitorDer::abrir($aninhado(LimitesDaConferencia::PROFUNDIDADE_MAXIMA_DER));

        $this->expectException(LimiteExcedido::class);
        LeitorDer::abrir($aninhado(LimitesDaConferencia::PROFUNDIDADE_MAXIMA_DER + 1));
    }

    #[TestDox('limite: 50.000 elementos passam, 50.001 não')]
    public function testElementos(): void
    {
        LeitorDer::abrir(MontadorDeDer::sequencia(str_repeat(MontadorDeDer::nulo(), LimitesDaConferencia::ELEMENTOS_MAXIMOS_DER - 1)));

        $this->expectException(LimiteExcedido::class);
        LeitorDer::abrir(MontadorDeDer::sequencia(str_repeat(MontadorDeDer::nulo(), LimitesDaConferencia::ELEMENTOS_MAXIMOS_DER)));
    }

    /** @return iterable<string, array{string}> */
    public static function malformados(): iterable
    {
        yield 'vazio' => [''];
        yield 'um byte' => ["\x30"];
        yield 'BER com tamanho indefinido' => ["\x30\x80\x05\x00\x00\x00"];
        yield 'tamanho com 5 bytes' => ["\x04\x85\x00\x00\x00\x00\x01x"];
        yield 'tamanho longo cortado' => ["\x04\x82\x01"];
        yield 'valor passa do fim' => ["\x04\x05abc"];
        yield 'filho passa do pai' => ["\x30\x03\x04\x09\x00"];
        yield 'etiqueta de vários bytes' => ["\x1F\x81\x01\x00"];
    }

    #[DataProvider('malformados')]
    #[TestDox('DER malformado ($_dataName) lança FormatoNaoReconhecido')]
    public function testMalformado(string $der): void
    {
        $this->expectException(FormatoNaoReconhecido::class);

        LeitorDer::abrir($der);
    }

    #[TestDox('filhos de elemento primitivo e item ausente lançam FormatoNaoReconhecido')]
    public function testNavegacaoInvalida(): void
    {
        $der = MontadorDeDer::octetos(MontadorDeDer::nulo());
        $no = LeitorDer::abrir($der);

        try {
            LeitorDer::filhos($der, $no);
            self::fail('Primitivo não tem filhos.');
        } catch (FormatoNaoReconhecido) {
        }

        $this->expectException(FormatoNaoReconhecido::class);
        LeitorDer::item([], 0);
    }

    /** @return iterable<string, array{string, string}> */
    public static function textos(): iterable
    {
        yield 'BMPString' => [MontadorDeDer::tlv(0x1E, (string) mb_convert_encoding('João', 'UTF-16BE', 'UTF-8')), 'João'];
        yield 'UTF8String' => [MontadorDeDer::tlv(0x0C, 'Conceição'), 'Conceição'];
        yield 'UTF8String inválida' => [MontadorDeDer::tlv(0x0C, "A\xFFB"), 'A?B'];
        yield 'PrintableString como Latin-1' => [MontadorDeDer::tlv(0x13, "Jos\xE9"), 'José'];
        yield 'cortado em 256 caracteres' => [MontadorDeDer::tlv(0x0C, str_repeat('á', 300)), str_repeat('á', 256)];
    }

    #[DataProvider('textos')]
    #[TestDox('texto $_dataName sai em UTF-8 válido')]
    public function testTexto(string $der, string $esperado): void
    {
        self::assertSame($esperado, LeitorDer::texto($der, LeitorDer::abrir($der)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function datas(): iterable
    {
        yield 'UTCTime 49 é 2049' => [MontadorDeDer::tlv(0x17, '491231235959Z'), '2049-12-31T23:59:59+00:00'];
        yield 'UTCTime 50 é 1950' => [MontadorDeDer::tlv(0x17, '500101000000Z'), '1950-01-01T00:00:00+00:00'];
        yield 'GeneralizedTime com fração' => [MontadorDeDer::tlv(0x18, '20261109150000.123Z'), '2026-11-09T15:00:00+00:00'];
    }

    #[DataProvider('datas')]
    #[TestDox('data $_dataName')]
    public function testData(string $der, string $esperado): void
    {
        self::assertSame($esperado, LeitorDer::data($der, LeitorDer::abrir($der))->format(\DATE_ATOM));
    }

    #[TestDox('data impossível (mês 13) lança, como o Date inválido do protótipo')]
    public function testDataImpossivel(): void
    {
        $der = MontadorDeDer::tlv(0x18, '20261301000000Z');

        $this->expectException(FormatoNaoReconhecido::class);
        LeitorDer::data($der, LeitorDer::abrir($der));
    }
}
