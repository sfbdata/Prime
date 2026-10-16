<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\TenantRoleRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\PromoverPrimarioSocioInput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\PromoverPrimarioSocioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromoverPrimarioSocioUseCase::class)]
final class PromoverPrimarioSocioUseCaseTest extends TestCase
{
    private UserTenantRepository&MockObject $userTenantRepository;
    private TenantRoleRepository&MockObject $tenantRoleRepository;
    private EntityManagerInterface&MockObject $em;
    private PromoverPrimarioSocioUseCase $useCase;
    private User $ator;

    protected function setUp(): void
    {
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->tenantRoleRepository = $this->createMock(TenantRoleRepository::class);
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new PromoverPrimarioSocioUseCase(
            $this->userTenantRepository,
            $this->tenantRoleRepository,
            $this->em,
            new RegraDosPrimarios($this->userTenantRepository),
        );
        $this->ator = $this->usuario();
    }

    private function usuario(bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail(uniqid('socio_') . '@test.com');
        $user->setFullName('Sócio');
        $user->setIsActive($ativo);

        return $user;
    }

    private function perfil(Tenant $tenant, bool $sistema): TenantRole
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)');
        $role->setIsSystem($sistema);

        return $role;
    }

    #[TestDox('promove o secundário ativo: o vínculo passa ao perfil de sistema do mesmo escritório')]
    public function testPromoveSecundarioAtivo(): void
    {
        $tenant   = new Tenant();
        $alvo     = $this->usuario();
        $sistema  = $this->perfil($tenant, true);
        $vinculo  = (new UserTenant($alvo, $tenant))->setTenantRole($this->perfil($tenant, false));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->with($alvo, $tenant)->willReturn($vinculo);
        $this->tenantRoleRepository->method('findPerfilDoPrimario')->with($tenant)->willReturn($sistema);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenant, $alvo, $this->ator));

        self::assertSame($sistema, $vinculo->getTenantRole());
    }

    #[TestDox('recusa usuário sem vínculo ativo NESTE escritório (outro escritório não atravessa)')]
    public function testRecusaUsuarioDeOutroEscritorio(): void
    {
        $tenantA = new Tenant();
        $alvo    = $this->usuario();

        // O repositório busca pelo par (usuário, escritório A): quem só é do B não aparece.
        $this->userTenantRepository->method('findAtivoPorUserETenant')->with($alvo, $tenantA)->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/ativos desta conta/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenantA, $alvo, $this->ator));
    }

    #[TestDox('recusa vínculo devolvido de outro escritório (cinto: o repositório não é a única barreira)')]
    public function testRecusaVinculoDeOutroEscritorioMesmoSeORepositorioDevolver(): void
    {
        $tenantA = new Tenant();
        $tenantB = new Tenant();
        $alvo    = $this->usuario();
        $vinculoNoB = (new UserTenant($alvo, $tenantB))->setTenantRole($this->perfil($tenantB, false));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculoNoB);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenantA, $alvo, $this->ator));
    }

    #[TestDox('recusa vínculo inativo')]
    public function testRecusaVinculoInativo(): void
    {
        $tenant  = new Tenant();
        $alvo    = $this->usuario();
        $vinculo = (new UserTenant($alvo, $tenant))->setTenantRole($this->perfil($tenant, false));
        $this->desativar($vinculo);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/ativos desta conta/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenant, $alvo, $this->ator));
    }

    #[TestDox('recusa conta de usuário desativada')]
    public function testRecusaContaDesativada(): void
    {
        $tenant  = new Tenant();
        $alvo    = $this->usuario(false);
        $vinculo = (new UserTenant($alvo, $tenant))->setTenantRole($this->perfil($tenant, false));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/desativada/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenant, $alvo, $this->ator));
    }

    #[TestDox('recusa quem já é primário')]
    public function testRecusaQuemJaEPrimario(): void
    {
        $tenant  = new Tenant();
        $alvo    = $this->usuario();
        $vinculo = (new UserTenant($alvo, $tenant))->setTenantRole($this->perfil($tenant, true));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/já é primário/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenant, $alvo, $this->ator));
    }

    #[TestDox('recusa perfil de sistema de OUTRO escritório')]
    public function testRecusaPerfilDeSistemaDeOutroEscritorio(): void
    {
        $tenantA = new Tenant();
        $tenantB = new Tenant();
        $alvo    = $this->usuario();
        $vinculo = (new UserTenant($alvo, $tenantA))->setTenantRole($this->perfil($tenantA, false));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->tenantRoleRepository->method('findPerfilDoPrimario')->willReturn($this->perfil($tenantB, true));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/perfil de usuário primário/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenantA, $alvo, $this->ator));
    }

    /** UserTenant não tem desativação pública (o vínculo inativo é legado de antes do hard delete). */
    private function desativar(UserTenant $vinculo): void
    {
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
    }

    #[TestDox('ninguém além do próprio Master promove um usuário Master')]
    public function testNaoPromoveMasterPorOutro(): void
    {
        $tenant = new Tenant();
        $master = $this->usuario();
        $master->setRoles(['ROLE_SUPER_ADMIN']);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/próprio Master/');

        $this->useCase->executar(new PromoverPrimarioSocioInput($tenant, $master, $this->ator));
    }
}
