<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\UploadImagemEditorInput;
use App\Pasta\UseCase\UploadImagemEditorUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(UploadImagemEditorUseCase::class)]
final class UploadImagemEditorUseCaseTest extends TestCase
{
    private ArquivoStorageInterface&MockObject $storage;
    private UploadImagemEditorUseCase $useCase;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->useCase = new UploadImagemEditorUseCase($this->storage);
    }

    private function criarArquivoMock(string $mimeType, int $tamanho): UploadedFile&MockObject
    {
        $arquivo = $this->createMock(UploadedFile::class);
        $arquivo->method('getMimeType')->willReturn($mimeType);
        $arquivo->method('getSize')->willReturn($tamanho);

        return $arquivo;
    }

    #[TestDox('Upload de JPEG válido salva o arquivo e retorna o nome gerado')]
    public function testDeveRetornarNomeArquivoAoFazerUploadDeJpegValido(): void
    {
        $arquivo = $this->criarArquivoMock('image/jpeg', 1 * 1024 * 1024);

        $this->storage->expects($this->once())
            ->method('salvar')
            ->with($arquivo, '/uploads/pastas')
            ->willReturn('abc123.jpg');

        $output = $this->useCase->executar(new UploadImagemEditorInput($arquivo, '/uploads/pastas'));

        self::assertSame('abc123.jpg', $output->nomeArquivo);
    }

    #[TestDox('Upload de PNG válido salva o arquivo e retorna o nome gerado')]
    public function testDeveRetornarNomeArquivoAoFazerUploadDePngValido(): void
    {
        $arquivo = $this->criarArquivoMock('image/png', 500 * 1024);

        $this->storage->method('salvar')->willReturn('img456.png');

        $output = $this->useCase->executar(new UploadImagemEditorInput($arquivo, '/uploads/pastas'));

        self::assertSame('img456.png', $output->nomeArquivo);
    }

    #[TestDox('MIME type inválido lança InvalidArgumentException sem chamar storage')]
    public function testDeveLancarExcecaoParaMimeTypeInvalido(): void
    {
        $arquivo = $this->criarArquivoMock('application/pdf', 1024);

        $this->storage->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Apenas imagens JPEG e PNG são permitidas no editor.');

        $this->useCase->executar(new UploadImagemEditorInput($arquivo, '/uploads/pastas'));
    }

    #[TestDox('Arquivo maior que 3 MB lança InvalidArgumentException sem chamar storage')]
    public function testDeveLancarExcecaoParaArquivoMuitoGrande(): void
    {
        $arquivo = $this->criarArquivoMock('image/jpeg', 4 * 1024 * 1024);

        $this->storage->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A imagem não pode ter mais de 3 MB.');

        $this->useCase->executar(new UploadImagemEditorInput($arquivo, '/uploads/pastas'));
    }

    #[TestDox('MIME type nulo é tratado como inválido')]
    public function testDeveLancarExcecaoParaMimeTypeNulo(): void
    {
        $arquivo = $this->createMock(UploadedFile::class);
        $arquivo->method('getMimeType')->willReturn(null);
        $arquivo->method('getSize')->willReturn(1024);

        $this->storage->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar(new UploadImagemEditorInput($arquivo, '/uploads/pastas'));
    }
}
