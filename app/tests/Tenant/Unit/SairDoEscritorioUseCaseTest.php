<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SairDoEscritorioUseCase é hoje uma delegação fina para
 * RemoverColaboradorDoEscritorioUseCase (porta OrigemRemocao::Saida). Por isso o
 * teste monta o UseCase real de remoção (com EntityManager/Repository mockados),
 * em vez de mockar a própria dependência — assim ele prova o efeito de ponta a
 * ponta (vínculo apagado, trava do último admin) e não só a chamada.
 */
#[CoversClass(SairDoEscritorioUseCase::class)]
final class SairDoEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private UserTenantRepository&MockObject $userTenantRepository;
    private SairDoEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->conn                 = $this->createMock(Connection::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->em->method('getConnection')->willReturn($this->conn);

        $remover        = new RemoverColaboradorDoEscritorioUseCase($this->em, $this->userTenantRepository);
        $this->useCase  = new SairDoEscritorioUseCase($remover);
    }

    private function criarUsuario(): User
    {
        $user = new User();
        $user->setEmail(uniqid() . '@test.com');
        $user->setFullName('Teste');

        return $user;
    }

    private function vinculoAdmin(User $user, Tenant $tenant): UserTenant
    {
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole((new TenantRole())->setIsSystem(true));

        return $vinculo;
    }

    #[TestDox('Sai com sucesso quando não é admin: o vínculo é apagado')]
    public function testSaiApagaOVinculoQuandoNaoAdmin(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = new UserTenant($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->expects($this->never())->method('contarAdminsAtivos');
        $this->em->expects($this->once())->method('remove')->with($vinculo);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($usuario, $tenant);
    }

    #[TestDox('Admin que não é o último sai e tem o vínculo apagado')]
    public function testSaiApagaOVinculoQuandoAdminMasNaoUltimo(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = $this->vinculoAdmin($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(2);
        $this->em->expects($this->once())->method('remove')->with($vinculo);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($usuario, $tenant);
    }

    #[TestDox('Bloqueia o último admin (RN06): lança exceção e não apaga o vínculo')]
    public function testBloqueiaUltimoAdmin(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = $this->vinculoAdmin($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);
        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/único administrador/i');

        $this->useCase->executar($usuario, $tenant);
    }

    #[TestDox('Lança exceção quando não há vínculo ativo com o escritório')]
    public function testFalhaQuandoSemVinculoAtivo(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($usuario, $tenant);
    }
}
