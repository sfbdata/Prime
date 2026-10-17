<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\ArquivoIconeExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O helper é a fonte única do padrão de ícone por tipo de arquivo no Twig (Guia
 * visual BlueJus, 04 Tipografia e ícones): a MESMA família `bi-file-earmark-*-fill`,
 * o MESMO `tipo` e as MESMAS cores que a aba Documentos desenha. Aqui se fixa o
 * que o helper devolve; a igualdade com o explorador e com o pasta-show.css é
 * provada em ArquivoIconePadraoFolhaTest, lendo os fontes.
 */
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
            'pdf'          => ['inicial.pdf', 'bi-file-earmark-pdf-fill', 'PDF'],
            'docx'         => ['contrato.DOCX', 'bi-file-earmark-word-fill', 'Word'],
            'doc'          => ['contrato.doc', 'bi-file-earmark-word-fill', 'Word'],
            'rtf'          => ['peca.rtf', 'bi-file-earmark-richtext-fill', 'Documento'],
            'texto'        => ['notas.txt', 'bi-file-earmark-text-fill', 'Texto'],
            'planilha'     => ['custas.xlsx', 'bi-file-earmark-excel-fill', 'Planilha'],
            'planilha_xls' => ['custas.xls', 'bi-file-earmark-excel-fill', 'Planilha'],
            'planilha_ods' => ['custas.ods', 'bi-file-earmark-spreadsheet-fill', 'Planilha'],
            'csv'          => ['lote.csv', 'bi-filetype-csv', 'CSV'],
            'slides'       => ['defesa.pptx', 'bi-file-earmark-slides-fill', 'Slides'],
            'imagem'       => ['foto.JPG', 'bi-file-earmark-image-fill', 'Imagem'],
            'imagem_png'   => ['print.png', 'bi-file-earmark-image-fill', 'Imagem'],
            'compactado'   => ['provas.zip', 'bi-file-earmark-zip-fill', 'Compactado'],
            'audio'        => ['gravacao.mp3', 'bi-file-earmark-music-fill', 'Áudio'],
            'video'        => ['audiencia.mp4', 'bi-file-earmark-play-fill', 'Vídeo'],
            'email'        => ['intimacao.eml', 'bi-envelope-paper-fill', 'E-mail'],
            'assinatura'   => ['peticao.p7s', 'bi-patch-check-fill', 'Assinatura'],
            'desconhecido' => ['arquivo.xyz', 'bi-file-earmark-fill', 'Arquivo'],
            'sem_extensao' => ['README', 'bi-file-earmark-fill', 'Arquivo'],
        ];
    }

    /**
     * As cores são as do Guia visual (PDF, Word, Excel) e dos tokens claros do
     * explorador da aba Documentos para os demais tipos. O hex serve a quem pinta
     * inline (Cobrança); a tela da pasta pinta pela classe do `tipo`.
     */
    #[DataProvider('casosCor')]
    #[TestDox('a cor do tipo é a do Guia visual / aba Documentos: $nome → $corEsperada')]
    public function testCorDoTipoSegueOGuiaVisual(string $nome, string $corEsperada): void
    {
        self::assertSame($corEsperada, $this->ext->arquivoIcone($nome)['cor']);
    }

    public static function casosCor(): array
    {
        return [
            'pdf'        => ['inicial.pdf', '#d93a2b'],
            'word'       => ['contrato.docx', '#1f5fbf'],
            'excel'      => ['custas.xlsx', '#1d7a46'],
            'imagem'     => ['foto.jpg', '#7a4bb0'],
            'compactado' => ['provas.zip', '#b7791f'],
            'texto'      => ['notas.txt', '#4f6878'],
            'slides'     => ['defesa.pptx', '#c0571d'],
            'midia'      => ['gravacao.mp3', '#7a4bb0'],
            'email'      => ['intimacao.eml', '#0a7aad'],
            'assinatura' => ['peticao.p7s', '#1d7a46'],
            'generico'   => ['arquivo.xyz', '#6b8494'],
        ];
    }

    #[DataProvider('casosTipo')]
    #[TestDox('o tipo é a chave da classe que pinta (a mesma do explorador): $nome → $tipoEsperado')]
    public function testTipoPorExtensao(string $nome, string $tipoEsperado): void
    {
        self::assertSame($tipoEsperado, $this->ext->arquivoIcone($nome)['tipo']);
    }

    public static function casosTipo(): array
    {
        return [
            'pdf'    => ['a.pdf', 'pdf'],
            'word'   => ['a.docx', 'word'],
            'rtf'    => ['a.rtf', 'word'],
            'excel'  => ['a.xlsx', 'excel'],
            'csv'    => ['a.csv', 'excel'],
            'slides' => ['a.pptx', 'slides'],
            'imagem' => ['a.jpg', 'img'],
            'zip'    => ['a.zip', 'zip'],
            'texto'  => ['a.txt', 'txt'],
            'html'   => ['a.html', 'txt'],
            'audio'  => ['a.mp3', 'midia'],
            'video'  => ['a.mp4', 'midia'],
            'email'  => ['a.eml', 'email'],
            'assin'  => ['a.p7s', 'assin'],
            'outro'  => ['a.xyz', 'outro'],
        ];
    }

    #[DataProvider('casosMime')]
    public function testArquivoIconeFallbackPorMime(string $mime, string $iconeEsperado, string $rotuloEsperado): void
    {
        $resultado = $this->ext->arquivoIcone('sem-ext', $mime);

        self::assertSame($iconeEsperado, $resultado['icone']);
        self::assertSame($rotuloEsperado, $resultado['rotulo']);
    }

    public static function casosMime(): array
    {
        return [
            'pdf'      => ['application/pdf', 'bi-file-earmark-pdf-fill', 'PDF'],
            'word'     => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'bi-file-earmark-word-fill', 'Word'],
            'planilha' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bi-file-earmark-excel-fill', 'Planilha'],
            'slides'   => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'bi-file-earmark-slides-fill', 'Slides'],
            'imagem'   => ['image/webp', 'bi-file-earmark-image-fill', 'Imagem'],
            'video'    => ['video/mp4', 'bi-file-earmark-play-fill', 'Vídeo'],
            'audio'    => ['audio/ogg', 'bi-file-earmark-music-fill', 'Áudio'],
            'zip'      => ['application/zip', 'bi-file-earmark-zip-fill', 'Compactado'],
            'nenhum'   => ['application/octet-stream', 'bi-file-earmark-fill', 'Arquivo'],
        ];
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
