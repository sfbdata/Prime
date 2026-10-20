<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\ReenviarConviteInput;
use App\Auth\UseCase\ReenviarConviteUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReenviarConviteUseCase::class)]
final class ReenviarConviteUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private EntityManagerInterface&MockObject $em;
    private ReenviarConviteUseCase $useCase;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->useCase        = new ReenviarConviteUseCase($this->invitationRepo, $this->em);
    }

    private function convitePendente(): Invitation
    {
        return new Invitation(
            email: 'teste@example.com',
            token: 'token-valido',
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
    }

    #[TestDox('Quem reenvia com o e-mail a confirmar é recusado e nada é gravado')]
    public function testEmailAConfirmarNaoReenvia(): void
    {
        $quem = (new User())->setEmail('novo@escritorio.com');
        $quem->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->convitePendente());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Confirme o seu e-mail para convidar pessoas. Reenviamos o link de confirmação se você pedir.');

        $this->useCase->executar(new ReenviarConviteInput('token-valido', $quem));
    }

    #[TestDox('Quem reenvia com o e-mail confirmado reenvia normalmente')]
    public function testEmailConfirmadoReenvia(): void
    {
        $quem = (new User())->setEmail('dono@escritorio.com');
        $convite = $this->convitePendente();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($convite);
        $this->em->expects($this->once())->method('flush');

        self::assertSame($convite, $this->useCase->executar(new ReenviarConviteInput('token-valido', $quem)));
    }

    #[TestDox('Convite não encontrado lança DomainException')]
    public function testTokenInexistenteLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não encontrado');

        $this->useCase->executar(new ReenviarConviteInput('token-invalido'));
    }

    #[TestDox('Convite não-pending lança DomainException')]
    public function testConviteNaoPendenteLancaExcecao(): void
    {
        $invitation = $this->convitePendente();
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('pendentes');

        $this->useCase->executar(new ReenviarConviteInput('token-valido'));
    }

    #[TestDox('Convite expirado lança DomainException')]
    public function testConviteExpiradoLancaExcecao(): void
    {
        $invitation = new Invitation(
            email: 'teste@example.com',
            token: 'token-expirado',
            type: 'office',
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new ReenviarConviteInput('token-expirado'));
    }

    #[TestDox('Reenviar 3 vezes e na 4ª lança DomainException com mensagem de limite')]
    public function testLimiteDeReenviosLancaExcecao(): void
    {
        $invitation = $this->convitePendente();
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Limite de reenvios');

        $this->useCase->executar(new ReenviarConviteInput('token-valido'));
    }

    #[TestDox('Reenvio válido incrementa contador e chama flush')]
    public function testReenvioValidoIncrementaContador(): void
    {
        $invitation = $this->convitePendente();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(new ReenviarConviteInput('token-valido'));

        self::assertSame(1, $resultado->getReenvioCount());
        self::assertSame('pending', $resultado->getStatus());
    }

    #[TestDox('Segundo reenvio chega a contador 2')]
    public function testSegundoReenvioIncrementaContadorParaDois(): void
    {
        $invitation = $this->convitePendente();
        $invitation->incrementarReenvio();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->method('flush');

        $resultado = $this->useCase->executar(new ReenviarConviteInput('token-valido'));

        self::assertSame(2, $resultado->getReenvioCount());
    }

    #[TestDox('Terceiro reenvio (último permitido) incrementa para 3')]
    public function testTerceiroReenvioPermitido(): void
    {
        $invitation = $this->convitePendente();
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(new ReenviarConviteInput('token-valido'));

        self::assertSame(3, $resultado->getReenvioCount());
    }
}
