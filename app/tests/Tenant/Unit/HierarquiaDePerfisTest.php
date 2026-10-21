<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Hierarquia de perfis (spec perfis-1.2-permissoes-e-hierarquia C1 a C5; desenho 05 - Perfis 1.2, L720-724).
 */
#[CoversClass(HierarquiaDePerfis::class)]
final class HierarquiaDePerfisTest extends TestCase
{
    private UserTenantRepository&MockObject $vinculos;
    private PermissionChecker&MockObject $checker;
    private HierarquiaDePerfis $hierarquia;
    private Tenant $tenant;
    private TenantRole $perfilDoSistema;
    /** @var array<int, UserTenant> vínculos ativos por id do objeto do usuário */
    private array $vinculosAtivos = [];

    protected function setUp(): void
    {
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn (User $u): ?UserTenant => $this->vinculosAtivos[spl_object_id($u)] ?? null
        );
        $this->checker  = $this->createMock(PermissionChecker::class);
        $this->hierarquia = new HierarquiaDePerfis(
            new RegraDosMasters($this->createMock(ContasDoSistemaRepository::class)),
            new RegraDosPrimarios($this->vinculos),
            $this->vinculos,
            $this->checker,
        );
        $this->tenant = new Tenant();
        $this->perfilDoSistema = (new TenantRole())->setIsSystem(true);
    }

    #[TestDox('C1: nível 3 o Master, 2 o primário ativo, 1 o secundário; perfil 2 o de sistema, 1 os demais')]
    public function testNiveis(): void
    {
        [$secundario] = $this->secundario(['admin.roles.manage']);
        $primario = $this->primario();

        self::assertSame(3, $this->hierarquia->nivelDoAtor($this->master(), $this->tenant));
        self::assertSame(2, $this->hierarquia->nivelDoAtor($primario, $this->tenant));
        self::assertSame(1, $this->hierarquia->nivelDoAtor($secundario, $this->tenant));
        self::assertSame(2, $this->hierarquia->nivelDoPerfil($this->perfilDoSistema));
        self::assertSame(1, $this->hierarquia->nivelDoPerfil(new TenantRole()));
    }

    #[TestDox('C2: a lista é do primário, do Master e de quem tem admin.roles.manage; o secundário não vê o perfil de sistema')]
    public function testVerALista(): void
    {
        [$comGestao] = $this->secundario(['admin.roles.manage']);
        $this->checker->method('canAdminister')->willReturnCallback(
            static fn (User $u, ?Tenant $t, string $p): bool => $u === $comGestao && $p === 'admin.roles.manage'
        );

        self::assertTrue($this->hierarquia->podeVerALista($comGestao, $this->tenant));
        self::assertTrue($this->hierarquia->podeVerALista($this->master(), $this->tenant));
        self::assertFalse($this->hierarquia->podeVerALista($this->usuario(), $this->tenant));
        self::assertFalse($this->hierarquia->podeVerOPerfil($comGestao, $this->tenant, $this->perfilDoSistema));
        self::assertTrue($this->hierarquia->podeVerOPerfil($comGestao, $this->tenant, new TenantRole()));
    }

    #[TestDox('C3: o secundário com admin.roles.manage não cria, não edita nem exclui perfil, nem o próprio')]
    public function testSecundarioSoLeitura(): void
    {
        [$secundario, $proprio] = $this->secundario(['admin.roles.manage']);
        $outro = new TenantRole();

        self::assertFalse($this->hierarquia->podeCriarPerfil($secundario, $this->tenant));
        foreach ([$proprio, $outro] as $perfil) {
            self::assertFalse($this->hierarquia->podeEditarPerfil($secundario, $this->tenant, $perfil));
            self::assertFalse($this->hierarquia->podeExcluirPerfil($secundario, $this->tenant, $perfil));
        }
        self::assertFalse($this->hierarquia->podeEditarPerfil($secundario, $this->tenant, $this->perfilDoSistema));
    }

    #[TestDox('C3/C4: primário e Master criam, editam e excluem secundários; o perfil de sistema abre a gestão de primários e nunca é excluído')]
    public function testPrimarioEMaster(): void
    {
        foreach ([$this->primario(), $this->master()] as $ator) {
            self::assertTrue($this->hierarquia->podeCriarPerfil($ator, $this->tenant));
            self::assertTrue($this->hierarquia->podeEditarPerfil($ator, $this->tenant, new TenantRole()));
            self::assertTrue($this->hierarquia->podeExcluirPerfil($ator, $this->tenant, new TenantRole()));
            self::assertTrue($this->hierarquia->podeEditarPerfil($ator, $this->tenant, $this->perfilDoSistema));
            self::assertFalse($this->hierarquia->podeExcluirPerfil($ator, $this->tenant, $this->perfilDoSistema));
            self::assertTrue($this->hierarquia->podeTrocarOProprioPerfil($ator, $this->tenant));
        }
    }

    #[TestDox('C5: o secundário só atribui perfil contido no dele (o próprio inclusive), nunca o de sistema, e não troca o próprio')]
    public function testSubconjunto(): void
    {
        [$secundario] = $this->secundario(['modules.clientes.view', 'admin.users.manage']);

        self::assertTrue($this->hierarquia->podeAtribuirPerfil($secundario, $this->tenant, $this->perfil(['modules.clientes.view'])));
        self::assertTrue($this->hierarquia->podeAtribuirPerfil($secundario, $this->tenant, $this->perfil(['admin.users.manage', 'modules.clientes.view'])));
        self::assertTrue($this->hierarquia->podeAtribuirPerfil($secundario, $this->tenant, $this->perfil([])));
        self::assertFalse($this->hierarquia->podeAtribuirPerfil($secundario, $this->tenant, $this->perfil(['modules.clientes.view', 'admin.roles.manage'])));
        self::assertFalse($this->hierarquia->podeAtribuirPerfil($secundario, $this->tenant, $this->perfilDoSistema));
        self::assertFalse($this->hierarquia->podeTrocarOProprioPerfil($secundario, $this->tenant));
    }

    #[TestDox('C5: sem vínculo ativo, só o perfil vazio cabe; primário e Master atribuem qualquer secundário')]
    public function testSemVinculoEPrimario(): void
    {
        $semVinculo = $this->usuario();

        self::assertTrue($this->hierarquia->podeAtribuirPerfil($semVinculo, $this->tenant, $this->perfil([])));
        self::assertFalse($this->hierarquia->podeAtribuirPerfil($semVinculo, $this->tenant, $this->perfil(['modules.clientes.view'])));

        $tudo = $this->perfil(['admin.roles.manage', 'admin.users.manage', 'modules.clientes.view']);
        self::assertTrue($this->hierarquia->podeAtribuirPerfil($this->primario(), $this->tenant, $tudo));
        self::assertTrue($this->hierarquia->podeAtribuirPerfil($this->master(), $this->tenant, $tudo));
    }

    #[TestDox('C5 no edit-role: o secundário vê só o próprio perfil para si; para outro, o atual e o que cabe no dele')]
    public function testPerfisAtribuiveis(): void
    {
        [$secundario, $proprio] = $this->secundario(['modules.clientes.view', 'admin.users.manage']);
        $contido = $this->perfil(['modules.clientes.view']);
        $acima   = $this->perfil(['admin.roles.manage']);
        $atualDoAlvo = $this->perfil(['admin.audit.view']);
        $alvo = $this->usuario();

        self::assertSame([$proprio], $this->hierarquia->perfisAtribuiveis($secundario, $this->tenant, $secundario, [$proprio, $contido, $acima], $proprio));
        self::assertSame(
            [$contido, $atualDoAlvo],
            $this->hierarquia->perfisAtribuiveis($secundario, $this->tenant, $alvo, [$contido, $acima, $atualDoAlvo], $atualDoAlvo),
            'o perfil atual do alvo fica (manter não é atribuir); o acima sai'
        );
        self::assertSame([$contido, $acima], $this->hierarquia->perfisAtribuiveis($this->primario(), $this->tenant, $alvo, [$contido, $acima], null));
    }

    #[TestDox('C5 no POST do edit-role: recusa a troca do próprio perfil e o perfil acima; manter o atual nunca é recusado')]
    public function testRecusaAoAtribuir(): void
    {
        [$secundario, $proprio] = $this->secundario(['modules.clientes.view']);
        $contido = $this->perfil(['modules.clientes.view']);
        $acima   = $this->perfil(['admin.roles.manage']);
        $alvo    = $this->usuario();

        self::assertSame(HierarquiaDePerfis::RECUSA_PROPRIO_PERFIL, $this->hierarquia->recusaAoAtribuir($secundario, $this->tenant, $secundario, $proprio, $contido));
        self::assertNull($this->hierarquia->recusaAoAtribuir($secundario, $this->tenant, $secundario, $proprio, $proprio));
        self::assertSame(HierarquiaDePerfis::RECUSA_PERFIL_ACIMA_DO_SEU, $this->hierarquia->recusaAoAtribuir($secundario, $this->tenant, $alvo, $contido, $acima));
        self::assertNull($this->hierarquia->recusaAoAtribuir($secundario, $this->tenant, $alvo, $acima, $acima));
        self::assertNull($this->hierarquia->recusaAoAtribuir($secundario, $this->tenant, $alvo, $acima, $contido));
        self::assertNull($this->hierarquia->recusaAoAtribuir($this->primario(), $this->tenant, $this->primario(), $this->perfilDoSistema, $acima));
    }

    private function usuario(): User
    {
        return (new User())->setEmail(uniqid('h_') . '@teste.com')->setIsActive(true);
    }

    private function master(): User
    {
        return $this->usuario()->setRoles(['ROLE_SUPER_ADMIN']);
    }

    private function primario(): User
    {
        $user = $this->usuario();
        $this->vincular($user, $this->perfilDoSistema);

        return $user;
    }

    /**
     * @param list<string> $codigos
     *
     * @return array{0: User, 1: TenantRole}
     */
    private function secundario(array $codigos): array
    {
        $user   = $this->usuario();
        $perfil = $this->perfil($codigos);
        $this->vincular($user, $perfil);

        return [$user, $perfil];
    }

    private function vincular(User $user, TenantRole $perfil): void
    {
        $this->vinculosAtivos[spl_object_id($user)] = (new UserTenant($user, $this->tenant))->setTenantRole($perfil);
    }

    /** @param list<string> $codigos */
    private function perfil(array $codigos): TenantRole
    {
        $perfil = new TenantRole();
        foreach ($codigos as $codigo) {
            $trp = (new TenantRolePermission())->setTenantRole($perfil)->setPermission((new Permission())->setCode($codigo));
            $perfil->getTenantRolePermissions()->add($trp);
        }

        return $perfil;
    }
}
