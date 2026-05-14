<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Profile\DTO\PerfilOutput;
use App\Profile\Entity\UserProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerfilOutput::class)]
final class PerfilOutputTest extends TestCase
{
    private function criarUser(string $nome = 'João Silva', string $email = 'joao@test.com'): User
    {
        $user = $this->createStub(User::class);
        $user->method('getFullName')->willReturn($nome);
        $user->method('getEmail')->willReturn($email);
        $user->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2023-01-01'));

        return $user;
    }

    #[TestDox('fromEntity mapeia todos os campos corretamente')]
    public function testFromEntityMapeiaCampos(): void
    {
        $user = $this->criarUser('Maria Souza', 'maria@test.com');
        $perfil = new UserProfile($user);
        $perfil->setNomeCompleto('Maria Souza');
        $perfil->setFotoUrl('foto.jpg');
        $perfil->setStatus('Trabalhando');
        $perfil->setCpf('123.456.789-09');
        $perfil->setDataNascimento(new \DateTimeImmutable('1990-05-15'));
        $perfil->setCtps('12345');
        $perfil->setSerie('001');

        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertSame('Maria Souza', $output->nomeCompleto);
        self::assertSame('Maria Souza', $output->nomeExibido);
        self::assertSame('foto.jpg', $output->fotoUrl);
        self::assertSame('Trabalhando', $output->status);
        self::assertSame('123.456.789-09', $output->cpf);
        self::assertSame('12345', $output->ctps);
        self::assertSame('001', $output->serie);
        self::assertSame('maria@test.com', $output->email);
        self::assertNull($output->cargo);
        self::assertNull($output->lotacao);
    }

    #[TestDox('nomeExibido usa nomeCompleto do perfil quando preenchido')]
    public function testNomeExibidoUsaNomeCompleto(): void
    {
        $user = $this->criarUser('Fulano User');
        $perfil = new UserProfile($user);
        $perfil->setNomeCompleto('Nome do Perfil');

        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertSame('Nome do Perfil', $output->nomeExibido);
    }

    #[TestDox('nomeExibido usa fullName do User quando nomeCompleto é null')]
    public function testNomeExibidoUsaFullNameDoUser(): void
    {
        $user = $this->criarUser('Fulano User');
        $perfil = new UserProfile($user);

        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertSame('Fulano User', $output->nomeExibido);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provedorIniciais(): array
    {
        return [
            'dois nomes'  => ['João Silva', 'JS'],
            'um nome'     => ['Maria', 'M'],
            'três nomes'  => ['Ana Clara Souza', 'AS'],
            'nome vazio'  => ['', '?'],
            'só espaços'  => ['   ', '?'],
        ];
    }

    #[DataProvider('provedorIniciais')]
    #[TestDox('Iniciais calculadas corretamente para: $nome')]
    public function testCalcularIniciais(string $nome, string $esperado): void
    {
        $user = $this->criarUser($nome);
        $perfil = new UserProfile($user);

        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertSame($esperado, $output->iniciais);
    }

    #[TestDox('membroDesde usa createdAt do User')]
    public function testMembroDesdeUsaCreatedAt(): void
    {
        $data = new \DateTimeImmutable('2022-03-10');

        $user = $this->createStub(User::class);
        $user->method('getFullName')->willReturn('João Silva');
        $user->method('getEmail')->willReturn('joao@test.com');
        $user->method('getCreatedAt')->willReturn($data);

        $perfil = new UserProfile($user);
        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertSame($data, $output->membroDesde);
    }

    #[TestDox('cargo e lotação são null quando userTenant é null')]
    public function testCargosNullQuandoUserTenantEhNull(): void
    {
        $user = $this->criarUser();
        $perfil = new UserProfile($user);

        $output = PerfilOutput::fromEntity($perfil, $user, null);

        self::assertNull($output->cargo);
        self::assertNull($output->lotacao);
    }

    #[TestDox('cargo e lotação vêm do UserTenant quando fornecido')]
    public function testCargosVemDoUserTenant(): void
    {
        $cargo = $this->createStub(Cargo::class);
        $cargo->method('getNome')->willReturn('Analista');
        $lotacao = $this->createStub(Lotacao::class);
        $lotacao->method('getNome')->willReturn('TI');

        $userTenant = $this->createStub(UserTenant::class);
        $userTenant->method('getCargo')->willReturn($cargo);
        $userTenant->method('getLotacao')->willReturn($lotacao);

        $user = $this->criarUser();
        $perfil = new UserProfile($user);

        $output = PerfilOutput::fromEntity($perfil, $user, $userTenant);

        self::assertSame('Analista', $output->cargo);
        self::assertSame('TI', $output->lotacao);
    }
}
