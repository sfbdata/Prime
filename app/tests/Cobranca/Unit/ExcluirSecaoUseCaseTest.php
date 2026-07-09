<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\ExcluirSecaoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirSecaoUseCase::class)]
final class ExcluirSecaoUseCaseTest extends TestCase
{
    private const UPLOADS_DIR = '/uploads/cobrancas';

    private CobrancaSecaoRepository&MockObject $secaoRepository;
    private ArquivoStorageInterface&MockObject $storage;
    private ExcluirSecaoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->sut = new ExcluirSecaoUseCase($this->secaoRepository, $this->storage, self::UPLOADS_DIR);
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function apagaArquivosFisicosNoDiretorioDoTenantERemoveASecao(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $this->adicionarDocumento($secao, 'hashA');
        $this->adicionarDocumento($secao, 'hashB');

        $diretorio = self::UPLOADS_DIR . '/7';

        // O caminho físico é reconstruído no diretório isolado por tenant (padrão M5).
        $this->storage
            ->expects($this->exactly(2))
            ->method('caminho')
            ->willReturnMap([
                [$diretorio, 'hashA', $diretorio . '/hashA'],
                [$diretorio, 'hashB', $diretorio . '/hashB'],
            ]);

        $this->storage->method('existe')->willReturn(true);

        // Ambos os arquivos existentes são excluídos do disco.
        $excluidos = [];
        $this->storage
            ->expects($this->exactly(2))
            ->method('excluir')
            ->willReturnCallback(static function (string $caminho) use (&$excluidos): void {
                $excluidos[] = $caminho;
            });

        // A seção é removida com flush único; documentos caem por cascade no banco.
        $this->secaoRepository
            ->expects($this->once())
            ->method('remover')
            ->with($secao, true);

        $this->sut->executar($secao, $this->tenant);

        self::assertSame([$diretorio . '/hashA', $diretorio . '/hashB'], $excluidos);
    }

    #[Test]
    public function naoExcluiArquivoInexistenteMasRemoveASecao(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $this->adicionarDocumento($secao, 'hashFantasma');

        $this->storage->method('caminho')->willReturn(self::UPLOADS_DIR . '/7/hashFantasma');
        // Arquivo já não está no disco.
        $this->storage->method('existe')->willReturn(false);

        // Nada a excluir fisicamente.
        $this->storage->expects($this->never())->method('excluir');

        // A remoção da linha ocorre mesmo assim.
        $this->secaoRepository
            ->expects($this->once())
            ->method('remover')
            ->with($secao, true);

        $this->sut->executar($secao, $this->tenant);
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenantComId(99));
        $this->adicionarDocumento($secao, 'hashA');

        // Nenhum efeito colateral: nem disco, nem banco.
        $this->storage->expects($this->never())->method('caminho');
        $this->storage->expects($this->never())->method('existe');
        $this->storage->expects($this->never())->method('excluir');
        $this->secaoRepository->expects($this->never())->method('remover');

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $this->sut->executar($secao, $this->tenant);
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $reflexao = new \ReflectionProperty(Tenant::class, 'id');
        $reflexao->setValue($tenant, $id);

        return $tenant;
    }

    /**
     * Injeta um documento na coleção interna da seção (populada pelo Doctrine em runtime).
     * No teste unit não há ORM, então adicionamos direto à ArrayCollection via reflexão.
     */
    private function adicionarDocumento(CobrancaSecao $secao, string $hash): void
    {
        $documento = (new CobrancaDocumento())->setCaminhoArquivo($hash);

        $reflexao = new \ReflectionProperty(CobrancaSecao::class, 'documentos');
        /** @var Collection<int, CobrancaDocumento> $colecao */
        $colecao = $reflexao->getValue($secao);
        $colecao->add($documento);
    }
}
