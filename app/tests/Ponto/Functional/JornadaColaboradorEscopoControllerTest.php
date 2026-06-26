<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Escopo por-URL das rotas de jornada do colaborador. O guard de vínculo do usuário-alvo era
 * pulado para super-admin (que roda sem tenant na sessão) → super-admin podia ler/mutar a jornada
 * de QUALQUER alvo. Agora o alvo precisa ter vínculo no tenant da URL, inclusive para super-admin.
 */
#[CoversClass(JornadaColaboradorController::class)]
final class JornadaColaboradorEscopoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Super-admin não lê nem muta jornada de alvo sem vínculo no tenant da URL (404); lê no tenant correto')]
    public function testSuperAdminEscopadoPelaUrlNaJornada(): void
    {
        $client     = static::createClient();
        $tenantX    = $this->criarTenant();
        $tenantY    = $this->criarTenant();
        $superAdmin = $this->criarSuperAdmin();
        $alvo       = $this->criarUsuario([$tenantX]); // vínculo só em X
        $this->limparIdentityMap();

        // super-admin sem tenant na sessão (o guard de vínculo era pulado para ele)
        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        // alvo não tem vínculo em Y → ler a jornada dele pela URL de Y dá 404
        $client->request('GET', "/tenant/{$tenantY->getId()}/user/{$alvo->getId()}/jornada-colaborador");
        self::assertResponseStatusCodeSame(404, 'super-admin não pode ler jornada de alvo sem vínculo no tenant da URL');

        // mutação (DELETE) pela URL de Y também é barrada
        $client->request('DELETE', "/tenant/{$tenantY->getId()}/user/{$alvo->getId()}/jornada-colaborador");
        self::assertResponseStatusCodeSame(404, 'super-admin não pode mutar jornada de alvo sem vínculo no tenant da URL');

        // controle positivo: pela URL de X (onde o alvo tem vínculo) a leitura funciona
        $client->request('GET', "/tenant/{$tenantX->getId()}/user/{$alvo->getId()}/jornada-colaborador");
        self::assertResponseIsSuccessful();
    }

    // ----------------------------------------------------------------- helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant JORNADA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param Tenant[] $tenants */
    private function criarUsuario(array $tenants): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Jornada');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        foreach ($tenants as $tenant) {
            $em->persist(new UserTenant($user, $tenant));
        }
        $em->flush();

        return $user;
    }

    private function criarSuperAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('superadmin_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
