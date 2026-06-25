<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SairDoEscritorioUseCase::class)]
final class SairDoEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserTenantRepository&MockObject $userTenantRepository;
    private SairDoEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->useCase              = new SairDoEscritorioUseCase($this->em, $this->userTenantRepository);
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

    #[TestDox('Sai com sucesso quando não é admin: vínculo inativo, demitidoEm permanece null')]
    public function testSaiComSucessoQuandoNaoAdmin(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = new UserTenant($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->expects($this->never())->method('contarAdminsAtivos');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($usuario, $tenant);

        self::assertFalse($vinculo->isActive());
        self::assertNull($vinculo->getDemitidoEm());
    }

    #[TestDox('Admin que não é o último sai normalmente')]
    public function testSaiComSucessoQuandoAdminMasNaoUltimo(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = $this->vinculoAdmin($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(2);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($usuario, $tenant);

        self::assertFalse($vinculo->isActive());
    }

    #[TestDox('Bloqueia o último admin (RN06): lança exceção e não persiste')]
    public function testBloqueiaUltimoAdmin(): void
    {
        $tenant  = new Tenant();
        $usuario = $this->criarUsuario();
        $vinculo = $this->vinculoAdmin($usuario, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($usuario, $tenant);

        self::assertTrue($vinculo->isActive());
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
