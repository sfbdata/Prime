<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserProfile::class)]
final class UserProfileEntityTest extends TestCase
{
    private function criarUserFalso(): User
    {
        return $this->createStub(User::class);
    }

    #[TestDox('Construtor inicializa perfil com criadoEm preenchido e campos nulos')]
    public function testConstrutorInicializaCamposCorretamente(): void
    {
        $user = $this->criarUserFalso();
        $perfil = new UserProfile($user);

        self::assertSame($user, $perfil->getUser());
        self::assertNull($perfil->getId());
        self::assertNull($perfil->getFotoUrl());
        self::assertNull($perfil->getStatus());
        self::assertNull($perfil->getNomeCompleto());
        self::assertNull($perfil->getCpf());
        self::assertNull($perfil->getDataNascimento());
        self::assertNull($perfil->getCtps());
        self::assertNull($perfil->getSerie());
        self::assertNull($perfil->getAtualizadoEm());
        self::assertInstanceOf(\DateTimeImmutable::class, $perfil->getCriadoEm());
    }

    #[TestDox('setFotoUrl persiste o novo valor')]
    public function testSetFotoUrl(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setFotoUrl('abc123.jpg');

        self::assertSame('abc123.jpg', $perfil->getFotoUrl());
    }

    #[TestDox('setFotoUrl aceita null para remover a foto')]
    public function testSetFotoUrlNulo(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setFotoUrl('abc123.jpg');
        $perfil->setFotoUrl(null);

        self::assertNull($perfil->getFotoUrl());
    }

    #[TestDox('setStatus persiste o novo valor')]
    public function testSetStatus(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setStatus('Trabalhando duro');

        self::assertSame('Trabalhando duro', $perfil->getStatus());
    }

    #[TestDox('setStatus aceita null para limpar o status')]
    public function testSetStatusNulo(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setStatus('Em reunião');
        $perfil->setStatus(null);

        self::assertNull($perfil->getStatus());
    }

    #[TestDox('setNomeCompleto persiste o novo valor')]
    public function testSetNomeCompleto(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setNomeCompleto('João da Silva');

        self::assertSame('João da Silva', $perfil->getNomeCompleto());
    }

    #[TestDox('setCpf persiste o novo valor')]
    public function testSetCpf(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setCpf('123.456.789-00');

        self::assertSame('123.456.789-00', $perfil->getCpf());
    }

    #[TestDox('setDataNascimento persiste o novo valor')]
    public function testSetDataNascimento(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $data = new \DateTimeImmutable('1990-05-15');
        $perfil->setDataNascimento($data);

        self::assertSame($data, $perfil->getDataNascimento());
    }

    #[TestDox('setCtps e setSerie persistem os valores corretamente')]
    public function testSetCtpsESerie(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->setCtps('12345');
        $perfil->setSerie('001');

        self::assertSame('12345', $perfil->getCtps());
        self::assertSame('001', $perfil->getSerie());
    }

    #[TestDox('Setters retornam a própria instância para encadeamento fluente')]
    public function testSettersRetornamInstancia(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());

        self::assertSame($perfil, $perfil->setNomeCompleto('Teste'));
        self::assertSame($perfil, $perfil->setStatus('ok'));
        self::assertSame($perfil, $perfil->setFotoUrl('f.jpg'));
        self::assertSame($perfil, $perfil->setCpf(null));
        self::assertSame($perfil, $perfil->setCtps(null));
        self::assertSame($perfil, $perfil->setSerie(null));
        self::assertSame($perfil, $perfil->setDataNascimento(null));
    }

    #[TestDox('aoAtualizar (PreUpdate lifecycle) preenche atualizadoEm')]
    public function testAoAtualizarPreencheAtualizadoEm(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());

        self::assertNull($perfil->getAtualizadoEm());

        $perfil->aoAtualizar();

        self::assertInstanceOf(\DateTimeImmutable::class, $perfil->getAtualizadoEm());
    }

    #[TestDox('aoAtualizar chamado duas vezes gera timestamps distintos')]
    public function testAoAtualizarGeraTimestampsDiferentes(): void
    {
        $perfil = new UserProfile($this->criarUserFalso());
        $perfil->aoAtualizar();
        $primeira = $perfil->getAtualizadoEm();

        usleep(1000);

        $perfil->aoAtualizar();
        $segunda = $perfil->getAtualizadoEm();

        self::assertNotSame($primeira, $segunda);
    }
}
