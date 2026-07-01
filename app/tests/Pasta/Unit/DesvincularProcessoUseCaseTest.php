<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DesvincularProcessoUseCase::class)]
final class DesvincularProcessoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DesvincularProcessoUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new DesvincularProcessoUseCase($this->em);
        $this->tenant  = $this->createStub(Tenant::class);
        $this->usuario = $this->createStub(User::class);
    }

    public function testDesvincularNaoPrincipalMantemPrincipal(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso();
        $b     = $this->novoProcesso();
        $pasta->vincularProcesso($a, $this->usuario); // principal
        $pasta->vincularProcesso($b, $this->usuario);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $b);

        self::assertCount(1, $pasta->getPastaProcessos());
        self::assertSame($a, $pasta->getProcessoPrincipal());
    }

    public function testDesvincularPrincipalPromoveOutro(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso();
        $b     = $this->novoProcesso();
        $pasta->vincularProcesso($a, $this->usuario); // principal
        $pasta->vincularProcesso($b, $this->usuario);

        $this->useCase->executar($pasta, $a);

        self::assertCount(1, $pasta->getPastaProcessos());
        self::assertSame($b, $pasta->getProcessoPrincipal());
        self::assertTrue($pasta->getVinculoPrincipal()?->isPrincipal());
    }

    public function testDesvincularUltimoEsvaziaSemPrincipal(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso();
        $pasta->vincularProcesso($a, $this->usuario);

        $this->useCase->executar($pasta, $a);

        self::assertCount(0, $pasta->getPastaProcessos());
        self::assertNull($pasta->getProcessoPrincipal());
    }

    public function testDesvincularProcessoNaoVinculadoEhNoOp(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso();
        $pasta->vincularProcesso($a, $this->usuario);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->novoProcesso());

        self::assertCount(1, $pasta->getPastaProcessos());
        self::assertSame($a, $pasta->getProcessoPrincipal());
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
