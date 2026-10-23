<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\RevogarConviteInput;
use App\Auth\UseCase\RevogarConviteUseCase;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RevogarConviteUseCase::class)]
final class RevogarConviteUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $repo;
    private EntityManagerInterface&MockObject $em;
    private RevogarConviteUseCase $useCase;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(InvitationRepository::class);
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RevogarConviteUseCase($this->repo, $this->em);
    }

    private function makeInvitation(string $status): Invitation
    {
        $invitation = new Invitation(
            email: 'test@example.com',
            token: 'abc123',
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );

        if ($status !== 'pending') {
            match ($status) {
                'accepted' => $invitation->aceitar(new \App\Entity\Auth\User()),
                'rejected' => $invitation->recusar(),
                'revoked'  => $invitation->revogar(),
                'expired'  => $invitation->expirar(),
                default    => null,
            };
        }

        return $invitation;
    }

    public function testTokenValidoPendingeRevoga(): void
    {
        $invitation = $this->makeInvitation('pending');

        $this->repo->method('find')->willReturn($invitation);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(new RevogarConviteInput(1));

        self::assertSame('revoked', $resultado->getStatus());
        self::assertSame($invitation, $resultado);
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->repo->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não encontrado');

        $this->useCase->executar(new RevogarConviteInput(1));
    }

    public function testStatusAcceptedLancaExcecao(): void
    {
        $this->repo->method('find')->willReturn($this->makeInvitation('accepted'));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('pendentes podem ser revogados');

        $this->useCase->executar(new RevogarConviteInput(1));
    }

    public function testStatusRejectedLancaExcecao(): void
    {
        $this->repo->method('find')->willReturn($this->makeInvitation('rejected'));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new RevogarConviteInput(1));
    }

    public function testStatusRevokedLancaExcecao(): void
    {
        $this->repo->method('find')->willReturn($this->makeInvitation('revoked'));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new RevogarConviteInput(1));
    }

    public function testStatusExpiredLancaExcecao(): void
    {
        $this->repo->method('find')->willReturn($this->makeInvitation('expired'));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new RevogarConviteInput(1));
    }

    #[TestDox('Busca o convite pelo id do input, não pelo token')]
    public function testBuscaPeloId(): void
    {
        $this->repo->expects($this->never())->method('encontrarPorToken');
        $this->repo->expects($this->once())->method('find')->with(42)->willReturn($this->makeInvitation('pending'));

        self::assertSame('revoked', $this->useCase->executar(new RevogarConviteInput(42))->getStatus());
    }
}
