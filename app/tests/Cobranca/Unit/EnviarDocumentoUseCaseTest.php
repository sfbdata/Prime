<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\EnviarDocumentoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use App\Shared\Service\CompressorArquivoInterface;
use App\Shared\Service\ResultadoCompressao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(EnviarDocumentoUseCase::class)]
final class EnviarDocumentoUseCaseTest extends TestCase
{
    private const UPLOADS_DIR = '/uploads/cobrancas';

    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private ArquivoStorageInterface&MockObject $storage;
    private CompressorArquivoInterface&MockObject $compressor;
    private EnviarDocumentoUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->compressor = $this->createMock(CompressorArquivoInterface::class);
        $this->sut = new EnviarDocumentoUseCase(
            $this->documentoRepository,
            $this->storage,
            $this->compressor,
            self::UPLOADS_DIR,
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function enviaDocumentoNoCasoSemSecao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->arquivo('application/pdf', 2048, 'contrato.pdf');

        // Isolamento físico por tenant: o diretório efetivo termina em /<tenantId>.
        $this->storage
            ->expects($this->once())
            ->method('salvar')
            ->with($file, self::UPLOADS_DIR . '/7')
            ->willReturn('hash-abc');

        // Sem redução de tamanho: o compressor não é acionado.
        $this->compressor->expects($this->never())->method('comprimir');

        $this->documentoRepository->method('proximaOrdem')->with($caso)->willReturn(3);

        $salvo = null;
        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(CobrancaDocumento::class), true)
            ->willReturnCallback(function (CobrancaDocumento $doc) use (&$salvo): void {
                $salvo = $doc;
            });

        $documento = $this->sut->executar(
            $caso,
            null,
            $file,
            CategoriaDocumentoCobranca::TermoAcordo,
            'Contrato assinado',
            $this->tenant,
        );

        self::assertSame($salvo, $documento);
        self::assertSame($caso, $documento->getCaso());
        self::assertNull($documento->getSecao());
        self::assertSame($this->tenant, $documento->getTenant());
        self::assertSame('CONTRATO.PDF', $documento->getTitulo());
        self::assertSame(CategoriaDocumentoCobranca::TermoAcordo, $documento->getCategoria());
        self::assertSame('Contrato assinado', $documento->getDescricao());
        self::assertSame('hash-abc', $documento->getCaminhoArquivo());
        self::assertSame('contrato.pdf', $documento->getNomeOriginal());
        self::assertSame('application/pdf', $documento->getMimeType());
        self::assertSame(2048, $documento->getTamanhoBytes());
        self::assertSame(3, $documento->getOrdem());
    }

    #[Test]
    public function enviaDocumentoEmSecaoDoMesmoCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($caso);
        $file = $this->arquivo('image/png', 1024, 'boleto.png');

        $this->storage->method('salvar')->willReturn('hash-png');
        $this->documentoRepository->method('proximaOrdem')->willReturn(1);
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $caso,
            $secao,
            $file,
            CategoriaDocumentoCobranca::Boleto,
            null,
            $this->tenant,
        );

        self::assertSame($secao, $documento->getSecao());
        // descricao vazia/null vira null.
        self::assertNull($documento->getDescricao());
    }

    #[Test]
    public function comprimeQuandoReduzirTamanhoSolicitado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->arquivo('application/pdf', 8_000_000, 'peticao.pdf');

        $this->storage->method('salvar')->willReturn('hash-pdf');
        $this->storage
            ->expects($this->once())
            ->method('caminho')
            ->with(self::UPLOADS_DIR . '/7', 'hash-pdf')
            ->willReturn('/caminho/fisico/hash-pdf');

        // Compressão reduz o tamanho: o documento guarda o tamanho FINAL.
        $this->compressor
            ->expects($this->once())
            ->method('comprimir')
            ->with('/caminho/fisico/hash-pdf', 'application/pdf')
            ->willReturn(new ResultadoCompressao(8_000_000, 5_000_000, true));

        $this->documentoRepository->method('proximaOrdem')->willReturn(0);
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $caso,
            null,
            $file,
            CategoriaDocumentoCobranca::Outro,
            null,
            $this->tenant,
            reduzirTamanho: true,
        );

        self::assertSame(5_000_000, $documento->getTamanhoBytes());
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenantComId(99))->setCaso($caso);
        $file = $this->arquivo('application/pdf', 1024, 'x.pdf');

        // Nada toca o disco nem o banco: a guarda é anterior.
        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($caso, $secao, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaSecaoDeOutroCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($outroCaso);
        $file = $this->arquivo('application/pdf', 1024, 'x.pdf');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(SecaoNaoEncontradaException::class);

        $this->sut->executar($caso, $secao, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenantComId(42));
        $file = $this->arquivo('application/pdf', 1024, 'x.pdf');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaMimeForaDaWhitelist(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->arquivo('application/x-msdownload', 1024, 'virus.exe');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(TipoArquivoNaoPermitidoException::class);

        $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaArquivoAcimaDoLimite(): void
    {
        // PNG tem limite de 3 MB; 4 MB estoura.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->arquivo('image/png', 4 * 1024 * 1024, 'gigante.png');

        $this->storage->expects($this->never())->method('salvar');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(ArquivoMuitoGrandeException::class);

        $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant);
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
