<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Security\UserAuthenticator;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Volta pós-login (spec login-desenho-10, comportamento 2): o login pelo formulário sempre passa
 * pelo véu `/login/entrando?para=<destino>`, e o destino respeita o que o usuário tentava abrir
 * (`target_path`) quando o caso de escritório permite.
 */
#[CoversClass(UserAuthenticator::class)]
final class VoltaPosLoginTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-de-teste-123';
    private const TARGET_PATH = '_security.main.target_path';

    #[TestDox('Com 1 escritório, volta para a tela que pediu o login, passando pelo véu')]
    public function testUmEscritorioHonraOTargetPath(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 1);

        $client->request('GET', '/agenda?dia=2026-10-19');
        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/login');
        // Termos já aceitos (a sessão migra no login com os atributos): o portão dos Termos não
        // pode desviar o véu neste teste.
        $this->marcarTermosAceitos($client);
        self::assertSame('/agenda?dia=2026-10-19', $this->submeter($client, $crawler, $user));
        self::assertFalse($client->getSession()->has(self::TARGET_PATH), 'O target_path é lido uma vez e sai da sessão.');

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('/agenda?dia=2026-10-19', json_decode((string) $crawler->filter('#veuEntrada')->attr('data-destino'), true));
    }

    #[TestDox('Com 1 escritório e sem target_path, o destino é o Expediente e o último acesso é gravado')]
    public function testUmEscritorioSemAlvoVaiParaOExpediente(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 1);

        self::assertSame('/expediente', $this->entrar($client, $user));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($vinculo?->getLastLoginAt());
    }

    #[TestDox('target_path de outro site na sessão é ignorado: o destino é o Expediente')]
    public function testTargetPathDeOutroSiteEhIgnorado(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 1);

        $crawler = $client->request('GET', '/login');
        $session = $client->getSession();
        $session->set(self::TARGET_PATH, 'https://evil.com/agenda');
        $session->save();

        self::assertSame('/expediente', $this->submeter($client, $crawler, $user));
    }

    #[TestDox('Incidente da v2.5.0: o fetch anônimo do sino recebe 401 em JSON, não vira destino, e o login vai ao Expediente')]
    public function testFetchDoSinoNaoViraDestino(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 1);

        // O `fetch('/notificacoes/count')` do base: sem X-Requested-With, com o modo que o navegador manda.
        $client->request('GET', '/notificacoes/count', [], [], ['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_ACCEPT' => '*/*']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Sua sessão terminou. Entre de novo.', json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertFalse($client->getSession()->has(self::TARGET_PATH), 'pedido de dados não vira destino');

        self::assertSame('/expediente', $this->entrar($client, $user));
    }

    #[TestDox('XHR e pedido que só aceita JSON também recebem 401 sem virar destino')]
    public function testXhrEJsonRecebem401(): void
    {
        $client = static::createClient();

        $client->xmlHttpRequest('GET', '/notificacoes/count');
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', '/agenda', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getSession()->has(self::TARGET_PATH));
    }

    #[TestDox('Navegação de página e formulário vencido continuam indo ao login')]
    public function testNavegacaoContinuaIndoAoLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/agenda', [], [], ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ACCEPT' => 'text/html']);
        self::assertResponseRedirects('/login');
        $client->request('POST', '/notificacoes/excluir', [], [], ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ACCEPT' => 'text/html']);
        self::assertResponseRedirects('/login');
    }

    #[TestDox('Sessão antiga com o contador do sino gravado como destino: o login vai ao Expediente')]
    public function testDestinoAntigoDoSinoNaSessaoEhIgnorado(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 1);

        $crawler = $client->request('GET', '/login');
        $session = $client->getSession();
        $session->set(self::TARGET_PATH, 'https://localhost/notificacoes/count');
        $session->save();

        self::assertSame('/expediente', $this->submeter($client, $crawler, $user));
    }

    #[TestDox('Master sem escritório volta para a tela pedida, ou para o painel do Master')]
    public function testMasterSemEscritorio(): void
    {
        $client = static::createClient();
        $master = $this->criarUsuario(escritorios: 0, roles: ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        self::assertSame('/admin/platform', $this->entrar($client, $master));

        $client->getCookieJar()->clear();
        $client->request('GET', '/agenda');
        self::assertSame('/agenda', $this->entrar($client, $master));
    }

    #[TestDox('Com vários escritórios, a seleção vem antes e o target_path se perde')]
    public function testVariosEscritoriosVaiParaASelecao(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario(escritorios: 2);

        $client->request('GET', '/agenda');

        self::assertSame('/escritorio/selecionar', $this->entrar($client, $user));
    }

    /**
     * Faz o login pelo formulário e devolve o `?para=` do salto para o véu.
     */
    private function entrar(KernelBrowser $client, User $user): string
    {
        $crawler = $client->request('GET', '/login');

        return $this->submeter($client, $crawler, $user);
    }

    private function submeter(KernelBrowser $client, Crawler $crawler, User $user): string
    {
        $form = $crawler->selectButton('Entrar')->form();
        $form['email'] = (string) $user->getEmail();
        $form['password'] = self::SENHA;
        $client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertSame('/login/entrando', parse_url($location, PHP_URL_PATH), 'Todo login pelo formulário passa pelo véu.');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) ($query['para'] ?? '');
    }

    /**
     * @param list<string> $roles
     */
    private function criarUsuario(int $escritorios, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('volta_' . uniqid() . '@adv.com');
        $user->setFullName('Marina Souza');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $em->persist($user);

        for ($i = 0; $i < $escritorios; ++$i) {
            $tenant = new Tenant();
            $tenant->setName('Escritório Volta ' . $i . ' ' . uniqid());
            $em->persist($tenant);
            $em->persist(new UserTenant($user, $tenant));
        }
        $em->flush();

        return $user;
    }
}
