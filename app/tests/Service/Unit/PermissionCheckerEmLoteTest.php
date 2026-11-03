<?php

declare(strict_types=1);

namespace App\Tests\Service\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `idsPermitidosComVinculo` (lixeira do escritório) é o MESMO `canAccessResourceComVinculo`, em lote:
 * para cada cenário, a resposta em lote tem de ser exatamente a do item a item, e o lote faz UMA
 * consulta aos acessos item a item.
 */
#[CoversClass(PermissionChecker::class)]
final class PermissionCheckerEmLoteTest extends TestCase
{
    private const PASTAS = [1, 2, 3, 4];

    /** @return iterable<string, array{string}> */
    public static function cenarios(): iterable
    {
        yield 'papel comum sem permissão, acesso item a item em 1 e 2 (2 só ver)' => ['comum'];
        yield 'papel comum com resources.pasta.edit' => ['papel'];
        yield 'perfil de sistema (primário)' => ['sistema'];
        yield 'vínculo inativo' => ['inativo'];
        yield 'vínculo de outra pessoa' => ['outra'];
        yield 'sem vínculo' => ['sem'];
        yield 'Master sem vínculo' => ['master'];
    }

    #[DataProvider('cenarios')]
    #[TestDox('lote = item a item: $cenario')]
    public function testLoteIgualAoItemAItem(string $cenario): void
    {
        $tenant = new Tenant();
        self::id($tenant, 7);
        $quem = self::usuario(10, $cenario === 'master' ? ['ROLE_SUPER_ADMIN'] : ['ROLE_USER']);
        $vinculo = $this->vinculo($cenario, $quem, $tenant);
        // Pasta 3 trancada para todos: o teto do cadeado vale no lote também.
        $trancada = (new Pasta())->setTenant($tenant);
        self::id($trancada, 3);
        $cadeados = [3 => new CadeadoDaPasta($tenant, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable())];

        $acessos = [1 => self::acesso($quem, 1, true), 2 => self::acesso($quem, 2, false), 3 => self::acesso($quem, 3, true)];
        $repo = $this->createMock(ResourceAccessRepository::class);
        $repo->method('findForUserAndResource')->willReturnCallback(static fn (User $u, string $t, int $id): ?ResourceAccess => $acessos[$id] ?? null);
        $repo->expects(self::atMost(1))->method('findForUserAndResources')->willReturnCallback(static fn (User $u, string $t, array $ids): array => array_intersect_key($acessos, array_flip($ids)));

        $checker = $this->checker($repo, $cadeados);
        $esperado = $vinculo === null
            ? array_values(array_filter(self::PASTAS, fn (int $id): bool => $cenario === 'master'))
            : array_values(array_filter(self::PASTAS, static fn (int $id): bool => $checker->canAccessResourceComVinculo($quem, $vinculo, AccessRequest::RESOURCE_PASTA, $id, AccessRequest::ACTION_EDIT)));

        self::assertSame($esperado, $checker->idsPermitidosComVinculo($quem, $vinculo, AccessRequest::RESOURCE_PASTA, self::PASTAS, AccessRequest::ACTION_EDIT));
    }

    #[TestDox('papel comum: só o item com acesso de editar e fora do cadeado (sanidade do cenário)')]
    public function testCenarioComum(): void
    {
        $tenant = new Tenant();
        self::id($tenant, 7);
        $quem = self::usuario(10, ['ROLE_USER']);
        $repo = $this->createStub(ResourceAccessRepository::class);
        $repo->method('findForUserAndResources')->willReturn([1 => self::acesso($quem, 1, true), 2 => self::acesso($quem, 2, false)]);

        self::assertSame([1], $this->checker($repo, [])->idsPermitidosComVinculo($quem, $this->vinculo('comum', $quem, $tenant), AccessRequest::RESOURCE_PASTA, self::PASTAS, AccessRequest::ACTION_EDIT));
    }

    /** @param array<int, CadeadoDaPasta> $cadeados */
    private function checker(ResourceAccessRepository $repo, array $cadeados): PermissionChecker
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $regra = new RegraDosPrimarios($vinculos);
        $cadeadoRepo = $this->createStub(CadeadoDaPastaRepository::class);
        $cadeadoRepo->method('daPastaPorId')->willReturnCallback(static fn (int $id): ?CadeadoDaPasta => $cadeados[$id] ?? null);
        $contexto = new TenantContext(new RequestStack(), $this->createStub(Security::class), $this->createStub(EntityManagerInterface::class), new BloqueioPorPedidoDeExclusao($this->createStub(PedidoDeExclusaoDeContaRepository::class), $regra));

        return new PermissionChecker($repo, $vinculos, new NivelNoCadeado($cadeadoRepo, $vinculos, $regra, $contexto));
    }

    private function vinculo(string $cenario, User $quem, Tenant $tenant): ?UserTenant
    {
        if ($cenario === 'sem' || $cenario === 'master') {
            return null;
        }
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel');
        $papel->setIsSystem($cenario === 'sistema');
        if ($cenario === 'papel') {
            $permissao = new Permission();
            $permissao->setCode('resources.pasta.edit');
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($permissao);
            $papel->getTenantRolePermissions()->add($ligacao);
        }
        $vinculo = new UserTenant($cenario === 'outra' ? self::usuario(99, ['ROLE_USER']) : $quem, $tenant);
        $vinculo->setTenantRole($papel);
        if ($cenario === 'inativo') {
            $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        }

        return $vinculo;
    }

    private static function acesso(User $quem, int $id, bool $edita): ResourceAccess
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($quem);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId($id);
        $acesso->setCanView(true);
        $acesso->setCanEdit($edita);

        return $acesso;
    }

    /** @param list<string> $papeis */
    private static function usuario(int $id, array $papeis): User
    {
        $user = (new User())->setEmail('u' . $id . '@test.com');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        self::id($user, $id);

        return $user;
    }

    private static function id(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }
}
