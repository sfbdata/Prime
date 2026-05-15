<?php
declare(strict_types=1);

namespace App\Tests\Controller\Tenant;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TenantController::class)]
final class DeleteSedeCrossTenantTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CT ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAtacante(Tenant $tenantVinculo): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('ct_atacante_' . uniqid() . '@test.com');
        $user->setFullName('Atacante CrossTenant');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenantVinculo));
        $em->flush();

        return $user;
    }

    private function criarSede(Tenant $tenant): Sede
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $sede = new Sede();
        $sede->setNome('Sede CT ' . uniqid());
        $sede->setLatitude('-22.90680000');
        $sede->setLongitude('-43.17290000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $em->persist($sede);
        $em->flush();

        return $sede;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    #[TestDox('POST deleteSede de tenant B por usuário sem vínculo em B retorna 403')]
    public function testUsuarioSemVinculoEmTenantBNaoConsegueDeletarSedeDeB(): void
    {
        $client = static::createClient();

        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $atacante = $this->criarAtacante($tenantA);
        $sede     = $this->criarSede($tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $atacante, $tenantA);

        $client->request('POST', "/tenant/{$tenantB->getId()}/sedes/{$sede->getId()}/delete", [
            '_token' => $this->gerarCsrf('delete_sede_' . $sede->getId()),
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
            'Esperado 403: atacante não tem vínculo em tenant B'
        );

        $sedeAindaExiste = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->find(Sede::class, $sede->getId());

        self::assertNotNull($sedeAindaExiste, 'Sede não deveria ter sido deletada');
    }
}