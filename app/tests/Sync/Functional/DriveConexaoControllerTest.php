<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Sync\Controller\DriveConexaoController;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\Service\GoogleDriveOAuthInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Sync\Support\FakeGoogleDriveOAuth;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(DriveConexaoController::class)]
final class DriveConexaoControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Drive ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuarioComVinculo(Tenant $tenant, bool $admin = false): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('drive_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Drive');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador do Escritório');
            $role->setIsSystem(true);
            $em->persist($role);
            $vinculo->setTenantRole($role);
        }
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    private function criarConexao(Tenant $tenant, bool $comPastaRaiz): TenantDriveConexao
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $cifrador = static::getContainer()->get(CifradorDeSegredo::class);

        $conexao = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais($cifrador->cifrar('refresh-existente'), 'dono@x.com', 'drive', $this->criarUsuarioComVinculo($tenant));
        if ($comPastaRaiz) {
            $conexao->definirRootFolder('1WBY-hLuad3weKWPBM0f297_RtWTJo0_R');
        }
        $em->persist($conexao);
        $em->flush();

        return $conexao;
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

    private function usarFakeOAuth(FakeGoogleDriveOAuth $fake): void
    {
        static::getContainer()->set(GoogleDriveOAuthInterface::class, $fake);
    }

    private function definirStateNaSessao(KernelBrowser $client, string $state): void
    {
        $session = $client->getSession();
        $session->set('sync_drive_oauth_state', $state);
        $session->save();
    }

    #[TestDox('não-admin recebe 403 na tela de conexão')]
    public function testGateNaoAdmin(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: false);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/sync/drive/conexao');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('admin vê a tela de conexão (sem conexão ainda)')]
    public function testAdminVeTela(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/sync/drive/conexao');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Conectar meu Drive');
    }

    #[TestDox('iniciar redireciona ao Google e guarda o state na sessão')]
    public function testIniciarRedirecionaEGuardaState(): void
    {
        $client = static::createClient();
        $this->usarFakeOAuth(new FakeGoogleDriveOAuth());
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/sync/drive/conexao/iniciar');

        self::assertResponseRedirects();
        self::assertStringContainsString('accounts.google.com', (string) $client->getResponse()->headers->get('Location'));
        self::assertNotEmpty($client->getSession()->get('sync_drive_oauth_state'));
    }

    #[TestDox('callback com state inválido retorna 403 e não cria conexão')]
    public function testCallbackStateInvalido(): void
    {
        $client = static::createClient();
        $this->usarFakeOAuth(new FakeGoogleDriveOAuth());
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->logarComTenant($client, $user, $tenant);
        $this->definirStateNaSessao($client, 'STATE_CERTO');
        $client->request('GET', '/sync/drive/conexao/callback?state=STATE_ERRADO&code=abc');

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]));
    }

    #[TestDox('callback ok cria a conexão com o refresh_token CIFRADO (não em texto puro)')]
    public function testCallbackCriaConexaoCifrada(): void
    {
        $client = static::createClient();
        $this->usarFakeOAuth(new FakeGoogleDriveOAuth(refreshTokenCanned: 'refresh-secreto-123'));
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->logarComTenant($client, $user, $tenant);
        $this->definirStateNaSessao($client, 'S1');
        $client->request('GET', '/sync/drive/conexao/callback?state=S1&code=code-valido');

        self::assertResponseRedirects('/sync/drive/conexao');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexao = $em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($conexao);
        self::assertNotSame('', $conexao->getRefreshTokenCifrado());
        self::assertNotSame('refresh-secreto-123', $conexao->getRefreshTokenCifrado(), 'não pode guardar o token em texto puro');
        // decifra e confere que é o token original
        $cifrador = static::getContainer()->get(CifradorDeSegredo::class);
        self::assertSame('refresh-secreto-123', $cifrador->decifrar($conexao->getRefreshTokenCifrado()));
        // sem pasta-raiz ainda → inativa
        self::assertFalse($conexao->isAtivo());
        self::assertSame('dono@escritorio.com', $conexao->getContaEmail());
    }

    #[TestDox('callback com erro do Google não cria conexão e volta com aviso')]
    public function testCallbackErroDoGoogle(): void
    {
        $client = static::createClient();
        $this->usarFakeOAuth(new FakeGoogleDriveOAuth());
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->logarComTenant($client, $user, $tenant);
        $this->definirStateNaSessao($client, 'S1');
        $client->request('GET', '/sync/drive/conexao/callback?state=S1&error=access_denied');

        self::assertResponseRedirects('/sync/drive/conexao');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]));
    }

    #[TestDox('definir pasta-raiz válida ativa a conexão')]
    public function testDefinirPastaRaizAtiva(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);
        $this->criarConexao($tenant, comPastaRaiz: false);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/sync/drive/conexao/pasta-raiz', [
            '_token'     => 'TOKEN_sync_drive_pasta_raiz',
            'pasta_raiz' => 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz012345',
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexao = $em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertTrue($conexao->isAtivo());
        self::assertSame('1AbCdEfGhIjKlMnOpQrStUvWxYz012345', $conexao->getRootFolderId());
    }

    #[TestDox('definir pasta-raiz inválida não ativa e mantém a conexão inativa')]
    public function testDefinirPastaRaizInvalida(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);
        $this->criarConexao($tenant, comPastaRaiz: false);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/sync/drive/conexao/pasta-raiz', [
            '_token'     => 'TOKEN_sync_drive_pasta_raiz',
            'pasta_raiz' => 'não é um id',
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexao = $em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertFalse($conexao->isAtivo());
        self::assertNull($conexao->getRootFolderId());
    }

    #[TestDox('CSRF inválido em pasta-raiz retorna 403')]
    public function testPastaRaizCsrfInvalido(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);
        $this->criarConexao($tenant, comPastaRaiz: false);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/sync/drive/conexao/pasta-raiz', [
            '_token'     => 'errado',
            'pasta_raiz' => '1AbCdEfGhIjKlMnOpQrStUvWxYz012345',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('desconectar limpa o token e desativa')]
    public function testDesconectar(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComVinculo($tenant, admin: true);
        $this->criarConexao($tenant, comPastaRaiz: true);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/sync/drive/conexao/desconectar', [
            '_token' => 'TOKEN_sync_drive_desconectar',
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $conexao = $em->getRepository(TenantDriveConexao::class)->findOneBy(['tenant' => $tenant]);
        self::assertFalse($conexao->isAtivo());
        self::assertSame('', $conexao->getRefreshTokenCifrado());
    }

    #[TestDox('admin de um escritório não desconecta o Drive de OUTRO (isolamento multi-tenant)')]
    public function testCrossTenantNaoAfetaOutro(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuarioComVinculo($tenantA, admin: true);
        // AMBOS os escritórios têm conexão ativa — só a do tenant atual (A) pode ser afetada.
        $conexaoA = $this->criarConexao($tenantA, comPastaRaiz: true);
        $conexaoB = $this->criarConexao($tenantB, comPastaRaiz: true);

        $this->instalarCsrfStorage();
        // logado no A: o controller resolve o tenant ATUAL (A), nunca o B.
        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('POST', '/sync/drive/conexao/desconectar', [
            '_token' => 'TOKEN_sync_drive_desconectar',
        ]);

        self::assertResponseRedirects('/sync/drive/conexao');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        // O TenantFilter ficou ligado em A durante a request; desligo para ler os dois tenants.
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $aDepois = $em->getRepository(TenantDriveConexao::class)->find($conexaoA->getId());
        $bDepois = $em->getRepository(TenantDriveConexao::class)->find($conexaoB->getId());
        // A (tenant atual) foi desconectado…
        self::assertFalse($aDepois->isAtivo(), 'a conexão do tenant atual (A) deve desligar');
        self::assertSame('', $aDepois->getRefreshTokenCifrado());
        // …mas B (outro tenant) permaneceu intacto.
        self::assertTrue($bDepois->isAtivo(), 'a conexão do tenant B não pode ser afetada');
        self::assertNotSame('', $bDepois->getRefreshTokenCifrado());
    }
}
