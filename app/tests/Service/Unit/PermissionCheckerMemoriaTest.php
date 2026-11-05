<?php

declare(strict_types=1);

namespace App\Tests\Service\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Memória do vínculo no `PermissionChecker` (spec `permission-checker-memoria.md`, D1): a segunda pergunta
 * do mesmo usuário no mesmo escritório não vai ao banco; o que o EntityManager não gerencia mais, o
 * "não encontrado", a entidade sem id e o `reset()` voltam a consultar.
 */
#[CoversClass(PermissionChecker::class)]
final class PermissionCheckerMemoriaTest extends TestCase
{
    private const PERMISSAO = 'modules.clientes.view';

    #[TestDox('a segunda chamada usa o vínculo guardado e não consulta o banco de novo')]
    public function testSegundaChamadaNaoConsultaDeNovo(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $vinculo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        $repo->expects(self::once())->method('findOneBy')->willReturn($vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->canAccessModule($quem, $tenant, 'clientes'));
        self::assertFalse($checker->hasPermission($quem, $tenant, 'modules.ponto.view'));
    }

    #[TestDox('a entrada que o EntityManager não gerencia mais é consultada de novo')]
    public function testEntradaNaoGerenciadaConsultaDeNovo(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $antigo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $novo = self::vinculo($quem, $tenant, []);
        $repo = $this->repo();
        $repo->expects(self::exactly(2))->method('findOneBy')->willReturnOnConsecutiveCalls($antigo, $novo);
        $repo->expects(self::once())->method('continuaGerenciado')->with(self::identicalTo($antigo))->willReturn(false);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        // O vínculo novo (relido do banco) não tem a permissão: prova que o antigo saiu da memória.
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('o vínculo desativado depois da primeira chamada recusa na segunda')]
    public function testVinculoDesativadoRecusa(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $vinculo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        $repo->method('findOneBy')->willReturn($vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertFalse($checker->canAccessModule($quem, $tenant, 'clientes'));
    }

    #[TestDox('o perfil de sistema desativado depois da primeira chamada perde o bypass')]
    public function testSistemaDesativadoPerdeOBypass(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $vinculo = self::vinculo($quem, $tenant, [], true);
        $repo = $this->repo();
        $repo->method('findOneBy')->willReturn($vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->isAdministradorDoEscritorio($quem, $tenant));
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        self::assertFalse($checker->isAdministradorDoEscritorio($quem, $tenant));
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('"não encontrado" não é guardado: cada chamada consulta o banco')]
    public function testNaoEncontradoNaoEGuardado(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $vinculo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        // O vínculo criado no meio do request aparece na terceira chamada.
        $repo->expects(self::exactly(3))->method('findOneBy')->willReturnOnConsecutiveCalls(null, null, $vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertFalse($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('reset() limpa a memória e a próxima chamada consulta de novo')]
    public function testResetConsultaDeNovo(): void
    {
        [$quem, $tenant] = $this->pessoaEEscritorio();
        $vinculo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        $repo->expects(self::exactly(2))->method('findOneBy')->willReturn($vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        $checker->reset();
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    #[TestDox('usuário sem id não é guardado')]
    public function testUsuarioSemIdNaoEGuardado(): void
    {
        $tenant = new Tenant();
        self::id($tenant, 7);
        $quem = self::usuario(null);
        $this->assertDuasConsultas($quem, $tenant);
    }

    #[TestDox('escritório sem id não é guardado')]
    public function testEscritorioSemIdNaoEGuardado(): void
    {
        $this->assertDuasConsultas(self::usuario(10), new Tenant());
    }

    #[TestDox('outro escritório tem a própria entrada: as chaves não se misturam')]
    public function testChavesDeEscritoriosDiferentesNaoSeMisturam(): void
    {
        [$quem, $tenantA] = $this->pessoaEEscritorio();
        $tenantB = new Tenant();
        self::id($tenantB, 8);
        $vinculoA = self::vinculo($quem, $tenantA, [self::PERMISSAO]);
        $vinculoB = self::vinculo($quem, $tenantB, []);
        $repo = $this->repo();
        $repo->expects(self::exactly(2))->method('findOneBy')->willReturnCallback(
            static fn (array $criterios): UserTenant => $criterios['tenant'] === $tenantA ? $vinculoA : $vinculoB,
        );
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenantA, self::PERMISSAO));
        self::assertFalse($checker->hasPermission($quem, $tenantB, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($quem, $tenantA, self::PERMISSAO));
        self::assertFalse($checker->hasPermission($quem, $tenantB, self::PERMISSAO));
    }

    #[TestDox('outro usuário no mesmo escritório tem a própria entrada')]
    public function testChavesDeUsuariosDiferentesNaoSeMisturam(): void
    {
        [$ana, $tenant] = $this->pessoaEEscritorio();
        $bia = self::usuario(11);
        $vinculoAna = self::vinculo($ana, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        $repo->expects(self::exactly(3))->method('findOneBy')->willReturnCallback(
            static fn (array $criterios): ?UserTenant => $criterios['user'] === $ana ? $vinculoAna : null,
        );
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($ana, $tenant, self::PERMISSAO));
        self::assertFalse($checker->hasPermission($bia, $tenant, self::PERMISSAO));
        self::assertFalse($checker->hasPermission($bia, $tenant, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($ana, $tenant, self::PERMISSAO));
    }

    private function assertDuasConsultas(User $quem, Tenant $tenant): void
    {
        $vinculo = self::vinculo($quem, $tenant, [self::PERMISSAO]);
        $repo = $this->repo();
        $repo->expects(self::exactly(2))->method('findOneBy')->willReturn($vinculo);
        $repo->method('continuaGerenciado')->willReturn(true);
        $checker = $this->checker($repo);

        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
        self::assertTrue($checker->hasPermission($quem, $tenant, self::PERMISSAO));
    }

    /** @return array{0: User, 1: Tenant} */
    private function pessoaEEscritorio(): array
    {
        $tenant = new Tenant();
        self::id($tenant, 7);

        return [self::usuario(10), $tenant];
    }

    /** @return UserTenantRepository&MockObject */
    private function repo(): UserTenantRepository
    {
        return $this->createMock(UserTenantRepository::class);
    }

    private function checker(UserTenantRepository $vinculos): PermissionChecker
    {
        $outros = $this->createStub(UserTenantRepository::class);
        $regra = new RegraDosPrimarios($outros);
        $contexto = new TenantContext(new RequestStack(), $this->createStub(Security::class), $this->createStub(EntityManagerInterface::class), new BloqueioPorPedidoDeExclusao($this->createStub(PedidoDeExclusaoDeContaRepository::class), $regra));

        return new PermissionChecker(
            $this->createStub(ResourceAccessRepository::class),
            $vinculos,
            new NivelNoCadeado($this->createStub(CadeadoDaPastaRepository::class), $outros, $regra, $contexto),
        );
    }

    /** @param list<string> $codigos */
    private static function vinculo(User $quem, Tenant $tenant, array $codigos, bool $sistema = false): UserTenant
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel');
        $papel->setIsSystem($sistema);
        foreach ($codigos as $codigo) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($permissao);
            $papel->getTenantRolePermissions()->add($ligacao);
        }
        $vinculo = new UserTenant($quem, $tenant);
        $vinculo->setTenantRole($papel);

        return $vinculo;
    }

    private static function usuario(?int $id): User
    {
        $user = (new User())->setEmail('u' . ($id ?? 0) . '@test.com');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        if ($id !== null) {
            self::id($user, $id);
        }

        return $user;
    }

    private static function id(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }
}
