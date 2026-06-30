<?php

declare(strict_types=1);

namespace App\Tests\Permission\Functional;

use App\Controller\AccessRequestController;
use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\ResourceAccessRepository;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Isolamento dos acessos granulares (ResourceAccess) por escritório (frente 4 / B3). Agora que
 * ResourceAccess é TenantAware, o TenantFilter escopa TODAS as suas queries:
 *  - `removeResourceAccess` (find por id) → fecha o write/IDOR cross-tenant que a frente 3 deixou
 *    aberto para o usuário multi-tenant (a guarda de posse do user não bastava);
 *  - `findForUserAndResource` (usada por PermissionChecker::canAccessResource) → a checagem de
 *    autorização passa a respeitar o tenant da sessão;
 *  - `approve` grava o tenant da sessão no acesso concedido.
 * Mutação da classe: remover `implements TenantAware` de ResourceAccess → o filtro deixa de tocá-la
 * → os 3 testes ficam vermelhos.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(AccessRequestController::class)]
#[CoversClass(ResourceAccessRepository::class)]
final class ResourceAccessIsolamentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('removeResourceAccess: admin de A não remove (via URL de A) o grant de um usuário multi-tenant a um recurso de B → 404')]
    public function testRemoveResourceAccessCrossTenantFecha(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();

        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, isSystem: true);   // admin do escritório A (o ator)
        $alvo    = $this->criarUsuario($tenantA, isSystem: false);  // usuário multi-tenant (o alvo)...
        $this->vincular($alvo, $tenantB, isSystem: false);          // ...também vinculado a B
        $raB     = $this->criarResourceAccess($alvo, $tenantB, ResourceAccess::RESOURCE_PASTA, 4242); // grant em B
        $raId    = (int) $raB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$alvo->getId()}/resource-access/{$raId}/remove", [
            '_token' => 'TOKEN_remove_resource_access_' . $raId,
        ]);

        self::assertResponseStatusCodeSame(404, 'o grant de B (find escopado em A) deve dar 404');

        // O grant de B permanece intacto (verificado com o filtro desligado).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        self::assertNotNull($em->find(ResourceAccess::class, $raId), 'o grant do escritório B foi removido pela URL de A');
    }

    #[TestDox('findForUserAndResource (base do canAccessResource) só enxerga o grant do tenant filtrado')]
    public function testFindForUserAndResourceEscopadoPeloFiltro(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $alvo    = $this->criarUsuario($tenantA, isSystem: false);
        $this->criarResourceAccess($alvo, $tenantA, ResourceAccess::RESOURCE_PASTA, 555); // grant em A
        $this->limparIdentityMap();

        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $repo = static::getContainer()->get(ResourceAccessRepository::class);

        // Filtro no tenant A → encontra o grant.
        $this->pinarFiltro($em, $tenantA);
        self::assertNotNull(
            $repo->findForUserAndResource($alvo, ResourceAccess::RESOURCE_PASTA, 555),
            'com o filtro em A o grant de A deveria ser encontrado',
        );

        // Filtro no tenant B → o grant de A some (a autorização não vaza entre escritórios).
        $em->clear();
        $this->pinarFiltro($em, $tenantB);
        self::assertNull(
            $repo->findForUserAndResource($alvo, ResourceAccess::RESOURCE_PASTA, 555),
            'com o filtro em B o grant de A NÃO pode ser encontrado',
        );
    }

    #[TestDox('approve grava o ResourceAccess com o tenant da sessão do admin')]
    public function testApproveGravaTenant(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();

        $tenant      = $this->criarTenant();
        $admin       = $this->criarUsuario($tenant, isSystem: true);
        $solicitante = $this->criarUsuario($tenant, isSystem: false);
        $pasta       = PastaFactory::createOne(['tenant' => $tenant]); // recurso REAL do escritório
        $pastaId     = (int) $pasta->getId();
        $req         = $this->criarAccessRequest($solicitante, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId);
        $idReq       = (int) $req->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/access-requests/{$idReq}/approve", [
            '_token'  => 'TOKEN_approve_request_' . $idReq,
            'canView' => '1',
        ]);

        self::assertResponseRedirects('/access-requests');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        $ra = $em->getRepository(ResourceAccess::class)->findOneBy(['resourceId' => $pastaId]);

        self::assertNotNull($ra, 'o ResourceAccess deveria ter sido concedido');
        self::assertSame($tenant->getId(), $ra->getTenant()?->getId(), 'o acesso deveria herdar o tenant da sessão do admin');
    }

    // ----------------------------------------------------------------- helpers

    private function pinarFiltro(EntityManagerInterface $em, Tenant $tenant): void
    {
        $em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
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

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant RA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, bool $isSystem): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('ra_' . uniqid() . '@test.com');
        $user->setFullName('Usuário RA');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);
        $em->flush();

        $this->vincular($user, $tenant, $isSystem);

        return $user;
    }

    private function vincular(User $user, Tenant $tenant, bool $isSystem): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem($isSystem);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
    }

    private function criarResourceAccess(User $user, Tenant $tenant, string $type, int $resourceId): ResourceAccess
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType($type);
        $ra->setResourceId($resourceId);
        $ra->setCanView(true);
        $em->persist($ra);
        $em->flush();

        return $ra;
    }

    private function criarAccessRequest(User $user, Tenant $tenant, string $resourceType, int $resourceId): AccessRequest
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $req = (new AccessRequest())
            ->setUser($user)
            ->setTenant($tenant)
            ->setResourceType($resourceType)
            ->setResourceId($resourceId)
            ->setAction(AccessRequest::ACTION_VIEW);
        $em->persist($req);
        $em->flush();

        return $req;
    }
}
