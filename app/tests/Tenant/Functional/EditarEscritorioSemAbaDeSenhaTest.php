<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A aba "Alterar Senha" saiu de Editar escritório (spec docs/specs/remover-aba-senha-do-escritorio.md).
 * Nunca funcionou na v2 (500 no POST) e, consertada, deixaria um secundário com
 * admin.tenant.settings.manage trocar a senha de um primário. A troca de senha de outra pessoa
 * continua em editar colaborador (edit-role), guardada pela RegraDosPrimarios.
 *
 * Cenário: escritório com o primário TITULAR, um primário SÓCIO e uma secundária "Advogada".
 */
#[CoversClass(TenantController::class)]
final class EditarEscritorioSemAbaDeSenhaTest extends JusPrimeWebTestCase
{
    private const SENHA_NOVA = 'SenhaNova#2026';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private TenantRole $perfilPrimario;
    private TenantRole $perfilAdvogado;
    private User $titular;
    private User $socio;
    private User $advogado;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório sem aba de senha ' . uniqid());
        $this->em->persist($this->tenant);

        $this->perfilPrimario = $this->criarPerfil('Administrador do Escritório', true);
        $this->perfilAdvogado = $this->criarPerfil('Advogado (a)', false);

        $this->titular  = $this->criarUsuario('Titular Primário', $this->perfilPrimario);
        $this->socio    = $this->criarUsuario('Sócio Primário', $this->perfilPrimario);
        $this->advogado = $this->criarUsuario('Advogada Secundária', $this->perfilAdvogado);
        $this->tenant->setCriadoPor($this->titular);
        $this->em->flush();
    }

    #[TestDox('Editar escritório não tem mais a aba, o painel nem campo de senha; as outras três abas seguem')]
    public function testAbaDeSenhaNaoExisteMais(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', $this->urlEditar());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#tab-senha-btn'), 'botão da aba');
        self::assertCount(0, $crawler->filter('#tab-senha'), 'painel da aba');
        self::assertCount(0, $crawler->filter('input[name^="tenant_password"]'), 'campos do form antigo');
        self::assertCount(0, $crawler->filter('#editTabs input[type=password], #editTabsContent input[type=password]'));
        self::assertStringNotContainsString('Alterar Senha', $crawler->filter('#editTabsContent')->text());

        foreach (['#tab-dados-btn', '#tab-cargos-btn', '#tab-lotacoes-btn'] as $aba) {
            self::assertCount(1, $crawler->filter($aba), "a aba {$aba} continua");
        }
    }

    #[TestDox('POST com os campos do formulário antigo não troca a senha de ninguém e não dá 500')]
    public function testPostDoFormularioAntigoNaoTrocaSenha(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $url = $this->urlEditar();

        // CSRF stateless: valor "csrf-token" + Referer da mesma origem. Antes da remoção este POST
        // chegava ao findOneBy(['tenantRole' => ...]) em User e estourava 500.
        $this->client->request('POST', $url, ['tenant_password' => [
            'password'         => self::SENHA_NOVA,
            'confirm_password' => self::SENHA_NOVA,
            '_token'           => 'csrf-token',
        ]], [], ['HTTP_REFERER' => 'http://localhost' . $url]);

        self::assertResponseIsSuccessful('sem o form, o POST só re-renderiza a tela');
        foreach ([$this->titular, $this->socio, $this->advogado] as $usuario) {
            self::assertTrue($this->senhaConfere($usuario, 'senha123'), $usuario->getFullName() . ' manteve a senha');
        }
    }

    #[TestDox('A aba Dados do Escritório continua salvando o nome')]
    public function testAbaDadosContinuaSalvando(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $url     = $this->urlEditar();
        $crawler = $this->client->request('GET', $url);

        $valores = $crawler->filter('form[name=tenant_name]')->form()->getPhpValues();
        $valores['tenant_name']['name']   = 'Escritório Renomeado';
        $valores['tenant_name']['_token'] = (string) $crawler->filter('input[name="tenant_name[_token]"]')->attr('value');
        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('Escritório Renomeado', $this->em->find(Tenant::class, $this->tenant->getId())?->getName());
    }

    #[TestDox('Editar colaborador: o primário troca a senha de um secundário')]
    public function testPrimarioTrocaSenhaDoSecundarioPeloColaborador(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $this->submeterEditRole($this->advogado, $this->perfilAdvogado, self::SENHA_NOVA);

        self::assertResponseRedirects();
        self::assertTrue($this->senhaConfere($this->advogado, self::SENHA_NOVA));
    }

    /** @return iterable<string, array{bool}> */
    public static function vinculoDoMaster(): iterable
    {
        yield 'Master como sócio' => [true];
        yield 'Master como secundário' => [false];
    }

    #[DataProvider('vinculoDoMaster')]
    #[TestDox('Editar colaborador: um secundário com admin.users.manage não troca a senha do Master')]
    public function testSecundarioComPermissaoNaoTrocaSenhaDoMaster(bool $comoSocio): void
    {
        $master = $this->criarUsuario('Master do Sistema', $comoSocio ? $this->perfilPrimario : $this->perfilAdvogado, ['ROLE_SUPER_ADMIN']);
        $this->em->flush();
        $this->darPermissao($this->perfilAdvogado, 'admin.users.manage');

        $this->logarComTenant($this->client, $this->advogado, $this->tenant);
        $this->submeterEditRole($master, $comoSocio ? $this->perfilPrimario : $this->perfilAdvogado, self::SENHA_NOVA);

        self::assertResponseIsSuccessful('form recusado é re-renderizado');
        // A mensagem prova que a recusa veio da RegraDosPrimarios (podeAlterarConta), e não de
        // outra validação do form.
        self::assertStringContainsString(
            'A conta do Master só pode ser alterada pelo próprio Master.',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertTrue($this->senhaConfere($master, 'senha123'));
    }

    // ── apoio (molde do PerfisDoEscritorioControllerTest) ──────────────────

    private function urlEditar(): string
    {
        return sprintf('/tenant/%d/edit', $this->tenant->getId());
    }

    private function submeterEditRole(User $alvo, TenantRole $perfil, string $novaSenha): void
    {
        $url     = sprintf('/tenant/%d/user/%d/edit-role', $this->tenant->getId(), $alvo->getId());
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $valores = $crawler->filter('form[name=edit_user_tenant_role]')->form()->getPhpValues();
        $valores['edit_user_tenant_role']['tenantRole']  = (string) $perfil->getId();
        $valores['edit_user_tenant_role']['newPassword'] = ['first' => $novaSenha, 'second' => $novaSenha];
        $valores['edit_user_tenant_role']['_token']      = (string) $crawler
            ->filter('input[name="edit_user_tenant_role[_token]"]')->attr('value');

        $this->client->request('POST', $url, $valores, [], ['HTTP_REFERER' => 'http://localhost' . $url]);
    }

    private function senhaConfere(User $user, string $senha): bool
    {
        $this->em->clear();
        $recarregado = $this->em->find(User::class, $user->getId());
        self::assertNotNull($recarregado);

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($recarregado, $senha);
    }

    private function darPermissao(TenantRole $perfil, string $codigo): void
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $permissao->setDescription($codigo);
            $this->em->persist($permissao);
        }

        $perfil = $this->em->find(TenantRole::class, $perfil->getId());
        self::assertNotNull($perfil);
        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($perfil);
        $vinculo->setPermission($permissao);
        $perfil->getTenantRolePermissions()->add($vinculo);
        $this->em->persist($vinculo);
        $this->em->flush();
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
        $user->setEmail(uniqid('sem_aba_senha_') . '@test.com');
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
