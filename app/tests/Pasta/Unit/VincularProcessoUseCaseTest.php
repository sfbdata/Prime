<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(VincularProcessoUseCase::class)]
final class VincularProcessoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private VincularProcessoUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new VincularProcessoUseCase($this->em);
        $this->tenant  = $this->createStub(Tenant::class);
        $this->usuario = $this->createStub(User::class);
    }

    public function testVincularPrimeiroProcessoMarcaComoPrincipalEPersiste(): void
    {
        $pasta    = $this->novaPasta();
        $processo = $this->novoProcesso($this->tenant);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $processo, $this->usuario);

        self::assertCount(1, $pasta->getPastaProcessos());
        self::assertSame($processo, $pasta->getProcessoPrincipal());
        self::assertTrue($pasta->getVinculoPrincipal()?->isPrincipal());
    }

    public function testSegundoProcessoNaoRoubaOPrincipal(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoProcesso($this->tenant);
        $b     = $this->novoProcesso($this->tenant);

        $this->useCase->executar($pasta, $a, $this->usuario);
        $this->useCase->executar($pasta, $b, $this->usuario);

        self::assertCount(2, $pasta->getPastaProcessos());
        self::assertSame($a, $pasta->getProcessoPrincipal());
    }

    public function testVincularProcessoJaVinculadoEhIdempotente(): void
    {
        $pasta    = $this->novaPasta();
        $processo = $this->novoProcesso($this->tenant);

        $this->useCase->executar($pasta, $processo, $this->usuario);
        $this->useCase->executar($pasta, $processo, $this->usuario);

        self::assertCount(1, $pasta->getPastaProcessos());
    }

    public function testProcessoDeOutroTenantEhNegado(): void
    {
        $pasta    = $this->novaPasta();
        $processo = $this->novoProcesso($this->createStub(Tenant::class));

        $this->em->expects($this->never())->method('flush');
        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($pasta, $processo, $this->usuario);
    }

    private function novaPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    private function novoProcesso(Tenant $tenant): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);

        return $processo;
    }
}
