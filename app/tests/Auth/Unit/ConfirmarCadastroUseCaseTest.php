<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Repository\CadastroPendenteRepository;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfirmarCadastroUseCase::class)]
final class ConfirmarCadastroUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CadastroPendenteRepository&MockObject $cadastroRepository;
    private UserRepository&MockObject $userRepository;
    private TenantBootstrapService&MockObject $bootstrap;
    private ConfirmarCadastroUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->cadastroRepository = $this->createMock(CadastroPendenteRepository::class);
        $this->userRepository     = $this->createMock(UserRepository::class);
        $this->bootstrap          = $this->createMock(TenantBootstrapService::class);

        // RegistrarAceiteTermoUseCase é final — usa-se o real com o repo curto-circuitado
        // (já aceito), tornando-o no-op dentro deste fluxo.
        $aceiteRepo = $this->createMock(AceiteTermoRepository::class);
        $aceiteRepo->method('existeAceiteVigente')->willReturn(true);
        $registrarAceite = new RegistrarAceiteTermoUseCase($aceiteRepo, new TermoVigente());

        $this->useCase = new ConfirmarCadastroUseCase(
            $this->em,
            $this->cadastroRepository,
            $this->userRepository,
            $this->bootstrap,
            $registrarAceite,
        );

        // Executa a closure da transação diretamente.
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn(callable $cb) => $cb());
    }

    private function pendente(): CadastroPendente
    {
        return new CadastroPendente('ana@adv.com', 'tok', 'Dra. Ana', 'Banca Ana', '12345', 'SP', 'HASH', '1.2.3.4', new \DateTimeImmutable('+1 hour'), 'UA');
    }

    #[TestDox('Confirma: cria User ativo com OAB, dispara bootstrap e marca o cadastro como confirmado')]
    public function testConfirmaCriaConta(): void
    {
        $cadastro = $this->pendente();
        $this->cadastroRepository->method('encontrarPorToken')->willReturn($cadastro);
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->bootstrap->expects($this->once())->method('bootstrap');
        $this->em->expects($this->once())->method('flush');

        $user = $this->useCase->executar('tok');

        self::assertSame('ana@adv.com', $user->getEmail());
        self::assertSame('Dra. Ana', $user->getFullName());
        self::assertSame('12345', $user->getOabNumero());
        self::assertTrue($user->isActive());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertSame('confirmado', $cadastro->getStatus());
    }

    #[TestDox('Falha quando o token não existe')]
    public function testFalhaTokenNaoEncontrado(): void
    {
        $this->cadastroRepository->method('encontrarPorToken')->willReturn(null);
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\DomainException::class);
        $this->useCase->executar('inexistente');
    }

    #[TestDox('Falha quando o cadastro já foi confirmado')]
    public function testFalhaJaConfirmado(): void
    {
        $cadastro = $this->pendente();
        $cadastro->confirmar();
        $this->cadastroRepository->method('encontrarPorToken')->willReturn($cadastro);
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\DomainException::class);
        $this->useCase->executar('tok');
    }

    #[TestDox('Falha quando o link de confirmação expirou')]
    public function testFalhaExpirado(): void
    {
        $cadastro = new CadastroPendente('ana@adv.com', 'tok', 'Ana', 'Banca', '123', 'SP', 'HASH', '1.2.3.4', new \DateTimeImmutable('-1 hour'), null);
        $this->cadastroRepository->method('encontrarPorToken')->willReturn($cadastro);
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\DomainException::class);
        $this->useCase->executar('tok');
    }

    #[TestDox('Falha quando já existe conta com o e-mail (corrida entre início e confirmação)')]
    public function testFalhaEmailJaExiste(): void
    {
        $cadastro = $this->pendente();
        $this->cadastroRepository->method('encontrarPorToken')->willReturn($cadastro);
        $this->userRepository->method('findOneBy')->willReturn(new User());
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\DomainException::class);
        $this->useCase->executar('tok');
    }

    #[TestDox('Corrida na confirmação (UNIQUE de user.email) vira DomainException, nunca 500')]
    public function testCorridaDeEmailViraDomainException(): void
    {
        $cadastro = $this->pendente();
        $this->cadastroRepository->method('encontrarPorToken')->willReturn($cadastro);
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->em->method('flush')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $this->expectException(\DomainException::class);
        $this->useCase->executar('tok');
    }
}
