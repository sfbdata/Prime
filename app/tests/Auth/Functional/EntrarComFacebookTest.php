<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\EntrarComFacebookController;
use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\ContaAConfirmar;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Security\FacebookAuthenticator;
use App\Tests\Auth\FacebookLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * "Continuar com Facebook", ida e volta (spec login-cupom-e-facebook, PR B). O Facebook não prova e-mail: só o
 * `facebook_id` já ligado entra; o e-mail do Facebook nunca liga, loga nem confirma conta existente.
 */
#[CoversClass(EntrarComFacebookController::class)]
#[CoversClass(FacebookAuthenticator::class)]
final class EntrarComFacebookTest extends JusPrimeWebTestCase
{
    private FacebookLoginFalso $facebook;

    #[TestDox('A ida leva ao Facebook com state, escopo public_profile,email e a volta no callback')]
    public function testIda(): void
    {
        $client = $this->cliente();

        $client->request('GET', $this->rotaIda($client));
        $local = (string) $client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($local, \PHP_URL_QUERY), $q);

        self::assertStringStartsWith('https://www.facebook.com/v21.0/dialog/oauth?', $local);
        self::assertSame('public_profile,email', $q['scope']);
        self::assertSame(32, strlen((string) $q['state']));
        self::assertSame('app-teste', $q['client_id']);
        self::assertStringEndsWith('/login/facebook/callback', (string) $q['redirect_uri']);
        self::assertSame([], $this->facebook->pedidos, 'a ida não chama o Graph');
    }

    #[TestDox('Sem credencial: a ida e a volta respondem 404, a tela não tem o botão e o Graph não é chamado')]
    public function testSemCredencial404(): void
    {
        $chamadas = 0;
        $client = $this->cliente(new FacebookLoginApi(new MockHttpClient(static function () use (&$chamadas): MockResponse {
            ++$chamadas;

            return new MockResponse('{}');
        })));

        $client->request('GET', '/login/facebook');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/login/facebook/callback?code=abc&state=x');
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertCount(0, $crawler->filter('#lgCdFacebook, a[href^="/login/facebook"]'));
        self::assertSame(0, $chamadas);
    }

    #[TestDox('Ida sem o token do botão (link forjado) ou com token errado: recusa e não vai ao Facebook')]
    public function testSemCsrfRecusa(): void
    {
        $client = $this->cliente();

        foreach (['/login/facebook', '/login/facebook?_token=forjado'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login?abrir=cadastro');
            $crawler = $client->followRedirect();
            self::assertSame(EntradaPeloFacebookRecusadaException::FALHOU, trim($crawler->filter('#lgErro')->text()));
            self::assertNull($client->getRequest()->getSession()->get(FacebookAuthenticator::SESSAO), 'sem state guardado');
        }
    }

    #[TestDox('O limitador da ida barra a tentativa excedente com a mensagem de muitas tentativas')]
    public function testLimitador(): void
    {
        $client = $this->cliente();
        static::getContainer()->set('limiter.login_facebook', new RateLimiterFactory(['id' => 'login_facebook_teste', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));
        $rota = $this->rotaIda($client);

        $client->request('GET', $rota);
        self::assertStringStartsWith('https://www.facebook.com/', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', $rota);

        self::assertResponseRedirects('/login');
        self::assertStringContainsString('Muitas tentativas', $client->request('GET', '/login')->filter('#lgErro')->text());
    }

    #[TestDox('facebook_id ligado, conta confirmada e ativa: entra pelo véu, como o login do Google')]
    public function testLigadoConfirmadoEntra(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('antigo@escritorio.com.br', facebookId: 'fb-1');
        $this->facebook->email = 'qualquer@outro.com';

        $this->voltar($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $token = static::getContainer()->get('security.token_storage')->getToken();
        self::assertNotNull($token);
        self::assertSame('antigo@escritorio.com.br', $token->getUserIdentifier());
        self::assertNotNull($eu->getId());
    }

    #[TestDox('facebook_id ligado mas e-mail ainda a confirmar: não loga e volta com o aviso de confirmar o e-mail')]
    public function testLigadoNaoConfirmadoNaoLoga(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pendente@escritorio.com.br', facebookId: 'fb-1', confirmada: false);

        $this->voltar($client);

        self::assertResponseRedirects('/login');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        $crawler = $client->request('GET', '/login');
        self::assertSame(ContaAConfirmar::mensagemDeLogin('pendente@escritorio.com.br'), trim($crawler->filter('#lgErro')->text()));
        self::assertSame(ContaAConfirmar::BOTAO_REENVIAR, trim($crawler->filter('#lgReenviar')->text()));
        self::assertNotNull($eu->getId());
    }

    #[TestDox('facebook_id ligado a conta inativa: recusa, sem login')]
    public function testInativoRecusa(): void
    {
        $client = $this->cliente();
        $this->usuario('inativa@escritorio.com.br', facebookId: 'fb-1', ativa: false);

        $this->voltar($client);

        self::assertResponseRedirects('/login');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    #[TestDox('ANTI-TOMADA DE CONTA: o /me devolve o e-mail de uma conta existente com outro id: não loga, não liga, não confirma; vai ao cadastro com a sugestão')]
    public function testEmailDeContaExistenteNaoTomaAConta(): void
    {
        $client = $this->cliente();
        $vitima = $this->usuario('vitima@escritorio.com.br', facebookId: null, confirmada: false);
        $confirmada = $this->usuario('confirmada@escritorio.com.br', facebookId: null);
        foreach ([$vitima, $confirmada] as $conta) {
            $this->facebook->id = 'fb-do-atacante-' . $conta->getId();
            $this->facebook->email = (string) $conta->getEmail();

            $this->voltar($client);

            self::assertResponseRedirects('/login?abrir=cadastro');
            self::assertNull(static::getContainer()->get('security.token_storage')->getToken(), 'não loga');
            $guardada = $client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO);
            self::assertSame($conta->getEmail(), $guardada['email'], 'a sugestão segue para a tela');
            $crawler = $client->request('GET', '/login?abrir=cadastro');
            self::assertSame($conta->getEmail(), $crawler->filter('#lgCdEmail')->attr('value'));
        }
        $linhas = $this->em()->getConnection()->fetchAllAssociative('SELECT email, facebook_id, email_a_confirmar_desde FROM "user" WHERE id IN (:a, :b) ORDER BY id', ['a' => $vitima->getId(), 'b' => $confirmada->getId()]);
        self::assertNull($linhas[0]['facebook_id'], 'não liga');
        self::assertNotNull($linhas[0]['email_a_confirmar_desde'], 'não confirma a conta a confirmar');
        self::assertNull($linhas[1]['facebook_id'], 'não liga');
    }

    #[TestDox('Sem conta com o id: guarda a identidade e abre o Criar conta conectado, sem erro e sem login; sem e-mail do Facebook, o campo vem vazio')]
    public function testSemContaAbreOCadastro(): void
    {
        $client = $this->cliente();
        $this->facebook->email = null;
        $this->facebook->nome = 'Nova Pessoa';

        $this->voltar($client);

        self::assertResponseRedirects('/login?abrir=cadastro');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        self::assertSame(['GET /v21.0/oauth/access_token', 'GET /v21.0/me'], $this->facebook->pedidos);
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertCount(1, $crawler->filter('#lgErro[hidden]'));
        self::assertCount(1, $crawler->filter('#lgCdSocOk:not([hidden])[data-provedor="facebook"]'));
        self::assertSame('Nova Pessoa', $crawler->filter('#lgCdSocNome')->text());
        self::assertSame('Conectado pelo Facebook', $crawler->filter('#lgCdSocOk .lg-socok__txt > span')->text());
        self::assertCount(1, $crawler->filter('#lgCdEmailCampo:not([hidden]) > #lgCdEmail'));
        self::assertSame('', (string) $crawler->filter('#lgCdEmail')->attr('value'));
    }

    #[TestDox('State errado, volta sem ida, cancelado e error=access_denied não logam; o state vale uma vez só')]
    public function testStateReplayECancelado(): void
    {
        $client = $this->cliente();
        $this->usuario('antigo@escritorio.com.br', facebookId: 'fb-1');

        $client->request('GET', '/login/facebook/callback?code=abc&state=sem-ida');
        self::assertResponseRedirects('/login');
        self::assertSame(EntradaPeloFacebookRecusadaException::FALHOU, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $state = $this->ida($client);
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $state . 'x');
        self::assertSame(EntradaPeloFacebookRecusadaException::FALHOU, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $state = $this->ida($client);
        $client->request('GET', '/login/facebook/callback?error=access_denied&error_reason=user_denied&state=' . $state);
        self::assertSame(EntradaPeloFacebookRecusadaException::CANCELADA, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $state = $this->ida($client);
        $client->request('GET', '/login/facebook/callback?state=' . $state);
        self::assertSame(EntradaPeloFacebookRecusadaException::CANCELADA, trim($client->request('GET', '/login')->filter('#lgErro')->text()));
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        self::assertSame([], $this->facebook->pedidos, 'nada disso chegou ao Graph');

        $state = $this->ida($client);
        $volta = '/login/facebook/callback?code=abc&state=' . $state;
        $client->request('GET', $volta);
        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', $volta);
        self::assertResponseRedirects('/login');
    }

    #[TestDox('Código recusado pelo Facebook: volta ao login com a mensagem neutra e nada é gravado')]
    public function testCodigoRecusado(): void
    {
        $client = $this->cliente();
        $this->facebook->recusarCodigo = true;

        $this->voltar($client);

        self::assertResponseRedirects('/login');
        self::assertSame(EntradaPeloFacebookRecusadaException::FALHOU, trim($client->request('GET', '/login')->filter('#lgErro')->text()));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    private function rotaIda(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/login?abrir=cadastro');

        return (string) $crawler->filter('#lgCdFacebook')->attr('href');
    }

    private function ida(KernelBrowser $client): string
    {
        $client->request('GET', $this->rotaIda($client));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        return (string) $q['state'];
    }

    private function voltar(KernelBrowser $client): void
    {
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $this->ida($client));
    }

    private function cliente(?FacebookLoginApi $api = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->facebook = new FacebookLoginFalso();
        static::getContainer()->set(FacebookLoginApi::class, $api ?? $this->facebook->api());

        return $client;
    }

    private function usuario(string $email, ?string $facebookId, bool $confirmada = true, bool $ativa = true): User
    {
        $u = (new User())->setEmail($email)->setFullName('Pessoa ' . uniqid())->setRoles(['ROLE_USER'])->setIsActive($ativa)->setPassword('x');
        if (null !== $facebookId) {
            $u->ligarAoFacebook($facebookId);
        }
        if (!$confirmada) {
            $u->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($u);
        $tenant = (new Tenant())->setName('Tenant FB ' . uniqid());
        $this->em()->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Gestor ' . uniqid())->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($u, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $u;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
