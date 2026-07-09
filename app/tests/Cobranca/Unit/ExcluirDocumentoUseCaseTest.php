<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\ExcluirDocumentoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ExcluirDocumentoUseCase::class)]
final class ExcluirDocumentoUseCaseTest extends TestCase
{
    private const UPLOADS_DIR = '/uploads/cobrancas';

    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private ArquivoStorageInterface&MockObject $storage;
    private ExcluirDocumentoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->sut = new ExcluirDocumentoUseCase(
            $this->documentoRepository,
            $this->storage,
            self::UPLOADS_DIR,
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function excluiArquivoFisicoERegistroQuandoArquivoExiste(): void
    {
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaminhoArquivo('hash-abc');

        // Caminho reconstruído com isolamento por tenant (contrato congelado, padrão M5).
        $this->storage
            ->expects($this->once())
            ->method('caminho')
            ->with(self::UPLOADS_DIR . '/7', 'hash-abc')
            ->willReturn('/fisico/hash-abc');
        $this->storage->method('existe')->with('/fisico/hash-abc')->willReturn(true);
        $this->storage->expects($this->once())->method('excluir')->with('/fisico/hash-abc');

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $this->tenant);
    }

    #[Test]
    public function removeRegistroMesmoQuandoArquivoNaoExisteNoDisco(): void
    {
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaminhoArquivo('hash-sumido');

        $this->storage->method('caminho')->willReturn('/fisico/hash-sumido');
        $this->storage->method('existe')->willReturn(false);
        // Best-effort: arquivo ausente não impede a remoção da linha, e nada é excluído do disco.
        $this->storage->expects($this->never())->method('excluir');

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $this->tenant);
    }

    #[Test]
    public function rejeitaDocumentoDeOutroTenant(): void
    {
        $documento = (new CobrancaDocumento())->setTenant($this->tenantComId(99))->setCaminhoArquivo('hash-x');

        // Guarda anterior: nada toca o disco nem o banco.
        $this->storage->expects($this->never())->method('existe');
        $this->storage->expects($this->never())->method('excluir');
        $this->documentoRepository->expects($this->never())->method('remover');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($documento, $this->tenant);
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
