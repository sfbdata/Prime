<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Controller\ContaDoEscritorioController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Twig\TenantContextExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Menu do usuário, abas do Meu Perfil e aba "Trocar conta" (desenhos "03 - EXPEDIENTE" e
 * "07 - Meu Perfil", VS 18.10.26). Quem vê o quê:
 *   - Convites do escritório: só o primário (ou o Master), decisão do dono em 19/10/2026;
 *   - Exclusivo do Master: só ROLE_SUPER_ADMIN, 6 atalhos para o painel e 2 itens em preparação;
 *   - Trocar conta: só as contas da própria pessoa.
 */
#[CoversClass(ContaDoEscritorioController::class)]
#[CoversClass(TenantContextExtension::class)]
final class MenuDoUsuarioTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private TenantRole $perfilPrimario;
    private TenantRole $perfilSecundario;
    private User $titular;
    private User $secundario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant           = $this->criarEscritorio('Escritório Menu');
        $this->perfilPrimario   = $this->criarPerfil($this->tenant, 'Administrador do Escritório', true);
        $this->perfilSecundario = $this->criarPerfil($this->tenant, 'Advogado (a)', false);

        $this->titular    = $this->criarUsuario('Farlei Assis da Rocha');
        $this->secundario = $this->criarUsuario('Jéssica Martins');
        $this->vincular($this->titular, $this->tenant, $this->perfilPrimario);
        $this->vincular($this->secundario, $this->tenant, $this->perfilSecundario);
        $this->tenant->setCriadoPor($this->titular);
        $this->em->flush();
    }

    #[TestDox('secundário: sem Convites, sem seção do Master, com Trocar de conta e selo SECUNDÁRIO')]
    public function testMenuDoSecundario(): void
    {
        $menu = $this->menuDe($this->secundario);

        self::assertCount(0, $menu->filter('[data-mu-convites]'));
        self::assertCount(0, $menu->filter('.mu-grupo-master'));
        self::assertCount(1, $menu->filter('[data-mu-conta="trocar"]'));
        self::assertSame('SECUNDÁRIO', trim($menu->filter('.mu-selos > .mu-selo-papel')->text()));
        self::assertCount(0, $menu->filter('.mu-selo-master'));
    }

    #[TestDox('primário: vê Convites do escritório (rótulo "equipe") e o selo USUÁRIO PRIMÁRIO')]
    public function testMenuDoPrimario(): void
    {
        $menu = $this->menuDe($this->titular);

        $convites = $menu->filter('.mu-grupo > a[data-mu-convites][href="/escritorio/convites"]');
        self::assertCount(1, $convites);
        self::assertSame('equipe', trim($convites->filter('.mu-item-dir')->text()));
        self::assertSame('USUÁRIO PRIMÁRIO', trim($menu->filter('.mu-selos > .mu-selo-papel')->text()));
        self::assertCount(0, $menu->filter('.mu-grupo-master'));
    }

    #[TestDox('Master: selo MASTER e seção com 8 itens; 6 levam ao painel, a Central de vagas ao Trabalho Externo e 1 abre "em preparação"')]
    public function testMenuDoMaster(): void
    {
        $master = $this->criarUsuario('Master Menu', ['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->tenant, $this->perfilSecundario);
        $this->em->flush();

        $menu  = $this->menuDe($master);
        $itens = $menu->filter('.mu-grupo-master > [data-item-master]');

        self::assertCount(1, $menu->filter('.mu-selos > .mu-selo-master'));
        self::assertCount(8, $itens);
        self::assertCount(6, $menu->filter('.mu-grupo-master > a[data-item-master][href^="/master#aba="]'));
        self::assertSame(
            ['Campanha por e-mail'],
            $menu->filter('.mu-grupo-master > button[data-em-preparacao]')->each(static fn (Crawler $b): string => (string) $b->attr('data-em-preparacao'))
        );
        // Desenho 03 L330 e 07 L209: a Central de vagas leva ao Trabalho Externo, aba de vagas.
        $vagas = $menu->filter('.mu-grupo-master > a[data-item-master]')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'Central de vagas'));
        self::assertSame('/trabalho-externo#mod=vagas', $vagas->attr('href'));
        self::assertCount(1, $menu->filter('[data-mu-convites]'), 'o Master vê Convites mesmo com vínculo secundário');
    }

    #[TestDox('cabeçalho: avatar com as iniciais do primeiro e do último nome')]
    public function testIniciaisNoAvatar(): void
    {
        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', '/perfil');

        self::assertSame('FR', trim($crawler->filter('#navtopAvatar')->text()));
        self::assertSame('FR', trim($crawler->filter('.mu-menu #usuarioAvatarLg')->text()));
    }

    #[TestDox('Meu Perfil: abas do topo; Convites só para o primário e Master só para o Master')]
    public function testAbasDoPerfil(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $crawler = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $abas = $crawler->filter('.mu-abas-perfil > .mu-abas-perfil-miolo');
        self::assertSame('Meu Perfil', trim($abas->filter('.mu-aba-perfil.on')->text()));
        self::assertCount(0, $abas->filter('a[href="/escritorio/convites"]'));
        self::assertCount(1, $abas->filter('[data-mu-conta="trocar"]'));
        self::assertCount(0, $abas->filter('.mu-aba-perfil-master'));

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        $crawler = $this->client->request('GET', '/perfil');
        self::assertCount(1, $crawler->filter('.mu-abas-perfil-miolo > a[href="/escritorio/convites"]'));
    }

    #[TestDox('Trocar conta: lista só as contas da pessoa, com equipe, primário e selo ATUAL')]
    public function testTrocarContaListaSoAsMinhas(): void
    {
        $outro   = $this->criarEscritorio('Outro Escritório Meu');
        $alheio  = $this->criarEscritorio('Escritório Alheio');
        $perfil  = $this->criarPerfil($outro, 'Administrador', true);
        $this->vincular($this->secundario, $outro, $perfil);
        $this->vincular($this->criarUsuario('Pessoa Alheia'), $alheio, $this->criarPerfil($alheio, 'Adm', true));
        $this->em->flush();

        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $crawler = $this->client->request('GET', '/escritorio/contas');

        self::assertResponseIsSuccessful();
        $contas = $crawler->filter('.mu-contas > form > button.mu-conta');
        self::assertCount(2, $contas);
        self::assertStringNotContainsString('Escritório Alheio', (string) $this->client->getResponse()->getContent());
        $atual = $crawler->filter('.mu-contas > form > button.mu-conta.atual');
        self::assertStringContainsString($this->tenant->getName(), $atual->text());
        self::assertStringContainsString('2 usuário(s) · primário: Farlei Assis da Rocha', $atual->text());
        self::assertSame('ATUAL', trim($atual->filter('.mu-conta-atual')->text()));
    }

    #[TestDox('Trocar conta: "Sair deste escritório" aparece para o secundário e nunca para o titular (D4)')]
    public function testSairDoEscritorioSoParaQuemNaoETitular(): void
    {
        $seletor = sprintf('.mu-contas > form.mu-sair-conta[action="/escritorio/%d/sair"]', $this->tenant->getId());

        $this->logarComTenant($this->client, $this->titular, $this->tenant);
        self::assertCount(0, $this->client->request('GET', '/escritorio/contas')->filter($seletor));

        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        self::assertCount(1, $this->client->request('GET', '/escritorio/contas')->filter($seletor));
    }

    #[TestDox('modal Conta do escritório: aba Abrir conta para o primário e o Master, nunca para o secundário')]
    public function testAbaAbrirContaSoParaPrimarioOuMaster(): void
    {
        $seletor = '#modalContaEscritorio .mu-abas > [data-mu-aba="nova"]';
        $master  = $this->criarUsuario('Master Aba', ['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->tenant, $this->perfilSecundario);
        $this->em->flush();

        foreach ([[$this->secundario, 0], [$this->titular, 1], [$master, 1]] as [$usuario, $esperado]) {
            $this->logarComTenant($this->client, $usuario, $this->tenant);
            $crawler = $this->client->request('GET', '/perfil');
            self::assertCount($esperado, $crawler->filter($seletor), $usuario->getFullName());
            self::assertCount($esperado, $crawler->filter('#modalContaEscritorio [data-mu-painel="nova"] a[href="/escritorio/criar"]'));
            self::assertCount(1, $crawler->filter('#modalContaEscritorio .mu-abas > [data-mu-aba="trocar"]'));
        }
    }

    #[TestDox('Master fora de escritório: menu sem Convites e sem Trocar de conta (a tela de convites exige escritório)')]
    public function testMasterSemEscritorioNaoVeConvites(): void
    {
        $master = $this->criarUsuario('Master Solto', ['ROLE_SUPER_ADMIN']);
        $this->em->flush();

        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);
        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('.menu-usuario > .usuario-dropdown.mu-menu');
        self::assertCount(1, $menu->filter('.mu-grupo-master'));
        self::assertCount(0, $menu->filter('[data-mu-convites]'));
        self::assertCount(0, $menu->filter('[data-mu-conta="trocar"]'));
    }

    #[TestDox('Trocar conta exige login')]
    public function testTrocarContaExigeLogin(): void
    {
        $this->client->request('GET', '/escritorio/contas');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function menuDe(User $usuario): Crawler
    {
        $this->logarComTenant($this->client, $usuario, $this->tenant);
        $crawler = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $crawler->filter('.menu-usuario > .usuario-dropdown.mu-menu');
    }

    private function criarEscritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarPerfil(Tenant $tenant, string $nome, bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName($nome);
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(string $nome, array $papeis = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(uniqid('menu_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);

        return $user;
    }

    private function vincular(User $user, Tenant $tenant, TenantRole $perfil): void
    {
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);
    }
}
