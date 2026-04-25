<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\Pasta;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarSituacaoContratoUseCase::class)]
final class AlterarSituacaoContratoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AlterarSituacaoContratoUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new AlterarSituacaoContratoUseCase($this->em);
    }

    public function testAlterarParaRegularPersiste(): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, 'REGULAR');

        self::assertSame('REGULAR', $pasta->getSituacaoContrato());
    }

    public function testAlterarParaPendentePersiste(): void
    {
        $pasta = new Pasta();
        $pasta->setSituacaoContrato('REGULAR');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, 'PENDENTE');

        self::assertSame('PENDENTE', $pasta->getSituacaoContrato());
    }

    public function testSituacaoInvalidaLancaExcecao(): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($pasta, 'INVALIDO');
    }
}
