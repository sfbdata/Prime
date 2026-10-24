<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Service\MontadorDeBlocosDaAnthropic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os blocos que o "Ler N documentos" (PR F) manda na última mensagem do usuário: PDF como `document`, imagem
 * como `image`, os dois em base64 inline, na ordem dos anexos, e o texto do pedido por último.
 */
#[CoversClass(MontadorDeBlocosDaAnthropic::class)]
#[CoversClass(AnexoDeDocumento::class)]
final class MontadorDeBlocosDaAnthropicTest extends TestCase
{
    #[TestDox('Sem anexos: o conteúdo continua a string')]
    public function testSemAnexosEhString(): void
    {
        self::assertSame('Resuma.', MontadorDeBlocosDaAnthropic::conteudo('Resuma.', []));
    }

    #[TestDox('Com anexos: rótulo e bloco de cada arquivo, na ordem, e o texto por último')]
    public function testComAnexos(): void
    {
        $pdf = new AnexoDeDocumento(7, 'peticao.pdf', AnexoDeDocumento::PDF, '%PDF-1.4 x');
        $png = new AnexoDeDocumento(9, 'foto.png', 'image/png', "\x89PNG\r\n\x1A\nzz");

        $blocos = MontadorDeBlocosDaAnthropic::conteudo('Leia.', [$pdf, $png]);

        self::assertIsArray($blocos);
        self::assertSame(['text', 'document', 'text', 'image', 'text'], array_column($blocos, 'type'));
        self::assertSame('DOCUMENTO ANEXADO 1', $blocos[0]['text']);
        self::assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 x')], $blocos[1]['source']);
        self::assertSame('DOCUMENTO ANEXADO 2', $blocos[2]['text']);
        self::assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode("\x89PNG\r\n\x1A\nzz")], $blocos[3]['source']);
        self::assertSame('Leia.', $blocos[4]['text']);
        self::assertSame(['type', 'source'], array_keys($blocos[1]), 'o nome do arquivo não vai em title nem metadado');
    }

    #[TestDox('Anexo: só PDF e as quatro imagens, nunca vazio; sha256 e tamanho do conteúdo')]
    public function testAnexo(): void
    {
        $anexo = new AnexoDeDocumento(1, 'a.pdf', AnexoDeDocumento::PDF, 'abc');
        self::assertTrue($anexo->ehPdf());
        self::assertSame(hash('sha256', 'abc'), $anexo->sha256());
        self::assertSame(3, $anexo->tamanho());
        self::assertFalse((new AnexoDeDocumento(1, 'a.webp', 'image/webp', 'x'))->ehPdf());

        foreach ([['image/svg+xml', 'x'], ['text/plain', 'x'], [AnexoDeDocumento::PDF, '']] as [$tipo, $bytes]) {
            try {
                new AnexoDeDocumento(1, 'a', $tipo, $bytes);
                self::fail('Deveria recusar ' . $tipo);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
