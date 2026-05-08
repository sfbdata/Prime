<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarConviteEscritorioUseCase::class)]
final class CriarConviteEscritorioUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private UserTenantRepository&MockObject $userTenantRepo;
    private EntityManagerInterface&MockObject $em;
    private CriarConviteEscritorioUseCase $useCase;
    private Tenant $tenant;
    private TenantRole $role;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->invitationRepo  = $this->createMock(InvitationRepository::class);
        $this->userRepo        = $this->createMock(UserRepository::class);
        $this->userTenantRepo  = $this->createMock(UserTenantRepository::class);
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->useCase         = new CriarConviteEscritorioUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->userTenantRepo,
            $this->em,
        );
        $this->tenant    = new Tenant();
        $this->role      = new TenantRole();
        $this->criadoPor = (new User())->setEmail('admin@escritorio.com');
    }

    private function input(string $email, ?string $fullName = null): CriarConviteEscritorioInput
    {
        return new CriarConviteEscritorioInput(
            email: $email,
            fullName: $fullName,
            tenant: $this->tenant,
            tenantRole: $this->role,
            criadoPor: $this->criadoPor,
        );
    }

    public function testEmailSemContaCriaConviteEscritorio(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->input('novo@example.com'));

        self::assertSame('office', $resultado->getType());
        self::assertSame('pending', $resultado->getStatus());
        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertSame($this->role, $resultado->getTenantRole());

        $diff = $resultado->getExpiresAt()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        self::assertEqualsWithDelta(7 * 86400, $diff, 5);
    }

    public function testEmailComContaSemVinculoAtivoCriaConvite(): void
    {
        $existingUser = new User();
        $this->userRepo->method('findOneBy')->willReturn($existingUser);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->input('existente@example.com'));

        self::assertSame('pending', $resultado->getStatus());
    }

    public function testEmailComContaComVinculoAtivoLancaExcecao(): void
    {
        $existingUser = new User();
        $this->userRepo->method('findOneBy')->willReturn($existingUser);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(true);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('colaborador ativo');

        $this->useCase->executar($this->input('membro@example.com'));
    }

    public function testEmailNormalizadoUpperEspacos(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->input('  CONVIDADO@EXAMPLE.COM  '));

        self::assertSame('convidado@example.com', $resultado->getEmail());
    }

    public function testExisteVinculoNaoChamadoSeUserNaoExiste(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->userTenantRepo->expects($this->never())->method('existeVinculoAtivo');
        $this->em->method('persist');
        $this->em->method('flush');

        $this->useCase->executar($this->input('novo@example.com'));
    }

    public function testTenantERolePropagados(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->input('novo@example.com', 'Maria'));

        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertSame($this->role, $resultado->getTenantRole());
        self::assertSame('Maria', $resultado->getFullName());
    }
}
