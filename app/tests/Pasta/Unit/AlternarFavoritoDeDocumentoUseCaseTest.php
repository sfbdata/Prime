<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Pasta\UseCase\AlternarFavoritoDeDocumentoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlternarFavoritoDeDocumentoUseCase::class)]
final class AlternarFavoritoDeDocumentoUseCaseTest extends TestCase
{
    private PastaDocumentoFavoritoRepository&MockObject $repository;
    private PermissionChecker&MockObject $permissionChecker;
    private AlternarFavoritoDeDocumentoUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->repository        = $this->createMock(PastaDocumentoFavoritoRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new AlternarFavoritoDeDocumentoUseCase($this->repository, $this->permissionChecker);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = $this->pasta($this->tenant, 7);
    }

    #[TestDox('marcar um documento grava pela inserção idempotente e devolve true')]
    public function testMarcarDocumento(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, 7, AccessRequest::ACTION_VIEW)
            ->willReturn(true);
        $this->repository->expects(self::once())->method('marcarSeAusente')->with($this->tenant, $this->usuario, $doc);
        $this->repository->expects(self::never())->method('desmarcar');

        self::assertTrue($this->useCase->executar($this->pasta, $doc, true, $this->usuario, $this->tenant));
    }

    #[TestDox('marcar duas vezes (clique duplo) devolve true as duas vezes — a inserção não lança')]
    public function testMarcarDuasVezesEhIdempotente(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::exactly(2))->method('marcarSeAusente');
        $this->repository->expects(self::never())->method('desmarcar');

        self::assertTrue($this->useCase->executar($this->pasta, $doc, true, $this->usuario, $this->tenant));
        self::assertTrue($this->useCase->executar($this->pasta, $doc, true, $this->usuario, $this->tenant));
    }

    #[TestDox('desmarcar uma subpasta apaga a estrela e devolve false — o estado pedido, não uma inversão')]
    public function testDesmarcarSecao(): void
    {
        $secao = $this->secao($this->pasta, $this->tenant);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->repository->expects(self::never())->method('marcarSeAusente');
        $this->repository->expects(self::exactly(2))->method('desmarcar')->with($this->tenant, $this->usuario, $secao);

        self::assertFalse($this->useCase->executar($this->pasta, $secao, false, $this->usuario, $this->tenant));
        self::assertFalse($this->useCase->executar($this->pasta, $secao, false, $this->usuario, $this->tenant), 'desmarcar o que não está marcado continua false');
    }

    #[TestDox('pasta de outro escritório: PastaDeOutroEscritorioException, sem consultar permissão nem gravar')]
    public function testPastaDeOutroEscritorio(): void
    {
        $outroTenant = new Tenant();
        $pastaAlheia = $this->pasta($outroTenant, 8);
        $doc         = $this->documento($pastaAlheia, $outroTenant);
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->naoGrava();

        $this->expectException(PastaDeOutroEscritorioException::class);
        $this->useCase->executar($pastaAlheia, $doc, true, $this->usuario, $this->tenant);
    }

    #[TestDox('alvo de pasta IRMÃ do mesmo escritório: PastaDeOutroEscritorioException (404), sem gravar')]
    public function testAlvoDePastaIrma(): void
    {
        $irma = $this->pasta($this->tenant, 9);
        $doc  = $this->documento($irma, $this->tenant);
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->naoGrava();

        $this->expectException(PastaDeOutroEscritorioException::class);
        $this->useCase->executar($this->pasta, $doc, true, $this->usuario, $this->tenant);
    }

    #[TestDox('alvo com tenant diferente do da sessão, mesmo pendurado na pasta certa: 404, sem gravar')]
    public function testAlvoDeOutroTenant(): void
    {
        $secao = $this->secao($this->pasta, new Tenant());
        $this->naoGrava();

        $this->expectException(PastaDeOutroEscritorioException::class);
        $this->useCase->executar($this->pasta, $secao, true, $this->usuario, $this->tenant);
    }

    #[TestDox('sem permissão de VER a pasta: SemPermissaoParaVerPastaException, sem gravar')]
    public function testSemPermissaoDeVer(): void
    {
        $doc = $this->documento($this->pasta, $this->tenant);
        $this->permissionChecker->method('canAccessResource')->willReturn(false);
        $this->naoGrava();

        $this->expectException(SemPermissaoParaVerPastaException::class);
        $this->useCase->executar($this->pasta, $doc, false, $this->usuario, $this->tenant);
    }

    private function naoGrava(): void
    {
        $this->repository->expects(self::never())->method('marcarSeAusente');
        $this->repository->expects(self::never())->method('desmarcar');
    }

    private function pasta(Tenant $tenant, int $id): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('P-' . $id);
        $pasta->setTenant($tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, $id);

        return $pasta;
    }

    private function documento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);

        return $doc;
    }

    private function secao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);

        return $secao;
    }
}
