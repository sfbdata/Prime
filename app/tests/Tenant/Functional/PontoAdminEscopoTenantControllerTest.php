<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Escopo por-URL das rotas admin de ponto (MÉDIO + C6). O TenantFilter é ligado pelo tenant da
 * SESSÃO; estas rotas operam sobre o tenant da URL. As rotas re-apontam o filtro para o {tenantId}
 * da URL após o guard de acesso. Estes dois vetores eram CEGOS na suíte (dev sem user multi-tenant;
 * testes logavam admin no mesmo tenant da rota).
 */
#[CoversClass(TenantController::class)]
final class PontoAdminEscopoTenantControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('MÉDIO: a folha usa o tenant da URL, não o da sessão (admin multi-tenant)')]
    public function testFolhaUsaTenantDaUrlNaoDaSessao(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarUsuario('admin', [$tenantA, $tenantB], admin: true);
        $alvo    = $this->criarUsuario('alvo', [$tenantA, $tenantB], admin: false);

        $registroA = $this->criarBatida($alvo, $tenantA, '2026-03-10 08:01:00');
        $registroB = $this->criarBatida($alvo, $tenantB, '2026-03-10 14:02:00');
        $this->limparIdentityMap();

        // sessão = A, mas a URL aponta para B
        $this->logarComTenant($client, $admin, $tenantA);
        $client->request('GET', "/tenant/{$tenantB->getId()}/user/{$alvo->getId()}/edit-role?competencia=2026-03&tab=ponto");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("/ponto/{$registroB->getId()}/edit", $body, 'a folha de B deveria listar a batida de B');
        self::assertStringNotContainsString("/ponto/{$registroA->getId()}/edit", $body, 'a folha de B vazou a batida do escritório A (tenant da sessão)');
    }

    #[TestDox('C6: super-admin sem tenant na sessão não carrega batida de outro tenant pela URL errada (404)')]
    public function testSuperAdminNaoCarregaBatidaDeOutroTenantPorId(): void
    {
        $client     = static::createClient();
        $tenantX    = $this->criarTenant();
        $tenantY    = $this->criarTenant();
        $superAdmin = $this->criarSuperAdmin();
        $alvo       = $this->criarUsuario('alvo', [$tenantX, $tenantY], admin: false);
        $registroX  = $this->criarBatida($alvo, $tenantX, '2026-03-11 09:00:00');
        $this->limparIdentityMap();

        // super-admin SEM tenant na sessão → o TenantFilter ficaria desligado (a frestinha)
        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        // batida de X carregada via a URL de Y → filtro re-apontado p/ Y → find() null → 404
        $client->request('GET', "/tenant/{$tenantY->getId()}/user/{$alvo->getId()}/ponto/{$registroX->getId()}/edit");
        self::assertResponseStatusCodeSame(404, 'super-admin não pode carregar batida de outro tenant pela URL errada');

        // controle positivo: pela URL correta (X) a batida carrega
        $client->request('GET', "/tenant/{$tenantX->getId()}/user/{$alvo->getId()}/ponto/{$registroX->getId()}/edit");
        self::assertResponseIsSuccessful();
    }

    #[TestDox('MUTAÇÃO: admin multi-tenant (sessão=A) não exclui pela URL de B uma batida que é de A')]
    public function testMutacaoMultiTenantNaoTocaBatidaDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarUsuario('admin', [$tenantA, $tenantB], admin: true);
        $alvo    = $this->criarUsuario('alvo', [$tenantA, $tenantB], admin: false);
        $registroA = $this->criarBatida($alvo, $tenantA, '2026-03-12 08:00:00');
        $idA = (int) $registroA->getId();

        $this->instalarCsrfStorage();
        $this->limparIdentityMap();

        // sessão=A; tenta EXCLUIR via a URL de B a batida que pertence a A
        $this->logarComTenant($client, $admin, $tenantA);
        $client->request('POST', "/tenant/{$tenantB->getId()}/user/{$alvo->getId()}/ponto/{$idA}/delete", [
            '_token' => $this->gerarCsrf('delete_ponto_' . $idA),
        ]);

        self::assertResponseStatusCodeSame(404, 'excluir via a URL de B uma batida de A deve dar 404');

        // a batida de A permanece intacta (verificado com o filtro desligado)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(RegistroPonto::class, $idA), 'a batida do escritório A foi excluída via a URL de B');
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

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Cria um usuário com vínculo ativo em cada tenant. Com $admin=true, cada vínculo recebe um
     * TenantRole isSystem (canAdminister=true naquele tenant) — multi-tenant de propósito.
     *
     * @param Tenant[] $tenants
     */
    private function criarUsuario(string $prefixo, array $tenants, bool $admin): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' Teste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        foreach ($tenants as $tenant) {
            $userTenant = new UserTenant($user, $tenant);
            if ($admin) {
                $role = new TenantRole();
                $role->setTenant($tenant);
                $role->setName('Admin ' . uniqid());
                $role->setIsSystem(true);
                $em->persist($role);
                $userTenant->setTenantRole($role);
            }
            $em->persist($userTenant);
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

    private function criarBatida(User $user, Tenant $tenant, string $dataHora, string $tipo = RegistroPonto::TIPO_ENTRADA): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setTipo($tipo);
        $em->persist($registro);
        $em->flush();

        return $registro;
    }
}
