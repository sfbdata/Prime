<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\DemitirFuncionarioInput;
use App\Tenant\UseCase\DemitirFuncionarioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemitirFuncionarioUseCase::class)]
final class DemitirFuncionarioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private UserTenantRepository&MockObject $userTenantRepository;
    private DemitirFuncionarioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->conn                 = $this->createMock(Connection::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->useCase              = new DemitirFuncionarioUseCase($this->em, $this->userTenantRepository);
    }

    private function criarUsuario(): User
    {
        $user = new User();
        $user->setEmail(uniqid() . '@test.com');
        $user->setFullName('Teste');

        return $user;
    }

    private function criarVinculo(User $user, Tenant $tenant): UserTenant
    {
        return new UserTenant($user, $tenant);
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

    #[TestDox('Demite o funcionário: vinculo isActive false, demitidoEm preenchido, User permanece ativo')]
    public function testDemiteEMarcaVinculoComoInativo(): void
    {
        $this->configurarMocksExecutar();
        $this->em->expects($this->once())->method('flush');

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario();
        $funcionario = $this->criarUsuario();
        $vinculo     = $this->criarVinculo($funcionario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant));

        self::assertFalse($vinculo->isActive());
        self::assertNotNull($vinculo->getDemitidoEm());
        self::assertInstanceOf(\DateTimeImmutable::class, $vinculo->getDemitidoEm());
        self::assertTrue($funcionario->isActive());
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
        $executor    = $this->criarUsuario();
        $funcionario = $this->criarUsuario();
        $vinculo     = $this->criarVinculo($funcionario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant));
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
        $executor    = $this->criarUsuario();
        $funcionario = $this->criarUsuario();
        $substituto  = $this->criarUsuario();
        $vinculo     = $this->criarVinculo($funcionario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant, $substituto));
    }

    #[TestDox('Lança InvalidArgumentException quando executor tenta se demitir')]
    public function testNaoPermiteDemitirAsiMesmo(): void
    {
        $tenant   = new Tenant();
        $executor = $this->criarUsuario();

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $executor, $tenant));
    }

    #[TestDox('Lança InvalidArgumentException ao tentar demitir o criador do tenant')]
    public function testNaoPermiteDemitirCriadorDoTenant(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario();
        $funcionario = $this->criarUsuario();
        $tenant->setCriadoPor($funcionario);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant));
    }

    #[TestDox('Lança InvalidArgumentException quando substituto está inativo')]
    public function testSubstitutoDeveEstarAtivo(): void
    {
        $this->configurarMocksExecutar();
        $this->userTenantRepository->expects($this->never())->method('findAtivoPorUserETenant');

        $tenant      = new Tenant();
        $executor    = $this->criarUsuario();
        $funcionario = $this->criarUsuario();
        $substituto  = $this->criarUsuario();
        $substituto->setIsActive(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new DemitirFuncionarioInput($executor, $funcionario, $tenant, $substituto));
    }
}
