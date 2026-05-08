<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\RecusarConviteEscritorioInput;
use App\Auth\UseCase\RecusarConviteEscritorioUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecusarConviteEscritorioUseCase::class)]
final class RecusarConviteEscritorioUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $repo;
    private EntityManagerInterface&MockObject $em;
    private RecusarConviteEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(InvitationRepository::class);
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RecusarConviteEscritorioUseCase($this->repo, $this->em);
    }

    private function makeUser(string $email): User
    {
        return (new User())->setEmail($email);
    }

    private function makePendingInvitation(string $email, bool $expirada = false): Invitation
    {
        $expiresAt = $expirada
            ? new \DateTimeImmutable('-1 hour')
            : new \DateTimeImmutable('+7 days');

        return new Invitation(
            email: $email,
            token: 'tok123',
            type: 'office',
            expiresAt: $expiresAt,
        );
    }

    public function testTokenValidoEmailBateNaoExpiradoRecusa(): void
    {
        $user       = $this->makeUser('user@example.com');
        $invitation = $this->makePendingInvitation('user@example.com');

        $this->repo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(new RecusarConviteEscritorioInput('tok123', $user));

        self::assertSame('rejected', $resultado->getStatus());
        self::assertSame($invitation, $resultado);
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->repo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new RecusarConviteEscritorioInput('invalido', $this->makeUser('u@x.com')));
    }

    public function testStatusAcceptedNaoPodeSerRecusado(): void
    {
        $invitation = $this->makePendingInvitation('u@x.com');
        $invitation->aceitar(new User());

        $this->repo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pode mais ser recusado');

        $this->useCase->executar(new RecusarConviteEscritorioInput('tok123', $this->makeUser('u@x.com')));
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makePendingInvitation('u@x.com', expirada: true);

        $this->repo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new RecusarConviteEscritorioInput('tok123', $this->makeUser('u@x.com')));
    }

    public function testEmailDiferenteLancaExcecao(): void
    {
        $invitation = $this->makePendingInvitation('convidado@example.com');

        $this->repo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('pertence a outro');

        $this->useCase->executar(new RecusarConviteEscritorioInput('tok123', $this->makeUser('outro@example.com')));
    }

    public function testEmailDifereSoEmCaseAceita(): void
    {
        $invitation = $this->makePendingInvitation('TEST@EXAMPLE.COM');
        $user       = $this->makeUser('test@example.com');

        $this->repo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(new RecusarConviteEscritorioInput('tok123', $user));

        self::assertSame('rejected', $resultado->getStatus());
    }

    public function testFlushNaoEChamadoQuandoHaExcecao(): void
    {
        $this->repo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar(new RecusarConviteEscritorioInput('tok', $this->makeUser('u@x.com')));
        } catch (\DomainException) {
            // esperado
        }
    }
}
