<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\AdminConviteController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(AdminConviteController::class)]
final class AdminConviteControllerTest extends JusPrimeWebTestCase
{
    private function criarSuperAdmin(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('superadmin_convite_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Convite');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarUsuarioComTenant(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new \App\Entity\Tenant\Tenant();
        $tenant->setName('Tenant Admin Test ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('user_comum_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Comum');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new \App\Entity\Auth\UserTenant($user, $tenant);
        $em->persist($ut);
        $em->flush();

        return ['user' => $user, 'tenant' => $tenant];
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

    #[TestDox('GET /admin/platform/convites sem autenticação redireciona para login')]
    public function testGetSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/platform/convites');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /admin/platform/convites como ROLE_USER retorna 403')]
    public function testGetComoUserComumRetorna403(): void
    {
        $client = static::createClient();
        ['user' => $user, 'tenant' => $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/admin/platform/convites');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('GET /admin/platform/convites como SuperAdmin retorna 200')]
    public function testGetComoSuperAdminRetorna200(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/admin/platform/convites');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('POST criar sem CSRF retorna 403')]
    public function testPostCriarSemCsrfRetorna403(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', '/admin/platform/convites/novo', [
            'email'    => 'novo@example.com',
            '_token'   => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST criar com email novo cria convite e redireciona')]
    public function testPostCriarEmailNovoCriaConvite(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $email = 'plataforma_novo_' . uniqid() . '@example.com';
        $client->request('POST', '/admin/platform/convites/novo', [
            'email'    => $email,
            'full_name' => 'Novo Convidado',
            '_token'   => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/admin/platform/convites');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => $email]);
        self::assertNotNull($invitation);
        self::assertSame('platform', $invitation->getType());
        self::assertSame('pending', $invitation->getStatus());
    }

    #[TestDox('POST criar com email já existente redireciona com flash de erro')]
    public function testPostCriarEmailExistenteRedirecionaComErro(): void
    {
        $client    = static::createClient();
        $admin     = $this->criarSuperAdmin();
        ['user' => $existente] = $this->criarUsuarioComTenant();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('POST', '/admin/platform/convites/novo', [
            'email'  => $existente->getEmail(),
            '_token' => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/admin/platform/convites');

        $client->followRedirect();
        self::assertSelectorExists('.alert-erro');
    }

    #[TestDox('POST revogar convite pending altera status para revoked')]
    public function testPostRevogarConvitePending(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'revogar_plataforma_' . uniqid() . '@example.com',
            tokenHash: Invitation::hashDoToken(bin2hex(random_bytes(16))),
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/admin/platform/convites/' . $invitation->getId() . '/revogar', [
            '_token' => $this->gerarCsrf('convite_revogar_' . $invitation->getId()),
        ]);

        self::assertResponseRedirects('/admin/platform/convites');

        $em->clear();
        $atualizado = $em->find(Invitation::class, $invitation->getId());
        self::assertSame('revoked', $atualizado->getStatus());
    }

    #[TestDox('POST reenviar convite válido incrementa reenvioCount')]
    public function testPostReenviarConviteValidoIncrementaContador(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'reenviar_plataforma_' . uniqid() . '@example.com',
            tokenHash: Invitation::hashDoToken(bin2hex(random_bytes(16))),
            type: 'platform',
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/admin/platform/convites/' . $invitation->getId() . '/reenviar', [
            '_token' => $this->gerarCsrf('convite_reenviar_' . $invitation->getId()),
        ]);

        self::assertResponseRedirects('/admin/platform/convites');

        $em->clear();
        $atualizado = $em->find(Invitation::class, $invitation->getId());
        self::assertSame(1, $atualizado->getReenvioCount());
    }

    #[TestDox('POST revogar convite de escritório pelo SuperAdmin retorna 404')]
    public function testPostRevogarConviteEscritorioRetorna404(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'escritorio_' . uniqid() . '@example.com',
            tokenHash: Invitation::hashDoToken(bin2hex(random_bytes(16))),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/admin/platform/convites/' . $invitation->getId() . '/revogar', [
            '_token' => $this->gerarCsrf('convite_revogar_' . $invitation->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('A lista da plataforma não leva o token; revogar e reenviar vão pelo id, com o e-mail fora do onclick')]
    public function testListaNaoLevaOToken(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $convite = new Invitation(
            email: 'lista_plataforma_' . uniqid() . '@example.com',
            tokenHash: Invitation::hashDoToken(bin2hex(random_bytes(32))),
            type: Invitation::TIPO_PLATAFORMA,
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $em->persist($convite);
        $em->flush();

        $client->request('GET', '/admin/platform/convites');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($convite->getEmail(), $html);
        self::assertStringNotContainsString($convite->getTokenHash(), $html);
        self::assertSelectorExists(sprintf('form[action="/admin/platform/convites/%d/revogar"][data-confirmar="Revogar convite de %s?"]', $convite->getId(), $convite->getEmail()));
        self::assertSelectorExists(sprintf('form[action="/admin/platform/convites/%d/reenviar"][data-confirmar="Reenviar convite para %s?"]', $convite->getId(), $convite->getEmail()));
        self::assertSelectorNotExists(sprintf('[onclick*="%s"]', $convite->getEmail()));
    }

    #[TestDox('E-mail inválido no convite de plataforma é recusado com flash de erro e nada é gravado')]
    public function testEmailInvalidoNaoCriaConvite(): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $email = 'invalido_' . uniqid() . '@example.com?cc=outro@example.com';
        $client->request('POST', '/admin/platform/convites/novo', [
            'email'  => $email,
            '_token' => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/admin/platform/convites');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-erro', 'Informe um e-mail válido.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Invitation::class)->findOneBy(['email' => $email]));
    }

    /** @return iterable<string, array{string}> */
    public static function acoesDeGestao(): iterable
    {
        yield 'revogar' => ['revogar'];
        yield 'reenviar' => ['reenviar'];
    }

    #[DataProvider('acoesDeGestao')]
    #[TestDox('CSRF inválido: revogar ou reenviar convite de plataforma pelo id dá 403 e nada muda')]
    public function testCsrfInvalidoNaoMuda(string $acao): void
    {
        $client = static::createClient();
        $admin  = $this->criarSuperAdmin();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $convite = new Invitation(
            email: 'csrf_plataforma_' . uniqid() . '@example.com',
            tokenHash: Invitation::hashDoToken(bin2hex(random_bytes(32))),
            type: Invitation::TIPO_PLATAFORMA,
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $em->persist($convite);
        $em->flush();

        $client->request('POST', sprintf('/admin/platform/convites/%d/%s', $convite->getId(), $acao), ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $intacto = $em->find(Invitation::class, $convite->getId());
        self::assertSame('pending', $intacto->getStatus());
        self::assertSame(0, $intacto->getReenvioCount());
    }
}
