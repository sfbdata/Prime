<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Armazenamento\GeradorDeCodigoDeDocumento;
use App\Pasta\Repository\ArquivoArmazenadoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeradorDeCodigoDeDocumento::class)]
final class GeradorDeCodigoDeDocumentoTest extends TestCase
{
    #[TestDox('o código tem o formato DOC-XXXX-XXXX-XXXX sem caracteres ambíguos')]
    public function testFormato(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            self::assertMatchesRegularExpression('/^DOC-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', GeradorDeCodigoDeDocumento::sortear());
        }
    }

    #[TestDox('repete o sorteio quando o código já existe no escritório')]
    public function testRepeteNaColisao(): void
    {
        $repo = $this->createMock(ArquivoArmazenadoRepository::class);
        $repo->expects(self::exactly(3))->method('existeCodigo')->willReturnOnConsecutiveCalls(true, true, false);

        self::assertStringStartsWith('DOC-', (new GeradorDeCodigoDeDocumento($repo))->gerar(1));
    }

    #[TestDox('desiste depois de muitas colisões em vez de laçar para sempre')]
    public function testDesiste(): void
    {
        $repo = $this->createStub(ArquivoArmazenadoRepository::class);
        $repo->method('existeCodigo')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        (new GeradorDeCodigoDeDocumento($repo))->gerar(1);
    }
}
