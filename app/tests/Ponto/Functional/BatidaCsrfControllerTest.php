<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C4a — `ponto_batida` (registro de batida de ponto, risco ALTO) passa a exigir token CSRF no
 * header X-CSRF-Token. Sem token → 403; com token → passa o CSRF e cai na validação de payload.
 */
#[CoversClass(PontoController::class)]
final class BatidaCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('ponto/batida sem token CSRF retorna 403')]
    public function testBatidaSemTokenRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        $client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['tipo' => 'entrada']));

        self::assertResponseStatusCodeSame(403, 'batida sem CSRF deveria ser barrada');
    }

    #[TestDox('ponto/batida com token CSRF passa pela validação CSRF (cai em 422 do payload)')]
    public function testBatidaComTokenPassaCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        // payload sem 'tipo' → se o CSRF passar, cai na validação de payload (422 'tipo obrigatório').
        // Um 403 aqui significaria que o CSRF barrou indevidamente.
        $client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode(['latitude' => null]));

        self::assertResponseStatusCodeSame(422, 'com token, a batida deveria passar o CSRF e cair na validação de payload');
    }

    // ----------------------------------------------------------------- helpers

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

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CSRF BATIDA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarSuperAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('superadmin_batida_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Batida');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        // vínculo com o tenant para que a sessão (logarComTenant) seja aceita e o módulo resolva
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }
}
