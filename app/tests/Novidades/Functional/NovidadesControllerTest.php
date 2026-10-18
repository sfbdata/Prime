<?php

declare(strict_types=1);

namespace App\Tests\Novidades\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Novidades\Controller\NovidadesController;
use App\Novidades\Service\LeitorDeNovidades;
use App\Shared\VersaoDoSistema;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(NovidadesController::class)]
final class NovidadesControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('quem está logado vê as novidades da versão atual')]
    public function testPaginaMostraAsVersoes(): void
    {
        $client = static::createClient();
        $this->logar($client);

        $crawler = $client->request('GET', '/novidades');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Novidades');
        $primeira = $crawler->filter('.novidades-versao')->first();
        // A primeira seção é a versão mais recente COM texto para o cliente (uma correção sem
        // texto não entra no arquivo), não necessariamente a VersaoDoSistema.
        self::assertStringContainsString('Versão ' . $this->maisRecente(), $primeira->text());
        self::assertStringContainsString('versão ' . VersaoDoSistema::ATUAL, $crawler->filter('.novidades-pagina p')->first()->text());
        self::assertGreaterThan(0, $primeira->filter('li')->count());
    }

    #[TestDox('sem login, vai para o login')]
    public function testSemLoginRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/novidades');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('o menu do usuário leva às Novidades, com a versão mais recente para o selo')]
    public function testMenuDoUsuarioLevaANovidades(): void
    {
        $client = static::createClient();
        $this->logar($client);

        $crawler = $client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('.usuario-dropdown a[href="/novidades"]');
        self::assertCount(1, $link);
        self::assertSame($this->maisRecente(), $link->attr('data-novidades-versao'));
    }

    private function maisRecente(): string
    {
        return (string) static::getContainer()->get(LeitorDeNovidades::class)->maisRecente()?->versao;
    }

    private function logar(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Escritório Novidades ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('novidades_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Novidades');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha-de-teste-123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
    }
}
