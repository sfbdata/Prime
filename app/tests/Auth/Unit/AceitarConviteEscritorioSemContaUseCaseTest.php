<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\UseCase\AceitarConviteEscritorioSemContaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(AceitarConviteEscritorioSemContaUseCase::class)]
final class AceitarConviteEscritorioSemContaUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private AceitarConviteEscritorioSemContaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->userRepo       = $this->createMock(UserRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->hasher         = $this->createMock(UserPasswordHasherInterface::class);

        // Registrar real com repo curto-circuitado (já aceito) — sem efeitos no fluxo do convite.
        $aceiteRepo = $this->createMock(AceiteTermoRepository::class);
        $aceiteRepo->method('existeAceiteVigente')->willReturn(true);
        $registrarAceite = new RegistrarAceiteTermoUseCase($aceiteRepo, new TermoVigente());

        $this->useCase = new AceitarConviteEscritorioSemContaUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->em,
            $this->hasher,
            $registrarAceite,
        );
        $this->tenant = new Tenant();
    }

    private function makeInvitation(
        string $email = 'novo@example.com',
        ?string $fullName = 'Nome Invitation',
        bool $expirada = false,
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
        $invitation->setFullName($fullName);

        return $invitation;
    }

    public function testHappyPathFullNameNoInputCriaUserEUserTenant(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(function ($obj) use (&$persistidos) {
            $persistidos[] = $obj;
        });
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome do Input', 'senha123', aceiteTermos: true)
        );

        self::assertInstanceOf(User::class, $resultado);
        self::assertSame('Nome do Input', $resultado->getFullName());
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($resultado, $invitation->getAcceptedAsUser());
        self::assertCount(2, $persistidos);
    }

    public function testFullNameVazioUsaNomeDaInvitation(): void
    {
        $invitation = $this->makeInvitation(fullName: 'Nome da Invitation');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', '', 'senha123', aceiteTermos: true)
        );

        self::assertSame('Nome da Invitation', $resultado->getFullName());
    }

    public function testFullNameNoInputTemPrioridadeSobreInvitation(): void
    {
        $invitation = $this->makeInvitation(fullName: 'Nome da Invitation');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome Prioritário', 'senha123', aceiteTermos: true)
        );

        self::assertSame('Nome Prioritário', $resultado->getFullName());
    }

    public function testTenantRoleNaInvitationPropagado(): void
    {
        $role       = new TenantRole();
        $invitation = $this->makeInvitation();
        $invitation->setTenantRole($role);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $userTenantCapturado = null;
        $this->em->method('persist')->willReturnCallback(function ($obj) use (&$userTenantCapturado) {
            if ($obj instanceof \App\Entity\Auth\UserTenant) {
                $userTenantCapturado = $obj;
            }
        });
        $this->em->method('flush');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true)
        );

        self::assertNotNull($userTenantCapturado);
        self::assertSame($role, $userTenantCapturado->getTenantRole());
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('invalido', 'Nome', 'senha'));
    }

    public function testStatusNaoPendingLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha'));
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation(expirada: true);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha'));
    }

    public function testEmailJaTemContaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([new User()]);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Já existe uma conta');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha'));
    }

    public function testFullNameVazioEInvitationNullLancaExcecao(): void
    {
        $invitation = $this->makeInvitation(fullName: null);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nome completo é obrigatório');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', '', 'senha'));
    }

    public function testInvitationSemTenantLancaExcecao(): void
    {
        $invitation = new Invitation(
            email: 'novo@example.com',
            token: 'tok123',
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setFullName('Nome');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('escritório não encontrado');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha'));
    }

    public function testSemAceitarTermosLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Termos de Uso');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: false)
        );
    }

    public function testHashPasswordFoiChamado(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $this->hasher->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'minhasenha')
            ->willReturn('hashed_pass');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'minhasenha', aceiteTermos: true)
        );
    }
}
