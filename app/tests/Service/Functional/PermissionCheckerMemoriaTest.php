<?php

declare(strict_types=1);

namespace App\Tests\Service\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Service\PermissionChecker;
use App\Tenant\Enum\SituacaoNaEquipe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Memória do vínculo no `PermissionChecker` contra o banco (spec `permission-checker-memoria.md`, Testes):
 * a resposta com a memória é a mesma de sempre consultar, e o que muda no vínculo, no papel ou nas
 * permissões do papel no mesmo request vale na hora. O DAMA desfaz o que foi gravado.
 */
#[CoversClass(PermissionChecker::class)]
final class PermissionCheckerMemoriaTest extends KernelTestCase
{
    private const PERMISSAO = 'modules.clientes.view';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('equivalência: com a memória, hasPermission e canAccessModule respondem igual a consultar sempre')]
    public function testEquivalenciaComESemMemoria(): void
    {
        $codigos = array_values(array_filter(array_map(
            static fn (Permission $p): ?string => $p->getCode(),
            $this->em()->getRepository(Permission::class)->findAll(),
        )));
        self::assertNotEmpty($codigos, 'O banco de teste não tem Permission: monte o saas_test com as permissões.');
        sort($codigos);
        $modulos = [];
        foreach ($codigos as $codigo) {
            if (preg_match('/^modules\.(.+)\.view$/', $codigo, $m) === 1) {
                $modulos[] = $m[1];
            }
        }
        // Uma permissão em cada três para o colaborador: algumas sim, outras não.
        $doColaborador = array_values(array_filter($codigos, static fn (int $i): bool => $i % 3 === 0, ARRAY_FILTER_USE_KEY));

        $tenant = $this->novoEscritorio();
        $outro = $this->novoEscritorio();
        $primario = $this->novoUsuario('Paula Primária');
        $this->vincular($primario, $tenant, [], true);
        $colaborador = $this->novoUsuario('Ana Equipe');
        $this->vincular($colaborador, $tenant, $doColaborador);
        $semVinculo = $this->novoUsuario('Sem Vínculo');
        $this->vincular($semVinculo, $outro, $codigos);
        $inativo = $this->novoUsuario('Ina Suspensa');
        $this->vincular($inativo, $tenant, $codigos)->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $master = $this->novoUsuario('Mestre Master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->em()->flush();

        $ids = ['primário' => $primario->getId(), 'colaborador' => $colaborador->getId(), 'sem vínculo' => $semVinculo->getId(), 'vínculo inativo' => $inativo->getId(), 'super admin' => $master->getId()];
        $tenantId = $tenant->getId();
        // Tudo relido do banco: a coleção do papel e as Permission vêm pelo carregamento real.
        $this->em()->clear();
        $tenant = $this->em()->find(Tenant::class, $tenantId);
        self::assertNotNull($tenant);
        $pessoas = [];
        foreach ($ids as $nome => $id) {
            $pessoas[$nome] = $this->em()->find(User::class, $id);
            self::assertNotNull($pessoas[$nome]);
        }

        $checker = $this->checker();
        $checker->reset();
        $comMemoria = [];
        foreach ($codigos as $codigo) {
            foreach ($pessoas as $nome => $quem) {
                $comMemoria[$nome]['permissão ' . $codigo] = $checker->hasPermission($quem, $tenant, $codigo);
            }
        }
        foreach ($modulos as $modulo) {
            foreach ($pessoas as $nome => $quem) {
                $comMemoria[$nome]['módulo ' . $modulo] = $checker->canAccessModule($quem, $tenant, $modulo);
            }
        }

        $semMemoria = [];
        foreach ($codigos as $codigo) {
            foreach ($pessoas as $nome => $quem) {
                $checker->reset();
                $semMemoria[$nome]['permissão ' . $codigo] = $checker->hasPermission($quem, $tenant, $codigo);
            }
        }
        foreach ($modulos as $modulo) {
            foreach ($pessoas as $nome => $quem) {
                $checker->reset();
                $semMemoria[$nome]['módulo ' . $modulo] = $checker->canAccessModule($quem, $tenant, $modulo);
            }
        }

        self::assertSame($semMemoria, $comMemoria);

        // Sanidade do cenário: as pessoas respondem como o esperado, não só igual entre si.
        $total = \count($codigos);
        self::assertCount($total, array_filter($comMemoria['primário'], static fn (string $k): bool => str_starts_with($k, 'permissão '), ARRAY_FILTER_USE_KEY));
        self::assertNotContains(false, $comMemoria['primário']);
        self::assertNotContains(false, $comMemoria['super admin']);
        self::assertNotContains(true, $comMemoria['sem vínculo']);
        self::assertNotContains(true, $comMemoria['vínculo inativo']);
        foreach ($codigos as $codigo) {
            self::assertSame(\in_array($codigo, $doColaborador, true), $comMemoria['colaborador']['permissão ' . $codigo], $codigo);
        }
    }

    #[TestDox('vínculo desativado no mesmo request, sem gravar, passa a recusar')]
    public function testDesativarSemFlushRecusa(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertFalse($checker->canAccessModule($quem, $tenant, 'clientes'));
    }

    #[TestDox('vínculo desativado e gravado no mesmo request passa a recusar')]
    public function testDesativarComFlushRecusa(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('vínculo desativado direto no banco depois do clear passa a recusar (a entrada solta é relida)')]
    public function testDesativarNoBancoDepoisDoClearRecusa(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();
        $vinculoId = $vinculo->getId();
        $quemId = $quem->getId();
        $tenantId = $tenant->getId();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE id = ?', [$vinculoId]);
        $this->em()->clear();
        $quem = $this->em()->find(User::class, $quemId);
        $tenant = $this->em()->find(Tenant::class, $tenantId);
        self::assertNotNull($quem);
        self::assertNotNull($tenant);

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('vínculo removido no mesmo request passa a recusar')]
    public function testRemoverRecusa(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $this->em()->remove($vinculo);
        $this->em()->flush();

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertFalse($checker->canAccessModule($quem, $tenant, 'clientes'));
    }

    #[TestDox('papel trocado por um sem a permissão, no mesmo request, passa a recusar')]
    public function testTrocarPapelRecusa(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $vinculo->setTenantRole($this->novoPapel($tenant, ['modules.ponto.view']));

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO), 'sem gravar');
        self::assertTrue($checker->hasPermission($quem, $tenant, 'modules.ponto.view'), 'sem gravar');
        $this->em()->flush();
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO), 'depois de gravar');
    }

    #[TestDox('papel trocado pelo de sistema, no mesmo request, ganha o bypass')]
    public function testTrocarParaPapelDeSistemaPermite(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([]);
        $checker = $this->checker();

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $vinculo->setTenantRole($this->novoPapel($tenant, [], true));
        $this->em()->flush();

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->isAdministradorDoEscritorio($quem, $tenant));
    }

    #[TestDox('permissão incluída no papel passa a permitir e retirada passa a recusar, no mesmo request')]
    public function testIncluirERetirarPermissaoDoPapel(): void
    {
        [$quem, $tenant, $vinculo] = $this->colaboradorCom([]);
        $checker = $this->checker();
        $papel = $vinculo->getTenantRole();
        self::assertNotNull($papel);

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));

        $ligacao = new TenantRolePermission();
        $ligacao->setTenantRole($papel);
        $ligacao->setPermission($this->permissao(self::PERMISSAO));
        $papel->getTenantRolePermissions()->add($ligacao);
        $this->em()->persist($ligacao);
        $this->em()->flush();
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->canAccessModule($quem, $tenant, 'clientes'));

        $papel->getTenantRolePermissions()->removeElement($ligacao);
        $this->em()->remove($ligacao);
        $this->em()->flush();
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertFalse($checker->canAccessModule($quem, $tenant, 'clientes'));
    }

    #[TestDox('o serviço do container zera a memória no reset entre requests e mensagens (kernel.reset)')]
    public function testResetDoKernelZeraAMemoria(): void
    {
        [$quem, $tenant] = $this->colaboradorCom([self::PERMISSAO]);
        $checker = $this->checker();
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $memoria = new \ReflectionProperty(PermissionChecker::class, 'vinculos');
        self::assertCount(1, $memoria->getValue($checker), 'o vínculo deveria estar guardado');

        static::getContainer()->get('services_resetter')->reset();

        self::assertSame([], $memoria->getValue($checker));
    }

    /**
     * @param list<string> $codigos
     *
     * @return array{0: User, 1: Tenant, 2: UserTenant}
     */
    private function colaboradorCom(array $codigos): array
    {
        $tenant = $this->novoEscritorio();
        $quem = $this->novoUsuario('Ana Equipe');
        $vinculo = $this->vincular($quem, $tenant, $codigos);
        $this->em()->flush();

        return [$quem, $tenant, $vinculo];
    }

    private function checker(): PermissionChecker
    {
        return static::getContainer()->get(PermissionChecker::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function novoEscritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório da memória ' . uniqid());
        $this->em()->persist($tenant);

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function novoUsuario(string $nome, array $papeis = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail('memoria_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('sem-senha');
        $this->em()->persist($user);

        return $user;
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, Tenant $tenant, array $codigos, bool $sistema = false): UserTenant
    {
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($this->novoPapel($tenant, $codigos, $sistema));
        $this->em()->persist($vinculo);

        return $vinculo;
    }

    /** @param list<string> $codigos */
    private function novoPapel(Tenant $tenant, array $codigos, bool $sistema = false): TenantRole
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($sistema ? 'Administrador ' : 'Colaborador ') . uniqid());
        $papel->setIsSystem($sistema);
        $this->em()->persist($papel);
        foreach ($codigos as $codigo) {
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($this->permissao($codigo));
            $papel->getTenantRolePermissions()->add($ligacao);
            $this->em()->persist($ligacao);
        }

        return $papel;
    }

    private function permissao(string $codigo): Permission
    {
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setDescription($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $this->em()->persist($permissao);
        }

        return $permissao;
    }
}
