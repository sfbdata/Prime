<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\AceitarConvitePlataformaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use App\Auth\Event\ConvitePlataformaAceito;

#[CoversClass(AceitarConvitePlataformaUseCase::class)]
final class AceitarConvitePlataformaUseCaseTest extends TestCase
{
    private EventDispatcher $eventos;

    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private AceitarConvitePlataformaUseCase $useCase;

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

        $this->useCase = new AceitarConvitePlataformaUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->em,
            $this->hasher,
            $registrarAceite,
            new ValidadorOab((new OabWebServiceClientFake())->indisponivel()),
            new EspelhoDaOabPrincipal($this->createStub(InscricaoOabRepository::class)),
            $this->eventos = new EventDispatcher(),
        );
    }

    private function makeInvitation(bool $expirada = false): Invitation
    {
        $expiresAt = $expirada
            ? new \DateTimeImmutable('-1 hour')
            : new \DateTimeImmutable('+24 hours');

        return new Invitation(
            email: 'advogado@example.com',
            tokenHash: Invitation::hashDoToken('tok123'),
            type: 'platform',
            expiresAt: $expiresAt,
        );
    }

    private function validInput(
        string $oabNumero = '12345',
        string $oabUf = 'SP',
    ): AceitarConvitePlataformaInput {
        return new AceitarConvitePlataformaInput(
            token: 'tok123',
            fullName: 'Dr. Advogado',
            senha: 'senha_segura',
            oabNumero: $oabNumero,
            oabUf: $oabUf,
            aceiteTermos: true,
            ip: '203.0.113.7',
        );
    }

    public function testConviteDeOutroTipoERecusadoSemGravar(): void
    {
        $invitation = new Invitation(email: 'advogado@example.com', tokenHash: Invitation::hashDoToken('tok123'), type: Invitation::TIPO_ESCRITORIO, expiresAt: new \DateTimeImmutable('+24 hours'));
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Convite não encontrado.');

        $this->useCase->executar($this->validInput());
    }

    public function testSenhaCurtaERecusadaSemGravar(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makeInvitation());
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A senha deve ter ao menos 8 caracteres.');

        $this->useCase->executar(new AceitarConvitePlataformaInput(token: 'tok123', fullName: 'Dr. Advogado', senha: 'curta', oabNumero: '12345', oabUf: 'SP', aceiteTermos: true, ip: '203.0.113.7'));
    }

    public function testNomeEmBrancoSemNomeNoConviteERecusado(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makeInvitation());
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nome completo é obrigatório.');

        $this->useCase->executar(new AceitarConvitePlataformaInput(token: 'tok123', fullName: '   ', senha: 'senha_segura', oabNumero: '12345', oabUf: 'SP', aceiteTermos: true, ip: '203.0.113.7'));
    }

    public function testHappyPathOabValidoCriaUser(): void
    {
        $capturados = [];
        $this->eventos->addListener(ConvitePlataformaAceito::class, static function (object $e) use (&$capturados): void { $capturados[] = $e; });
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->validInput());

        self::assertTrue($resultado->isActive());
        self::assertSame('12345', $resultado->getOabNumero());
        self::assertSame('SP', $resultado->getOabUf());
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($resultado, $invitation->getAcceptedAsUser());
        self::assertCount(1, $capturados);
    }

    public function testEmailVemDaInvitationNaoDoInput(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->validInput());

        self::assertSame('advogado@example.com', $resultado->getEmail());
    }

    public function testSenhaHasheadaEPersistidoEFlushed(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);

        $this->hasher->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'senha_segura')
            ->willReturn('hashed_pass');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->validInput());
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar($this->validInput());
    }

    public function testStatusNaoPendingLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar($this->validInput());
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation(expirada: true);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar($this->validInput());
    }

    public function testEmailJaTemContaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([new User()]);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Já existe uma conta');

        $this->useCase->executar($this->validInput());
    }

    public function testSemAceitarTermosLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Termos de Uso');

        $input = new AceitarConvitePlataformaInput(
            token: 'tok123',
            fullName: 'Dr. Advogado',
            senha: 'senha_segura',
            oabNumero: '12345',
            oabUf: 'SP',
            aceiteTermos: false,
        );

        $this->useCase->executar($input);
    }

    public function testOabNumeroComLetrasLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apenas dígitos');

        $this->useCase->executar($this->validInput(oabNumero: '12A34'));
    }

    public function testOabNumeroVazioLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apenas dígitos');

        $this->useCase->executar($this->validInput(oabNumero: ''));
    }

    public function testOabTotalmenteAusenteLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        // OAB obrigatória no Passo 1: número e UF ambos vazios é rejeitado.
        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->validInput(oabNumero: '', oabUf: ''));
    }

    public function testOabUfComUmaLetraLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exatamente 2 letras');

        $this->useCase->executar($this->validInput(oabUf: 'S'));
    }

    public function testOabUfComTresLetrasLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exatamente 2 letras');

        $this->useCase->executar($this->validInput(oabUf: 'SPP'));
    }

    public function testOabUfComMinusculasLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->validInput(oabUf: 'sp'));
    }

    public function testOabUfComNumeroLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->validInput(oabUf: 'S1'));
    }

    public function testOabValidoNumero12345UfSPAceita(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->validInput('12345', 'SP'));

        self::assertSame('12345', $resultado->getOabNumero());
        self::assertSame('SP', $resultado->getOabUf());
    }
}
