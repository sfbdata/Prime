<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaAdministrativaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Administrativo sem processo" (`pasta_administrativa_definir`, interruptor da aba Processo,
 * desenho 1.2.3).
 *
 * Guardas: pasta do escritório (404), CSRF (403), permissão de EDITAR a pasta (403). O
 * cross-tenant usa um SUPER_ADMIN de propósito — ele passa por `canAccessResource` em qualquer
 * pasta, então o 404 só pode vir da conferência de dono. O teste da permissão usa o par "só ver
 * pasta" (403) × "ver + editar pasta" (sucesso) no MESMO escritório, para o 403 não ser outra
 * barreira.
 */
#[CoversClass(PastaAdministrativaController::class)]
#[Group('pasta')]
final class PastaAdministrativaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    private function instalarCsrfStorage(): void
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
    }

    /** @param array<string, string> $servidor */
    private function definir(KernelBrowser $client, Pasta $pasta, string $valor, ?string $token = null, array $servidor = []): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', [
            '_token'         => $token ?? 'TOKEN_pasta_administrativa_' . $pasta->getId(),
            'administrativa' => $valor,
        ], [], $servidor);
    }

    /** Lê do BANCO, não da entidade em memória (o EntityManager do teste não relê). */
    private function administrativaNoBanco(Pasta $pasta): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            'SELECT administrativa FROM pasta WHERE id = :id',
            ['id' => $pasta->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Papel comum com `resources.pasta.view` E `resources.pasta.edit`. */
    private function criarEditorDePasta(Tenant $tenant): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Editor de pasta ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach (['resources.pasta.view' => 'Visualizar pasta específica', 'resources.pasta.edit' => 'Criar e editar pasta'] as $codigo => $descricao) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($descricao);
                $perm->setGroup('resources');
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $user = new User();
        $user->setEmail('adm_editor_' . uniqid() . '@test.com');
        $user->setFullName('Editor de pasta');
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

    #[TestDox('Marcar (sem XHR) grava true e volta para a aba Processo; desmarcar grava false')]
    public function testMarcaEDesmarca(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->definir($client, $pasta, '1');
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#processo');
        self::assertTrue($this->administrativaNoBanco($pasta));

        $this->definir($client, $pasta, '0');
        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#processo');
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('Com XHR responde {sucesso, administrativa, html} e o html traz o interruptor já ligado')]
    public function testXhrDevolveOParcial(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '1', null, self::XHR);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['sucesso']);
        self::assertTrue($dados['administrativa']);
        self::assertStringContainsString('aria-checked="true"', (string) $dados['html']);
        self::assertStringContainsString('ps-vazio--administrativa', (string) $dados['html']);
        self::assertTrue($this->administrativaNoBanco($pasta));
    }

    #[TestDox('A troca entra no audit_log da pasta, com o campo administrativa no diff')]
    public function testRegistraNaAuditoria(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '1');
        self::assertResponseRedirects();

        $linha = $this->em()->getConnection()->fetchAssociative(
            "SELECT action, actor_email, route, changes::text AS changes
               FROM audit_log
              WHERE entity_class = ? AND entity_id = ?
              ORDER BY id DESC LIMIT 1",
            [Pasta::class, (string) $pasta->getId()],
        );

        self::assertNotFalse($linha, 'Marcar precisa deixar linha na auditoria.');
        self::assertSame('update', $linha['action']);
        self::assertSame($user->getEmail(), $linha['actor_email']);
        self::assertSame('pasta_administrativa_definir', $linha['route']);
        self::assertStringContainsString('administrativa', (string) $linha['changes']);
    }

    #[TestDox('Valor que não é booleano dá 422 e não grava nada')]
    public function testValorInvalido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, 'talvez', null, self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('Token CSRF inválido dá 403 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '1', 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('Pasta de OUTRO escritório dá 404, mesmo para quem passa em qualquer permissão, e não grava nada')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $this->definir($client, $pastaB, '1');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->administrativaNoBanco($pastaB));
    }

    #[TestDox('Pasta de OUTRO escritório por XHR também dá 404 em JSON, sem gravar')]
    public function testOutroEscritorioXhrDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $this->definir($client, $pastaB, '1', null, self::XHR);

        self::assertResponseStatusCodeSame(404);
        self::assertArrayHasKey('erro', $this->json($client));
        self::assertFalse($this->administrativaNoBanco($pastaB));
    }

    #[TestDox('Usuário do MESMO escritório que só pode VER a pasta leva 403 e não grava nada')]
    public function testSoVerDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant); // só resources.pasta.view
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $leitor, $tenant);
        $this->definir($client, $pasta, '1');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('XHR com CSRF inválido dá 403 em JSON com `erro` e sem `html` — é o que o JS usa para desfazer o interruptor')]
    public function testCsrfInvalidoXhrDa403EmJson(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '1', 'token_invalido', self::XHR);

        self::assertResponseStatusCodeSame(403);
        $dados = $this->json($client);
        self::assertSame('Token de segurança inválido.', $dados['erro'] ?? null);
        self::assertArrayNotHasKey('html', $dados);
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('XHR de quem só pode VER a pasta dá 403 em JSON com `erro`, sem `html`, e não grava nada')]
    public function testSoVerXhrDa403EmJson(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant); // só resources.pasta.view
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $leitor, $tenant);
        $this->definir($client, $pasta, '1', null, self::XHR);

        self::assertResponseStatusCodeSame(403);
        $dados = $this->json($client);
        self::assertIsString($dados['erro'] ?? null);
        self::assertNotSame('', $dados['erro']);
        self::assertArrayNotHasKey('html', $dados);
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('XHR ao DESMARCAR com processo vinculado devolve o cartão com o interruptor desligado e a confirmação de volta em data-confirmar')]
    public function testXhrDesmarcarComProcessoDevolveConfirmacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07011345720258070007'));
        $pasta->setAdministrativa(true);
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '0', null, self::XHR);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['sucesso']);
        self::assertFalse($dados['administrativa']);
        $html = new Crawler('<div id="processoTabContent">' . $dados['html'] . '</div>');
        $form = $html->filter('#processoTabContent > .ps-processos > .ps-card-cab > form.js-pasta-administrativa');
        self::assertCount(1, $form);
        self::assertSame('false', $form->filter('button[role="switch"]')->attr('aria-checked'));
        self::assertSame('1', $form->filter('input[name="administrativa"]')->attr('value'));
        self::assertStringContainsString('Administrativo sem processo', (string) $form->attr('data-confirmar'));
        self::assertNull($form->attr('onsubmit'));
        self::assertFalse($this->administrativaNoBanco($pasta));
    }

    #[TestDox('Usuário comum com permissão de EDITAR a pasta consegue marcar — o 403 acima é a permissão, não outra barreira')]
    public function testEditorConsegueMarcar(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $editor     = $this->criarEditorDePasta($tenant);
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $editor, $tenant);
        $this->definir($client, $pasta, '1');

        self::assertResponseRedirects('/pasta/' . $pasta->getId() . '#processo');
        self::assertTrue($this->administrativaNoBanco($pasta));
    }

    #[TestDox('Pasta com processo vinculado pode ser marcada, e o vínculo continua')]
    public function testMarcaComProcessoVinculado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07011345720258070007'));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->definir($client, $pasta, '1');

        self::assertResponseRedirects();
        self::assertTrue($this->administrativaNoBanco($pasta));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_processo WHERE pasta_id = :id',
            ['id' => $pasta->getId()],
        ));
    }
}
