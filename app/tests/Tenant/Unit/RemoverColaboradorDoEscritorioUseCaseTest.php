<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class RemoverColaboradorDoEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $conn;
    private UserTenantRepository&MockObject $userTenantRepository;
    private RemoverColaboradorDoEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->conn                 = $this->createMock(Connection::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->em->method('getConnection')->willReturn($this->conn);
        $this->useCase = new RemoverColaboradorDoEscritorioUseCase($this->em, $this->userTenantRepository);
    }

    private function criarUsuario(string $nome = 'Teste'): User
    {
        $user = new User();
        $user->setEmail(uniqid() . '@test.com');
        $user->setFullName($nome);

        return $user;
    }

    private function criarVinculoAdmin(User $user, Tenant $tenant): UserTenant
    {
        $role = new TenantRole();
        $role->setName('Administrador Master');
        $role->setIsSystem(true);

        return (new UserTenant($user, $tenant))->setTenantRole($role);
    }

    #[TestDox('apaga o vinculo do colaborador removido')]
    public function testApagaOVinculo(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->em->expects($this->once())->method('remove')->with($vinculo);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa remover o ultimo administrador ativo')]
    public function testRecusaRemoverOUltimoAdmin(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Unico Admin');
        $vinculo     = $this->criarVinculoAdmin($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/último administrador/i');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa o admin remover a si mesmo pelo painel')]
    public function testRecusaRemoverASiMesmoPeloPainel(): void
    {
        $tenant  = new Tenant();
        $pessoa  = $this->criarUsuario('Eu');
        $vinculo = new UserTenant($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new RemoverColaboradorInput($pessoa, $pessoa, $tenant));
    }

    #[TestDox('permite a propria pessoa sair quando a origem e a saida voluntaria')]
    public function testPermiteSairPelaPortaDaSaida(): void
    {
        $tenant  = new Tenant();
        $pessoa  = $this->criarUsuario('Eu');
        $vinculo = new UserTenant($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->once())->method('remove')->with($vinculo);

        $this->useCase->executar(
            new RemoverColaboradorInput($pessoa, $pessoa, $tenant, null, OrigemRemocao::Saida)
        );
    }

    #[TestDox('recusa substituto que nao e colaborador ativo do escritorio')]
    public function testRecusaSubstitutoDeFora(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $estranho    = $this->criarUsuario('De fora');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('existeVinculoAtivo')->willReturn(false);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant, $estranho));
    }

    #[TestDox('recusa remover o ultimo administrador ativo tambem pela porta da saida')]
    public function testRecusaRemoverOUltimoAdminPelaSaida(): void
    {
        $tenant  = new Tenant();
        $pessoa  = $this->criarUsuario('Unico Admin');
        $vinculo = $this->criarVinculoAdmin($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/último administrador/i');

        $this->useCase->executar(
            new RemoverColaboradorInput($pessoa, $pessoa, $tenant, null, OrigemRemocao::Saida)
        );
    }

    #[TestDox('permite remover o penultimo administrador')]
    public function testPermiteRemoverPenultimoAdmin(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Penultimo Admin');
        $vinculo     = $this->criarVinculoAdmin($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(2);

        $this->em->expects($this->once())->method('remove')->with($vinculo);

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa remover o ultimo administrador mesmo com executor super-admin')]
    public function testRecusaRemoverOUltimoAdminMesmoComExecutorSuperAdmin(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Super Admin');
        $executor->setRoles(['ROLE_SUPER_ADMIN']);
        $colaborador = $this->criarUsuario('Unico Admin');
        $vinculo     = $this->criarVinculoAdmin($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/último administrador/i');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('recusa remover o criador do escritorio pelo painel')]
    public function testRecusaRemoverCriadorDoEscritorioPeloPainel(): void
    {
        $tenant      = new Tenant();
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Fundador');
        $tenant->setCriadoPor($colaborador);
        $vinculo = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->em->expects($this->never())->method('remove');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/criador/i');

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('permite remover colaborador quando ele nao e o criador do escritorio')]
    public function testPermiteRemoverColaboradorQuandoNaoECriador(): void
    {
        $tenant      = new Tenant();
        $fundador    = $this->criarUsuario('Fundador');
        $tenant->setCriadoPor($fundador);
        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->em->expects($this->once())->method('remove')->with($vinculo);

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));
    }

    #[TestDox('permite o criador do escritorio sair sozinho, pois a trava do criador vale so no painel')]
    public function testPermiteCriadorSairPelaPortaDaSaida(): void
    {
        $tenant = new Tenant();
        $pessoa = $this->criarUsuario('Fundador');
        $tenant->setCriadoPor($pessoa);
        $vinculo = new UserTenant($pessoa, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $this->em->expects($this->once())->method('remove')->with($vinculo);

        $this->useCase->executar(
            new RemoverColaboradorInput($pessoa, $pessoa, $tenant, null, OrigemRemocao::Saida)
        );
    }

    #[TestDox('grava o registro de auditoria com o tenant da rota, nao o da sessao')]
    public function testGravaAuditoriaComOTenantDaRota(): void
    {
        $tenant = new Tenant();
        $ref    = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, 77);

        $executor    = $this->criarUsuario('Admin');
        $colaborador = $this->criarUsuario('Colaborador');
        $vinculo     = new UserTenant($colaborador, $tenant);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);

        $capturado = null;
        $this->em->method('persist')->willReturnCallback(function ($entidade) use (&$capturado) {
            if ($entidade instanceof AuditLog) {
                $capturado = $entidade;
            }
        });

        $this->useCase->executar(new RemoverColaboradorInput($executor, $colaborador, $tenant));

        self::assertNotNull($capturado, 'nenhum AuditLog foi persistido');
        self::assertSame(77, $capturado->getTenantId());
        self::assertSame('delete', $capturado->getAction());
        self::assertSame(UserTenant::class, $capturado->getEntityClass());
    }
}
