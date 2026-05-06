<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\PastaDocumento;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Pasta\UseCase\ExportarPecaTextoUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportarPecaTextoUseCase::class)]
final class ExportarPecaTextoUseCaseTest extends TestCase
{
    private ArquivoStorageInterface&MockObject $storage;
    private ExportarPecaTextoUseCase $useCase;
    private string $arquivoHtml;
    private PastaDocumento $doc;

    private const HTML_SIMPLES = '<p>Petição válida com ação e acentuação: ç, ã, é.</p>';

    protected function setUp(): void
    {
        $this->storage = $this->createMock(ArquivoStorageInterface::class);

        $this->arquivoHtml = tempnam(sys_get_temp_dir(), 'peca_export_');
        file_put_contents($this->arquivoHtml, self::HTML_SIMPLES);

        $this->useCase = new ExportarPecaTextoUseCase(
            $this->storage,
            '/uploads/pastas',
            sys_get_temp_dir(),
        );

        $this->doc = (new PastaDocumento())
            ->setTitulo('Petição Inicial')
            ->setCaminhoArquivo(basename($this->arquivoHtml))
            ->setCategoria('PECA')
            ->setMimeType('text/html')
            ->setNomeOriginal('Petição Inicial.html')
            ->setTamanhoBytes(strlen(self::HTML_SIMPLES));

        $this->storage->method('caminho')->willReturn($this->arquivoHtml);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->arquivoHtml)) {
            unlink($this->arquivoHtml);
        }
    }

    #[TestDox('Exportar DOCX retorna mime correto, conteúdo não vazio e arquivo íntegro')]
    public function testExportarDocxRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'docx');

        self::assertInstanceOf(ExportarPecaTextoOutput::class, $output);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $output->mimeType,
        );
        self::assertStringEndsWith('.docx', $output->nomeArquivo);
        self::assertNotEmpty($output->conteudo);

        // Verificar integridade: DOCX é um ZIP — tentar abrir valida a estrutura
        $tmpDocx = tempnam(sys_get_temp_dir(), 'peca_chk_') . '.docx';
        file_put_contents($tmpDocx, $output->conteudo);
        try {
            $phpWord = IOFactory::load($tmpDocx, 'Word2007');
            self::assertNotNull($phpWord);
        } finally {
            @unlink($tmpDocx);
        }
    }

    #[TestDox('Exportar ODT retorna mime correto e conteúdo com estrutura ZIP válida')]
    public function testExportarOdtRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'odt');

        self::assertSame('application/vnd.oasis.opendocument.text', $output->mimeType);
        self::assertStringEndsWith('.odt', $output->nomeArquivo);
        self::assertNotEmpty($output->conteudo);

        // ODT é um ZIP — verificar que a estrutura básica é válida
        $tmpOdt = tempnam(sys_get_temp_dir(), 'peca_chk_') . '.odt';
        file_put_contents($tmpOdt, $output->conteudo);
        try {
            $zip = new \ZipArchive();
            $resultado = $zip->open($tmpOdt);
            self::assertTrue($resultado, 'ODT deve ser um arquivo ZIP válido');
            $zip->close();
        } finally {
            @unlink($tmpOdt);
        }
    }

    #[TestDox('Exportar PDF retorna mime correto e conteúdo com assinatura PDF válida')]
    public function testExportarPdfRetornaMimeCorretoEConteudoValido(): void
    {
        $output = $this->useCase->executar($this->doc, 'pdf');

        self::assertSame('application/pdf', $output->mimeType);
        self::assertStringEndsWith('.pdf', $output->nomeArquivo);
        self::assertStringStartsWith('%PDF-', $output->conteudo);
        self::assertGreaterThan(100, strlen($output->conteudo));
    }

    #[TestDox('Exportar TXT retorna texto sem tags HTML e com acentuação UTF-8 correta')]
    public function testExportarTxtRetornaTextoSemTagsComAcentuacao(): void
    {
        $output = $this->useCase->executar($this->doc, 'txt');

        self::assertSame('text/plain', $output->mimeType);
        self::assertStringEndsWith('.txt', $output->nomeArquivo);
        self::assertStringNotContainsString('<p>', $output->conteudo);
        self::assertStringNotContainsString('</p>', $output->conteudo);
        self::assertStringContainsString('ç', $output->conteudo);
        self::assertStringContainsString('ã', $output->conteudo);
        self::assertStringContainsString('é', $output->conteudo);
    }

    #[TestDox('Formato inválido lança InvalidArgumentException')]
    public function testFormatoInvalidoLancaInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->doc, 'xml');
    }

    #[TestDox('Exportar DOCX com tabela HTML5 (col sem self-closing) não lança exceção')]
    public function testExportarDocxComTabelaHtml5NaoLancaExcecao(): void
    {
        $html = '<table border="1"><colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>'
            . '<tbody><tr><td>Coluna A</td><td>Coluna B</td></tr></tbody></table>';
        file_put_contents($this->arquivoHtml, $html);

        $output = $this->useCase->executar($this->doc, 'docx');

        self::assertNotEmpty($output->conteudo);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $output->mimeType,
        );
    }

    #[TestDox('Exportar ODT com tabela HTML5 (col sem self-closing) não lança exceção')]
    public function testExportarOdtComTabelaHtml5NaoLancaExcecao(): void
    {
        $html = '<table border="1"><colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup>'
            . '<tbody><tr><td>Coluna A</td><td>Coluna B</td></tr></tbody></table>';
        file_put_contents($this->arquivoHtml, $html);

        $output = $this->useCase->executar($this->doc, 'odt');

        self::assertNotEmpty($output->conteudo);
        self::assertSame('application/vnd.oasis.opendocument.text', $output->mimeType);
    }

    #[TestDox('Nome do arquivo contém a extensão correta para cada formato')]
    public function testNomeDaArquivoContemExtensaoCorreta(): void
    {
        self::assertStringEndsWith('.docx', $this->useCase->executar($this->doc, 'docx')->nomeArquivo);
        self::assertStringEndsWith('.pdf', $this->useCase->executar($this->doc, 'pdf')->nomeArquivo);
        self::assertStringEndsWith('.txt', $this->useCase->executar($this->doc, 'txt')->nomeArquivo);
    }
}
