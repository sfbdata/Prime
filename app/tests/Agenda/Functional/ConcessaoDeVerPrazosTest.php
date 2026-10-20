<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Service\PermissoesDaAgenda;
use App\DataFixtures\PermissionFixture;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261020134100;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A permissão "Ver prazos na agenda" nasce ligada (spec agenda-prazos-permissao): roda o SQL da migration
 * sobre perfis de teste. As migrations não estão no autoload do composer, daí o require_once.
 */
#[CoversNothing]
final class ConcessaoDeVerPrazosTest extends KernelTestCase
{
    private Connection $banco;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../migrations/Version20261020134100.php';
    }

    protected function setUp(): void
    {
        $this->banco = $this->em()->getConnection();
    }

    #[TestDox('Recebe quem vê pastas e o perfil de sistema; não recebe quem não vê pastas; rodar de novo não repete')]
    public function testConcessao(): void
    {
        $tenant = (new Tenant())->setName('Tenant VP ' . uniqid());
        $this->em()->persist($tenant);
        $comPastas = $this->perfil($tenant, false, ['modules.agenda.view', 'modules.pastas.view']);
        $sistema = $this->perfil($tenant, true, []);
        $semPastas = $this->perfil($tenant, false, ['modules.agenda.view']);
        $this->em()->flush();

        $this->conceder();
        $this->conceder();

        self::assertSame(1, $this->concessoes($comPastas));
        self::assertSame(1, $this->concessoes($sistema));
        self::assertSame(0, $this->concessoes($semPastas));
    }

    #[TestDox('Código, descrição e grupo da migration são os da PermissionFixture e da regra da Agenda')]
    public function testMesmoCatalogoDaFixture(): void
    {
        $daFixture = array_values(array_filter(
            (new \ReflectionClassConstant(PermissionFixture::class, 'PERMISSIONS'))->getValue(),
            static fn (array $p): bool => PermissoesDaAgenda::VER_PRAZOS === $p['code'],
        ));

        self::assertSame(PermissoesDaAgenda::VER_PRAZOS, Version20261020134100::CODIGO);
        self::assertSame([['code' => Version20261020134100::CODIGO, 'description' => Version20261020134100::DESCRICAO, 'group' => Version20261020134100::GRUPO]], $daFixture);
    }

    private function conceder(): void
    {
        $this->banco->executeStatement(Version20261020134100::SQL_PERMISSAO, ['codigo' => Version20261020134100::CODIGO, 'descricao' => Version20261020134100::DESCRICAO, 'grupo' => Version20261020134100::GRUPO]);
        $this->banco->executeStatement(Version20261020134100::SQL_CONCESSAO, ['codigo' => Version20261020134100::CODIGO]);
    }

    private function concessoes(TenantRole $perfil): int
    {
        return (int) $this->banco->fetchOne(
            'SELECT COUNT(*) FROM tenant_role_permission trp JOIN permission p ON p.id = trp.permission_id WHERE trp.tenant_role_id = :perfil AND p.code = :codigo',
            ['perfil' => $perfil->getId(), 'codigo' => Version20261020134100::CODIGO],
        );
    }

    /** @param list<string> $codigos */
    private function perfil(Tenant $tenant, bool $sistema, array $codigos): TenantRole
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName('Perfil ' . uniqid())->setIsSystem($sistema);
        $this->em()->persist($perfil);
        foreach ($codigos as $codigo) {
            $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]) ?? (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup('modules');
            $this->em()->persist($permissao);
            $concessao = new TenantRolePermission();
            $concessao->setTenantRole($perfil);
            $concessao->setPermission($permissao);
            $this->em()->persist($concessao);
        }

        return $perfil;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
