<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Prova, via HTTP, que tornar Pasta TenantAware fecha o IDOR das rotas por id: um gestor
 * isSystem (que bypassa o PermissionChecker) de outro tenant recebe 404 ao carregar a pasta
 * pelo ParamConverter — é o filtro na camada de dados que fecha, não a permissão.
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Peticionar (show) de pasta de outro tenant retorna 404')]
    public function testPeticionarIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $pastaB = $this->criarPasta($tenantB);
        $id = (int) $pastaB->getId();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/pasta/{$id}/peticionar");

        self::assertResponseStatusCodeSame(404, 'peticionar não pode revelar pasta de outro tenant');
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PET ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('NUP-PET-' . (++$this->seq) . '-' . substr(uniqid(), -6));
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
