<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Service\CodigoPixEstatico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodigoPixEstatico::class)]
final class CodigoPixEstaticoTest extends TestCase
{
    /**
     * Referências geradas pela própria função `pix()` do desenho 08 (rodada no Node): o código do
     * servidor tem de ser byte a byte o mesmo que o protótipo gera.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function doDesenho(): iterable
    {
        yield 'R$ 20' => ['BJ000001', 2000, '00020101021126360014br.gov.bcb.pix011466803163000110520400005303986540520.005802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62120508BJ00000163048F7B'];
        yield 'valor livre' => ['BJ000123', 0, '00020101021126360014br.gov.bcb.pix0114668031630001105204000053039865802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62120508BJ0001236304E051'];
        yield 'R$ 12,50' => ['BJ000123', 1250, '00020101021126360014br.gov.bcb.pix011466803163000110520400005303986540512.505802BR5925BLUEJUS GESTAO E TECNOLOG6008BRASILIA62120508BJ00012363046F40'];
    }

    #[TestDox('BR Code igual ao do desenho: $caso')]
    #[DataProvider('doDesenho')]
    public function testIgualAoDesenho(string $txid, int $centavos, string $esperado): void
    {
        self::assertSame($esperado, CodigoPixEstatico::gerar($txid, $centavos));
        self::assertTrue(CodigoPixEstatico::valido($esperado));
    }

    #[TestDox('CRC adulterado ou estrutura quebrada não é PIX válido')]
    public function testInvalido(): void
    {
        $codigo = CodigoPixEstatico::gerar('BJ000001', 2000);

        self::assertFalse(CodigoPixEstatico::valido(substr($codigo, 0, -4) . '0000'));
        self::assertFalse(CodigoPixEstatico::valido('lixo'));
    }
}
