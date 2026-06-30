<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\EventListener\TenantUrlScopeListener;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * B5 frente 3 — remendo explícito `escoparFiltroNoTenant` (defesa-em-profundidade) nas rotas de
 * escritório que leem dados TenantAware por id: `downloadAnexoJustificativa` e `listUsers`. Estes
 * testes DESLIGAM a trava automática (TenantUrlScopeListener, frente 1) para ISOLAR o remendo —
 * provam que ele sozinho escopa no `{tenantId}` da URL mesmo sem o listener (cenário "listener
 * desabilitado/removido"). Sem desligar a trava, o 404 viria dela e o remendo seria peso morto não
 * testado.
 *
 * `removeResourceAccess` fica de fora do remendo: `ResourceAccess` NÃO é `TenantAware` →
 * `escoparFiltroNoTenant` seria no-op ali; o isolamento real vem na frente 4 (B3). Aqui só travamos
 * o guard atual dessa rota (target user precisa pertencer ao tenant da URL).
 */
#[CoversClass(TenantController::class)]
final class TenantEscopoRemendoControllerTest extends JusPrimeWebTestCase
{
    /** @var string[] caminhos absolutos de arquivos criados, limpos no tearDown */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        $this->arquivosCriados = [];

        parent::tearDown();
    }

    #[TestDox('Remendo: super-admin não baixa atestado de justificativa de outro tenant pela URL errada (trava desligada)')]
    public function testDownloadAnexoNaoVazaCrossTenantComTravaDesligada(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->desligarTrava();

        $tenantA    = $this->criarTenant();
        $tenantB    = $this->criarTenant();
        $user       = $this->criarUsuario([$tenantA, $tenantB]);
        $superAdmin = $this->criarSuperAdmin();
        $justB      = $this->criarJustificativaComAnexo($user, $tenantB, 'atestado_b.pdf');
        $this->limparIdentityMap();

        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        // Via a URL de A: o remendo escopa o filtro em A → find(justB) null → 404.
        // (Sem o remendo + trava desligada, find(justB) retornaria e o PDF de B seria servido.)
        $client->request('GET', "/tenant/{$tenantA->getId()}/user/{$user->getId()}/justificativa/{$justB->getId()}/anexo");
        self::assertResponseStatusCodeSame(404, 'super-admin não pode baixar o atestado de B pela URL de A');

        $this->limparIdentityMap();

        // Controle positivo: pela URL correta (B) o remendo escopa em B → o atestado é servido.
        $client->request('GET', "/tenant/{$tenantB->getId()}/user/{$user->getId()}/justificativa/{$justB->getId()}/anexo");
        self::assertResponseIsSuccessful('pela URL correta (B) o atestado deve ser servido');
    }

    #[TestDox('Remendo: super-admin não vê label de recurso de outro tenant na lista de colaboradores (trava desligada)')]
    public function testListUsersNaoVazaLabelCrossTenantComTravaDesligada(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->desligarTrava();

        $tenantA    = $this->criarTenant();
        $tenantB    = $this->criarTenant();
        $user       = $this->criarUsuario([$tenantA]);
        $superAdmin = $this->criarSuperAdmin();

        $pastaB     = PastaFactory::createOne(['tenant' => $tenantB, 'nup' => 'SECRETO-B-' . uniqid()]);
        $nupSecreto = (string) $pastaB->getNup(); // valor REAL armazenado (a entidade normaliza p/ maiúsculas)
        $this->criarResourceAccess($user, ResourceAccess::RESOURCE_PASTA, (int) $pastaB->getId());
        $this->limparIdentityMap();

        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        // O user é colaborador de A; listUsers de A resolve os labels dos ResourceAccess dele.
        // Sem o remendo (trava desligada) o NUP da pasta de B vazaria; com o remendo vira "Pasta #id".
        $client->request('GET', "/tenant/{$tenantA->getId()}/users");
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            $nupSecreto,
            (string) $client->getResponse()->getContent(),
            'o NUP da pasta do tenant B vazou na lista de colaboradores de A',
        );
    }

    #[TestDox('removeResourceAccess: guarda atual barra grant de usuário fora do tenant da URL (isolamento real = frente 4)')]
    public function testRemoveResourceAccessGuardAtualBarraUsuarioForaDoTenant(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();

        $tenantA    = $this->criarTenant();
        $tenantB    = $this->criarTenant();
        $userB      = $this->criarUsuario([$tenantB]); // só em B
        $superAdmin = $this->criarSuperAdmin();
        $ra         = $this->criarResourceAccess($userB, ResourceAccess::RESOURCE_PASTA, 123);
        $raId       = (int) $ra->getId();
        $this->limparIdentityMap();

        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        // userB não pertence ao tenant A → guard "target user no tenant da URL" → 404.
        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$userB->getId()}/resource-access/{$raId}/remove", [
            '_token' => 'TOKEN_remove_resource_access_' . $raId,
        ]);
        self::assertResponseStatusCodeSame(404, 'não pode remover grant de usuário que não é do tenant da URL');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(ResourceAccess::class, $raId), 'o ResourceAccess não deveria ter sido removido');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Remove o TenantUrlScopeListener (frente 1) do dispatcher para isolar o remendo desta frente.
     * Exige disableReboot() antes, senão o reboot do kernel re-registra o listener a cada request.
     */
    private function desligarTrava(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');

        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $alvo = is_array($listener) ? $listener[0] : $listener;
            if ($alvo instanceof TenantUrlScopeListener) {
                $dispatcher->removeListener(KernelEvents::REQUEST, $listener);
            }
        }

        // Sanidade: a trava não pode mais estar registrada, senão o teste não isola o remendo.
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $alvo = is_array($listener) ? $listener[0] : $listener;
            self::assertFalse($alvo instanceof TenantUrlScopeListener, 'a trava deveria ter sido desligada para isolar o remendo');
        }
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant REMENDO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param Tenant[] $tenants */
    private function criarUsuario(array $tenants): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('remendo_' . uniqid() . '@test.com');
        $user->setFullName('User Remendo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
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
        $user->setEmail('superadmin_remendo_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Remendo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarJustificativaComAnexo(User $user, Tenant $tenant, string $nomeArquivo): JustificativaPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('pendente');
        $justificativa->setAnexoPath($nomeArquivo);
        $em->persist($justificativa);
        $em->flush();

        // Escreve o arquivo real no diretório de uploads de teste (storage é final → sem stub).
        $dir = (string) static::getContainer()->getParameter('justificativas_uploads_dir');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $caminho = $dir . '/' . $nomeArquivo;
        file_put_contents($caminho, '%PDF-1.4 atestado de teste');
        $this->arquivosCriados[] = $caminho;

        return $justificativa;
    }

    private function criarResourceAccess(User $user, string $type, int $resourceId): ResourceAccess
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setResourceType($type);
        $ra->setResourceId($resourceId);
        $ra->setCanView(true);
        $em->persist($ra);
        $em->flush();

        return $ra;
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
}
