<?php
declare(strict_types=1);
namespace App\Tests\Profile\Functional;

use App\Profile\Controller\ProfileController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(ProfileController::class)]
final class ProfileControllerTest extends WebTestCase
{
    #[TestDox('GET /perfil sem autenticação redireciona para login')]
    public function testGetPerfilRedirecionaSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST /perfil/status sem autenticação redireciona para login')]
    public function testPostStatusRedirecionaSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/status');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST /perfil/foto sem autenticação redireciona para login')]
    public function testPostFotoRedirecionaSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/foto');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST /perfil/dados sem autenticação redireciona para login')]
    public function testPostDadosRedirecionaSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/dados');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /perfil/foto/{nome} sem autenticação redireciona para login')]
    public function testGetServirFotoRedirecionaSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/foto/alguma_foto.jpg');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /perfil/status retorna 405 Method Not Allowed')]
    public function testGetStatusRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/status');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('GET /perfil/foto (POST endpoint) retorna 405 Method Not Allowed')]
    public function testGetFotoEndpointPostRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/foto');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('GET /perfil/dados retorna 405 Method Not Allowed')]
    public function testGetDadosRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/dados');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('POST /perfil/status sem autenticação é interceptado pelo firewall antes do CSRF')]
    public function testPostStatusTokenForjadoBloqueadoPeloFirewall(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/status', ['_token' => 'token_forjado', 'status' => 'hack']);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST /perfil/foto sem autenticação é interceptado pelo firewall antes do CSRF')]
    public function testPostFotoTokenForjadoBloqueadoPeloFirewall(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/foto', ['_token' => 'token_forjado']);

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }
}
