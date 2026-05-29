<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('PETICAO.PDF', $resultado->getTitulo());
        self::assertSame('PECA', $resultado->getCategoria());
        self::assertSame('application/pdf', $resultado->getMimeType());
        self::assertSame(1024, $resultado->getTamanhoBytes());
        self::assertSame('abc123.pdf', $resultado->getCaminhoArquivo());
        self::assertSame($this->pasta, $resultado->getPasta());
        self::assertNull($resultado->getSecao());
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

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'PECA', 'Descrição da peça', '001/2026', $this->tenant);

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

        $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);
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

        $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);
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

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'DEMAIS', '', '', $this->tenant);

        self::assertNull($resultado->getDescricao());
        self::assertNull($resultado->getNumero());
    }

    public function testUploadComSecaoValidaAssociaDocumentoASecao(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome('Documentos do Cliente');

        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(512);
        $file->method('getClientOriginalName')->willReturn('doc.pdf');

        $this->storage->method('salvar')->willReturn('doc.pdf');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $secao, $file, 'PECA', null, null, $this->tenant);

        self::assertSame($secao, $resultado->getSecao());
    }

    public function testUploadComSecaoDeTenantErradoLancaAccessDeniedException(): void
    {
        $outroTenant = new Tenant();
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($outroTenant);

        $file = $this->createMock(UploadedFile::class);

        $this->storage->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, $secao, $file, 'PECA', null, null, $this->tenant);
    }

    public function testUploadComSecaoDeOutraPastaLancaInvalidArgumentException(): void
    {
        $outraPasta = new Pasta();
        $secao = new PastaSecao();
        $secao->setPasta($outraPasta);
        $secao->setTenant($this->tenant);

        $file = $this->createMock(UploadedFile::class);

        $this->storage->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $secao, $file, 'PECA', null, null, $this->tenant);
    }

    public function testTextPlainEAceito(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getSize')->willReturn(1 * 1024 * 1024);
        $file->method('getClientOriginalName')->willReturn('nota.txt');

        $this->storage->method('salvar')->willReturn('nota.txt');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('text/plain', $resultado->getMimeType());
    }

    public function testApplicationZipEAceito(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/zip');
        $file->method('getSize')->willReturn(10 * 1024 * 1024);
        $file->method('getClientOriginalName')->willReturn('acervo.zip');

        $this->storage->method('salvar')->willReturn('acervo.zip');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('application/zip', $resultado->getMimeType());
    }

    public function testPkcs7SignatureEAceito(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pkcs7-signature');
        $file->method('getSize')->willReturn(50 * 1024);
        $file->method('getClientOriginalName')->willReturn('assinatura.p7s');

        $this->storage->method('salvar')->willReturn('assinatura.p7s');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('application/pkcs7-signature', $resultado->getMimeType());
    }

    public function testAudioOpusEAceito(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('audio/opus');
        $file->method('getSize')->willReturn(5 * 1024 * 1024);
        $file->method('getClientOriginalName')->willReturn('gravacao.opus');

        $this->storage->method('salvar')->willReturn('gravacao.opus');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('audio/opus', $resultado->getMimeType());
    }
}
