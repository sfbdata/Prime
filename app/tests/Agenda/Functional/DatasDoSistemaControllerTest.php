<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\DatasDoSistemaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\Feriado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(DatasDoSistemaController::class)]
final class DatasDoSistemaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Devolve as datas do período com as UFs e os feriados do Ponto do próprio escritório')]
    public function testDevolveDatasDoEscritorio(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant('AL');
        $tenantB = $this->criarTenant('SP');
        $gestorA = $this->criarUsuario($tenantA, true);
        $this->criarFeriado($tenantA, 'Padroeira de A', '2000-08-28');
        $this->criarFeriado($tenantB, 'Padroeira de B', '2000-08-27');
        $totalAntes = $this->contarFeriados();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/agenda/datas?de=2026-08-01&ate=2026-08-31');

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['AL'], $json['ufs']);
        $titulos = array_column($json['datas'], 'titulo', 'data');
        self::assertSame('Padroeira de A', $titulos['2026-08-28']);
        self::assertArrayNotHasKey('2026-08-27', $titulos, 'feriado do Ponto de outro escritório não pode vazar');
        self::assertSame('Dia dos Pais', $titulos['2026-08-09']);
        self::assertSame($totalAntes, $this->contarFeriados(), 'a Agenda não pode gravar na tabela feriado do Ponto');
    }

    #[TestDox('Período inválido ou longo demais devolve 400')]
    public function testPeriodoInvalido(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant(null);
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        foreach (['', '?de=2026-13-01&ate=2026-12-31', '?de=2026-12-31&ate=2026-01-01', '?de=2026-01-01&ate=2027-12-31'] as $q) {
            $client->request('GET', '/agenda/datas' . $q);
            self::assertResponseStatusCodeSame(400, 'consulta: ' . $q);
        }
    }

    #[TestDox('Sem acesso ao módulo agenda devolve 403')]
    public function testSemPermissao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant(null);
        $this->logarComTenant($client, $this->criarUsuario($tenant, false), $tenant);

        $client->request('GET', '/agenda/datas?de=2026-08-01&ate=2026-08-31');

        self::assertResponseStatusCodeSame(403);
    }

    private function criarTenant(?string $estado): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant DATAS ' . uniqid());
        $tenant->setEstado($estado);
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, bool $gestor): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('datas_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem($gestor);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarFeriado(Tenant $tenant, string $nome, string $data): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $feriado = (new Feriado())->setNome($nome)->setData(new \DateTime($data))->setRecorrente(true);
        $feriado->setTenant($tenant);
        $em->persist($feriado);
        $em->flush();
    }

    private function contarFeriados(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM feriado');
    }
}
