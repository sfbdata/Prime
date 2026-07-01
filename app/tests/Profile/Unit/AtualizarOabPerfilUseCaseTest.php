<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\VerificarOabUseCase;
use App\Entity\Auth\User;
use App\Profile\DTO\OabPerfilInput;
use App\Profile\UseCase\AtualizarOabPerfilUseCase;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarOabPerfilUseCase::class)]
final class AtualizarOabPerfilUseCaseTest extends TestCase
{
    private function useCase(OabWebServiceClientFake $fake): AtualizarOabPerfilUseCase
    {
        $em        = $this->createStub(EntityManagerInterface::class);
        $validador = new ValidadorOab($fake);

        return new AtualizarOabPerfilUseCase($validador, new VerificarOabUseCase($validador, $em), $em);
    }

    private function input(?string $numero, ?string $uf): OabPerfilInput
    {
        $input            = new OabPerfilInput();
        $input->oabNumero = $numero;
        $input->oabUf     = $uf;

        return $input;
    }

    private function usuario(): User
    {
        $user = new User();
        $user->setFullName('João Silva');

        return $user;
    }

    #[TestDox('informar OAB nova grava número/UF e status NaoVerificada (dormente)')]
    public function testInformarNovaOab(): void
    {
        $user = $this->usuario();

        $this->useCase((new OabWebServiceClientFake())->indisponivel())
            ->executar($user, $this->input('12345', 'SP'));

        self::assertSame('12345', $user->getOabNumero());
        self::assertSame('SP', $user->getOabUf());
        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus());
    }

    #[TestDox('UF é normalizada para maiúscula')]
    public function testUfMaiuscula(): void
    {
        $user = $this->usuario();

        $this->useCase((new OabWebServiceClientFake())->indisponivel())
            ->executar($user, $this->input('123', 'sp'));

        self::assertSame('SP', $user->getOabUf());
    }

    #[TestDox('editar para número diferente reseta status confirmado')]
    public function testEditarDiferenteReseta(): void
    {
        $user = $this->usuario();
        $user->setOabNumero('111');
        $user->setOabUf('SP');
        $user->setOabStatus(StatusOab::Confirmada);

        $this->useCase((new OabWebServiceClientFake())->indisponivel())
            ->executar($user, $this->input('222', 'SP'));

        self::assertSame('222', $user->getOabNumero());
        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus());
    }

    #[TestDox('reenviar o mesmo número/UF NÃO reseta o status')]
    public function testMesmoValorNaoReseta(): void
    {
        $user = $this->usuario();
        $user->setOabNumero('111');
        $user->setOabUf('SP');
        $user->setOabStatus(StatusOab::Confirmada);

        $this->useCase((new OabWebServiceClientFake())->indisponivel())
            ->executar($user, $this->input('111', 'SP'));

        self::assertSame(StatusOab::Confirmada, $user->getOabStatus());
    }

    #[TestDox('limpar a OAB zera número, UF e status')]
    public function testLimparZeraTudo(): void
    {
        $user = $this->usuario();
        $user->setOabNumero('111');
        $user->setOabUf('SP');
        $user->setOabStatus(StatusOab::Confirmada);
        $user->setOabNomeOficial('JOAO SILVA');
        $user->setOabVerificadaEm(new \DateTimeImmutable());

        $this->useCase(new OabWebServiceClientFake())
            ->executar($user, $this->input('', ''));

        self::assertNull($user->getOabNumero());
        self::assertNull($user->getOabUf());
        self::assertNull($user->getOabStatus());
        self::assertNull($user->getOabNomeOficial());
        self::assertNull($user->getOabVerificadaEm());
    }

    #[TestDox('formato inválido lança InvalidArgumentException')]
    public function testFormatoInvalido(): void
    {
        $user = $this->usuario();

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase(new OabWebServiceClientFake())
            ->executar($user, $this->input('abc', 'SP'));
    }
}
