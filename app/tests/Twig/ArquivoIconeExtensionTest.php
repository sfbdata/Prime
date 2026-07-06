<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\ArquivoIconeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArquivoIconeExtension::class)]
final class ArquivoIconeExtensionTest extends TestCase
{
    private ArquivoIconeExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new ArquivoIconeExtension();
    }

    #[DataProvider('casosIcone')]
    public function testArquivoIconePorExtensao(string $nome, string $iconeEsperado, string $rotuloEsperado): void
    {
        $resultado = $this->ext->arquivoIcone($nome);

        self::assertSame($iconeEsperado, $resultado['icone']);
        self::assertSame($rotuloEsperado, $resultado['rotulo']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $resultado['cor']);
    }

    public static function casosIcone(): array
    {
        return [
            'pdf'          => ['inicial.pdf', 'bi-filetype-pdf', 'PDF'],
            'docx'         => ['contrato.DOCX', 'bi-filetype-docx', 'Word'],
            'planilha'     => ['custas.xlsx', 'bi-filetype-xlsx', 'Planilha'],
            'imagem'       => ['foto.JPG', 'bi-filetype-jpg', 'Imagem'],
            'compactado'   => ['provas.zip', 'bi-file-zip', 'Compactado'],
            'desconhecido' => ['arquivo.xyz', 'bi-file-earmark', 'Arquivo'],
            'sem_extensao' => ['README', 'bi-file-earmark', 'Arquivo'],
        ];
    }

    public function testArquivoIconeFallbackPorMime(): void
    {
        $resultado = $this->ext->arquivoIcone('sem-ext', 'application/pdf');

        self::assertSame('bi-filetype-pdf', $resultado['icone']);
        self::assertSame('PDF', $resultado['rotulo']);
    }

    #[DataProvider('casosBytes')]
    public function testFormatarBytes(int $bytes, string $esperado): void
    {
        self::assertSame($esperado, $this->ext->formatarBytes($bytes));
    }

    public static function casosBytes(): array
    {
        return [
            'bytes'      => [512, '512 B'],
            'kilobytes'  => [2048, '2,0 KB'],
            'megabytes'  => [5 * 1024 * 1024, '5,00 MB'],
            'zero'       => [0, '0 B'],
        ];
    }

    public function testFormatarBytesNuloVira0(): void
    {
        self::assertSame('0 B', $this->ext->formatarBytes(null));
    }
}
