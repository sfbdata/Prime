<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(UploadPecaUseCase::class)]
final class UploadPecaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private UploadPecaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->useCase = new UploadPecaUseCase($this->em, $this->storage, '/uploads/pastas');
        $this->pasta   = new Pasta();
        $this->tenant  = new Tenant();
    }

    public function testUploadValidoRetornaPastaDocumento(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(1024);
        $file->method('getClientOriginalName')->willReturn('peticao.pdf');

        $this->storage->expects($this->once())
            ->method('salvar')
            ->with($file, '/uploads/pastas')
            ->willReturn('abc123.pdf');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $file, 'PECA', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('PETICAO.PDF', $resultado->getTitulo());
        self::assertSame('PECA', $resultado->getCategoria());
        self::assertSame('application/pdf', $resultado->getMimeType());
        self::assertSame(1024, $resultado->getTamanhoBytes());
        self::assertSame('abc123.pdf', $resultado->getCaminhoArquivo());
        self::assertSame($this->pasta, $resultado->getPasta());
    }

    public function testUploadComDescricaoENumero(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(512);
        $file->method('getClientOriginalName')->willReturn('doc.pdf');

        $this->storage->method('salvar')->willReturn('xyz.pdf');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $file, 'PECA', 'Descrição da peça', '001/2026', $this->tenant);

        self::assertSame('Descrição da peça', $resultado->getDescricao());
        self::assertSame('001/2026', $resultado->getNumero());
    }

    public function testMimeTypeInvalidoLancaInvalidArgumentException(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/x-executable');
        $file->method('getClientOriginalName')->willReturn('malware.exe');

        $this->storage->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $file, 'PECA', null, null, $this->tenant);
    }

    public function testTamanhoExcedidoLancaInvalidArgumentException(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(20 * 1024 * 1024); // 20 MB > limite de 10 MB
        $file->method('getClientOriginalName')->willReturn('grande.pdf');

        $this->storage->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $file, 'PECA', null, null, $this->tenant);
    }

    public function testDescricaoENumeroVaziosViramNull(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(100);
        $file->method('getClientOriginalName')->willReturn('teste.pdf');

        $this->storage->method('salvar')->willReturn('file.pdf');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $file, 'DEMAIS', '', '', $this->tenant);

        self::assertNull($resultado->getDescricao());
        self::assertNull($resultado->getNumero());
    }
}
