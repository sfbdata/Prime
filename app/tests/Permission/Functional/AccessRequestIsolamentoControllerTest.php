<?php

declare(strict_types=1);

namespace App\Tests\Permission\Functional;

use App\Controller\AccessRequestController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\AccessRequestRepository;
use App\Repository\ResourceAccessRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Isolamento das solicitações de acesso por escritório via HTTP (M2). Vetor da auditoria: um
 * usuário multi-escritório cria a solicitação num escritório; o admin do OUTRO não pode vê-la
 * no painel nem aprová-la/negá-la (concessão sem autoridade sobre o recurso).
 */
#[CoversClass(AccessRequestController::class)]
final class AccessRequestIsolamentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('submit grava a solicitação com o tenant do escritório ativo (sessão)')]
    public function testSubmitDefineTenantDaSessao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        // Role NÃO-system: canAccessResource retorna false → o submit prossegue.
        $user = $this->criarUsuario($tenant, isSystem: false);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/access-requests/submit', [
            '_token'       => 'TOKEN_access_request_submit',
            'resourceType' => AccessRequest::RESOURCE_CLIENTE,
            'resourceId'   => 4242,
            'action'       => AccessRequest::ACTION_VIEW,
            'description'  => 'Preciso ver este cliente',
        ]);

        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        $req = $em->getRepository(AccessRequest::class)->findOneBy(['resourceId' => 4242]);

        self::assertNotNull($req, 'a solicitação deveria ter sido criada');
        self::assertSame($tenant->getId(), $req->getTenant()?->getId(), 'a solicitação deveria herdar o tenant da sessão');
    }

    #[TestDox('approve de solicitação de outro escritório responde 404 e não concede acesso')]
    public function testApproveDeOutroEscritorioDa404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $solicitante = $this->criarUsuario($tenantA, isSystem: false);
        $req = $this->criarAccessRequest($solicitante, $tenantA, 555);
        $idReq = (int) $req->getId();

        // Admin do escritório B (role system → tem a permissão de aprovar no PRÓPRIO tenant).
        $adminB = $this->criarUsuario($tenantB, isSystem: true);
        $this->logarComTenant($client, $adminB, $tenantB);

        $client->request('POST', "/access-requests/{$idReq}/approve", [
            'canView' => '1',
        ]);

        self::assertResponseStatusCodeSame(404, 'admin de B não pode aprovar solicitação de A');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        $reqDepois = $em->find(AccessRequest::class, $idReq);
        self::assertTrue($reqDepois->isPending(), 'a solicitação não pode ter sido aprovada');

        $resourceAccessRepo = static::getContainer()->get(ResourceAccessRepository::class);
        self::assertNull(
            $resourceAccessRepo->findForUserAndResource(
                $em->find(User::class, $solicitante->getId()),
                AccessRequest::RESOURCE_CLIENTE,
                555,
            ),
            'nenhum ResourceAccess pode ter sido concedido',
        );
    }

    #[TestDox('deny de solicitação de outro escritório responde 404 e mantém pendente')]
    public function testDenyDeOutroEscritorioDa404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $solicitante = $this->criarUsuario($tenantA, isSystem: false);
        $req = $this->criarAccessRequest($solicitante, $tenantA, 666);
        $idReq = (int) $req->getId();

        $adminB = $this->criarUsuario($tenantB, isSystem: true);
        $this->logarComTenant($client, $adminB, $tenantB);

        $client->request('POST', "/access-requests/{$idReq}/deny");

        self::assertResponseStatusCodeSame(404, 'admin de B não pode negar solicitação de A');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        self::assertTrue($em->find(AccessRequest::class, $idReq)->isPending(), 'a solicitação deveria seguir pendente');
    }

    #[TestDox('O painel do admin não lista solicitação feita em outro escritório')]
    public function testIndexNaoVazaSolicitacaoDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        $solicitante = $this->criarUsuario($tenantA, isSystem: false, nome: 'Solicitante Secreto De A');
        $this->criarAccessRequest($solicitante, $tenantA, 777);

        // Admin de B: NÃO deve ver a solicitação de A.
        $adminB = $this->criarUsuario($tenantB, isSystem: true);
        $this->logarComTenant($client, $adminB, $tenantB);
        $client->request('GET', '/access-requests');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Solicitante Secreto De A',
            (string) $client->getResponse()->getContent(),
            'vazou solicitação de A no painel de B',
        );

        // Admin de A: DEVE ver a solicitação do próprio escritório.
        $adminA = $this->criarUsuario($tenantA, isSystem: true);
        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('GET', '/access-requests');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Solicitante Secreto De A',
            (string) $client->getResponse()->getContent(),
            'o painel do próprio escritório deveria listar a solicitação',
        );
    }

    #[TestDox('submit do mesmo recurso em escritórios diferentes gera solicitações distintas (anti-duplicata é per-tenant)')]
    public function testSubmitDuplicadoEscopaPorTenant(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        // Mesmo usuário, vínculo NÃO-system nos dois escritórios (canAccessResource = false em ambos).
        $user = $this->criarUsuario($tenantA, isSystem: false);
        $this->vincular($user, $tenantB, isSystem: false);

        $payload = [
            '_token'       => 'TOKEN_access_request_submit',
            'resourceType' => AccessRequest::RESOURCE_CLIENTE,
            'resourceId'   => 8888,
            'action'       => AccessRequest::ACTION_VIEW,
        ];

        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', '/access-requests/submit', $payload);
        self::assertResponseIsSuccessful('1ª solicitação (escritório A)');

        $this->logarComTenant($client, $user, $tenantB);
        $client->request('POST', '/access-requests/submit', $payload);
        self::assertResponseIsSuccessful('2ª solicitação (escritório B) — anti-duplicata é per-tenant, não pode bloquear');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();
        $reqs = $em->getRepository(AccessRequest::class)->findBy(['resourceId' => 8888]);

        self::assertCount(2, $reqs, 'deveria existir uma solicitação por escritório');
        $tenantIds = array_map(static fn (AccessRequest $r) => $r->getTenant()?->getId(), $reqs);
        sort($tenantIds);
        $esperados = [$tenantA->getId(), $tenantB->getId()];
        sort($esperados);
        self::assertSame($esperados, $tenantIds, 'as duas solicitações devem pertencer a A e B');
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
        $tenant->setName('Tenant AR HTTP ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, bool $isSystem, string $nome = 'Usuário AR'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('ar_http_' . uniqid() . '@test.com');
        $user->setFullName($nome);
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

    private function criarAccessRequest(User $user, Tenant $tenant, int $resourceId): AccessRequest
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $req = (new AccessRequest())
            ->setUser($user)
            ->setTenant($tenant)
            ->setResourceType(AccessRequest::RESOURCE_CLIENTE)
            ->setResourceId($resourceId)
            ->setAction(AccessRequest::ACTION_VIEW);
        $em->persist($req);
        $em->flush();

        return $req;
    }
}
