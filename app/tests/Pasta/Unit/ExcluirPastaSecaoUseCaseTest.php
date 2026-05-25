<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ExcluirPastaSecaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ExcluirPastaSecaoUseCase::class)]
final class ExcluirPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirPastaSecaoUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private PastaSecao $secao;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirPastaSecaoUseCase($this->em);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');

        $this->secao = new PastaSecao();
        $this->secao->setTenant($this->tenant);
        $this->secao->setNome('Petições');
    }

    public function testExcluirChamaRemoveEFlush(): void
    {
        $this->em->expects($this->once())
            ->method('remove')
            ->with($this->secao);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->secao, $this->autor, $this->tenant);
    }

    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $outraTenant = new Tenant();

        $this->em->expects($this->never())->method('remove');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->secao, $this->autor, $outraTenant);
    }
}
