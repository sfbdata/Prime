<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\UseCase\AlternarTrancaDaPastaUseCase;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(AlternarTrancaDaPastaUseCase::class)]
final class AlternarTrancaDaPastaUseCaseTest extends TestCase
{
    #[TestDox('administrador tranca (guarda quem) e, de novo, destranca')]
    public function testAlterna(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();
        $useCase = $this->useCase(true, 2);

        self::assertTrue($useCase->executar($pasta, $user, $tenant));
        self::assertSame($user, $pasta->getTrancadaPor());
        self::assertFalse($useCase->executar($pasta, $user, $tenant));
        self::assertNull($pasta->getTrancadaEm());
    }

    #[TestDox('quem não é administrador é recusado e nada é gravado')]
    public function testNaoAdministrador(): void
    {
        [$pasta, $tenant, $user] = $this->cenario();

        $this->expectException(AccessDeniedException::class);
        try {
            $this->useCase(false, 0)->executar($pasta, $user, $tenant);
        } finally {
            self::assertFalse($pasta->estaTrancada());
        }
    }

    #[TestDox('pasta de outro escritório é recusada antes da permissão')]
    public function testOutroEscritorio(): void
    {
        [$pasta, , $user] = $this->cenario();

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase(true, 0)->executar($pasta, $user, new Tenant());
    }

    /** @return array{0: Pasta, 1: Tenant, 2: User} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $pasta  = new Pasta();
        $pasta->setTenant($tenant);

        return [$pasta, $tenant, (new User())->setEmail('adm@test.com')];
    }

    private function useCase(bool $admin, int $flushes): AlternarTrancaDaPastaUseCase
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('isAdministradorDoEscritorio')->willReturn($admin);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly($flushes))->method('flush');

        return new AlternarTrancaDaPastaUseCase($checker, $em);
    }
}
