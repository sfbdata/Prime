<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A foto do cliente no cabeçalho da pasta (desenho 02 VS 18.10.26: botão L1255, foto L3853, menu L472-482, editor
 * L483-505). Arranjo com combinador de FILHO DIRETO: o círculo é filho do bloco do cliente, que é filho do cabeçalho.
 *  - quem edita o cliente: botão com menu ("Carregar foto" ou "Carregar nova foto"; "Ajustar foto" e "Remover foto"
 *    só com foto) e o editor de recorte;
 *  - quem só vê o cliente: a foto (ou o ícone), sem botão nem menu;
 *  - quem não vê o cliente, ou pasta sem cliente: o ícone, sem foto.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCabecalhoFotoClienteTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const BOTAO = '.ps-cabecalho > .ps-cab-cliente > button.ps-cab-cliente-icone.ps-cab-foto.js-ps-foto';
    private const SPAN = '.ps-cabecalho > .ps-cab-cliente > span.ps-cab-cliente-icone';

    #[TestDox('quem edita, cliente sem foto: botão de 38px do desenho com menu "Carregar foto"; Ajustar e Remover escondidos')]
    public function testEditorSemFoto(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $cliente] = $this->pastaComCliente($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $c = $this->abrir($client, $pasta);

        $botao = $c->filter(self::BOTAO);
        self::assertCount(1, $botao);
        self::assertCount(0, $c->filter(self::SPAN));
        self::assertSame('Foto do cliente', $botao->attr('title'));
        self::assertSame('menu', $botao->attr('aria-haspopup'));
        self::assertSame('0', $botao->attr('data-tem-foto'));
        self::assertSame('/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto', $botao->attr('data-url-trocar'));
        self::assertSame('/pasta/' . $pasta->getId() . '/clientes/' . $cliente->getId() . '/foto/remover', $botao->attr('data-url-remover'));
        self::assertNotSame('', (string) $botao->attr('data-token'));
        self::assertCount(1, $botao->filter('button.ps-cab-foto > i.bi.bi-person-fill'));
        self::assertNotNull($botao->filter('button.ps-cab-foto > img.ps-cab-foto-img')->attr('hidden'));

        $menu = $c->filter('.ps-cab-cliente > [data-ps-foto-menu][role="menu"]');
        self::assertCount(1, $menu);
        self::assertSame(['Carregar foto', 'Ajustar foto', 'Remover foto'], $menu->filter('[data-ps-foto-menu] > button[role="menuitem"]')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame(['bi bi-upload', 'bi bi-crop', 'bi bi-trash3'], $menu->filter('[data-ps-foto-menu] > button > i')->each(static fn (Crawler $i): string => (string) $i->attr('class')));
        self::assertNull($menu->filter('.js-ps-foto-carregar')->attr('hidden'));
        self::assertNotNull($menu->filter('.js-ps-foto-ajustar')->attr('hidden'));
        self::assertNotNull($menu->filter('.js-ps-foto-remover')->attr('hidden'));
        self::assertStringContainsString('ps-foto-menu-item--perigo', (string) $menu->filter('.js-ps-foto-remover')->attr('class'));
        self::assertCount(1, $c->filter('.ps-cab-cliente > input[type="file"][accept="image/*"][data-ps-foto-arquivo]'));
        self::assertCount(1, $c->filter('script[src*="js/pasta-foto-cliente.js"]'));
    }

    #[TestDox('editor de recorte: título, círculo, dica, faixa de zoom de 1 a 3, girar 90° e os três botões do desenho')]
    public function testEditorDeRecorte(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->pastaComCliente($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $c = $this->abrir($client, $pasta);

        $janela = $c->filter('.ps-cab-cliente > [data-ps-foto-editor][hidden] > .ps-foto-editor-janela[role="dialog"]');
        self::assertCount(1, $janela);
        self::assertSame('Ajustar foto', $janela->attr('aria-label'));
        self::assertSame('Ajustar foto do cliente', trim($janela->filter('.ps-foto-editor-janela > .ps-foto-editor-topo > .ps-foto-editor-titulo')->text()));
        self::assertCount(1, $janela->filter('.ps-foto-editor-topo > i.bi-person-bounding-box'));
        self::assertSame('Fechar', $janela->filter('.ps-foto-editor-topo > button.ps-foto-editor-fechar')->attr('aria-label'));
        $corpo = $janela->filter('.ps-foto-editor-janela > .ps-foto-editor-corpo');
        self::assertSame('Arraste para posicionar', $corpo->filter('.ps-foto-editor-corpo > .ps-foto-editor-circulo')->attr('title'));
        self::assertSame('Arraste para posicionar · role para aproximar', trim($corpo->filter('.ps-foto-editor-corpo > .ps-foto-editor-dica')->text()));
        $zoom = $corpo->filter('.ps-foto-editor-zoom > input[type="range"]');
        self::assertSame(['1', '3', '0.01', 'Zoom'], [$zoom->attr('min'), $zoom->attr('max'), $zoom->attr('step'), $zoom->attr('aria-label')]);
        self::assertSame(['bi bi-zoom-out', 'bi bi-zoom-in'], $corpo->filter('.ps-foto-editor-zoom > i')->each(static fn (Crawler $i): string => (string) $i->attr('class')));
        $girar = $corpo->filter('.ps-foto-editor-zoom > button.ps-foto-editor-girar');
        self::assertSame(['Girar 90°', 'Girar'], [$girar->attr('title'), $girar->attr('aria-label')]);
        self::assertSame(
            ['Trocar imagem', 'Cancelar', 'Salvar foto'],
            $janela->filter('.ps-foto-editor-janela > .ps-foto-editor-rodape > button')->each(static fn (Crawler $b): string => trim($b->text())),
        );
    }

    #[TestDox('quem edita, cliente com foto: a foto cobre o círculo (com ?v=), "Carregar nova foto", Ajustar e Remover visíveis')]
    public function testEditorComFoto(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $cliente] = $this->pastaComCliente($tenant, 'a1b2c3.jpg');

        $this->logarComTenant($client, $user, $tenant);
        $c = $this->abrir($client, $pasta);

        $botao = $c->filter(self::BOTAO);
        self::assertSame('1', $botao->attr('data-tem-foto'));
        $img = $botao->filter('button.ps-cab-foto > img.ps-cab-foto-img');
        self::assertNull($img->attr('hidden'));
        self::assertMatchesRegularExpression('#^/clientes/' . $cliente->getId() . '/foto/a1b2c3\.jpg\?v=\d+$#', (string) $img->attr('src'));
        $menu = $c->filter('.ps-cab-cliente > [data-ps-foto-menu]');
        self::assertSame('Carregar nova foto', trim($menu->filter('.js-ps-foto-carregar')->text()));
        self::assertNull($menu->filter('.js-ps-foto-ajustar')->attr('hidden'));
        self::assertNull($menu->filter('.js-ps-foto-remover')->attr('hidden'));
    }

    #[TestDox('quem só VÊ o cliente: a foto, num span, sem botão, menu nem editor')]
    public function testSoVer(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        [$pasta, $cliente] = $this->pastaComCliente($tenant, 'a1b2c3.jpg');
        $leitor = $this->colaborador($tenant, ['resources.pasta.view', 'resources.cliente.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $c = $this->abrir($client, $pasta);

        self::assertCount(0, $c->filter(self::BOTAO));
        self::assertCount(0, $c->filter('[data-ps-foto-menu], [data-ps-foto-editor], [data-ps-foto-arquivo]'));
        $span = $c->filter(self::SPAN . '.ps-cab-foto');
        self::assertCount(1, $span);
        self::assertStringStartsWith('/clientes/' . $cliente->getId() . '/foto/a1b2c3.jpg', (string) $span->filter('span.ps-cab-foto > img.ps-cab-foto-img')->attr('src'));
    }

    #[TestDox('quem não vê o cliente: o ícone, sem a foto')]
    public function testSemVerOCliente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        [$pasta] = $this->pastaComCliente($tenant, 'a1b2c3.jpg');
        $semCliente = $this->colaborador($tenant, ['resources.pasta.view']);

        $this->logarComTenant($client, $semCliente, $tenant);
        $c = $this->abrir($client, $pasta);

        self::assertCount(0, $c->filter(self::BOTAO));
        self::assertCount(1, $c->filter(self::SPAN));
        self::assertCount(0, $c->filter('.ps-cab-cliente img.ps-cab-foto-img'));
    }

    #[TestDox('pasta excluída (lápide): mesmo quem edita vê só a foto, sem menu')]
    public function testLapide(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->pastaComCliente($tenant, 'a1b2c3.jpg');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $c = $this->abrir($client, $pasta);

        self::assertCount(0, $c->filter(self::BOTAO));
        self::assertCount(0, $c->filter('[data-ps-foto-menu]'));
        self::assertCount(1, $c->filter(self::SPAN . '.ps-cab-foto > img.ps-cab-foto-img'));
    }

    #[TestDox('pasta sem cliente cadastrado: o ícone inerte, sem foto nem menu')]
    public function testSemCliente(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $c = $this->abrir($client, $pasta);

        self::assertCount(1, $c->filter(self::SPAN));
        self::assertCount(0, $c->filter(self::BOTAO . ', [data-ps-foto-menu], .ps-cab-cliente img.ps-cab-foto-img'));
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array{0: Pasta, 1: ClientePF} */
    private function pastaComCliente(Tenant $tenant, ?string $foto = null): array
    {
        $pasta = $this->criarPasta($tenant);
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setEmail('foto' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setNomeCompleto('Ana Cliente');
        $cliente->setCpf((string) random_int(10000000000, 99999999999));
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        if ($foto !== null) {
            $cliente->trocarFoto($foto, new \DateTimeImmutable());
        }
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        return [$pasta, $cliente];
    }

    /** @param list<string> $codigos */
    private function colaborador(Tenant $tenant, array $codigos): User
    {
        $em = $this->em();
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup('resources');
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $user = new User();
        $user->setEmail('foto_colab_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Foto');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
