<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\UseCase\ExcluirChecklistModeloUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ExcluirChecklistModeloUseCase::class)]
final class ExcluirChecklistModeloUseCaseTest extends TestCase
{
    private PastaChecklistModeloRepository&MockObject $modeloRepo;
    private ExcluirChecklistModeloUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->modeloRepo = $this->createMock(PastaChecklistModeloRepository::class);
        $this->useCase    = new ExcluirChecklistModeloUseCase($this->modeloRepo);
        $this->tenant     = new Tenant();
    }

    #[TestDox('Excluir remove o modelo do escritório')]
    public function testExclui(): void
    {
        $modelo = (new PastaChecklistModelo())->setTenant($this->tenant)->setNome('Trabalhista');

        $this->modeloRepo->expects($this->once())
            ->method('remover')
            ->with($modelo, true);

        $this->useCase->executar($modelo, $this->tenant);
    }

    #[TestDox('Modelo de outro escritório não pode ser excluído')]
    public function testModeloDeOutroEscritorioEhRecusado(): void
    {
        $modelo = (new PastaChecklistModelo())->setTenant(new Tenant())->setNome('Trabalhista');

        $this->modeloRepo->expects($this->never())->method('remover');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($modelo, $this->tenant);
    }
}
