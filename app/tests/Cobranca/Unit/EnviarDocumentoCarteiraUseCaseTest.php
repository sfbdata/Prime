<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Enum\CategoriaDocumentoCarteira;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\CarteiraDocumentoRepository;
use App\Cobranca\UseCase\EnviarDocumentoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(EnviarDocumentoCarteiraUseCase::class)]
final class EnviarDocumentoCarteiraUseCaseTest extends TestCase
{
    private const UPLOADS_DIR = '/uploads/cobrancas';

    private CarteiraDocumentoRepository&MockObject $documentoRepository;
    private ArquivoStorageInterface&MockObject $storage;
    private EnviarDocumentoCarteiraUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CarteiraDocumentoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->sut = new EnviarDocumentoCarteiraUseCase(
            $this->documentoRepository,
            $this->storage,
            self::UPLOADS_DIR,
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function enviaDocumentoNaCarteira(): void
    {
        $carteira = (new Carteira())->setTenant($this->tenant);
        $file = $this->arquivo('application/pdf', 2048, 'ata.pdf');

        // Isolamento físico por tenant: MESMO diretório flat dos documentos de caso.
        $this->storage
            ->expects($this->once())
            ->method('salvar')
            ->with($file, self::UPLOADS_DIR . '/7')
            ->willReturn('hash-abc');

        $salvo = null;
        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(CarteiraDocumento::class), true)
            ->willReturnCallback(function (CarteiraDocumento $doc) use (&$salvo): void {
                $salvo = $doc;
            });

        $documento = $this->sut->executar(
            $carteira,
            $file,
            CategoriaDocumentoCarteira::AtaDeReuniao,
            'Ata da assembleia',
            $this->tenant,
        );

        self::assertSame($salvo, $documento);
        self::assertSame($carteira, $documento->getCarteira());
        self::assertSame($this->tenant, $documento->getTenant());
        self::assertSame('ata.pdf', $documento->getTitulo());
        self::assertSame(CategoriaDocumentoCarteira::AtaDeReuniao, $documento->getCategoria());
        self::assertSame('Ata da assembleia', $documento->getObservacao());
        self::assertSame('hash-abc', $documento->getCaminhoArquivo());
        self::assertSame('ata.pdf', $documento->getNomeOriginal());
        self::assertSame('application/pdf', $documento->getMimeType());
        self::assertSame(2048, $documento->getTamanhoBytes());
    }

    #[Test]
    public function observacaoVaziaOuNulaViraNull(): void
    {
        $carteira = (new Carteira())->setTenant($this->tenant);
        $file = $this->arquivo('image/png', 1024, 'contrato.png');

        $this->storage->method('salvar')->willReturn('hash-png');
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $carteira,
            $file,
            CategoriaDocumentoCarteira::Contrato,
            '',
            $this->tenant,
        );

        self::assertNull($documento->getObservacao());
    }

    #[Test]
    public function rejeitaCarteiraDeOutroTenant(): void
    {
        $carteira = (new Carteira())->setTenant($this->tenantComId(42));
        $file = $this->arquivo('application/pdf', 1024, 'x.pdf');

        // Guarda IDOR: nada toca o disco nem o banco.
        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($carteira, $file, CategoriaDocumentoCarteira::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaMimeForaDaWhitelist(): void
    {
        $carteira = (new Carteira())->setTenant($this->tenant);
        $file = $this->arquivo('application/x-msdownload', 1024, 'virus.exe');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(TipoArquivoNaoPermitidoException::class);

        $this->sut->executar($carteira, $file, CategoriaDocumentoCarteira::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaArquivoAcimaDoLimite(): void
    {
        // PNG tem limite de 3 MB; 4 MB estoura.
        $carteira = (new Carteira())->setTenant($this->tenant);
        $file = $this->arquivo('image/png', 4 * 1024 * 1024, 'gigante.png');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(ArquivoMuitoGrandeException::class);

        $this->sut->executar($carteira, $file, CategoriaDocumentoCarteira::Outro, null, $this->tenant);
    }

    private function arquivo(string $mime, int $tamanho, string $nome): UploadedFile&MockObject
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($tamanho);
        $file->method('getClientOriginalName')->willReturn($nome);

        return $file;
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
