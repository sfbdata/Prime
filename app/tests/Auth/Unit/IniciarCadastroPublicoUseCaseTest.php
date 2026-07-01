<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Repository\CadastroPendenteRepository;
use App\Auth\UseCase\IniciarCadastroPublicoUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(IniciarCadastroPublicoUseCase::class)]
final class IniciarCadastroPublicoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private CadastroPendenteRepository&MockObject $cadastroRepository;
    private UserPasswordHasherInterface&MockObject $hasher;
    private IniciarCadastroPublicoUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->userRepository     = $this->createMock(UserRepository::class);
        $this->cadastroRepository = $this->createMock(CadastroPendenteRepository::class);
        $this->hasher             = $this->createMock(UserPasswordHasherInterface::class);
        $this->useCase            = new IniciarCadastroPublicoUseCase(
            $this->em,
            $this->userRepository,
            $this->cadastroRepository,
            $this->hasher,
        );
    }

    private function input(string $email = 'novo@adv.com', string $oabNumero = '12345', string $oabUf = 'SP'): IniciarCadastroPublicoInput
    {
        $in                 = new IniciarCadastroPublicoInput();
        $in->nomeCompleto   = 'Dra. Ana';
        $in->email          = $email;
        $in->senha          = 'segredo123';
        $in->nomeEscritorio = 'Banca Ana';
        $in->oabNumero      = $oabNumero;
        $in->oabUf          = $oabUf;
        $in->aceiteTermos   = true;

        return $in;
    }

    #[TestDox('Cria o cadastro pendente com senha hasheada quando e-mail livre e OAB válida')]
    public function testCriaCadastroPendente(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->cadastroRepository->method('encontrarPorEmail')->willReturn([]);
        $this->hasher->method('hashPassword')->willReturn('HASH');
        $this->em->expects($this->once())->method('persist')->with(self::isInstanceOf(CadastroPendente::class));
        $this->em->expects($this->once())->method('flush');

        $cadastro = $this->useCase->executar($this->input(), '1.2.3.4', 'UA');

        self::assertSame('novo@adv.com', $cadastro->getEmail());
        self::assertSame('Banca Ana', $cadastro->getNomeEscritorio());
        self::assertSame('HASH', $cadastro->getSenhaHash());
        self::assertSame('pending', $cadastro->getStatus());
        self::assertSame('SP', $cadastro->getOabUf());
        self::assertNotSame('', $cadastro->getToken());
    }

    #[TestDox('Falha quando já existe conta com o e-mail')]
    public function testFalhaEmailJaExiste(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(new User());
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->useCase->executar($this->input(), '1.2.3.4', null);
    }

    #[TestDox('Falha quando a OAB é inválida (formato)')]
    public function testFalhaOabInvalida(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->input(oabNumero: '12A'), '1.2.3.4', null);
    }

    #[TestDox('Remove cadastros pendentes anteriores do mesmo e-mail antes de criar o novo')]
    public function testRemovePendentesAnteriores(): void
    {
        $anterior = new CadastroPendente('novo@adv.com', 'tok', 'X', 'Y', '1', 'SP', 'h', '0.0.0.0', new \DateTimeImmutable('+1 hour'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->cadastroRepository->method('encontrarPorEmail')->willReturn([$anterior]);
        $this->hasher->method('hashPassword')->willReturn('HASH');
        $this->em->expects($this->once())->method('remove')->with($anterior);

        $this->useCase->executar($this->input(), '1.2.3.4', null);
    }
}
