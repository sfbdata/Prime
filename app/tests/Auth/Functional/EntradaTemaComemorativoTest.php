<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Controller\SecurityController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\TemaConfig;
use App\Master\Twig\TemaComemorativoExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tema comemorativo nas telas de entrada (desenho "10 - Login - VS 24.10.26", que passou a carregar o bj-tema.js;
 * spec docs/specs/login-desenho-10-24-10.md). No login e no véu valem as cores, a fita e os ícones na tarja; o
 * aplicar() do desenho sai antes do selo quando a página é o Login, então não há selo nem botão redondo.
 *
 * O tema usa o modo manual (independe da data em que a suíte roda). As cores trocadas no CSS da entrada, os ícones e
 * o brilho do logo são do navegador: smoke do dono.
 */
#[CoversClass(SecurityController::class)]
#[CoversClass(TemaComemorativoExtension::class)]
final class EntradaTemaComemorativoTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('login com tema ativo: CSS e variáveis do tema, fita, tarja e logos marcados para os ícones e o brilho; sem selo')]
    public function testLoginComTema(): void
    {
        $this->gravar('manual', 'outrosa');

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertTemaSemSelo($crawler, '--bjt-p:#d0558f');
        self::assertCount(1, $crawler->filter('header.lg-tarja[data-bjt-cabecalho]'));
        self::assertCount(4, $crawler->filter('img[src*="bluejus-logo"][data-bjt-logo]'));
        self::assertCount(0, $crawler->filter('img[src*="bluejus-logo"]:not([data-bjt-logo])'));
        self::assertNull($crawler->filter('#bjtTema')->attr('data-catalogo'), 'anônimo não recebe o catálogo da prévia');
    }

    #[TestDox('Master logado no login: vem o catálogo da prévia, ainda sem selo')]
    public function testMasterNoLoginLevaAPrevia(): void
    {
        $this->gravar('manual', 'outrosa');
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertTemaSemSelo($crawler, '--bjt-p:#d0558f');
        self::assertCount(11, json_decode((string) $crawler->filter('#bjtTema')->attr('data-catalogo'), true));
    }

    #[TestDox('login com tema animado (Dia das Crianças): a fita anima')]
    public function testLoginComTemaAnimado(): void
    {
        $this->gravar('manual', 'criancas');

        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('#bjtFita.bjt-anim'));
    }

    #[TestDox('login sem tema: nada do tema na página, nem CSS nem script')]
    public function testLoginSemTema(): void
    {
        $this->gravar('desligado', null);

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#bjtTema, #bjtCss, #bjtFita, #bjtSelo, #bjtMini'));
        self::assertStringNotContainsString('tema-comemorativo', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('véu "Abrindo o seu escritório digital" com tema ativo: CSS e fita do tema, sem selo')]
    public function testVeuComTema(): void
    {
        $this->gravar('manual', 'natal');
        $this->logar();

        $crawler = $this->client->request('GET', '/login/entrando');

        self::assertResponseIsSuccessful();
        $this->assertTemaSemSelo($crawler, '--bjt-p:#b22234');
    }

    #[TestDox('véu sem tema: nada do tema na página')]
    public function testVeuSemTema(): void
    {
        $this->gravar('desligado', null);
        $this->logar();

        $crawler = $this->client->request('GET', '/login/entrando');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#bjtTema, #bjtCss, #bjtFita, #bjtSelo, #bjtMini'));
    }

    private function assertTemaSemSelo(Crawler $crawler, string $variavel): void
    {
        self::assertCount(1, $crawler->filter('#bjtTema[hidden][data-sem-selo="1"]'));
        self::assertCount(1, $crawler->filter('body link#bjtCssArquivo[rel="stylesheet"][href*="/css/tema-comemorativo.css"]'));
        self::assertStringContainsString($variavel, $crawler->filter('body style#bjtCss')->text());
        self::assertCount(1, $crawler->filter('body #bjtFita[aria-hidden="true"]'));
        self::assertCount(0, $crawler->filter('#bjtSelo, #bjtMini'), 'o desenho não mostra o selo nas telas de entrada');
        self::assertCount(1, $crawler->filter('body script[src*="/js/tema-comemorativo.js"]'));
    }

    private function gravar(string $modo, ?string $manual): void
    {
        $this->em->clear();
        $config = $this->em->getRepository(TemaConfig::class)->find(TemaConfig::ID_UNICO) ?? new TemaConfig();
        $config->definirModo($modo, $manual, null, new \DateTimeImmutable());
        $this->em->persist($config);
        $this->em->flush();
    }

    private function logar(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório do tema ' . uniqid());
        $this->em->persist($tenant);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();

        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $user = new User();
        $user->setEmail('tema_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Tema');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('nao-usada');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
