<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\CriarConvitePlataformaInput;
use App\Auth\UseCase\CriarConvitePlataformaUseCase;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Auth\Service\EmailDoConvite;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarConvitePlataformaUseCase::class)]
final class CriarConvitePlataformaUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private EntityManagerInterface&MockObject $em;
    private CriarConvitePlataformaUseCase $useCase;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->userRepo       = $this->createMock(UserRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->useCase        = new CriarConvitePlataformaUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->em,
        );
        $this->criadoPor = (new User())->setEmail('admin@jusprime.com.br');
    }

    public function testEmailNovoGeraConvitePlataforma(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new CriarConvitePlataformaInput('novo@example.com', 'Fulano', $this->criadoPor)
        )->convite;

        self::assertSame('platform', $resultado->getType());
        self::assertSame('pending', $resultado->getStatus());

        $diff = $resultado->getExpiresAt()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        self::assertEqualsWithDelta(86400, $diff, 5);
    }

    public function testEmailJaExisteLancaExcecao(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(new User());
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Já existe uma conta');

        $this->useCase->executar(
            new CriarConvitePlataformaInput('existente@example.com', null, $this->criadoPor)
        );
    }

    public function testEmailComEspacosMaiusculasNormalizado(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new CriarConvitePlataformaInput('  TEST@X.COM  ', null, $this->criadoPor)
        )->convite;

        self::assertSame('test@x.com', $resultado->getEmail());
    }

    public function testFullNameNuloEhAceito(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new CriarConvitePlataformaInput('novo@example.com', null, $this->criadoPor)
        )->convite;

        self::assertNull($resultado->getFullName());
    }

    public function testFullNamePreenchidoPropagado(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new CriarConvitePlataformaInput('novo@example.com', 'João Silva', $this->criadoPor)
        )->convite;

        self::assertSame('João Silva', $resultado->getFullName());
    }

    #[TestDox('Raiz B: o token puro (64 hex) volta só no ConviteEmitido; o convite grava o hash dele')]
    public function testTokenGeradoTem64CharsEOConviteGuardaSoOHash(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $emitido = $this->useCase->executar(
            new CriarConvitePlataformaInput('novo@example.com', null, $this->criadoPor)
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $emitido->token);
        self::assertSame(hash('sha256', $emitido->token), $emitido->convite->getTokenHash());
        self::assertNotSame($emitido->token, $emitido->convite->getTokenHash());
    }

    public function testCriadoPorPropagado(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new CriarConvitePlataformaInput('novo@example.com', null, $this->criadoPor)
        )->convite;

        self::assertSame($this->criadoPor, $resultado->getCreatedBy());
    }

    /** @return iterable<string, array{string}> */
    public static function emailsInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'sem arroba' => ['pessoa.example.com'];
        yield 'dois destinatários' => ['a@example.com,b@example.com'];
        yield 'parâmetro na URL' => ['a@example.com?cc=b@example.com'];
        yield 'aspas e parêntese' => ["a'@example.com')"];
        yield 'acima de 180' => [str_repeat('a', 170) . '@example.com'];
    }

    #[DataProvider('emailsInvalidos')]
    #[TestDox('E-mail inválido é recusado com a mensagem do fluxo e nada é gravado')]
    public function testEmailInvalidoRecusado(string $email): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(EmailDoConvite::ERRO);

        $this->useCase->executar(new CriarConvitePlataformaInput(email: $email, fullName: null, criadoPor: $this->criadoPor));
    }
}
