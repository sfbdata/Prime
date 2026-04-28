<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\DemitirFuncionarioInput;
use App\Tenant\UseCase\DemitirFuncionarioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(DemitirFuncionarioUseCase::class)]
final class DemitirFuncionarioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private DemitirFuncionarioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->conn    = $this->createMock(Connection::class);
        $this->useCase = new DemitirFuncionarioUseCase($this->em);
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $user = new User();
        $user->setTenant($tenant);
        $user->setEmail(uniqid() . '@test.com');
        $user->setFullName('Teste');

        return $user;
    }

    private function configurarMocksExecutar(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setParameter', 'execute'])
            ->getMock();
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(0);

        $this->em->method('createQuery')->willReturn($query);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->conn->method('executeStatement')->willReturn(0);
    }

    #[TestDox('Demite o funcionário: isActive false e demitidoEm preenchido')]
    public function testDemiteEMarcaUsuarioComoInativo(): void
    {
        $this->configurarMocksExecutar();
        $this->em->expects($this->once())->method('flush');

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario($tenant);
        $funcionario = $this->criarUsuario($tenant);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario));

        self::assertFalse($funcionario->isActive());
        self::assertNotNull($funcionario->getDemitidoEm());
        self::assertInstanceOf(\DateTimeImmutable::class, $funcionario->getDemitidoEm());
    }

    #[TestDox('Sem substituto: exatamente 2 DELETEs DBAL (tarefa + evento)')]
    public function testSemSubstitutoExecutaDuasDeletesDbal(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setParameter', 'execute'])
            ->getMock();
        $query->method('setParameter')->willReturnSelf();

        $this->em->method('createQuery')->willReturn($query);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->em->method('flush');

        $this->conn->expects($this->exactly(2))->method('executeStatement');

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario($tenant);
        $funcionario = $this->criarUsuario($tenant);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario));
    }

    #[TestDox('Com substituto: 4 chamadas DBAL (INSERT+DELETE para tarefa e evento)')]
    public function testComSubstitutoExecutaQuatroChamadasDbal(): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setParameter', 'execute'])
            ->getMock();
        $query->method('setParameter')->willReturnSelf();

        $this->em->method('createQuery')->willReturn($query);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->em->method('flush');

        $this->conn->expects($this->exactly(4))->method('executeStatement');

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario($tenant);
        $funcionario = $this->criarUsuario($tenant);
        $substituto  = $this->criarUsuario($tenant);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $substituto));
    }

    #[TestDox('Lança AccessDeniedException quando funcionário é de tenant diferente')]
    public function testLancaExcecaoQuandoTenantIncorreto(): void
    {
        $executor    = $this->criarUsuario(new Tenant());
        $funcionario = $this->criarUsuario(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario));
    }

    #[TestDox('Lança InvalidArgumentException quando executor tenta se demitir')]
    public function testNaoPermiteDemitirAsiMesmo(): void
    {
        $tenant   = new Tenant();
        $executor = $this->criarUsuario($tenant);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $executor));
    }

    #[TestDox('Lança InvalidArgumentException quando substituto é de outro tenant')]
    public function testSubstitutoDeveSerDoMesmoTenant(): void
    {
        $this->configurarMocksExecutar();

        $tenant1     = new Tenant();
        $executor    = $this->criarUsuario($tenant1);
        $funcionario = $this->criarUsuario($tenant1);
        $substituto  = $this->criarUsuario(new Tenant());

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $substituto));
    }

    #[TestDox('Lança InvalidArgumentException ao tentar demitir o criador do tenant')]
    public function testNaoPermiteDemitirCriadorDoTenant(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario($tenant);
        $funcionario = $this->criarUsuario($tenant);
        $tenant->setCriadoPor($funcionario);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario));
    }

    #[TestDox('Lança InvalidArgumentException quando substituto está inativo')]
    public function testSubstitutoDeveEstarAtivo(): void
    {
        $this->configurarMocksExecutar();

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario($tenant);
        $funcionario = $this->criarUsuario($tenant);
        $substituto  = $this->criarUsuario($tenant);
        $substituto->setIsActive(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $substituto));
    }
}
