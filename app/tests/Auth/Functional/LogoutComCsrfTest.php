<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Controller\SecurityController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Logout com CSRF (`enable_csrf` no firewall main): sem o token, outro site derrubava a sessão e o lembrar-me
 * com um GET para /logout. O id `logout` é stateless, então o token vale pela origem (Sec-Fetch-Site, Origin
 * ou Referer); os links "Sair" saem de `logout_path('main')`.
 */
#[CoversClass(SecurityController::class)]
#[Group('auth')]
final class LogoutComCsrfTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Escritório do logout');
        $em->persist($tenant);
        $user = new User();
        $user->setEmail('logout_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa do logout');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($this->client, $user, $tenant);
    }

    #[TestDox('GET /logout vindo de outro site, sem token: recusado e a sessão continua')]
    public function testOutroSiteNaoDerrubaASessao(): void
    {
        $this->client->request('GET', '/logout', [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->continuaLogado());
    }

    #[TestDox('GET /logout com o token mas vindo de outro site: recusado (o token stateless vale pela origem)')]
    public function testTokenDeOutroSiteNaoVale(): void
    {
        $this->client->request('GET', '/logout?_csrf_token=csrf-token', [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->continuaLogado());
    }

    #[TestDox('GET /logout sem token e sem origem: recusado')]
    public function testSemTokenESemOrigemERecusado(): void
    {
        $this->client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->continuaLogado());
    }

    #[TestDox('O link "Sair" do menu leva o token e, clicado no próprio sistema, encerra a sessão')]
    public function testLinkDoMenuSai(): void
    {
        $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $link = $this->client->getCrawler()->filter('a[data-mu-sair]');
        self::assertCount(1, $link);
        $href = (string) $link->attr('href');
        self::assertStringContainsString('_csrf_token=', $href);

        $this->client->request('GET', $href, [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects();
        self::assertFalse($this->continuaLogado());
    }

    private function continuaLogado(): bool
    {
        $this->client->request('GET', '/perfil');

        return $this->client->getResponse()->isSuccessful();
    }
}
