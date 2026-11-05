<?php

declare(strict_types=1);

namespace App\Tests\Security\Functional;

use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Security\ExpiracaoDaSessaoListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Expiração por inatividade vista de fora: sessão envelhecida cai no /login com o aviso (spec login-sessao-expiracao). */
#[CoversClass(ExpiracaoDaSessaoListener::class)]
final class ExpiracaoDaSessaoTest extends JusPrimeWebTestCase
{
    private const AVISO = 'Sua sessão expirou por inatividade. Entre novamente.';
    private const SENHA = 'Senha-Forte-Expira-2026!';

    #[TestDox('Dentro do prazo, a página abre normalmente')]
    public function testDentroDoPrazo(): void
    {
        $client = $this->logado(10 * 60);

        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'), 'sessão de 10 min com padrão de 24 não expira');
    }

    #[TestDox('Inativa além do padrão: cai no /login com o aviso e a sessão acaba')]
    public function testSessaoEnvelhecidaCaiNoLogin(): void
    {
        $client = $this->logado(40 * 60);

        $client->request('GET', '/master');
        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();

        self::assertStringContainsString(self::AVISO, $crawler->filter('.lg-flash--error')->text());
        $client->request('GET', '/master');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Pedido de dados (XHR) com a sessão vencida recebe 401 em JSON')]
    public function testXhrRecebe401(): void
    {
        $client = $this->logado(40 * 60);

        $client->request('GET', '/master', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401);
        self::assertJson((string) $client->getResponse()->getContent());
    }

    #[TestDox('Cookie de sessão velho em rota pública (/convite, /senha) não vai ao login')]
    public function testRotaPublicaNaoExpira(): void
    {
        $client = $this->logado(40 * 60);

        foreach (['/convite/token-qualquer', '/senha/esqueci'] as $url) {
            $client->request('GET', $url);
            self::assertFalse($client->getResponse()->isRedirect('/login'), $url);
        }
    }

    #[TestDox('Cada requisição renova o carimbo: depois de um acesso dentro do prazo, o último uso é agora')]
    public function testRequisicaoRenova(): void
    {
        $client = $this->logado(20 * 60);

        $client->request('GET', '/master');
        self::assertFalse($client->getResponse()->isRedirect('/login'));

        $ultimo = $client->getSession()->getMetadataBag()->getLastUsed();
        self::assertLessThan(10, time() - $ultimo, 'o carimbo gravado pela requisição é recente, não os 20 min antigos');
    }

    #[TestDox('O prazo gravado no Master vale: com sessao=480, 40 min de pausa não expira')]
    public function testConfiguracaoDoMasterVale(): void
    {
        $client = $this->logado(40 * 60);
        $repositorio = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $repositorio->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirValores(['sessao' => '480'], null, new \DateTimeImmutable());
        $repositorio->salvar($configuracao, true);

        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'));
    }

    #[TestDox('Sessão parada além do prazo: abrir uma rota pública ($url) não renova, e o pedido seguinte expira')]
    #[DataProvider('rotasQueNaoRenovam')]
    public function testRotaPublicaNaoRenovaASessaoVencida(string $url): void
    {
        $client = $this->logado(40 * 60);

        $client->request('GET', $url);
        $client->request('GET', '/master');

        $this->assertExpirou($client);
    }

    #[TestDox('Com o carimbo próprio já gravado: 40 min parado, /login e depois /master expira')]
    public function testLoginNaoRenovaOCarimboProprio(): void
    {
        $client = $this->logado(60);
        $client->request('GET', '/master');
        $this->envelhecer($client, 40 * 60);

        $client->request('GET', '/login');
        $client->request('GET', '/master');

        $this->assertExpirou($client);
    }

    /** @return iterable<string, array{string}> */
    public static function rotasQueNaoRenovam(): iterable
    {
        yield 'login' => ['/login'];
        yield 'esqueci a senha' => ['/senha/esqueci'];
    }

    #[TestDox('Pedido autenticado dentro do prazo renova o carimbo próprio: mais 20 min parado não expira')]
    public function testRequisicaoRenovaOCarimboProprio(): void
    {
        $client = $this->logado(20 * 60);
        $client->request('GET', '/master');
        self::assertLessThan(10, time() - (int) $client->getSession()->get(ExpiracaoDaSessaoListener::CARIMBO));

        $this->envelhecer($client, 20 * 60);
        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'));
    }

    #[TestDox('Cookie REMEMBERME forjado numa sessão sem "manter conectado": 40 min parado expira')]
    public function testCookieLembrarMeForjadoNaoProtege(): void
    {
        $client = $this->logado(40 * 60);
        $client->getCookieJar()->set(new Cookie('REMEMBERME', 'x'));

        $client->request('GET', '/master');

        $this->assertExpirou($client);
    }

    #[TestDox('Login pelo formulário com "manter conectado": 40 min parado, /master continua abrindo')]
    public function testManterConectadoPeloFormularioNaoExpira(): void
    {
        $client = $this->entrarPeloFormulario(lembrar: true);
        $this->envelhecer($client, 40 * 60);

        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'));
        self::assertTrue($client->getSession()->get(ExpiracaoDaSessaoListener::MANTER_CONECTADO));
    }

    #[TestDox('Login pelo formulário sem "manter conectado", com cookie REMEMBERME forjado: 40 min parado expira')]
    public function testFormularioSemManterConectadoExpiraMesmoComCookie(): void
    {
        $client = $this->entrarPeloFormulario(lembrar: false);
        $this->envelhecer($client, 40 * 60);
        $client->getCookieJar()->set(new Cookie('REMEMBERME', 'x'));

        $client->request('GET', '/master');

        $this->assertExpirou($client);
    }

    #[TestDox('Login pelo formulário logo depois de uma sessão vencida: o primeiro pedido não expira')]
    public function testLoginDepoisDeSessaoVencidaNaoExpira(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->request('GET', '/login');
        $this->envelhecer($client, 40 * 60);
        $client->getSession()->set(ExpiracaoDaSessaoListener::CARIMBO, time() - 40 * 60);
        $client->getSession()->save();

        $this->entrarPeloFormulario(lembrar: false, client: $client);
        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'));
    }

    private function assertExpirou(KernelBrowser $client): void
    {
        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertStringContainsString(self::AVISO, $crawler->filter('.lg-flash--error')->text());
    }

    /** Entra pelo formulário de verdade (dispara o LoginSuccessEvent) como SuperAdmin. */
    private function entrarPeloFormulario(bool $lembrar, ?KernelBrowser $client = null): KernelBrowser
    {
        if ($client === null) {
            $client = static::createClient();
            $client->disableReboot();
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->novoSuperAdmin($em);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $em->flush();

        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = (string) $user->getEmail();
        $form['password'] = self::SENHA;
        if ($lembrar) {
            $form['_remember_me']->tick();
        }
        $client->submit($form);
        self::assertNotNull($client->getContainer()->get('security.token_storage')->getToken(), 'o login pelo formulário entrou');
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** Recua em `$segundos` o último uso da sessão: o carimbo do MetadataBag e o carimbo próprio, se houver. */
    private function envelhecer(KernelBrowser $client, int $segundos): void
    {
        $sessao = $client->getSession();
        $meta = $sessao->getMetadataBag();
        self::assertInstanceOf(MetadataBag::class, $meta);
        $propriedade = new \ReflectionProperty(MetadataBag::class, 'meta');
        $dados = $propriedade->getValue($meta);
        $dados[MetadataBag::UPDATED] = time() - $segundos;
        $propriedade->setValue($meta, $dados);
        if ($sessao->has(ExpiracaoDaSessaoListener::CARIMBO)) {
            $sessao->set(ExpiracaoDaSessaoListener::CARIMBO, time() - $segundos);
        }
        $sessao->save();
    }

    private function novoSuperAdmin(EntityManagerInterface $em): User
    {
        $user = new User();
        $user->setEmail('expira_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Expira');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Entra como SuperAdmin e recua o carimbo de último uso da sessão em `$segundosParado`. */
    private function logado(int $segundosParado): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->novoSuperAdmin($em);

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $this->envelhecer($client, $segundosParado);

        return $client;
    }
}
