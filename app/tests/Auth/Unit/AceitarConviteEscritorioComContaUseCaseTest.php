<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Auth\UseCase\AceitarConviteEscritorioComContaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AceitarConviteEscritorioComContaUseCase::class)]
final class AceitarConviteEscritorioComContaUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private UserTenantRepository&MockObject $userTenantRepo;
    private EntityManagerInterface&MockObject $em;
    private AceitarConviteEscritorioComContaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->userTenantRepo = $this->createMock(UserTenantRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->useCase        = new AceitarConviteEscritorioComContaUseCase(
            $this->invitationRepo,
            $this->userTenantRepo,
            $this->em,
        );
        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('usuario@example.com');
    }

    private function makePendingOfficeInvitation(
        string $email,
        bool $expirada = false,
        ?TenantRole $role = null,
    ): Invitation {
        $expiresAt = $expirada
            ? new \DateTimeImmutable('-1 hour')
            : new \DateTimeImmutable('+7 days');

        $invitation = new Invitation(
            email: $email,
            token: 'tok123',
            type: 'office',
            expiresAt: $expiresAt,
        );
        $invitation->setTenant($this->tenant);
        if ($role !== null) {
            $invitation->setTenantRole($role);
        }

        return $invitation;
    }

    public function testHappyPathCriaUserTenantEAceita(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(UserTenant::class));
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertInstanceOf(UserTenant::class, $resultado);
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($this->usuario, $invitation->getAcceptedAsUser());
    }

    public function testTenantRoleNaInvitationPropagadoParaUserTenant(): void
    {
        $role       = new TenantRole();
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', role: $role);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame($role, $resultado->getTenantRole());
    }

    public function testSemTenantRoleNaInvitationUserTenantSemRole(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertNull($resultado->getTenantRole());
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('invalido', $this->usuario));
    }

    public function testStatusNaoPendingLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não está mais disponível');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', expirada: true);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testEmailNaoCorrespondeAoUsuarioLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('outro@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pertence');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testEmailDifereSoEmCaseAceita(): void
    {
        $invitation = $this->makePendingOfficeInvitation('USUARIO@EXAMPLE.COM');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame('accepted', $invitation->getStatus());
        self::assertInstanceOf(UserTenant::class, $resultado);
    }

    public function testJaEColaboradorAtivoLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(true);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('já é colaborador');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testInvitationSemTenantLancaExcecao(): void
    {
        $invitation = new Invitation(
            email: 'usuario@example.com',
            token: 'tok123',
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('escritório não encontrado');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }
}
