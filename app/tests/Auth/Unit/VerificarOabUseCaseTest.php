<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\ConsultaOabResultado;
use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\VerificarOabUseCase;
use App\Entity\Auth\User;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificarOabUseCase::class)]
final class VerificarOabUseCaseTest extends TestCase
{
    private function usuarioComOab(): User
    {
        $user = new User();
        $user->setEmail('adv@example.com');
        $user->setFullName('João da Silva');
        $user->setOabNumero('12345');
        $user->setOabUf('SP');

        return $user;
    }

    #[TestDox('dormente (indisponível) grava NaoVerificada e NÃO marca verificadaEm')]
    public function testDormenteGravaNaoVerificada(): void
    {
        $user = $this->usuarioComOab();
        $fake = (new OabWebServiceClientFake())->indisponivel();
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $resultado = (new VerificarOabUseCase(new ValidadorOab($fake), $em))->executar($user);

        self::assertNotNull($resultado);
        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus());
        self::assertNull($user->getOabNomeOficial());
        self::assertNull($user->getOabVerificadaEm());
    }

    #[TestDox('confirmando grava Confirmada, nome oficial e verificadaEm')]
    public function testConfirmadaGravaTudo(): void
    {
        $user = $this->usuarioComOab();
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'JOÃO DA SILVA', 'Regular'));
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new VerificarOabUseCase(new ValidadorOab($fake), $em))->executar($user);

        self::assertSame(StatusOab::Confirmada, $user->getOabStatus());
        self::assertSame('JOÃO DA SILVA', $user->getOabNomeOficial());
        self::assertNotNull($user->getOabVerificadaEm());
    }

    #[TestDox('divergente (nome não bate) grava Divergente e marca verificadaEm')]
    public function testDivergenteMarcaVerificadaEm(): void
    {
        $user = $this->usuarioComOab();
        $fake = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'OUTRO NOME QUALQUER', 'Regular'));
        $em   = $this->createMock(EntityManagerInterface::class);

        (new VerificarOabUseCase(new ValidadorOab($fake), $em))->executar($user);

        self::assertSame(StatusOab::Divergente, $user->getOabStatus());
        self::assertSame('OUTRO NOME QUALQUER', $user->getOabNomeOficial());
        self::assertNotNull($user->getOabVerificadaEm());
    }

    #[TestDox('usuário sem número de OAB (dono grandfathered) → retorna null e não persiste')]
    public function testSemOabNaoPersiste(): void
    {
        $user = new User();
        $user->setEmail('dono@example.com');
        $user->setFullName('Maria Dona');
        $user->setOabStatus(StatusOab::Confirmada); // grandfathered, sem número

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $resultado = (new VerificarOabUseCase(new ValidadorOab(new OabWebServiceClientFake()), $em))->executar($user);

        self::assertNull($resultado);
        self::assertSame(StatusOab::Confirmada, $user->getOabStatus()); // inalterado
    }
}
