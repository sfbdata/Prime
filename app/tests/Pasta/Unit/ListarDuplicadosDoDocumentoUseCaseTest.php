<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\DocumentoDuplicadoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\ListarDuplicadosDoDocumentoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O aviso "este arquivo já existe em…": consulta por `(tenant, sha256)` e filtro de permissão
 * por pasta. O isolamento por escritório aqui é duplo — o documento tem de ser do tenant da
 * sessão, e a consulta recebe esse tenant — e o que o usuário não pode ver não é revelado.
 */
#[CoversClass(ListarDuplicadosDoDocumentoUseCase::class)]
#[CoversClass(DocumentoDuplicadoOutput::class)]
final class ListarDuplicadosDoDocumentoUseCaseTest extends TestCase
{
    private PastaDocumentoRepository&MockObject $repository;
    private PermissionChecker&MockObject $permissionChecker;
    private ListarDuplicadosDoDocumentoUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repository        = $this->createMock(PastaDocumentoRepository::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new ListarDuplicadosDoDocumentoUseCase($this->repository, $this->permissionChecker);
        $this->tenant            = $this->tenant(7);
        $this->usuario           = new User();
    }

    #[TestDox('documento sem sha256 não tem com o que ser comparado: vazio, sem consultar nada')]
    public function testSemHashDevolveVazioSemConsultar(): void
    {
        $doc = $this->documento(10, $this->pasta(1, 'A-1'), null);

        $this->repository->expects(self::never())->method('comOMesmoConteudo');
        $this->permissionChecker->expects(self::never())->method('canAccessResource');

        self::assertSame([], $this->useCase->executar($doc, $this->usuario, $this->tenant));
    }

    #[TestDox('documento de outro escritório: PastaDeOutroEscritorioException antes de qualquer consulta')]
    public function testDocumentoDeOutroEscritorioEhRecusado(): void
    {
        $doc = $this->documento(10, $this->pasta(1, 'A-1'), hash('sha256', 'x'), $this->tenant(99));

        $this->repository->expects(self::never())->method('comOMesmoConteudo');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($doc, $this->usuario, $this->tenant);
    }

    #[TestDox('consulta pelo tenant da sessão e pelo hash, excluindo o próprio documento')]
    public function testConsultaPorTenantHashEExcluiOProprio(): void
    {
        $sha = hash('sha256', 'conteúdo');
        $doc = $this->documento(10, $this->pasta(1, 'A-1'), $sha);

        $this->repository
            ->expects(self::once())
            ->method('comOMesmoConteudo')
            ->with($this->tenant, $sha, 10)
            ->willReturn([]);

        self::assertSame([], $this->useCase->executar($doc, $this->usuario, $this->tenant));
    }

    #[TestDox('só pastas que o usuário pode VER entram; a permissão é perguntada uma vez por pasta')]
    public function testFiltraPorPermissaoDeVerAPastaUmaVezPorPasta(): void
    {
        $sha     = hash('sha256', 'conteúdo');
        $pastaA  = $this->pasta(1, 'NUP-A');
        $pastaB  = $this->pasta(2, 'NUP-B');
        $doc     = $this->documento(10, $pastaA, $sha);
        $emA1    = $this->documento(11, $pastaA, $sha, titulo: 'Cópia na A');
        $emA2    = $this->documento(12, $pastaA, $sha, titulo: 'Outra cópia na A');
        $emB     = $this->documento(13, $pastaB, $sha, titulo: 'Cópia na B restrita');

        $this->repository->method('comOMesmoConteudo')->willReturn([$emA1, $emB, $emA2]);

        $perguntas = [];
        $this->permissionChecker
            ->expects(self::exactly(2))
            ->method('canAccessResource')
            ->willReturnCallback(function (User $u, Tenant $t, string $tipo, int $id, string $acao) use (&$perguntas): bool {
                self::assertSame($this->usuario, $u);
                self::assertSame($this->tenant, $t);
                self::assertSame(AccessRequest::RESOURCE_PASTA, $tipo);
                self::assertSame(AccessRequest::ACTION_VIEW, $acao);
                $perguntas[] = $id;

                return $id === 1;
            });

        $resultado = $this->useCase->executar($doc, $this->usuario, $this->tenant);

        self::assertSame([1, 2], $perguntas, 'uma pergunta por pasta, na ordem em que aparecem');
        self::assertCount(2, $resultado);
        self::assertContainsOnlyInstancesOf(DocumentoDuplicadoOutput::class, $resultado);
        self::assertSame([11, 12], array_map(static fn (DocumentoDuplicadoOutput $d): int => $d->documentoId, $resultado));
        self::assertSame(
            [['pastaId' => 1, 'pastaNup' => 'NUP-A', 'titulo' => 'CÓPIA NA A'], ['pastaId' => 1, 'pastaNup' => 'NUP-A', 'titulo' => 'OUTRA CÓPIA NA A']],
            array_map(static fn (DocumentoDuplicadoOutput $d): array => $d->paraJson(), $resultado),
        );
    }

    #[TestDox('pasta sem número (acervo antigo) é identificada por #id, nunca por rótulo vazio')]
    public function testPastaSemNupViraIdComCerquilha(): void
    {
        $sha   = hash('sha256', 'conteúdo');
        $doc   = $this->documento(10, $this->pasta(1, 'NUP-A'), $sha);
        $outro = $this->documento(20, $this->pasta(55, null), $sha, titulo: 'Antigo');

        $this->repository->method('comOMesmoConteudo')->willReturn([$outro]);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);

        $resultado = $this->useCase->executar($doc, $this->usuario, $this->tenant);

        self::assertCount(1, $resultado);
        self::assertSame('#55', $resultado[0]->pastaNup);
        self::assertSame(55, $resultado[0]->pastaId);
        self::assertSame('ANTIGO', $resultado[0]->titulo);
    }

    // ----------------------------------------------------------------- helpers

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function pasta(int $id, ?string $nup): Pasta
    {
        $pasta = (new Pasta())->setTenant($this->tenant);
        if ($nup !== null) {
            $pasta->setNup($nup);
        }
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, $id);

        return $pasta;
    }

    private function documento(int $id, Pasta $pasta, ?string $sha256, ?Tenant $tenant = null, string $titulo = 'Doc'): PastaDocumento
    {
        $doc = (new PastaDocumento())
            ->setTenant($tenant ?? $this->tenant)
            ->setPasta($pasta)
            ->setTitulo($titulo)
            ->setSha256($sha256);
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($doc, $id);

        return $doc;
    }
}
