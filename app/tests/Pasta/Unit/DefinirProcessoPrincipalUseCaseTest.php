<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirProcessoPrincipalUseCase;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirProcessoPrincipalUseCase::class)]
final class DefinirProcessoPrincipalUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DefinirProcessoPrincipalUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new DefinirProcessoPrincipalUseCase($this->em);
        $this->tenant  = $this->createStub(Tenant::class);
        $this->usuario = $this->createStub(User::class);
    }

    public function testDefinirNovoPrincipalZeraOAnterior(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso();
        $b     = $this->novoProcesso();
        $pasta->vincularProcesso($a, $this->usuario); // principal inicial
        $pasta->vincularProcesso($b, $this->usuario);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $b);

        self::assertSame($b, $pasta->getProcessoPrincipal());
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            self::assertSame($vinculo->getProcesso() === $b, $vinculo->isPrincipal());
        }
    }

    public function testDefinirPrincipalProcessoNaoVinculadoLancaExcecao(): void
    {
        $pasta = $this->novaPasta();
        $pasta->vincularProcesso($this->novoProcesso(), $this->usuario);

        $this->em->expects($this->never())->method('flush');
        $this->expectException(\DomainException::class);

        $this->useCase->executar($pasta, $this->novoProcesso());
    }

    private function novaPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    private function novoProcesso(): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);

        return $processo;
    }
}
