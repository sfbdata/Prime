<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaCarteiraController;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\MoverPastaDeCarteiraUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Mover para outra carteira" (`pasta_mover_carteira`, POST /pasta/{id}/carteira) e o submenu do ⋮ do cabeçalho
 * (desenho 02 - EXPEDIENTES VS 18.10.26, dc L1228 e `menuPasta('mover')` L4577).
 *
 * Guardas: pasta do escritório (404), CSRF (403), carteira da lista fixa (400) e permissão de EDITAR (403). Cada
 * recusa confere que a carteira não mudou e que nenhum cartão foi escrito.
 */
#[CoversClass(PastaCarteiraController::class)]
#[CoversClass(MoverPastaDeCarteiraUseCase::class)]
#[Group('pasta')]
final class PastaCarteiraControllerTest extends JusPrimeWebTestCase
{
    private const CARTEIRAS = ['Contencioso cível', 'Condominial', 'Família e sucessões', 'Trabalhista', 'Previdenciário', 'Empresarial'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    // =========================================================================
    // Tela
    // =========================================================================

    #[TestDox('o item "Mover para outra carteira" é filho direto do ⋮, logo depois de "Trocar responsável", com bi-folder-symlink, e abre o submenu')]
    public function testItemNoMenu(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->abrir($pasta);

        $item = $c->filter('#psMenuAcoes > #psMenuTrocarResponsavel + button#psMenuMoverCarteira.ps-pop-item');
        self::assertCount(1, $item, 'filho direto do ⋮, logo depois de "Trocar responsável"');
        self::assertSame('Mover para outra carteira', trim($item->filter('button > span')->text()));
        self::assertCount(1, $item->filter('button > i.bi.bi-folder-symlink'));
        self::assertSame('psMoverCarteira', $item->attr('data-ps-pop'));
        self::assertCount(1, $c->filter('.ps-pop-wrap > #psMenuAcoes ~ #psMoverCarteira.ps-pop.ps-pop--carteira'), 'o submenu é irmão do ⋮: fecha o ⋮ e abre no lugar dele');
    }

    #[TestDox('pasta sem carteira: título "Mover pasta N para", as seis na ordem do desenho, nenhuma "· atual" e todas com bi-briefcase')]
    public function testSubmenuSemCarteira(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $menu = $this->abrir($pasta)->filter('#psMoverCarteira');

        self::assertSame('Mover pasta ' . $pasta->getNup() . ' para', trim($menu->filter('#psMoverCarteira > .ps-pop-secao:first-child')->text()));
        $opcoes = $menu->filter('#psMoverCarteira > button.js-carteira-opt');
        self::assertSame(self::CARTEIRAS, $opcoes->each(static fn (Crawler $o): string => trim($o->filter('span')->text())));
        self::assertSame(['contencioso_civel', 'condominial', 'familia_sucessoes', 'trabalhista', 'previdenciario', 'empresarial'], $opcoes->each(static fn (Crawler $o): ?string => $o->attr('data-carteira')));
        self::assertSame(array_fill(0, 6, 'false'), $opcoes->each(static fn (Crawler $o): ?string => $o->attr('aria-checked')));
        self::assertSame(array_fill(0, 6, 'bi bi-briefcase'), $opcoes->each(static fn (Crawler $o): ?string => $o->filter('i')->attr('class')));
    }

    #[TestDox('pasta em Trabalhista: só ela leva "Trabalhista · atual" e bi-check2; as outras, bi-briefcase')]
    public function testSubmenuComCarteiraAtual(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $pasta->moverParaCarteira(CarteiraDaPasta::Trabalhista);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $opcoes = $this->abrir($pasta)->filter('#psMoverCarteira > button.js-carteira-opt');

        $esperado = self::CARTEIRAS;
        $esperado[3] = 'Trabalhista · atual';
        self::assertSame($esperado, $opcoes->each(static fn (Crawler $o): string => trim($o->filter('span')->text())));
        self::assertSame(['false', 'false', 'false', 'true', 'false', 'false'], $opcoes->each(static fn (Crawler $o): ?string => $o->attr('aria-checked')));
        self::assertSame(
            ['bi bi-briefcase', 'bi bi-briefcase', 'bi bi-briefcase', 'bi bi-check2', 'bi bi-briefcase', 'bi bi-briefcase'],
            $opcoes->each(static fn (Crawler $o): ?string => $o->filter('i')->attr('class')),
        );
        self::assertSame('Trabalhista · atual', $opcoes->eq(3)->attr('data-rotulo-atual'));
        self::assertSame('Trabalhista', $opcoes->eq(3)->attr('data-rotulo'));
    }

    #[TestDox('sem permissão de editar, nem o item nem o submenu aparecem')]
    public function testSemEditarNaoMostra(): void
    {
        [, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $c = $this->abrir($pasta);

        self::assertCount(1, $c->filter('#psMenuAcoes'), 'o ⋮ existe; o que falta é só o item');
        self::assertCount(0, $c->filter('#psMenuMoverCarteira'));
        self::assertCount(0, $c->filter('#psMoverCarteira'));
    }

    // =========================================================================
    // POST
    // =========================================================================

    #[TestDox('o POST com CSRF grava a carteira, responde o rótulo e o Registro ganha o cartão "Carteira" com bi-folder-symlink')]
    public function testMoverGravaERegistra(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mover($pasta, 'trabalhista');

        self::assertResponseIsSuccessful();
        self::assertSame(['sucesso' => true, 'mudou' => true, 'carteira' => 'trabalhista', 'rotulo' => 'Trabalhista'], $this->resposta());
        self::assertSame('trabalhista', $this->carteira($pasta));
        $cartoes = $this->cartoes($pasta);
        self::assertCount(1, $cartoes);
        self::assertSame('<p><strong>Carteira:</strong> movida de &quot;Sem carteira&quot; para &quot;Trabalhista&quot;.</p>', $cartoes[0]['conteudo']);

        $c = $this->abrir($pasta);
        $cartao = $c->filter('#pasta-msg-' . $cartoes[0]['id']);
        self::assertCount(1, $cartao->filter('#pasta-msg-' . $cartoes[0]['id'] . ' > .ps-anotacao-corpo > i.ps-anotacao-icone.bi-folder-symlink'));
        self::assertStringContainsString('movida de "Sem carteira" para "Trabalhista".', $cartao->text());
    }

    #[TestDox('de Trabalhista para Previdenciário: "movida de "Trabalhista" para "Previdenciário"."; a mesma carteira de novo não escreve cartão')]
    public function testMoverDeNovoEMesmaCarteira(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mover($pasta, 'trabalhista');

        $this->mover($pasta, 'previdenciario');
        self::assertResponseIsSuccessful();
        $this->mover($pasta, 'previdenciario');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->resposta()['mudou']);

        self::assertSame('previdenciario', $this->carteira($pasta));
        $cartoes = $this->cartoes($pasta);
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('movida de &quot;Trabalhista&quot; para &quot;Previdenciário&quot;.', $cartoes[1]['conteudo']);
    }

    #[TestDox('CSRF inválido: 403 e nada muda')]
    public function testCsrfInvalido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mover($pasta, 'trabalhista', 'TOKEN_errado');

        self::assertResponseStatusCodeSame(403);
        $this->assertIntocada($pasta);
    }

