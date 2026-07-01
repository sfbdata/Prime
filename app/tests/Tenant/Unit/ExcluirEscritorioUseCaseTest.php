<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\ExcluirEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirEscritorioUseCase::class)]
final class ExcluirEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirEscritorioUseCase($this->em);
    }

    #[TestDox('Soft delete: desativa o escritório e marca excluidoEm, com um flush')]
    public function testExcluiSoftDelete(): void
    {
        $tenant = new Tenant();
        $tenant->setIsActive(true);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($tenant);

        self::assertFalse($tenant->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $tenant->getExcluidoEm());
    }

    #[TestDox('Idempotente: escritório já inativo não é reprocessado (sem flush)')]
    public function testIdempotenteQuandoJaInativo(): void
    {
        $tenant = new Tenant();
        $tenant->setIsActive(false);

        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($tenant);

        self::assertFalse($tenant->isActive());
        self::assertNull($tenant->getExcluidoEm());
    }
}
