<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\PreparadorDeImagemDaCampanha;
use App\Comercial\DTO\ImagemPreparada;
use App\Comercial\Exception\CampanhaRecusada;
use App\Tests\Comercial\Support\FabricaDeImagens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conferência e a regravação da imagem da campanha (spec comercial-e-emails.md §12, F3i): o tipo pelo conteúdo, só
 * JPEG e PNG, 1 MB, 4000 px, e o que sai é só o que o GD desenhou (o EXIF some).
 */
#[CoversClass(PreparadorDeImagemDaCampanha::class)]
#[CoversClass(ImagemPreparada::class)]
final class PreparadorDeImagemDaCampanhaTest extends TestCase
{
    #[TestDox('JPEG aceito e regravado: as dimensões ficam, o EXIF (com a localização) some')]
    public function testJpegSemExif(): void
    {
        $original = FabricaDeImagens::jpegComExif(120, 80, 'GPS-SEGREDO-DA-FOTO');
        self::assertStringContainsString('GPS-SEGREDO-DA-FOTO', $original);

        $saida = (new PreparadorDeImagemDaCampanha())->preparar($original);

        self::assertSame(['image/jpeg', 'jpg', 120, 80], [$saida->mime, $saida->extensao, $saida->largura, $saida->altura]);
        self::assertStringNotContainsString('GPS-SEGREDO-DA-FOTO', $saida->bytes);
        self::assertStringNotContainsString('Exif', $saida->bytes);
        self::assertSame("\xFF\xD8", substr($saida->bytes, 0, 2));
        self::assertSame(hash('sha256', $saida->bytes), $saida->sha256());
        self::assertSame(\strlen($saida->bytes), $saida->tamanho());
    }

    #[TestDox('PNG aceito e regravado como PNG')]
    public function testPng(): void
    {
        $saida = (new PreparadorDeImagemDaCampanha())->preparar(FabricaDeImagens::png(30, 40));

        self::assertSame(['image/png', 'png', 30, 40], [$saida->mime, $saida->extensao, $saida->largura, $saida->altura]);
        self::assertSame("\x89PNG", substr($saida->bytes, 0, 4));
    }

    /** @return iterable<string, array{string, string}> */
    public static function recusadas(): iterable
    {
        yield 'GIF' => [FabricaDeImagens::gif(10, 10), PreparadorDeImagemDaCampanha::TIPO_RECUSADO];
        yield 'texto com nome de .jpg' => ['isto não é uma imagem, só um texto renomeado', PreparadorDeImagemDaCampanha::TIPO_RECUSADO];
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', PreparadorDeImagemDaCampanha::TIPO_RECUSADO];
        yield 'vazio' => ['', PreparadorDeImagemDaCampanha::TIPO_RECUSADO];
        yield 'JPEG cortado no meio' => [substr(FabricaDeImagens::jpeg(50, 50), 0, 40), PreparadorDeImagemDaCampanha::TIPO_RECUSADO];
        yield 'acima de 1 MB' => [FabricaDeImagens::jpeg(10, 10) . str_repeat("\0", PreparadorDeImagemDaCampanha::LIMITE_EM_BYTES), PreparadorDeImagemDaCampanha::GRANDE_DEMAIS];
        yield 'acima de 4000 px de largura' => [FabricaDeImagens::png(4001, 2), PreparadorDeImagemDaCampanha::DIMENSAO_RECUSADA];
        yield 'acima de 4000 px de altura' => [FabricaDeImagens::png(2, 4001), PreparadorDeImagemDaCampanha::DIMENSAO_RECUSADA];
    }

    #[DataProvider('recusadas')]
    #[TestDox('Recusa: $_dataName')]
    public function testRecusa(string $bytes, string $mensagem): void
    {
        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage($mensagem);

        (new PreparadorDeImagemDaCampanha())->preparar($bytes);
    }

    #[TestDox('4000 px de lado ainda passa (o limite é "no máximo")')]
    public function testNoLimiteDoLado(): void
    {
        $saida = (new PreparadorDeImagemDaCampanha())->preparar(FabricaDeImagens::png(4000, 1));

        self::assertSame([4000, 1], [$saida->largura, $saida->altura]);
    }
}
