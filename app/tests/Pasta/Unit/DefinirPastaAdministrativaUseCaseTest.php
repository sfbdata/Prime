<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\DefinirPastaAdministrativaUseCase;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirPastaAdministrativaUseCase::class)]
final class DefinirPastaAdministrativaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PermissionChecker&MockObject $permissionChecker;
    private DefinirPastaAdministrativaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->permissionChecker = $this->createMock(PermissionChecker::class);
        $this->useCase           = new DefinirPastaAdministrativaUseCase($this->em, $this->permissionChecker);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
    }

    #[TestDox('Pasta nova nasce NÃO administrativa')]
    public function testPadraoEhFalse(): void
    {
        self::assertFalse((new Pasta())->isAdministrativa());
    }

    #[TestDox('Marcar grava true, dá flush uma vez e devolve true (mudou); a permissão conferida é a de EDITAR a pasta')]
    public function testMarca(): void
    {
        $this->permissionChecker
            ->expects(self::once())
            ->method('canAccessResource')
            ->with($this->usuario, $this->tenant, AccessRequest::RESOURCE_PASTA, self::anything(), AccessRequest::ACTION_EDIT)
            ->willReturn(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, true, $this->usuario, $this->tenant));
        self::assertTrue($this->pasta->isAdministrativa());
    }

    #[TestDox('Desmarcar volta a false, dá flush e devolve true')]
    public function testDesmarca(): void
    {
        $this->pasta->setAdministrativa(true);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, false, $this->usuario, $this->tenant));
        self::assertFalse($this->pasta->isAdministrativa());
    }

    #[TestDox('Estado igual ao atual (clique duplo, duas abas) não grava nada e devolve false')]
    public function testMesmoEstadoNaoGrava(): void
    {
        $this->pasta->setAdministrativa(true);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($this->pasta, true, $this->usuario, $this->tenant));
        self::assertTrue($this->pasta->isAdministrativa());
    }

    #[TestDox('Pasta com processo vinculado PODE ser marcada — o desenho só pede confirmação na tela — e o vínculo continua')]
    public function testMarcaComProcessoVinculado(): void
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso('07011345720258070007');
        $this->pasta->vincularProcesso($processo);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($this->pasta, true, $this->usuario, $this->tenant));
        self::assertTrue($this->pasta->isAdministrativa());
        self::assertTrue($this->pasta->temProcesso($processo));
    }

    #[TestDox('Pasta de outro escritório: lança PastaDeOutroEscritorioException antes de conferir permissão, sem gravar')]
    public function testOutroEscritorio(): void
    {
        $this->permissionChecker->expects(self::never())->method('canAccessResource');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(PastaDeOutroEscritorioException::class);

        try {
            $this->useCase->executar($this->pasta, true, $this->usuario, new Tenant());
        } finally {
            self::assertFalse($this->pasta->isAdministrativa());
        }
    }

    #[TestDox('Sem permissão de editar a pasta: lança SemPermissaoParaEditarPastaException, sem gravar')]
    public function testSemPermissao(): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturn(false);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(SemPermissaoParaEditarPastaException::class);

        try {
            $this->useCase->executar($this->pasta, true, $this->usuario, $this->tenant);
        } finally {
            self::assertFalse($this->pasta->isAdministrativa());
        }
    }
}
