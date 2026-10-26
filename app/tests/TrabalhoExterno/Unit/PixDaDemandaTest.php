<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Service\PixDaDemanda;
use App\TrabalhoExterno\Service\QrCodeEmSvg;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O BR Code do desenho de 25/10 (`Component.pix` e `Component.crc16`, l.825). Os vetores abaixo saíram do JavaScript
 * do próprio desenho (`node`, mesma função), não do PHP.
 */
#[CoversClass(PixDaDemanda::class)]
#[CoversClass(QrCodeEmSvg::class)]
final class PixDaDemandaTest extends TestCase
{
    #[TestDox('o CRC16-CCITT confere com o vetor padrão "123456789" = 29B1')]
    public function testCrcPadrao(): void
    {
        self::assertSame('29B1', PixDaDemanda::crc16('123456789'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function vetoresDoDesenho(): array
    {
        return [
            'TE-123 com R$ 1.500,00' => ['TE-123', '1500.00', '00020101021226360014br.gov.bcb.pix01146680316300011052040000530398654071500.005802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62090505TE1236304741C'],
            'valor zero omite o campo 54' => ['TE-123', '0', '00020101021226360014br.gov.bcb.pix0114668031630001105204000053039865802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62090505TE12363049179'],
            'txid cortado em 25, valor com 2 casas' => ['TE-12345678901234567890123456789', '0.5', '00020101021226360014br.gov.bcb.pix01146680316300011052040000530398654040.505802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62290525TE123456789012345678901236304D87E'],
        ];
    }

    #[DataProvider('vetoresDoDesenho')]
    #[TestDox('o código sai idêntico ao do desenho: $nome')]
    public function testIdenticoAoDesenho(string $codigo, string $valor, string $esperado): void
    {
        self::assertSame($esperado, (new PixDaDemanda())->gerar($codigo, $valor));
    }

    #[TestDox('estrutura: campo 01 = 12, chave CNPJ, nome e cidade do desenho, valor com 2 casas e o CRC fecha o código')]
    public function testEstrutura(): void
    {
        $codigo = (new PixDaDemanda())->gerar('TE-0042', '2350.9');

        self::assertStringStartsWith('000201010212', $codigo, 'campo 00 = 01 e campo 01 = 12 (pagamento único)');
        self::assertStringContainsString('0014br.gov.bcb.pix011466803163000110', $codigo);
        self::assertStringContainsString('54072350.90', $codigo, 'valor 2350.90 (7 caracteres)');
        self::assertStringContainsString('5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA', $codigo);
        self::assertStringContainsString('62100506TE0042', $codigo);
        self::assertSame(PixDaDemanda::crc16(substr($codigo, 0, -4)), substr($codigo, -4));
        self::assertSame('6304', substr($codigo, -8, 4), 'o campo 63 (CRC) fecha o código');
    }

    #[TestDox('o txid é só alfanumérico do código da demanda, no máximo 25 caracteres')]
    public function testTxid(): void
    {
        self::assertSame('TE123', PixDaDemanda::txid('TE-123'));
        self::assertSame('TE000000042', PixDaDemanda::txid('TE-000000042'));
        self::assertSame(25, strlen(PixDaDemanda::txid('TE-' . str_repeat('9', 40))));
        self::assertSame('ABCD', PixDaDemanda::txid('A_B C-D!'));
    }

    #[TestDox('valor maior que zero leva o campo 54; zero e negativo, não')]
    public function testValorZeroEMaiorQueZero(): void
    {
        $pix = new PixDaDemanda();

        self::assertStringContainsString('540510.00', $pix->gerar('TE-1', '10.00'));
        self::assertStringNotContainsString('5303986540', $pix->gerar('TE-1', '0'));
        self::assertStringNotContainsString('5303986540', $pix->gerar('TE-1', '-5'));
    }

    #[TestDox('o QR sai como um <svg> inteiro, com o tamanho do quadro da aba, sem prólogo XML e sem script')]
    public function testQrEmSvg(): void
    {
        $svg = (new QrCodeEmSvg())->gerar((new PixDaDemanda())->gerar('TE-1', '100.00'));

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('style="width:100%;height:100%"', $svg);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg), 'o SVG é XML bem formado');
        self::assertSame('svg', $doc->documentElement?->nodeName);
        self::assertSame($svg, (new QrCodeEmSvg())->gerar((new PixDaDemanda())->gerar('TE-1', '100.00')), 'determinístico');
    }
}
