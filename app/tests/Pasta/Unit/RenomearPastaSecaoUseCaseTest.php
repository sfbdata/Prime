<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\RenomearPastaSecaoUseCase;
use App\Repository\Pasta\PastaSecaoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(RenomearPastaSecaoUseCase::class)]
final class RenomearPastaSecaoUseCaseTest extends TestCase
{
    private PastaSecaoRepository&MockObject $repo;
    private RenomearPastaSecaoUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private PastaSecao $secao;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new RenomearPastaSecaoUseCase($this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com')->setTenant($this->tenant);

        $this->secao = new PastaSecao();
        $this->secao->setTenant($this->tenant);
        $this->secao->setNome('Nome original');
    }

    public function testRenomearAtualizaNome(): void
    {
        $this->repo->expects($this->once())
            ->method('salvar')
            ->with($this->secao, true);

        $this->useCase->executar($this->secao, $this->autor, 'Novo nome');

        self::assertSame('Novo nome', $this->secao->getNome());
    }

    public function testNomeComEspacosEhTrimado(): void
    {
        $this->repo->method('salvar');

        $this->useCase->executar($this->secao, $this->autor, '  Contratos  ');

        self::assertSame('Contratos', $this->secao->getNome());
    }

    public function testNomeVazioLancaExcecao(): void
    {
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->secao, $this->autor, '   ');
    }

    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $outraTenant = new Tenant();
        $autorOutroTenant = (new User())->setEmail('outro@test.com')->setTenant($outraTenant);

        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->secao, $autorOutroTenant, 'Novo nome');
    }

    public function testUsuarioSemTenantLancaLogicException(): void
    {
        $autorSemTenant = (new User())->setEmail('semtenant@test.com');

        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(\LogicException::class);

        $this->useCase->executar($this->secao, $autorSemTenant, 'Novo nome');
    }
}
