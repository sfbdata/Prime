<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ResultadoUploadPeca;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use App\Shared\Service\CompressorArquivoInterface;
use App\Shared\Service\ResultadoCompressao;
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
    private CompressorArquivoInterface&MockObject $compressor;
    private UploadPecaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->storage    = $this->createMock(ArquivoStorageInterface::class);
        $this->compressor = $this->createMock(CompressorArquivoInterface::class);
        $this->useCase    = new UploadPecaUseCase($this->em, $this->storage, $this->compressor, '/uploads/pastas');
        $this->pasta      = new Pasta();
        $this->tenant     = new Tenant();
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

        $this->compressor->expects($this->never())->method('comprimir');
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);

        self::assertInstanceOf(ResultadoUploadPeca::class, $resultado);
        $doc = $resultado->documento;
        self::assertInstanceOf(PastaDocumento::class, $doc);
        self::assertSame('PETICAO.PDF', $doc->getTitulo());
        self::assertSame('PECA', $doc->getCategoria());
        self::assertSame('application/pdf', $doc->getMimeType());
        self::assertSame(1024, $doc->getTamanhoBytes());
        self::assertSame('abc123.pdf', $doc->getCaminhoArquivo());
        self::assertSame($this->pasta, $doc->getPasta());
        self::assertNull($doc->getSecao());
        self::assertFalse($resultado->compressao->comprimido);
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

        self::assertSame('Descrição da peça', $resultado->documento->getDescricao());
        self::assertSame('001/2026', $resultado->documento->getNumero());
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

        self::assertNull($resultado->documento->getDescricao());
        self::assertNull($resultado->documento->getNumero());
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

        self::assertSame($secao, $resultado->documento->getSecao());
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

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('text/plain', $resultado->documento->getMimeType());
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

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('application/zip', $resultado->documento->getMimeType());
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

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('application/pkcs7-signature', $resultado->documento->getMimeType());
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

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('audio/opus', $resultado->documento->getMimeType());
    }

    public function testReduzirTamanhoComprimeEGravaTamanhoFinal(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(5000);
        $file->method('getClientOriginalName')->willReturn('grande.pdf');

        $this->storage->method('salvar')->willReturn('abc.pdf');
        $this->storage->method('caminho')
            ->with('/uploads/pastas', 'abc.pdf')
            ->willReturn('/uploads/pastas/abc.pdf');

        $this->compressor->expects($this->once())
            ->method('comprimir')
            ->with('/uploads/pastas/abc.pdf', 'application/pdf')
            ->willReturn(new ResultadoCompressao(5000, 1500, true, true));

        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant, true);

        self::assertSame(1500, $resultado->documento->getTamanhoBytes());
        self::assertTrue($resultado->compressao->comprimido);
        self::assertTrue($resultado->compressao->eraAssinado);
    }
}
