<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\ExcluirSecaoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
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
    private ArmazenamentoEmMemoria $armazenamento;
    private ExcluirSecaoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->sut = new ExcluirSecaoUseCase($this->secaoRepository, $this->storage, $this->armazenamento, self::UPLOADS_DIR);
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function apagaArquivosFisicosNoDiretorioDoTenantERemoveASecao(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $docA  = $this->adicionarDocumento($secao, 'hashA');
        $docB  = $this->adicionarDocumento($secao, 'hashB');
        $this->armazenamento->gravar(ChavesDeCobranca::documentoDeCaso($docA), FonteDeConteudo::deTexto('A'));
        $this->armazenamento->gravar(ChavesDeCobranca::documentoDeCaso($docB), FonteDeConteudo::deTexto('B'));

        $diretorio = self::UPLOADS_DIR . '/7';

        // Presença pelo armazenamento novo; remoção pela interface antiga, no caminho isolado por tenant (M5).
        $this->storage->expects($this->never())->method('existe');
        $this->storage
            ->expects($this->exactly(2))
            ->method('caminho')
            ->willReturnMap([
                [$diretorio, 'hashA', $diretorio . '/hashA'],
                [$diretorio, 'hashB', $diretorio . '/hashB'],
            ]);

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

        // Nada a excluir fisicamente; a remoção da linha ocorre mesmo assim.
        $this->storage->expects($this->never())->method('excluir');
        $this->secaoRepository
            ->expects($this->once())
            ->method('remover')
            ->with($secao, true);

        $this->sut->executar($secao, $this->tenant);
    }

    #[Test]
    public function naoEnxergaArquivoDeOutroEscritorioComOMesmoNome(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $this->adicionarDocumento($secao, 'hashA');
        $this->armazenamento->gravar(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'hashA'),
            FonteDeConteudo::deTexto('de outro escritório'),
        );

        $this->storage->expects($this->never())->method('excluir');
        $this->secaoRepository->expects($this->once())->method('remover');

        $this->sut->executar($secao, $this->tenant);
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenantComId(99));
        $this->adicionarDocumento($secao, 'hashA');

        // Nenhum efeito colateral: nem disco, nem banco.
        $this->storage->expects($this->never())->method('caminho');
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
     * No teste unit não há ORM, então adicionamos direto à ArrayCollection via reflexão. O
     * documento nasce com o tenant da seção, como no banco (coluna NOT NULL).
     */
    private function adicionarDocumento(CobrancaSecao $secao, string $hash): CobrancaDocumento
    {
        $documento = (new CobrancaDocumento())->setTenant($secao->getTenant())->setCaminhoArquivo($hash);

        $reflexao = new \ReflectionProperty(CobrancaSecao::class, 'documentos');
        /** @var Collection<int, CobrancaDocumento> $colecao */
        $colecao = $reflexao->getValue($secao);
        $colecao->add($documento);

        return $documento;
    }
}
