<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\GerenciarConvitesController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(GerenciarConvitesController::class)]
final class GerenciarConvitesControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Convites ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPermissaoConvite(): Permission
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.invite']);

        if ($perm !== null) {
            return $perm;
        }

        $perm = new Permission();
        $perm->setCode('admin.users.invite');
        $perm->setDescription('Convidar usuários para o tenant');
        $perm->setGroup('admin');
        $em->persist($perm);
        $em->flush();

        return $perm;
    }

    private function criarRoleComPermissaoConvite(Tenant $tenant): TenantRole
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $this->criarPermissaoConvite();

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor Convites ' . uniqid());
        $em->persist($role);

        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);

        $em->flush();

        return $role;
    }

    private function criarAdminComPermissao(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = $this->criarRoleComPermissaoConvite($tenant);

        $user = new User();
        $user->setEmail('admin_inv_' . uniqid() . '@test.com');
        $user->setFullName('Admin Convites');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    private function criarUsuarioSemPermissao(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sem_perm_' . uniqid() . '@test.com');
        $user->setFullName('Sem Permissão');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $em->persist($ut);

        $em->flush();

        return $user;
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

    #[TestDox('GET /escritorio/convites sem autenticação redireciona para login')]
    public function testGetSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/escritorio/convites');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /escritorio/convites sem permissão admin.users.invite retorna 403')]
    public function testGetSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioSemPermissao($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/escritorio/convites');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('GET /escritorio/convites com permissão retorna 200')]
    public function testGetComPermissaoRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/escritorio/convites');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.aviso-email-a-confirmar', 'conta confirmada não vê o aviso nem o botão de reenvio');
    }

    #[TestDox('POST criar com email novo cria convite de escritório')]
    public function testPostCriarEmailNovoCriaConviteEscritorio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $role   = $this->criarRoleComPermissaoConvite($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $roles  = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $roleId = $roles[0]->getId();

        $email = 'office_novo_' . uniqid() . '@example.com';
        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $email,
            'full_name'      => 'Novo Colaborador',
            'tenant_role_id' => $roleId,
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $em->clear();
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => $email]);
        self::assertNotNull($invitation);
        self::assertSame('office', $invitation->getType());
        self::assertSame('pending', $invitation->getStatus());
    }

    #[TestDox('POST criar com membro ativo redireciona com flash de erro')]
    public function testPostCriarMembroAtivoRedirecionaComErro(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $roles  = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $roleId = $roles[0]->getId();

        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $user->getEmail(),
            'tenant_role_id' => $roleId,
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $client->followRedirect();
        self::assertSelectorExists('.alert-erro');
    }

    #[TestDox('POST revogar convite pending do tenant altera status para revoked')]
    public function testPostRevogarConvitePending(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'revogar_office_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(16)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/escritorio/convites/' . $invitation->getId() . '/revogar', [
            '_token' => $this->gerarCsrf('convite_revogar_' . $invitation->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $em->clear();
        $atualizado = $em->find(Invitation::class, $invitation->getId());
        self::assertSame('revoked', $atualizado->getStatus());
    }

    #[TestDox('POST reenviar com limite atingido redireciona com flash de erro')]
    public function testPostReenviarComLimiteAtingidoRedirecionaComErro(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'limite_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(16)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/escritorio/convites/' . $invitation->getId() . '/reenviar', [
            '_token' => $this->gerarCsrf('convite_reenviar_' . $invitation->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $client->followRedirect();
        self::assertSelectorExists('.alert-erro');
    }

    /**
     * Desde a spec `cadastro-exige-confirmacao.md`, a conta a confirmar não fica logada: a sessão termina antes do
     * controller. A recusa do caso de uso fica como defesa, provada no `CriarConviteEscritorioUseCaseTest`.
     */
    #[TestDox('E-mail a confirmar: a sessão termina antes de criar o convite, e nada é gravado nem enviado')]
    public function testEmailAConfirmarNaoCriaConvite(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->marcarEmailAConfirmar($user);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $roles = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $email = 'a_confirmar_' . uniqid() . '@example.com';

        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $email,
            'tenant_role_id' => $roles[0]->getId(),
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/login');
        self::assertNull($client->getContainer()->get('security.token_storage')->getToken());
        self::assertEmailCount(0);
        $em->clear();
        self::assertNull($em->getRepository(Invitation::class)->findOneBy(['email' => $email]));
    }

    /** Mesmo caso do anterior; a recusa do caso de uso se prova no `ReenviarConviteUseCaseTest`. */
    #[TestDox('E-mail a confirmar: a sessão termina antes de reenviar o convite, e o convite não muda')]
    public function testEmailAConfirmarNaoReenviaConvite(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->marcarEmailAConfirmar($user);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'reenvio_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(16)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/escritorio/convites/' . $invitation->getId() . '/reenviar', [
            '_token' => $this->gerarCsrf('convite_reenviar_' . $invitation->getId()),
        ]);

        self::assertResponseRedirects('/login');
        self::assertNull($client->getContainer()->get('security.token_storage')->getToken());
        self::assertEmailCount(0);
        $em->clear();
        self::assertSame(0, $em->find(Invitation::class, $invitation->getId())->getReenvioCount());
    }

    private function criarConvite(Tenant $tenant, ?TenantRole $perfil = null): Invitation
    {
        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'convidado_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(32)),
            type: Invitation::TIPO_ESCRITORIO,
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $invitation->setTenantRole($perfil);
        $em->persist($invitation);
        $em->flush();

        return $invitation;
    }

    /** Perfil com uma permissão que o gestor de convites (só admin.users.invite) não tem: fora do alcance dele (C5). */
    private function criarPerfilAcimaDoGestor(Tenant $tenant): TenantRole
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.manage']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('admin.users.manage');
            $perm->setDescription('Gerenciar usuários');
            $perm->setGroup('admin');
            $em->persist($perm);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Acima do gestor ' . uniqid());
        $em->persist($role);
        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);
        $em->flush();

        return $role;
    }

    #[TestDox('A lista de quem gerencia não leva o token do convite; revogar e reenviar vão pelo id, com o e-mail fora do onclick')]
    public function testListaNaoLevaOToken(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $user    = $this->criarAdminComPermissao($tenant);
        $convite = $this->criarConvite($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/escritorio/convites');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($convite->getEmail(), $html);
        self::assertStringNotContainsString($convite->getToken(), $html);
        self::assertSelectorExists(sprintf('form[action="/escritorio/convites/%d/revogar"][data-confirmar*="%s"]', $convite->getId(), $convite->getEmail()));
        self::assertSelectorExists(sprintf('form[action="/escritorio/convites/%d/reenviar"][data-confirmar*="%s"]', $convite->getId(), $convite->getEmail()));
        self::assertSelectorNotExists(sprintf('[onclick*="%s"]', $convite->getEmail()));
    }

    #[TestDox('Reenviar pelo id conta o reenvio')]
    public function testReenviarPeloId(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $user    = $this->criarAdminComPermissao($tenant);
        $convite = $this->criarConvite($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/escritorio/convites/' . $convite->getId() . '/reenviar', [
            '_token' => $this->gerarCsrf('convite_reenviar_' . $convite->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/convites');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, $em->find(Invitation::class, $convite->getId())->getReenvioCount());
    }

    /** @return iterable<string, array{string}> */
    public static function acoesDeGestao(): iterable
    {
        yield 'revogar' => ['revogar'];
        yield 'reenviar' => ['reenviar'];
    }

    #[DataProvider('acoesDeGestao')]
    #[TestDox('Convite de outro escritório: revogar e reenviar pelo id respondem 404 e nada muda')]
    public function testConviteDeOutroEscritorioE404(string $acao): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $alheio = $this->criarConvite($this->criarTenant());
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', sprintf('/escritorio/convites/%d/%s', $alheio->getId(), $acao), [
            '_token' => $this->gerarCsrf(sprintf('convite_%s_%d', $acao, $alheio->getId())),
        ]);

        self::assertResponseStatusCodeSame(404);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $intacto = $em->find(Invitation::class, $alheio->getId());
        self::assertSame('pending', $intacto->getStatus());
        self::assertSame(0, $intacto->getReenvioCount());
    }

    #[TestDox('C5: convite de perfil acima de quem gerencia não aparece na lista; o de perfil que cabe aparece')]
    public function testConviteDePerfilAcimaNaoApareceNaLista(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $acima  = $this->criarConvite($tenant, $this->criarPerfilAcimaDoGestor($tenant));
        $cabe   = $this->criarConvite($tenant, $this->criarRoleComPermissaoConvite($tenant));
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/escritorio/convites');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($acima->getEmail(), $html);
        self::assertStringContainsString($cabe->getEmail(), $html);
    }

    #[DataProvider('acoesDeGestao')]
    #[TestDox('C5: revogar ou reenviar convite de perfil acima de quem gerencia dá 404 e nada muda')]
    public function testConviteDePerfilAcimaE404(string $acao): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $acima  = $this->criarConvite($tenant, $this->criarPerfilAcimaDoGestor($tenant));
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', sprintf('/escritorio/convites/%d/%s', $acima->getId(), $acao), [
            '_token' => $this->gerarCsrf(sprintf('convite_%s_%d', $acao, $acima->getId())),
        ]);

        self::assertResponseStatusCodeSame(404);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $intacto = $em->find(Invitation::class, $acima->getId());
        self::assertSame('pending', $intacto->getStatus());
        self::assertSame(0, $intacto->getReenvioCount());
    }

    #[TestDox('E-mail inválido na criação é recusado com flash de erro e nada é gravado')]
    public function testEmailInvalidoNaoCriaConvite(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $roles = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $email = 'invalido_' . uniqid() . '@example.com,outro@example.com';

        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $email,
            'tenant_role_id' => $roles[0]->getId(),
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/escritorio/convites');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-erro', 'Informe um e-mail válido.');
        $em->clear();
        self::assertNull($em->getRepository(Invitation::class)->findOneBy(['email' => $email]));
    }

    private function marcarEmailAConfirmar(User $user): void
    {
        $user->marcarEmailAConfirmar(new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    #[DataProvider('acoesDeGestao')]
    #[TestDox('CSRF inválido: revogar ou reenviar pelo id dá 403 e nada muda')]
    public function testCsrfInvalidoNaoMuda(string $acao): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $user    = $this->criarAdminComPermissao($tenant);
        $convite = $this->criarConvite($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', sprintf('/escritorio/convites/%d/%s', $convite->getId(), $acao), ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $intacto = $em->find(Invitation::class, $convite->getId());
        self::assertSame('pending', $intacto->getStatus());
        self::assertSame(0, $intacto->getReenvioCount());
    }
}
