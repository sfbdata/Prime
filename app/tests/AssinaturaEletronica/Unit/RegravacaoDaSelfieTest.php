<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\SelfieRegravada;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\RegravacaoDaSelfie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A foto recebida no "Assinar" Reforçado (contrato da etapa 7, decisão 6 e seção 6): só JPEG ou PNG pelo conteúdo, até
 * 4 MB, de 240 a 4.096 px por lado e até 12,5 megapixels, e sempre regravada em JPEG pelo GD (sai o EXIF e qualquer
 * byte escondido), com fundo branco e o lado maior até 1.600 px.
 */
#[CoversClass(RegravacaoDaSelfie::class)]
#[CoversClass(SelfieRegravada::class)]
final class RegravacaoDaSelfieTest extends TestCase
{
    #[TestDox('JPEG com EXIF e bytes depois do fim: regravado em JPEG sem nenhum dos dois, com o sha256 do que sai')]
    public function testJpegSemExifNemBytesEscondidos(): void
    {
        $jpeg = self::jpeg(320, 240);
        $exif = "Exif\x00\x00" . 'SEGREDO-DO-EXIF GPS -15.79';
        $comExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', \strlen($exif) + 2) . $exif . substr($jpeg, 2) . 'ESCONDIDO-DEPOIS-DO-FIM';

        $selfie = RegravacaoDaSelfie::regravar(self::dataUrl('image/jpeg', $comExif));

        self::assertStringStartsWith("\xFF\xD8", $selfie->jpeg);
        self::assertStringNotContainsString('SEGREDO-DO-EXIF', $selfie->jpeg);
        self::assertStringNotContainsString('ESCONDIDO-DEPOIS-DO-FIM', $selfie->jpeg);
        self::assertSame(hash('sha256', $selfie->jpeg), $selfie->sha256);
        self::assertSame([320, 240], [$selfie->largura, $selfie->altura]);
        self::assertSame(\IMAGETYPE_JPEG, getimagesizefromstring($selfie->jpeg)[2] ?? null);
    }

    #[TestDox('PNG transparente: vira JPEG com fundo branco')]
    public function testPngComFundoBranco(): void
    {
        $imagem = imagecreatetruecolor(300, 300);
        imagealphablending($imagem, false);
        imagesavealpha($imagem, true);
        imagefill($imagem, 0, 0, (int) imagecolorallocatealpha($imagem, 0, 0, 0, 127));
        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();

        $selfie = RegravacaoDaSelfie::regravar(self::dataUrl('image/png', $png));

        self::assertSame(\IMAGETYPE_JPEG, getimagesizefromstring($selfie->jpeg)[2] ?? null);
        $regravada = imagecreatefromstring($selfie->jpeg);
        self::assertNotFalse($regravada);
        $cor = imagecolorsforindex($regravada, (int) imagecolorat($regravada, 150, 150));
        self::assertGreaterThan(245, min($cor['red'], $cor['green'], $cor['blue']), 'o transparente vira branco, não preto');
    }

    #[TestDox('o tipo vem do conteúdo, não do prefixo: JPEG com prefixo de PNG passa e sai JPEG')]
    public function testTipoPeloConteudo(): void
    {
        $selfie = RegravacaoDaSelfie::regravar(self::dataUrl('image/png', self::jpeg(320, 240)));

        self::assertSame(\IMAGETYPE_JPEG, getimagesizefromstring($selfie->jpeg)[2] ?? null);
    }

    #[TestDox('lado maior acima de 1.600 px: reduz mantendo a proporção; abaixo, fica como veio')]
    public function testReducao(): void
    {
        $grande = RegravacaoDaSelfie::regravar(self::dataUrl('image/jpeg', self::jpeg(2000, 1000)));
        $retrato = RegravacaoDaSelfie::regravar(self::dataUrl('image/jpeg', self::jpeg(900, 1800)));

        self::assertSame([1600, 800], [$grande->largura, $grande->altura]);
        self::assertSame([1600, 800], \array_slice((array) getimagesizefromstring($grande->jpeg), 0, 2));
        self::assertSame([800, 1600], [$retrato->largura, $retrato->altura]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidas(): iterable
    {
        yield 'GIF' => [self::dataUrl('image/gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: ''), 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'texto com prefixo de JPEG' => [self::dataUrl('image/jpeg', 'não é imagem'), 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'sem prefixo de data URL' => [base64_encode('qualquer coisa'), 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'base64 quebrado' => ['data:image/jpeg;base64,@@@###', 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'JPEG cortado' => [self::dataUrl('image/jpeg', substr(self::jpeg(320, 240), 0, 200)), 'assinatura_eletronica.erro.selfie_invalida'];
        yield 'pequena demais (100 x 100)' => [self::dataUrl('image/jpeg', self::jpeg(100, 100)), 'assinatura_eletronica.erro.selfie_tamanho'];
        yield 'lado acima de 4.096 px' => [self::dataUrl('image/png', self::pngDeCabecalho(5000, 300)), 'assinatura_eletronica.erro.selfie_tamanho'];
        yield 'acima de 12,5 megapixels' => [self::dataUrl('image/png', self::pngDeCabecalho(4000, 4000)), 'assinatura_eletronica.erro.selfie_tamanho'];
        yield 'acima de 4 MB' => ['data:image/jpeg;base64,' . str_repeat('A', 4 * (int) ceil(RegravacaoDaSelfie::MAXIMO_EM_BYTES / 3) + 4), 'assinatura_eletronica.erro.selfie_grande'];
    }

    #[DataProvider('invalidas')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusa(string $dataUrl, string $erro): void
    {
        $this->expectExceptionObject(new AcaoRecusada($erro));

        RegravacaoDaSelfie::regravar($dataUrl);
    }

    private static function dataUrl(string $tipo, string $bytes): string
    {
        return 'data:' . $tipo . ';base64,' . base64_encode($bytes);
    }

    private static function jpeg(int $largura, int $altura): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagefilledrectangle($imagem, 0, 0, $largura - 1, $altura - 1, (int) imagecolorallocate($imagem, 40, 120, 200));
        ob_start();
        imagejpeg($imagem, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Um PNG pequeno de verdade com as dimensões do cabeçalho trocadas: o `getimagesizefromstring` lê só o cabeçalho, e
     * o teste confere a dimensão sem montar uma imagem enorme na memória.
     */
    private static function pngDeCabecalho(int $largura, int $altura): string
    {
        $imagem = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($imagem);
        $png = (string) ob_get_clean();

        return substr($png, 0, 16) . pack('NN', $largura, $altura) . substr($png, 24);
    }
}