    #[TestDox('carteira fora da lista: 400 e nada muda')]
    public function testCarteiraInvalida(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mover($pasta, 'tributario');
        self::assertResponseStatusCodeSame(400);
        $this->mover($pasta, '');
        self::assertResponseStatusCodeSame(400);
        $this->assertIntocada($pasta);
    }

    #[TestDox('sem permissão de editar a pasta: 403 e nada muda')]
    public function testSemPermissao(): void
    {
        [, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->mover($pasta, 'trabalhista');

        self::assertResponseStatusCodeSame(403);
        $this->assertIntocada($pasta);
    }

    #[TestDox('pasta de outro escritório: 404 e nada muda')]
    public function testOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $alheia = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mover($alheia, 'trabalhista');

        self::assertResponseStatusCodeSame(404);
        $this->assertIntocada($alheia);
    }

    #[TestDox('GET na rota não existe (405)')]
    public function testSoPost(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/pasta/' . $pasta->getId() . '/carteira');

        self::assertResponseStatusCodeSame(405);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function abrir(Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $c;
    }

    private function mover(Pasta $pasta, string $carteira, ?string $token = null): void
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/carteira', [
            '_token'   => $token ?? 'TOKEN_pasta_carteira_' . $pasta->getId(),
            'carteira' => $carteira,
        ]);
    }

    private function assertIntocada(Pasta $pasta): void
    {
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT carteira FROM pasta WHERE id = ?', [$pasta->getId()]));
        self::assertSame([], $this->cartoes($pasta));
    }

    private function carteira(Pasta $pasta): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT carteira FROM pasta WHERE id = ?', [$pasta->getId()]);

        return $valor === null ? null : (string) $valor;
    }

    /** @return list<array{id: int, conteudo: string}> */
    private function cartoes(Pasta $pasta): array
    {
        /** @var list<array{id: int, conteudo: string}> */
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT id, conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'carteira' ORDER BY id",
            [$pasta->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Carteira ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Carteira');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    /** Vê pastas, mas não edita. */
    private function criarLeitor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('Leitor Carteira');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $this->em()->persist($role);
        foreach (['modules.pastas.view', 'resources.pasta.view'] as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $v = new TenantRolePermission();
            $v->setTenantRole($role);
            $v->setPermission($perm);
            $this->em()->persist($v);
            $role->getTenantRolePermissions()->add($v);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('carteira_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('CT' . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
