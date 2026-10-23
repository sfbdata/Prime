<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Controller\TenantRoleController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Controller\TenantUserProfileController;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A conta do Master (ROLE_SUPER_ADMIN) só o próprio Master mexe (spec perfis-master D2;
 * RegraDosPrimarios::podeAlterarConta). O cenário é o pior caso: escritório com criado_por NULO
 * (sem titular para proteger ninguém) e um primário comum agindo sobre o Master, que tem vínculo
 * como sócio (perfil primário) ou como secundário.
 *
 * Cada teste faz UMA requisição de escrita como primeira requisição do cliente: o token CSRF
 * previsível só vale no container dessa primeira requisição.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(TenantUserProfileController::class)]
#[CoversClass(TenantRoleController::class)]
#[CoversClass(RegraDosPrimarios::class)]
final class ContaDoMasterProtegidaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private TenantRole $perfilPrimario;
    private TenantRole $perfilSecundario;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório sem titular ' . uniqid());
        $this->em->persist($this->tenant);

        $this->perfilPrimario   = $this->criarPerfil('Administrador do Escritório', true);
        $this->perfilSecundario = $this->criarPerfil('Advogado (a)', false);
        $this->primario         = $this->criarUsuario('Primário Comum', $this->perfilPrimario);
        $this->em->flush();
    }

    /** @return iterable<string, array{bool}> */
    public static function vinculoDoMaster(): iterable
    {
        yield 'Master como sócio' => [true];
        yield 'Master como secundário' => [false];
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('edit-role: o primário não troca o perfil do Master')]
    public function testPrimarioNaoTrocaPerfilDoMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);
        $outro  = $comoSocio ? $this->perfilSecundario : $this->perfilPrimario;

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->postarEditRole($master, $outro);

        // O select só oferece o perfil atual: outro id é escolha inválida e o form volta (200).
        self::assertResponseIsSuccessful('form recusado é re-renderizado');
        self::assertSame($comoSocio, $this->vinculo($master)->getTenantRole()?->isSystem());
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('edit-role: o primário não troca a senha do Master')]
    public function testPrimarioNaoTrocaSenhaDoMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);
        $perfil = $comoSocio ? $this->perfilPrimario : $this->perfilSecundario;

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->postarEditRole($master, $perfil, 'SenhaNova#2026');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->senhaConfere($master, 'senha123'));
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('o primário não remove o Master do escritório')]
    public function testPrimarioNaoRemoveMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/remover', $this->tenant->getId(), $master->getId()), [
            '_token' => $this->tokenCsrf('remover_' . $master->getId()),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->vinculo($master), 'o vínculo do Master continua');
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('o primário não edita o nome do Master (403)')]
    public function testPrimarioNaoEditaNomeDoMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/edit-name', $this->tenant->getId(), $master->getId()), [
            '_token'   => $this->tokenCsrf('edit_user_name_' . $master->getId()),
            'fullName' => 'Nome Tomado',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame('Master do Sistema', $this->em->find(User::class, $master->getId())?->getFullName());
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('o primário não edita os dados pessoais do Master (403)')]
    public function testPrimarioNaoEditaDadosPessoaisDoMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);
        $url    = sprintf('/tenant/%d/user/%d/dados-pessoais', $this->tenant->getId(), $master->getId());

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', $url, [], [], ['HTTP_REFERER' => 'http://localhost' . $url]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('o próprio Master troca a própria senha pelo edit-role')]
    public function testMasterMexeNaPropriaConta(): void
    {
        $master = $this->criarMaster(false);

        $this->logarComTenant($this->client, $master, $this->tenant);
        $this->postarEditRole($master, $this->perfilSecundario, 'SenhaNova#2026');

        self::assertResponseRedirects();
        self::assertTrue($this->senhaConfere($master, 'SenhaNova#2026'));
    }

    #[TestDox('Perfis: o primário não promove a primário um Master secundário')]
    public function testPerfisNaoPromoveMaster(): void
    {
        $master = $this->criarMaster(false);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', sprintf('/tenant/%d/roles/primarios/promover', $this->tenant->getId()), [
            'usuario' => $master->getId(),
            '_token'  => $this->tokenCsrf('promover_primario_' . $this->tenant->getId()),
        ]);

        self::assertResponseRedirects();
        self::assertFalse($this->vinculo($master)?->getTenantRole()?->isSystem());
    }

    #[TestDox('Perfis: o primário não rebaixa um Master sócio')]
    public function testPerfisNaoRebaixaMaster(): void
    {
        $master = $this->criarMaster(true);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', sprintf('/tenant/%d/roles/primarios/%d/remover', $this->tenant->getId(), $master->getId()), [
            'perfil_destino' => $this->perfilSecundario->getId(),
            '_token'         => $this->tokenCsrf('remover_primario_' . $master->getId()),
        ]);

        self::assertResponseRedirects();
        self::assertTrue($this->vinculo($master)?->getTenantRole()?->isSystem());
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('Perfis: na edição do primário, o Master não tem lixeira nem entra no select de promover')]
    public function testPerfisEscondeAcoesSobreOMaster(bool $comoSocio): void
    {
        $master = $this->criarMaster($comoSocio);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $crawler = $this->client->request('GET', sprintf('/tenant/%d/roles/%d/edit', $this->tenant->getId(), $this->perfilPrimario->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter(sprintf('select[name=usuario] > option[value="%d"]', $master->getId())));
        self::assertCount(0, $crawler->filter(sprintf('form[action$="/primarios/%d/remover"]', $master->getId())));
        if ($comoSocio) {
            self::assertCount(1, $crawler->filter('[data-pf-primarios] > [data-pf-primario]:contains("Master do Sistema")'));
        }
    }

    // ── apoio ──────────────────────────────────────────────────────────────

    /** POST direto (sem GET): o CSRF do form é stateless (valor "csrf-token" + Referer da mesma origem). */
    private function postarEditRole(User $alvo, TenantRole $perfil, ?string $novaSenha = null): void
    {
        $url = sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $alvo->getId());
        $this->client->request('POST', $url, ['edit_user_tenant_role' => [
            'codigoFuncionario' => '',
            'tenantRole'        => (string) $perfil->getId(),
            'newPassword'       => ['first' => $novaSenha ?? '', 'second' => $novaSenha ?? ''],
            '_token'            => 'csrf-token',
        ]], [], ['HTTP_REFERER' => 'http://localhost' . $url]);
    }

    private function tokenCsrf(string $id): string
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return 'TOKEN_' . $id;
    }

    private function criarMaster(bool $comoSocio): User
    {
        $master = $this->criarUsuario('Master do Sistema', $comoSocio ? $this->perfilPrimario : $this->perfilSecundario, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();

        return $master;
    }

    private function vinculo(User $user): ?UserTenant
    {
        $this->em->clear();

        return $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $this->tenant->getId()]);
    }

    private function senhaConfere(User $user, string $senha): bool
    {
        $this->em->clear();
        $recarregado = $this->em->find(User::class, $user->getId());
        self::assertNotNull($recarregado);

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($recarregado, $senha);
    }

    private function criarPerfil(string $nome, bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($nome);
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(string $nome, TenantRole $perfil, array $papeis = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('cm_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);

        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);

        return $user;
    }
}
